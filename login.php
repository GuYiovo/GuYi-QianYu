<?php
ini_set('display_errors', 0); 
error_reporting(0);

require_once 'config.php';
require_once 'database.php';
session_start();

try { $db = new Database(); } catch (Throwable $e) {
    die("系统维护中，请稍后重试。");
}

if (empty($_SESSION['csrf_token'])) {
    try {
        if (function_exists('random_bytes')) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } 
        else { $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true)); }
    } catch (Exception $e) { $_SESSION['csrf_token'] = md5(uniqid()); }
}
$csrf_token = $_SESSION['csrf_token'];

$is_trusted = false;
try {
    $adminHashFingerprint = md5((string)$db->getAdminHash());
    if (isset($_COOKIE['admin_trust'])) {
        $parts = explode('|', $_COOKIE['admin_trust']);
        if (count($parts) === 2) {
            list($payload, $sign) = $parts;
            if (hash_equals(hash_hmac('sha256', $payload, SYS_SECRET), $sign)) {
                $data = json_decode(base64_decode($payload), true);
                if ($data && isset($data['exp'], $data['ua'], $data['ph']) && 
                    $data['exp'] > time() && 
                    $data['ua'] === md5($_SERVER['HTTP_USER_AGENT']) && 
                    hash_equals($data['ph'], $adminHashFingerprint)) {
                    $is_trusted = true;
                    $_SESSION['admin_logged_in'] = true; 
                    session_regenerate_id(true); 
                    $_SESSION['last_ip'] = $_SERVER['REMOTE_ADDR'];
                }
            }
        }
    }
} catch (Exception $e) { }

if (isset($_SESSION['admin_logged_in'])) { header('Location: cards.php'); exit; }

$sysConf = $db->getSystemSettings();
$conf_site_title = $sysConf['site_title'] ?? 'GuYi Access';
$conf_favicon = !empty($sysConf['favicon']) ? $sysConf['favicon'] : 'https://q1.qlogo.cn/g?b=qq&nk=156440000&s=640';
$conf_avatar = !empty($sysConf['admin_avatar']) ? $sysConf['admin_avatar'] : 'https://q1.qlogo.cn/g?b=qq&nk=156440000&s=640';
$conf_bg_pc = $sysConf['bg_pc'] ?? 'https://www.loliapi.com/acg/pc/';
$conf_bg_mobile = $sysConf['bg_mobile'] ?? 'https://www.loliapi.com/acg/pe/';
$conf_bg_blur = $sysConf['bg_blur'] ?? '1';

