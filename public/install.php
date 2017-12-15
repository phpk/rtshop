<?php
/**
 * 安装程序
 * 
 * 安装完成后建议删除此文件
 * @author skyboy
 * @website http://www.qya.cn
 */
// error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
// ini_set('display_errors', '1');
// 定义目录分隔符
define('DS', DIRECTORY_SEPARATOR);

// 定义根目录
define('ROOT_PATH', __DIR__ . DS . '..' . DS);

// 定义应用目录
define('APP_PATH', ROOT_PATH . 'application' . DS);

// 安装包目录
define('INSTALL_PATH', APP_PATH . 'admin' . DS . 'command' . DS . 'Install' . DS);

// 判断文件或目录是否有写的权限
function is_really_writable($file)
{
    if (DIRECTORY_SEPARATOR == '/' AND @ ini_get("safe_mode") == FALSE)
    {
        return is_writable($file);
    }
    if (!is_file($file) OR ( $fp = @fopen($file, "r+")) === FALSE)
    {
        return FALSE;
    }

    fclose($fp);
    return TRUE;
}

$sitename = "rtshop";



// 检测目录是否存在
$checkDirs = [
    'thinkphp',
    'vendor',
    'public' . DS . 'assets' . DS . 'libs'
];
//缓存目录
$runtimeDir = APP_PATH . 'runtime';

//错误信息
$errInfo = '';

//数据库配置文件
$dbConfigFile = APP_PATH . 'database.php';

