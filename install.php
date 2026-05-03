<?php
error_reporting(0);

$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$msg = '';

$is_mobile = preg_match("/(android|avantgo|blackberry|bolt|boost|cricket|docomo|fone|hiptop|mini|mobi|palm|phone|pie|samsung|scp|wap|windows ce;iemobile|xhtml\\+xml)/i", $_SERVER["HTTP_USER_AGENT"] ?? '');
$bg_url = $is_mobile ? 'https://www.loliapi.com/acg/pe/' : 'https://www.loliapi.com/acg/pc/';

function check_env() {
    $r = [];
    $v = phpversion();
    $r[] = ['name'=>'PHP 版本','need'=>'≥ 7.2','val'=>$v,'ok'=>version_compare($v,'7.2.0','>=')];
    $r[] = ['name'=>'PDO MySQL','need'=>'必需','val'=>extension_loaded('pdo_mysql')?'已支持':'缺失','ok'=>extension_loaded('pdo_mysql')];
    $r[] = ['name'=>'目录权限','need'=>'可写','val'=>(is_writable(__DIR__.'/config.php')||is_writable(__DIR__))?'正常':'受限','ok'=>is_writable(__DIR__.'/config.php')||is_writable(__DIR__)];
    $r[] = ['name'=>'JSON 扩展','need'=>'必需','val'=>function_exists('json_encode')?'已支持':'缺失','ok'=>function_exists('json_encode')];
    return $r;
}

if ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['db_host'] ?? '');
    $name = trim($_POST['db_name'] ?? '');
    $user = trim($_POST['db_user'] ?? '');
    $pass = trim($_POST['db_pass'] ?? '');
    $port = trim($_POST['db_port'] ?? '3306');
    $admin_pwd = $_POST['admin_password'] ?? '';
    $admin_pwd2 = $_POST['admin_password_confirm'] ?? '';

    if (empty($host) || empty($name) || empty($user)) {
        $msg = '请完整填写数据库连接信息'; $step = 2;
    } elseif (mb_strlen($admin_pwd) < 6) {
        $msg = '管理员密码长度不能少于 6 位'; $step = 2;
    } elseif ($admin_pwd !== $admin_pwd2) {
        $msg = '两次输入的管理员密码不一致'; $step = 2;
    } else {
        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $secret = 'SYS_' . md5(uniqid('', true));
            $sh=addslashes($host);$sn=addslashes($name);$su=addslashes($user);$sp=addslashes($pass);$so=addslashes($port);
            $cfg = "<?php\ndefine('DB_INSTALLED_CHECK', true);\nheader(\"X-Frame-Options: SAMEORIGIN\");\nheader(\"X-XSS-Protection: 1; mode=block\");\nheader(\"X-Content-Type-Options: nosniff\");\ndefine('SYS_SECRET', '$secret');\ndefine('DB_HOST', '$sh');\ndefine('DB_NAME', '$sn');\ndefine('DB_USER', '$su');\ndefine('DB_PASS', '$sp');\ndefine('DB_PORT', '$so');\nerror_reporting(0);\nini_set('display_errors', 0);\ndefine('CARD_TYPES', [\n    'hour'=>['name'=>'小时卡','duration'=>3600],\n    'day'=>['name'=>'天卡','duration'=>86400],\n    'week'=>['name'=>'周卡','duration'=>604800],\n    'month'=>['name'=>'月卡','duration'=>2592000],\n    'season'=>['name'=>'季卡','duration'=>7776000],\n    'year'=>['name'=>'年卡','duration'=>31536000],\n]);\n?>";
            if (file_put_contents(__DIR__ . '/config.php', $cfg)) {
                require_once __DIR__ . '/config.php';
                require_once __DIR__ . '/database.php';
                (new Database())->updateAdminPassword($admin_pwd);
                $deleted = @unlink(__FILE__);
                $step = 3;
            } else { $msg = '配置文件写入失败，请检查目录权限'; $step = 2; }
        } catch (PDOException $e) {
            $msg = "数据库连接失败：" . $e->getMessage(); $step = 2;
        }
    }
}