$login_error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
         $login_error = "页面已过期，请刷新";
    } else {
        $error = null;
        if (!$is_trusted) {
            $input_captcha = strtoupper($_POST['captcha'] ?? '');
            $sess_captcha = $_SESSION['captcha_code'] ?? 'INVALID';
            unset($_SESSION['captcha_code']);
            if (empty($input_captcha) || $input_captcha !== $sess_captcha) $error = "验证码错误";
        }
        
        if (!$error) {
            $hash = $db->getAdminHash();
            if (!empty($hash) && password_verify($_POST['password'], $hash)) {
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['last_ip'] = $_SERVER['REMOTE_ADDR'];
                
                $cookieData = ['exp' => time() + 86400 * 3, 'ua' => md5($_SERVER['HTTP_USER_AGENT']), 'ph' => md5($hash)];
                $payload = base64_encode(json_encode($cookieData));
                $sign = hash_hmac('sha256', $payload, SYS_SECRET);
                setcookie('admin_trust', "$payload|$sign", time() + 86400 * 3, '/', '', false, true);
                
                header('Location: cards.php'); exit;
            } else {
                $error = "密钥无效";
                usleep(500000);
            }
        }
        $login_error = $error;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
<title>登录 - <?php echo htmlspecialchars($conf_site_title); ?></title>
<meta name="referrer" content="no-referrer" />
<link rel="icon" href="<?php echo htmlspecialchars($conf_favicon); ?>" type="image/png">
<style>
:root{--ay-accent-start:#ff7dc6;--ay-accent-end:#7aa8ff;--ay-text:#f3f6ff;--ay-sub:#b9c3e6;--ay-card:rgba(12,14,28,.55);--ay-stroke:rgba(255,255,255,.18);--ay-input:rgba(255,255,255,.06);--ay-input-h:48px;--ay-radius:20px}html,body{height:100%}*,*::before,*::after{box-sizing:border-box}body.ay-bg{margin:0;color:var(--ay-text);font-family:ui-sans-serif,-apple-system,Segoe UI,Roboto,PingFang SC,Microsoft YaHei,system-ui,Arial;background-image:url('<?php echo htmlspecialchars($conf_bg_pc); ?>');background-size:cover;background-position:center;background-attachment:fixed;overflow-x:hidden}@media(max-width:768px){body.ay-bg{background-image:url('<?php echo htmlspecialchars($conf_bg_mobile); ?>')!important;background-attachment:scroll!important}}.ay-dim{position:fixed;inset:0;pointer-events:none;background:rgba(0,0,0,0.3);backdrop-filter:blur(<?php echo $conf_bg_blur==='1'?'20px':'0px';?>)}.ay-petals{position:fixed;inset:0;pointer-events:none;overflow:hidden}.ay-petals i{position:absolute;width:12px;height:10px;background:linear-gradient(135deg,#ffd1e6,#ff9aca);border-radius:80% 80% 80% 20%/80% 80% 20% 80%;opacity:.5;filter:blur(.2px);animation:ay-fall linear infinite;transform:rotate(20deg)}.ay-petals i:nth-child(3n){width:9px;height:7px;animation-duration:12s}.ay-petals i:nth-child(4n){animation-duration:10s;opacity:.35}.ay-petals i:nth-child(5n){width:14px;height:12px;animation-duration:14s}@keyframes ay-fall{to{transform:translateY(110vh) rotate(360deg)}}.ay-wrap{min-height:100dvh;display:grid;place-items:center;padding:clamp(16px,4vw,32px);perspective:1000px}.ay-card{width:min(480px,92vw);margin-top:14px;background:var(--ay-card);backdrop-filter:blur(20px) saturate(140%);border:1px solid var(--ay-stroke);border-radius:24px;box-shadow:0 18px 60px rgba(5,9,20,.45);position:relative;overflow:hidden;transform-style:preserve-3d;transition:box-shadow .25s ease;will-change:transform}.ay-card:hover{box-shadow:0 24px 80px rgba(5,9,20,.55)}.ay-card::after{content:"";position:absolute;inset:-1px;border-radius:inherit;pointer-events:none;mix-blend-mode:overlay;opacity:0;transition:opacity .3s ease;background:radial-gradient(300px 300px at var(--mx,50%) var(--my,50%),rgba(255,255,255,.15),rgba(255,255,255,0) 60%);z-index:10}.ay-card:hover::after{opacity:1}.ay-card::before{content:"";position:absolute;inset:-1px;border-radius:inherit;padding:1px;background:conic-gradient(from 200deg,var(--ay-accent-start),var(--ay-accent-end),var(--ay-accent-start));-webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);-webkit-mask-composite:xor;mask-composite:exclude;opacity:.7;pointer-events:none}.ay-head{padding:26px 22px 8px;display:grid;place-items:center;row-gap:8px}
.ay-logo{width:64px;height:64px;border-radius:50%;background:url("<?php echo $conf_avatar; ?>") no-repeat center/cover;box-shadow:0 8px 26px rgba(255,154,202,.25)}
.ay-title{margin:4px 0 0;font-weight:900;letter-spacing:.6px;font-size:clamp(18px,2.6vw,22px);color:white}.ay-sub{margin:0 0 6px;color:var(--ay-sub);font-size:12px;text-align:center}.ay-body{padding:16px 22px 22px}.ay-field{position:relative;margin:16px 0 22px}.ay-input{width:100%;height:var(--ay-input-h);padding:12px 14px;border-radius:16px;border:1px solid var(--ay-stroke);background:var(--ay-input);color:var(--ay-text);outline:none;transition:all .18s ease}.ay-input:-webkit-autofill{-webkit-text-fill-color:var(--ay-text)!important;transition:background-color 5000s ease-in-out 0s;box-shadow:inset 0 0 0 1000px rgba(255,255,255,0.06)!important}.ay-input::placeholder{color:transparent}.ay-label{position:absolute;left:14px;top:50%;transform:translateY(-52%);font-size:13px;color:var(--ay-sub);pointer-events:none;transition:all .2s cubic-bezier(0.4,0,0.2,1)}.ay-input:focus{border-color:rgba(255,255,255,.38);box-shadow:0 0 0 3px rgba(255,125,198,.18);background:rgba(255,255,255,.08)}.ay-input:focus+.ay-label,.ay-input:not(:placeholder-shown)+.ay-label{top:-9px;font-size:11px;background:rgba(10,12,24,.95);padding:0 8px;border-radius:999px;color:#e9eaff;border:1px solid rgba(255,255,255,.15);transform:translateY(0)}.ay-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:34px;height:34px;border-radius:10px;border:1px solid transparent;background:transparent;display:grid;place-items:center;cursor:pointer;transition:background .2s}.ay-eye:hover{background:rgba(255,255,255,0.1)}.ay-eye svg{transition:stroke .3s ease}.ay-captcha-img{position:absolute;right:6px;top:50%;transform:translateY(-50%);height:36px;border-radius:10px;cursor:pointer;border:1px solid rgba(255,255,255,0.1);opacity:0.85;transition:opacity .2s}.ay-captcha-img:hover{opacity:1}.ay-btn{width:100%;height:48px;border:none;border-radius:14px;cursor:pointer;color:#ffffff;font-weight:900;letter-spacing:.5px;margin-top:10px;background:linear-gradient(135deg,#ffb6f0,#9ad6ff);box-shadow:0 12px 30px rgba(122,168,255,.35),inset 0 1px 0 rgba(255,255,255,.7);position:relative;overflow:hidden;transition:transform .1s ease,box-shadow .2s ease,filter .2s}.ay-btn:hover{transform:translateY(-2px);box-shadow:0 16px 36px rgba(122,168,255,.45),inset 0 1px 0 rgba(255,255,255,.8)}.ay-btn:active{transform:translateY(1px) scale(0.98);filter:brightness(0.95)}.ay-btn::after{content:"";position:absolute;top:-20%;bottom:-20%;left:-40%;right:-40%;pointer-events:none;background:linear-gradient(90deg,rgba(255,255,255,0) 0%,rgba(255,255,255,.4) 50%,rgba(255,255,255,0) 100%);transform:translateX(-120%) skewX(-20deg);transition:transform .6s ease}.ay-btn:hover::after{transform:translateX(140%) skewX(-20deg)}.ay-foot{margin:12px 0 8px;text-align:center;color:#dfe6ff;font-size:12px;opacity:.7;transition:opacity .2s}.ay-foot:hover{opacity:1}.ay-error{background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;font-size:12px;padding:8px 12px;border-radius:12px;margin-bottom:12px;display:flex;align-items:center;gap:6px}@media(max-width:480px){:root{--ay-input-h:46px}.ay-body{padding:12px 16px 18px}.ay-card{transform:none!important}}
</style>
</head>
<body class="ay-bg">
<div class="ay-dim" aria-hidden="true"></div>
<div class="ay-petals" aria-hidden="true">
    <i style="left:6%;top:-8vh;animation-duration:11s"></i><i style="left:24%;top:-12vh;animation-duration:13s"></i>
    <i style="left:52%;top:-16vh;animation-duration:12s"></i><i style="left:72%;top:-10vh;animation-duration:10s"></i>
    <i style="left:86%;top:-18vh;animation-duration:14s"></i>
</div>
<main class="ay-wrap">
    <section class="ay-card" id="ay-card" role="dialog" aria-labelledby="ay-title" aria-describedby="ay-sub">
        <header class="ay-head">
            <div class="ay-logo" aria-hidden="true"></div>
            <h1 id="ay-title" class="ay-title">欢迎回来，指挥官</h1>
            <p id="ay-sub" class="ay-sub">正在验证您的管理员身份</p>
        </header>
        <div class="ay-body">
            <?php if(isset($login_error) && $login_error): ?><div class="ay-error"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg><?php echo htmlspecialchars($login_error); ?></div><?php endif; ?>
            <form id="ay-form" method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="ay-field">
                    <input id="ay-user" name="password" class="ay-input" type="password" placeholder=" " autocomplete="current-password" required style="padding-right: 44px;">
                    <span class="ay-label">管理员密钥</span>
                    <button type="button" class="ay-eye" id="ay-eye" aria-label="显示密钥"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#cfe1ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <?php if(!$is_trusted): ?>
                <div class="ay-field">
                    <input id="ay-captcha" name="captcha" class="ay-input" type="text" placeholder=" " autocomplete="off" required maxlength="4" style="padding-right: 120px;">
                    <span class="ay-label">验证码</span>
                    <img src="Verifyfile/captcha.php" class="ay-captcha-img" onclick="this.src='Verifyfile/captcha.php?t='+Math.random()" title="点击刷新">
                </div>
                <?php endif; ?>
                <button class="ay-btn" type="submit" id="ay-submit">立即进入</button>
            </form>
            <div class="ay-foot">© <?php echo htmlspecialchars($conf_site_title); ?> System</div>
        </div>
    </section>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('ay-user'),e=document.getElementById('ay-eye');if(e&&p){const i=e.querySelector('svg');e.addEventListener('click',()=>{const x=p.type==='password';p.type=x?'text':'password';i.style.stroke=x?'#ff7dc6':'#cfe1ff'})}});
</script>
</body></html>