// 锁定的文件
$lockFile = INSTALL_PATH . 'install.lock';
if (is_file($lockFile))
{
    $errInfo = "当前已经安装{$sitename}，如果需要重新安装，请手动移除application/admin/command/Install/install.lock文件";
}
else if (version_compare(PHP_VERSION, '5.5.0', '<'))
{
    $errInfo = "当前版本(" . PHP_VERSION . ")过低，请使用PHP5.5以上版本";
}
else if (!extension_loaded("PDO"))
{
    $errInfo = "当前未开启PDO，无法进行安装";
}
else if (!is_really_writable($dbConfigFile))
{
    $errInfo = "当前权限不足，无法写入配置文件application/database.php";
}
else
{
    $dirArr = [];
    foreach ($checkDirs as $k => $v)
    {
        if (!is_dir(ROOT_PATH . $v))
        {
            $errInfo = '请先下载完整包覆盖后再安装';
            break;
        }
    }
}
// 当前是POST请求
if (!$errInfo && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST')
{
    $err = '';
    $mysqlHostname = isset($_POST['mysqlHost']) ? $_POST['mysqlHost'] : 'localhost';
    $mysqlHostport = 3306;
    $hostArr = explode(':', $mysqlHostname);
    if (count($hostArr) > 1)
    {
        $mysqlHostname = $hostArr[0];
        $mysqlHostport = $hostArr[1];
    }
    $mysqlUsername = isset($_POST['mysqlUsername']) ? $_POST['mysqlUsername'] : 'root';
    $mysqlPassword = isset($_POST['mysqlPassword']) ? $_POST['mysqlPassword'] : '';
    $mysqlDatabase = isset($_POST['mysqlDatabase']) ? $_POST['mysqlDatabase'] : 'qya';
    $mysqlPrefix = isset($_POST['mysqlPrefix']) ? $_POST['mysqlPrefix'] : 'rt_';
    $adminUsername = isset($_POST['adminUsername']) ? $_POST['adminUsername'] : 'admin';
    $adminPassword = isset($_POST['adminPassword']) ? $_POST['adminPassword'] : '123456';
    $adminPasswordConfirmation = isset($_POST['adminPasswordConfirmation']) ? $_POST['adminPasswordConfirmation'] : '123456';
    $adminEmail = isset($_POST['adminEmail']) ? $_POST['adminEmail'] : 'admin@admin.com';

    if ($adminPassword !== $adminPasswordConfirmation)
    {
        echo "两次输入的密码不一致";
        exit;
    }
    else if (!preg_match("/^\w+$/", $adminUsername))
    {
        echo "用户名只能输入字母、数字、下划线";
        exit;
    }
    else if (!preg_match("/^[\S]+$/", $adminPassword))
    {
        echo "密码不能包含空格";
        exit;
    }
    else if (strlen($adminUsername) < 3 || strlen($adminUsername) > 12)
    {
        echo "用户名请输入3~12位字符";
        exit;
    }
    else if (strlen($adminPassword) < 6 || strlen($adminPassword) > 16)
    {

        echo "密码请输入6~16位字符";
        exit;
    }
    try
    {
        //检测能否读取安装文件
        $sql = @file_get_contents(INSTALL_PATH . 'qya.sql');
        if (!$sql)
        {
            throw new Exception("无法读取application/admin/command/Install/qya.sql文件，请检查是否有读权限");
        }
        $sql = str_replace("`rt_", "`{$mysqlPrefix}", $sql);
        $pdo = new PDO("mysql:host={$mysqlHostname};port={$mysqlHostport}", $mysqlUsername, $mysqlPassword, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
        ));

        $pdo->query("CREATE DATABASE IF NOT EXISTS `{$mysqlDatabase}` CHARACTER SET utf8 COLLATE utf8_general_ci;");

        $pdo->query("USE `{$mysqlDatabase}`");

        $pdo->exec($sql);

        $config = @file_get_contents($dbConfigFile);
        $callback = function($matches) use($mysqlHostname, $mysqlHostport, $mysqlUsername, $mysqlPassword, $mysqlDatabase, $mysqlPrefix) {
            $field = ucfirst($matches[1]);
            $replace = ${"mysql{$field}"};
            if ($matches[1] == 'hostport' && $mysqlHostport == 3306)
            {
                $replace = '';
            }
            return "'{$matches[1]}'{$matches[2]}=>{$matches[3]}Env::get('database.{$matches[1]}', '{$replace}'),";
        };
        $config = preg_replace_callback("/'(hostname|database|username|password|hostport|prefix)'(\s+)=>(\s+)Env::get\((.*)\)\,/", $callback, $config);
        //检测能否成功写入数据库配置
        $result = @file_put_contents($dbConfigFile, $config);
        if (!$result)
        {
            throw new Exception("无法写入数据库信息到application/database.php文件，请检查是否有写权限");
        }

        //检测能否成功写入lock文件
        $result = @file_put_contents($lockFile, 1);
        if (!$result)
        {
            throw new Exception("无法写入安装锁定到application/admin/command/Install/install.lock文件，请检查是否有写权限");
        }
        $newSalt = substr(md5(uniqid(true)), 0, 6);
        $newPassword = md5(md5($adminPassword) . $newSalt);
        $pdo->query("UPDATE {$mysqlPrefix}admin SET username = '{$adminUsername}', email = '{$adminEmail}',password = '{$newPassword}', salt = '{$newSalt}' WHERE username = 'admin'");
        echo "success";
    }
    catch (Exception $e)
    {
        $err = $e->getMessage();
    }
    catch (PDOException $e)
    {
        $err = $e->getMessage();
    }
    echo $err;
    exit;
}
?>
<!doctype html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>安装<?php echo $sitename; ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1">
        <meta name="renderer" content="webkit">

        <style>
            body {
                background: #fff;
                margin: 0;
                padding: 0;
                line-height: 1.5;
            }
            body, input, button {
                font-family: 'Open Sans', sans-serif;
                font-size: 16px;
                color: #7E96B3;
            }
            .container {
                max-width: 515px;
                margin: 0 auto;
                padding: 20px;
                text-align: center;
            }
            a {
                color: #18bc9c;
                text-decoration: none;
            }
            a:hover {
                text-decoration: underline;
            }

            h1 {
                margin-top:0;
                margin-bottom: 10px;
            }
            h2 {
                font-size: 28px;
                font-weight: normal;
                color: #3C5675;
                margin-bottom: 0;
            }

            form {
                margin-top: 40px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group .form-field:first-child input {
                border-top-left-radius: 4px;
                border-top-right-radius: 4px;
            }
            .form-group .form-field:last-child input {
                border-bottom-left-radius: 4px;
                border-bottom-right-radius: 4px;
            }
            .form-field input {
                background: #EDF2F7;
                margin: 0 0 1px;
                border: 2px solid transparent;
                transition: background 0.2s, border-color 0.2s, color 0.2s;
                width: 100%;
                padding: 15px 15px 15px 180px;
                box-sizing: border-box;
            }
            .form-field input:focus {
                border-color: #18bc9c;
                background: #fff;
                color: #444;
                outline: none;
            }
            .form-field label {
                float: left;
                width: 160px;
                text-align: right;
                margin-right: -160px;
                position: relative;
                margin-top: 18px;
                font-size: 14px;
                pointer-events: none;
                opacity: 0.7;
            }
            button,.btn {
                background: #3C5675;
                color: #fff;
                border: 0;
                font-weight: bold;
                border-radius: 4px;
                cursor: pointer;
                padding: 15px 30px;
                -webkit-appearance: none;
            }
            button[disabled] {
                opacity: 0.5;
            }

            #error,.error,#success,.success {
                background: #D83E3E;
                color: #fff;
                padding: 15px 20px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            #success {
                background:#3C5675;
            }

            #error a, .error a {
                color:white;
                text-decoration: underline;
            }
        </style>
    </head>

    <body>
        <div class="container">
            <h1>
                <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="159px" height="142px" viewBox="0 0 159 142" enable-background="new 0 0 159 142" xml:space="preserve">  <image id="image0" width="159" height="142" x="0" y="0"
    xlink:href="data:image/png;base64,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" />