$env = check_env();
$env_ok = !in_array(false, array_column($env, 'ok'));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>系统部署与安装</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* 极致透明的核心参数 */
            --glass-bg: rgba(255, 255, 255, 0.02); /* 极低的底色 */
            --glass-border: rgba(255, 255, 255, 0.08); /* 极细若隐若现的边框 */
            
            --text-main: rgba(255, 255, 255, 0.95);
            --text-dim: rgba(255, 255, 255, 0.5);
            --success: #34d399;
            --error: #f87171;
            --accent: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #000;
            color: var(--text-main);
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* 全屏背景图 */
        .bg-layer {
            position: fixed;
            inset: 0;
            background: url('<?php echo $bg_url; ?>') center/cover no-repeat;
            z-index: -1;
            transform: scale(1.05); /* 缓冲边缘缩放 */
        }
        
        /* 增加微小的暗色渐变，确保浅色背景下图文依然可读 */
        .bg-layer::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at center, rgba(0,0,0,0) 0%, rgba(0,0,0,0.15) 100%);
        }

        /* 极致透明玻璃体 */
        .glass-card {
            width: 420px;
            max-width: 90%;
            background: var(--glass-bg);
            /* 低模糊度 (8px)，背景清晰穿透 */
            backdrop-filter: blur(8px) saturate(120%);
            -webkit-backdrop-filter: blur(8px) saturate(120%);
            border: 1px solid var(--glass-border);
            border-radius: 28px;
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.2), /* 柔和的外阴影 */
                inset 0 1px 0 rgba(255, 255, 255, 0.1); /* 顶部的微光反射 */
            padding: 36px;
            position: relative;
            z-index: 10;
        }

        /* 顶部与步骤条 */
        header { text-align: center; margin-bottom: 28px; }
        .logo { font-size: 22px; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 8px; }
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 14px;
        }
        .dot {
            width: 5px; height: 5px; border-radius: 50%;
            background: rgba(255,255,255,0.2);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .dot.active { background: var(--accent); transform: scale(1.6); box-shadow: 0 0 8px rgba(255,255,255,0.4); }
        .dot.done { background: rgba(255,255,255,0.6); }

        /* 排版 */
        h1 { font-size: 18px; font-weight: 600; text-align: center; margin-bottom: 6px; letter-spacing: 0.5px; }
        .subtitle { font-size: 12px; color: var(--text-dim); text-align: center; margin-bottom: 24px; }

        /* 表单输入区（全透明质感） */
        .form-group { margin-bottom: 14px; position: relative; }
        .input-row { display: flex; gap: 10px; }
        
        input {
            width: 100%;
            background: rgba(255, 255, 255, 0.02); /* 近乎透明 */
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 13px 16px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: all 0.25s ease;
            font-family: inherit;
        }
        input:focus {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.05);
        }
        input::placeholder { color: rgba(255, 255, 255, 0.25); font-size: 13px; }

        /* 新增：小眼睛图标样式 */
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: color 0.3s;
            font-size: 14px;
        }
        .toggle-password:hover { color: rgba(255, 255, 255, 0.8); }
        .has-eye { padding-right: 40px; } /* 防止文字被小眼睛挡住 */

        /* 环境检测列表 */
        .env-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 14px;
            margin-bottom: 8px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            transition: background 0.2s;
        }
        .env-item:hover { background: rgba(255, 255, 255, 0.04); }
        .env-name { font-size: 13px; font-weight: 500; color: rgba(255,255,255,0.8); }
        .env-status { font-size: 12px; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .status-ok { color: var(--success); }
        .status-no { color: var(--error); }

        /* 半透明发光按钮 */
        .btn {
            width: 100%;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 14px;
            padding: 14px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.23, 1, 0.32, 1);
            margin-top: 18px;
        }
        .btn:hover { 
            background: rgba(255, 255, 255, 0.15); 
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px); 
            box-shadow: 0 8px 20px rgba(0,0,0,0.15); 
        }
        .btn:active { transform: translateY(1px); }
        .btn:disabled { background: rgba(255,255,255,0.03); border-color: transparent; color: rgba(255,255,255,0.2); cursor: not-allowed; transform: none; box-shadow: none; }

        /* 警告框 */
        .alert {
            background: rgba(248, 113, 113, 0.08);
            border: 1px solid rgba(248, 113, 113, 0.15);
            color: rgba(248, 113, 113, 0.9);
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 12px;
            text-align: center;
            margin-bottom: 20px;
        }

        /* 完成页样式 */
        .success-icon {
            width: 56px; height: 56px;
            background: rgba(52, 211, 153, 0.1);
            border: 1px solid rgba(52, 211, 153, 0.2);
            color: var(--success);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; margin: 0 auto 18px;
        }
        .credential-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 16px;
            margin-top: 16px;
            font-size: 12px;
            line-height: 1.8;
            color: rgba(255,255,255,0.7);
        }
        .credential-box i { width: 16px; text-align: center; margin-right: 6px; opacity: 0.7; }
        .credential-box code { color: #fff; background: rgba(255,255,255,0.08); padding: 3px 6px; border-radius: 4px; font-family: monospace; letter-spacing: 0.5px; }

        /* 移动端细微调整 */
        @media (max-width: 480px) {
            .glass-card { padding: 30px 20px; width: 92%; border-radius: 24px; }
            .input-row { flex-direction: column; gap: 14px; }
        }
    </style>
</head>
<body>

<div class="bg-layer"></div>

<div class="glass-card">
    <header>
        <div class="logo">GuYi System</div>
        <div class="step-indicator">
            <div class="dot <?php echo $step==1?'active':($step>1?'done':''); ?>"></div>
            <div class="dot <?php echo $step==2?'active':($step>2?'done':''); ?>"></div>
            <div class="dot <?php echo $step==3?'active':''; ?>"></div>
        </div>
    </header>

    <?php if($msg): ?>
        <div class="alert"><i class="fas fa-circle-exclamation"></i> <?php echo $msg; ?></div>
    <?php endif; ?>

    <?php if($step == 1): ?>
        <h1>环境检测</h1>
        <p class="subtitle">检查服务器运行环境是否就绪</p>
        
        <div class="env-list">
            <?php foreach($env as $it): ?>
            <div class="env-item">
                <span class="env-name"><?php echo $it['name']; ?></span>
                <span class="env-status <?php echo $it['ok']?'status-ok':'status-no'; ?>">
                    <?php echo $it['ok'] ? '通过 <i class="fas fa-check"></i>' : '异常 <i class="fas fa-times"></i>'; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <button class="btn" <?php echo $env_ok?'':'disabled'; ?> onclick="location.href='?step=2'">
            下一步
        </button>

    <?php elseif($step == 2): ?>
        <h1>系统配置</h1>
        <p class="subtitle">配置数据库与管理员账号</p>
        
        <form method="POST" action="?step=3">
            <div class="form-group">
                <input type="text" name="db_host" placeholder="数据库主机 (例: 127.0.0.1)" value="<?php echo htmlspecialchars($_POST['db_host']??'127.0.0.1'); ?>">
            </div>
            
            <div class="input-row">
                <div class="form-group" style="flex: 2;">
                    <input type="text" name="db_name" placeholder="数据库名称" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <input type="text" name="db_port" placeholder="端口" value="3306">
                </div>
            </div>

            <div class="input-row">
                <div class="form-group">
                    <input type="text" name="db_user" placeholder="数据库用户名" required>
                </div>
                <div class="form-group">
                    <input type="password" name="db_pass" class="has-eye" placeholder="数据库密码">
                    <i class="fas fa-eye-slash toggle-password"></i>
                </div>
            </div>

            <!-- 超透明分割线 -->
            <div style="height: 1px; background: rgba(255,255,255,0.05); margin: 14px 0 20px;"></div>

            <div class="form-group">
                <input type="password" name="admin_password" class="has-eye" placeholder="设置管理员密码 (至少 6 位)" minlength="6" required>
                <i class="fas fa-eye-slash toggle-password"></i>
            </div>
            <div class="form-group">
                <input type="password" name="admin_password_confirm" class="has-eye" placeholder="再次确认管理员密码" minlength="6" required>
                <i class="fas fa-eye-slash toggle-password"></i>
            </div>

            <button type="submit" class="btn">立即安装</button>
        </form>

    <?php elseif($step == 3): ?>
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h1>安装完成</h1>
        <p class="subtitle">系统已成功部署并就绪</p>
        
        <div class="credential-box">
            <p><i class="fas fa-user"></i> 管理员账号：<code>GuYi</code></p>
            <p><i class="fas fa-lock"></i> 登录密码：<code>********</code> (您刚刚设置的密码)</p>
            <p style="margin-top: 12px; font-size: 11px; opacity: 0.5; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 8px;">
                <i class="fas fa-shield-halved"></i> 安全提示：安装文件 install.php 已自动销毁。
            </p>
        </div>

        <button class="btn" onclick="location.href='cards.php'">进入管理后台</button>
    <?php endif; ?>
</div>

<!-- 新增：显示/隐藏密码逻辑 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleIcons = document.querySelectorAll('.toggle-password');
    
    toggleIcons.forEach(function(icon) {
        icon.addEventListener('click', function() {
            // 获取同级的前一个元素（也就是 input 框）
            const input = this.previousElementSibling;
            
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.remove('fa-eye-slash');
                this.classList.add('fa-eye');
            } else {
                input.type = 'password';
                this.classList.remove('fa-eye');
                this.classList.add('fa-eye-slash');
            }
        });
    });
});
</script>
</body>
</html>