</svg>

            </h1>
            <h2>安装 <?php echo $sitename; ?></h2>
            <div>

                <?php echo $sitename; ?>
                <form method="post">
<?php if ($errInfo): ?>
                        <div class="error">
                        <?php echo $errInfo; ?>
                        </div>
                        <?php endif; ?>
                    <div id="error" style="display:none"></div>
                    <div id="success" style="display:none"></div>

                    <div class="form-group">
                        <div class="form-field">
                            <label>MySQL 数据库地址</label>
                            <input type="text" name="mysqlHost" value="localhost" required="">
                        </div>

                        <div class="form-field">
                            <label>MySQL 数据库名</label>
                            <input type="text" name="mysqlDatabase" value="qya" required="">
                        </div>

                        <div class="form-field">
                            <label>MySQL 用户名</label>
                            <input type="text" name="mysqlUsername" value="root" required="">
                        </div>

                        <div class="form-field">
                            <label>MySQL 密码</label>
                            <input type="password" name="mysqlPassword">
                        </div>

                        <div class="form-field">
                            <label>MySQL 数据表前缀</label>
                            <input type="text" name="mysqlPrefix" value="rt_">
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-field">
                            <label>管理者用户名</label>
                            <input name="adminUsername" value="admin" required="" />
                        </div>

                        <div class="form-field">
                            <label>管理者Email</label>
                            <input name="adminEmail" value="admin@admin.com" required="">
                        </div>

                        <div class="form-field">
                            <label>管理者密码</label>
                            <input type="password" name="adminPassword" required="">
                        </div>

                        <div class="form-field">
                            <label>重复密码</label>
                            <input type="password" name="adminPasswordConfirmation" required="">
                        </div>
                    </div>

                    <div class="form-buttons">
                        <button type="submit" <?php echo $errInfo ? 'disabled' : '' ?>>点击安装</button>
                    </div>
                </form>

                <script src="https://cdn.bootcss.com/jquery/2.1.4/jquery.min.js"></script>
                <script>
                    $(function () {
                        $('form :input:first').select();

                        $('form').on('submit', function (e) {
                            e.preventDefault();

                            var $button = $(this).find('button')
                                    .text('安装中...')
                                    .prop('disabled', true);

                            $.post('', $(this).serialize())
                                    .done(function (ret) {
                                        if (ret === 'success') {
                                            $('#error').hide();
                                            $("#success").text("安装成功！开始你的<?php echo $sitename; ?>之旅吧！").show();
                                            $('<a class="btn" href="./">访问首页</a> <a class="btn" href="./index.php/admin/index/login" style="background:#18bc9c">访问后台</a>').insertAfter($button);
                                            $button.remove();
                                        } else {
                                            $('#error').show().text(ret);
                                            $button.prop('disabled', false).text('点击安装');
                                            $("html,body").animate({
                                                scrollTop: 0
                                            }, 500);
                                        }
                                    })
                                    .fail(function (data) {
                                        $('#error').show().text('发生错误:\n\n' + data.responseText);
                                        $button.prop('disabled', false).text('点击安装');
                                        $("html,body").animate({
                                            scrollTop: 0
                                        }, 500);
                                    });

                            return false;
                        });
                    });
                </script>      
            </div>
        </div>
    </body>
</html>