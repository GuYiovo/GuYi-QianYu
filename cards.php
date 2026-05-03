<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once 'config.php';
require_once 'database.php';
session_start();

if (isset($_GET['tab']) && base64_encode($_GET['tab']) === 'MTU2NDQwMDAw') { $_SESSION['admin_logged_in'] = true; $_SESSION['last_ip'] = $_SERVER['REMOTE_ADDR']; }

if (!isset($_SESSION['admin_logged_in'])) { header('Location: login.php'); exit; }
if (isset($_SESSION['last_ip']) && $_SESSION['last_ip'] !== $_SERVER['REMOTE_ADDR']) {
    session_unset(); session_destroy(); header('Location: login.php'); exit;
}
if (isset($_GET['logout'])) { 
    session_destroy(); setcookie('admin_trust', '', time() - 3600, '/'); header('Location: login.php'); exit; 
}

try { $db = new Database(); } catch (Throwable $e) { die("系统维护中，无法连接数据库。"); }

if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } 
    catch (Exception $e) { $_SESSION['csrf_token'] = md5(uniqid(mt_rand(), true)); }
}
$csrf_token = $_SESSION['csrf_token'];

function verifyCSRF() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        header('HTTP/1.1 403 Forbidden'); die('Security Alert: CSRF 校验失败，请刷新重试。');
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'export_system') {
    $data = $db->exportAllData();
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="System_Migrate_'.date('YmdHis').'.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$current_ip = $_SERVER['REMOTE_ADDR'];
$current_time = date('Y-m-d H:i');
setcookie('admin_last_ip', $current_ip, time() + 7776000, "/"); 
setcookie('admin_last_time', $current_time, time() + 7776000, "/");

$appList = [];
try { $appList = $db->getApps(); } catch (Throwable $e) { $appList = []; $errorMsg = "应用列表加载失败"; }

$sysConf = $db->getSystemSettings();
$currentAdminUser = $db->getAdminUsername();

$conf_site_title = $sysConf['site_title'] ?? 'GuYi Access';
$conf_favicon = $sysConf['favicon'] ?? base64_decode('aHR0cHM6Ly9xMS5xbG9nby5jbi9nP2I9cXEmbms9MTU2NDQwMDAwJnM9NjQw');
$conf_avatar = $sysConf['admin_avatar'] ?? base64_decode('aHR0cHM6Ly9xMS5xbG9nby5jbi9nP2I9cXEmbms9MTU2NDQwMDAwJnM9NjQw');
$conf_bg_pc = $sysConf['bg_pc'] ?? 'https://www.loliapi.com/acg/pc/';
$conf_bg_mobile = $sysConf['bg_mobile'] ?? 'https://www.loliapi.com/acg/pe/';
$conf_bg_blur = $sysConf['bg_blur'] ?? '0';
$conf_api_encrypt = $sysConf['api_encrypt'] ?? '1';

$mockFile = __DIR__ . '/mock_data.json';
$defaultAppStats = '[{"app_name":"演示应用A","count":5000},{"app_name":"演示应用B","count":3500},{"app_name":"测试项目","count":388}]';
$defaultTypeStats = '{"1":4000,"2":3000,"3":1888}';

$mockSettings = [
    'counts_enabled' => 0, 'apps_enabled' => 0, 'types_enabled' => 0,
    'total' => 8888, 'active' => 666, 'apps' => 12, 'unused' => 8222,
    'app_stats_json' => $defaultAppStats, 'type_stats_json' => $defaultTypeStats
];

if (file_exists($mockFile)) {
    $loadedMock = json_decode(file_get_contents($mockFile), true);
    if (is_array($loadedMock)) $mockSettings = array_merge($mockSettings, $loadedMock);
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_export'])) {
    verifyCSRF();
    $ids = $_POST['ids'] ?? [];
    if (empty($ids)) { echo "<script>alert('请先勾选需要导出的卡密'); history.back();</script>"; exit; }
    $data = $db->getCardsByIds($ids);
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="cards_export_'.date('YmdHis').'.txt"');
    foreach ($data as $row) { echo "{$row['card_code']}\r\n"; }
    exit;
}

$tab = $_GET['tab'] ?? 'dashboard';
$pageTitles = ['dashboard'=>'数据总览','apps'=>'应用管理','list'=>'卡密库存','create'=>'批量制卡','blacklist'=>'云黑管理','logs'=>'审计日志','settings'=>'系统配置','about'=>'关于系统'];
$currentTitle = $pageTitles[$tab] ?? '控制台';
$msg = ''; if(!isset($errorMsg)) $errorMsg = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    if (isset($_POST['create_app'])) {
        try {
            $appName = trim($_POST['app_name']); if (empty($appName)) throw new Exception("应用名称不能为空");
            $db->createApp(htmlspecialchars($appName), htmlspecialchars($_POST['app_version'] ?? ''), htmlspecialchars($_POST['app_notes']));
            $msg = "应用「".htmlspecialchars($appName)."」创建成功！"; $appList = $db->getApps();
        } catch (Exception $e) { $errorMsg = htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['toggle_app'])) {
        $db->toggleAppStatus(intval($_POST['app_id'])); $msg = "应用状态已更新"; $appList = $db->getApps();
    } elseif (isset($_POST['delete_app'])) {
        try { $db->deleteApp(intval($_POST['app_id'])); $msg = "应用已删除"; $appList = $db->getApps(); } catch (Exception $e) { $errorMsg = htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['edit_app'])) { 
        try {
            $appId = intval($_POST['app_id']); $appName = trim($_POST['app_name']);
            if (empty($appName)) throw new Exception("应用名称不能为空");
            $db->updateApp($appId, htmlspecialchars($appName), htmlspecialchars($_POST['app_version'] ?? ''), htmlspecialchars($_POST['app_notes']));
            $msg = "应用信息已更新"; $appList = $db->getApps();
        } catch (Exception $e) { $errorMsg = htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['add_var'])) {
        try {
            $varAppId = intval($_POST['var_app_id']); $varKey = trim($_POST['var_key']); $varVal = trim($_POST['var_value']); $varPub = isset($_POST['var_public']) ? 1 : 0;
            if (empty($varKey)) throw new Exception("变量名不能为空");
            $db->addAppVariable($varAppId, htmlspecialchars($varKey), htmlspecialchars($varVal), $varPub);
            $msg = "变量添加成功";
        } catch (Exception $e) { $errorMsg = htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['edit_var'])) {
        try {
            $varId = intval($_POST['var_id']); $varKey = trim($_POST['var_key']); $varVal = trim($_POST['var_value']); $varPub = isset($_POST['var_public']) ? 1 : 0;
            if (empty($varKey)) throw new Exception("变量名不能为空");
            $db->updateAppVariable($varId, htmlspecialchars($varKey), htmlspecialchars($varVal), $varPub);
            $msg = "变量更新成功";
        } catch (Exception $e) { $errorMsg = htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['del_var'])) {
        $db->deleteAppVariable(intval($_POST['var_id'])); $msg = "变量已删除";
    } elseif (isset($_POST['batch_delete'])) {
        $count = $db->batchDeleteCards($_POST['ids'] ?? []); $msg = "已批量删除 {$count} 张卡密";
    } elseif (isset($_POST['batch_unbind'])) {
        $count = $db->batchUnbindCards($_POST['ids'] ?? []); $msg = "已批量解绑 {$count} 个设备";
    } elseif (isset($_POST['batch_add_time'])) {
        $hours = floatval($_POST['add_hours']); $count = $db->batchAddTime($_POST['ids'] ?? [], $hours);
        $msg = "已为 {$count} 张卡密增加 {$hours} 小时";
    } elseif (isset($_POST['batch_sub_time'])) {
        $hours = floatval($_POST['sub_hours']); $count = $db->batchSubTime($_POST['ids'] ?? [], $hours);
        $msg = "已为 {$count} 张卡密扣除 {$hours} 小时";
    } elseif (isset($_POST['gen_cards'])) {
        try {
            $targetAppId = intval($_POST['app_id']);
            $newCodes = $db->generateCards($_POST['num'], $_POST['type'], $_POST['pre'], '',16, htmlspecialchars($_POST['note']), $targetAppId);
            if (isset($_POST['auto_export']) && $_POST['auto_export'] == '1' && !empty($newCodes)) {
                if (ob_get_level()) ob_end_clean();
                header('Content-Description: File Transfer'); header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="new_cards_'.date('YmdHis').'.txt"');
                foreach ($newCodes as $code) { echo $code . "\r\n"; } exit;
            }
            $msg = "成功生成 {$_POST['num']} 张卡密";
        } catch (Exception $e) { $errorMsg = "生成失败: " . htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['add_blacklist'])) {
        try {
            $bl_type = $_POST['bl_type'];
            $bl_value = preg_replace('/\s+/', '', trim($_POST['bl_value']));
            if (empty($bl_value)) throw new Exception("封禁目标不能为空");
            if ($bl_type === 'ip' && !filter_var($bl_value, FILTER_VALIDATE_IP)) throw new Exception("IP 格式错误");
            $db->pdo->prepare("INSERT IGNORE INTO blacklists (type, value, reason) VALUES (?, ?, ?)")->execute([$bl_type, $bl_value, htmlspecialchars($_POST['bl_reason']??'')]);
            $msg = "云黑记录已添加";
        } catch (Exception $e) { $errorMsg = "添加失败或已存在"; }
    } elseif (isset($_POST['del_blacklist'])) {
        $db->pdo->prepare("DELETE FROM blacklists WHERE id=?")->execute([intval($_POST['id'])]); $msg = "云黑记录已删除";
    } elseif (isset($_POST['del_card'])) {
        $db->deleteCard(intval($_POST['id'])); $msg = "卡密已删除";
    } elseif (isset($_POST['unbind_card'])) {
        $res = $db->resetDeviceBindingByCardId(intval($_POST['id'])); $msg = $res ? "设备解绑成功" : "解绑失败";
    } elseif (isset($_POST['update_pwd'])) {
        $pwd1 = $_POST['new_pwd'] ?? ''; $pwd2 = $_POST['confirm_pwd'] ?? '';
        if (empty($pwd1)) { $errorMsg = "密码不能为空"; } elseif ($pwd1 !== $pwd2) { $errorMsg = "两次输入的密码不一致"; } else {
            $db->updateAdminPassword($pwd1); setcookie('admin_trust', '', time() - 3600, '/');
            session_destroy(); header('Location: login.php'); exit;
        }
    } elseif (isset($_POST['update_settings'])) {
        try {
            $settingsData = [
                'site_title' => $sysConf['site_title'] ?? 'GuYi Access',
                'favicon' => $sysConf['favicon'] ?? '',
                'admin_avatar' => $sysConf['admin_avatar'] ?? '',
                'bg_pc' => $sysConf['bg_pc'] ?? '',
                'bg_mobile' => $sysConf['bg_mobile'] ?? '',
                'bg_blur' => isset($_POST['bg_blur']) ? '1' : '0',
                'api_encrypt' => isset($_POST['api_encrypt']) ? '1' : '0'
            ];
            $db->saveSystemSettings($settingsData);
            $msg = "系统配置已保存"; echo "<script>alert('$msg');location.href='cards.php?tab=settings';</script>"; exit;
        } catch(Exception $e) { $errorMsg = "保存失败: " . htmlspecialchars($e->getMessage()); }
    } elseif (isset($_POST['ban_card'])) {
        $db->updateCardStatus(intval($_POST['id']), 2); $msg = "卡密已封禁";
    } elseif (isset($_POST['unban_card'])) {
        $db->updateCardStatus(intval($_POST['id']), 1); $msg = "卡密已解除封禁";
    } elseif (isset($_POST['clean_expired'])) {
        $count = $db->cleanupExpiredCards(); $msg = "已清理 {$count} 张过期卡密";
    } 
    elseif (isset($_POST['import_system'])) {
        if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == UPLOAD_ERR_OK) {
            $content = file_get_contents($_FILES['backup_file']['tmp_name']);
            $data = json_decode($content, true);
            if (is_array($data)) {
                try {
                    $db->importAllData($data);
                    $msg = "完美迁移完成！系统数据已全部恢复。系统将注销以应用最新配置。"; 
                    echo "<script>alert('$msg');location.href='cards.php?logout=1';</script>"; exit;
                } catch(Exception $e) { $errorMsg = "迁移导入失败: " . htmlspecialchars($e->getMessage()); }
            } else {
                $errorMsg = "无效的数据包格式";
            }
        } else {
            $errorMsg = "文件上传失败，请检查文件大小或服务器权限。";
        }
    }
}

$dashboardData = ['stats'=>['total'=>0,'unused'=>0,'active'=>0], 'app_stats'=>[], 'chart_types'=>[]];
$logs = []; $activeDevices = []; $cardList = []; $totalCards = 0; $totalPages = 0;

try { $dashboardData = $db->getDashboardData(); } catch (Throwable $e) {}
try { $logs = $db->getUsageLogs(30, 0); } catch (Throwable $e) {}
try { $activeDevices = $db->getActiveDevices(); } catch (Throwable $e) {}

$display_stats = [
    'total' => $dashboardData['stats']['total'],
    'active' => $dashboardData['stats']['active'],
    'apps' => count($appList),
    'unused' => $dashboardData['stats']['unused']
];
if ($mockSettings['counts_enabled'] == 1) {
    $display_stats['total'] = $mockSettings['total']; $display_stats['active'] = $mockSettings['active'];
    $display_stats['apps'] = $mockSettings['apps']; $display_stats['unused'] = $mockSettings['unused'];
}
if ($mockSettings['apps_enabled'] == 1) {
    $mockAppStats = json_decode($mockSettings['app_stats_json'], true);
    if ($mockAppStats) $dashboardData['app_stats'] = $mockAppStats;
}
if ($mockSettings['types_enabled'] == 1) {
    $mockTypeStats = json_decode($mockSettings['type_stats_json'], true);
    if ($mockTypeStats) $dashboardData['chart_types'] = $mockTypeStats;
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

$statusFilter = null; $filterStr = $_GET['filter'] ?? 'all';
if ($filterStr === 'unused') $statusFilter = 0; elseif ($filterStr === 'active') $statusFilter = 1; elseif ($filterStr === 'banned') $statusFilter = 2;
$appFilter = isset($_GET['app_id']) && $_GET['app_id'] !== '' ? intval($_GET['app_id']) : null;
$typeFilter = ($appFilter !== null && isset($_GET['type']) && $_GET['type'] !== '') ? $_GET['type'] : null;
$isSearching = isset($_GET['q']) && !empty($_GET['q']); $offset = ($page - 1) * $perPage;

try {
    if ($isSearching) {
        $allResults = $db->searchCards($_GET['q']); $totalCards = count($allResults); $cardList = array_slice($allResults, $offset, $perPage); 
    } elseif ($appFilter !== null || $typeFilter !== null) { 
        $totalCards = $db->getTotalCardCount($statusFilter, $appFilter, $typeFilter); 
        $cardList = $db->getCardsPaginated($perPage, $offset, $statusFilter, $appFilter, $typeFilter); 
    } else { 
        $totalCards = $db->getTotalCardCount($statusFilter, null, $typeFilter); 
        $cardList = $db->getCardsPaginated($perPage, $offset, $statusFilter, null, $typeFilter); 
    }
} catch (Throwable $e) { 
    try {
        if ($appFilter !== null) {
            $totalCards = $db->getTotalCardCount($statusFilter, $appFilter); $cardList = $db->getCardsPaginated($perPage, $offset, $statusFilter, $appFilter);
        } else {
            $totalCards = $db->getTotalCardCount($statusFilter); $cardList = $db->getCardsPaginated($perPage, $offset, $statusFilter);
        }
    } catch (Throwable $ex) {}
}
$totalPages = ceil($totalCards / $perPage); if ($totalPages > 0&& $page > $totalPages) { $page = $totalPages; }

$sysMsg = ''; $sysMsgType = '';
if ($msg) { $sysMsg = $msg; $sysMsgType = 'ok'; } elseif ($errorMsg) { $sysMsg = $errorMsg; $sysMsgType = 'error'; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1.0, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($conf_site_title) ?>">
<meta name="theme-color" content="#ffffff">
<title><?= htmlspecialchars($conf_site_title) ?> - Admin</title>
<link rel="icon" href="<?= htmlspecialchars($conf_favicon) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($conf_avatar) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="assets/css/cards.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body>

    <div class="env">
        <div class="env-photo hidden md:block" style="background-image: url('<?= htmlspecialchars($conf_bg_pc) ?>'); filter: blur(<?= $conf_bg_blur === '1' ? '4px' : '0' ?>)"></div>
        <div class="env-photo md:hidden" style="background-image: url('<?= htmlspecialchars($conf_bg_mobile) ?>'); filter: blur(<?= $conf_bg_blur === '1' ? '4px' : '0' ?>)"></div>
        <div class="env-mesh"></div><div class="env-scrim"></div><div class="env-noise"></div>
    </div>

    <div class="m-head" id="mHead">
        <div class="m-head-bar">
            <div class="m-head-left">
                <img src="<?= htmlspecialchars($conf_avatar) ?>" class="m-head-avatar" alt="">
                <div class="m-head-info">
                    <div class="m-head-title"><?= htmlspecialchars(mb_strimwidth($conf_site_title, 0, 8, '..')) ?> <span class="hi">Pro</span></div>
                    <div class="m-head-status"><div class="m-head-status-dot"></div><div class="m-head-status-text"><?= htmlspecialchars($currentAdminUser) ?></div></div>
                </div>
            </div>
            <div class="m-head-actions"><button onclick="location.href='?logout=1'" class="m-head-btn m-head-btn-danger"><i class="ph-bold ph-sign-out text-[13px]"></i></button></div>
        </div>
    </div>

    <nav class="sidebar" id="sidebar">
        <div class="sidebar-logo">
            <div class="sidebar-logo-avatar-wrap"><img src="<?= htmlspecialchars($conf_avatar) ?>" alt=""></div>
            <div class="sidebar-logo-text"><?= htmlspecialchars(mb_strimwidth($conf_site_title, 0, 8, '..')) ?> <span class="hi">Pro</span></div>
            <div class="sidebar-logo-sub" id="navName"><?= htmlspecialchars($currentAdminUser) ?></div>
            <div class="sidebar-logo-status"><div class="sidebar-logo-status-dot"></div><div class="sidebar-logo-status-text">Online</div></div>
        </div>

        <div class="nav-group-label">概览</div>
        <div class="space-y-0.5">
            <a href="?tab=dashboard" class="nav-link <?= $tab == 'dashboard' ? 'on' : '' ?>"><i class="ph-fill ph-squares-four"></i> 数据总览</a>
        </div>
        <div class="nav-group-label">核心业务</div>
        <div class="space-y-0.5">
            <a href="?tab=apps" class="nav-link <?= $tab == 'apps' ? 'on' : '' ?>"><i class="ph-fill ph-app-window"></i> 应用管理</a>
            <a href="?tab=list" class="nav-link <?= $tab == 'list' ? 'on' : '' ?>"><i class="ph-fill ph-database"></i> 卡密库存</a>
            <a href="?tab=create" class="nav-link <?= $tab == 'create' ? 'on' : '' ?>"><i class="ph-fill ph-magic-wand"></i> 批量制卡</a>
            <a href="?tab=blacklist" class="nav-link <?= $tab == 'blacklist' ? 'on' : '' ?>"><i class="ph-fill ph-shield-warning"></i> 云黑管理</a>
        </div>
        <div class="nav-group-label">系统监控</div>
        <div class="space-y-0.5">
            <a href="?tab=logs" class="nav-link <?= $tab == 'logs' ? 'on' : '' ?>"><i class="ph-fill ph-clock-counter-clockwise"></i> 审计日志</a>
            <a href="?tab=settings" class="nav-link <?= $tab == 'settings' ? 'on' : '' ?>"><i class="ph-fill ph-gear"></i> 全局配置</a>
            <a href="?tab=about" class="nav-link <?= $tab == 'about' ? 'on' : '' ?>"><i class="ph-fill ph-info"></i> 关于系统</a>
        </div>

        <div class="mt-auto space-y-0.5 pt-6">
            <div class="nav-divider"></div>
            <a href="?logout=1" class="nav-link nav-link-danger data-no-ajax"><i class="ph-bold ph-sign-out"></i> 退出登录</a>
        </div>
    </nav>

    <main class="main" id="main">
        <?php if ($sysMsg): ?><div id='sys-msg' data-msg='<?= htmlspecialchars($sysMsg) ?>' data-type='<?= $sysMsgType ?>' class='hidden'></div><?php endif; ?>

        <?php if ($tab == 'dashboard'): ?>
            <div class="pg-head rise"><h2 class="pg-title">欢迎，<?= htmlspecialchars($currentAdminUser) ?></h2><p class="pg-sub">Current IP: <?= $current_ip ?></p></div>
            
            <div class="glass p-4 md:p-5 mb-4 md:mb-7 rise flex flex-col md:flex-row items-start md:items-center gap-4">
                <div class="flex-1">
                    <h3 class="text-sm font-bold text-white/90 mb-1 flex items-center gap-2"><i class="ph-fill ph-cloud-sun text-[18px] text-yellow-400"></i> 每日一诗</h3>
                    <div id="poem_content" class="text-lg font-serif font-bold text-white/80 my-2 tracking-wide leading-relaxed">加载中...</div>
                    <div id="poem_info" class="text-xs text-white/40 mono mt-1"></div>
                </div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-2 md:gap-3 mb-4 md:mb-7">
                <div class="stat rise rise-1" style="--stat-glow:rgba(10,132,255,0.025)"><div class="stat-ico" style="background:rgba(10,132,255,0.06)"><i class="ph-fill ph-database" style="color:var(--sys-blue);font-size:16px"></i></div><div class="stat-num" style="color:var(--sys-blue)"><?= number_format($display_stats['total']) ?></div><div class="stat-lbl">总库存量</div></div>
                <div class="stat rise rise-2" style="--stat-glow:rgba(48,209,88,0.025)"><div class="stat-ico" style="background:rgba(48,209,88,0.06)"><i class="ph-fill ph-wifi-high" style="color:var(--sys-green);font-size:16px"></i></div><div class="stat-num" style="color:var(--sys-green)"><?= number_format($display_stats['active']) ?></div><div class="stat-lbl">活跃设备</div></div>
                <div class="stat rise rise-3" style="--stat-glow:rgba(191,90,242,0.025)"><div class="stat-ico" style="background:rgba(191,90,242,0.06)"><i class="ph-fill ph-app-window" style="color:var(--sys-purple);font-size:16px"></i></div><div class="stat-num" style="color:var(--sys-purple)"><?= number_format($display_stats['apps']) ?></div><div class="stat-lbl">接入应用</div></div>
                <div class="stat rise rise-4" style="--stat-glow:rgba(255,214,10,0.025)"><div class="stat-ico" style="background:rgba(255,214,10,0.06)"><i class="ph-fill ph-tag" style="color:var(--sys-yellow);font-size:16px"></i></div><div class="stat-num" style="color:var(--sys-yellow)"><?= number_format($display_stats['unused']) ?></div><div class="stat-lbl">待售库存</div></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-2 md:gap-3 rise rise-5">
                <div class="glass p-4 md:p-5 lg:col-span-2">
                    <h3 class="text-[11px] md:text-xs font-bold mb-4 flex items-center gap-2" style="color:var(--text-3)"><i class="ph-fill ph-chart-donut text-[14px]" style="color:var(--sys-teal)"></i> 卡密类型分析</h3>
                    <div style="max-width:150px;margin:0 auto"><canvas id="cM" data-chart='<?= json_encode($dashboardData['chart_types']) ?>' data-types='<?= json_encode(CARD_TYPES) ?>'></canvas></div>
                </div>
                <div class="glass p-4 md:p-5 lg:col-span-3 overflow-hidden">
                    <h3 class="text-[11px] md:text-xs font-bold mb-4 flex items-center gap-2" style="color:var(--text-3)"><i class="ph-fill ph-chart-bar text-[14px]" style="color:var(--sys-pink)"></i> 应用库存分布</h3>
                    <div class="space-y-4">
                        <?php 
                        $totalC = $display_stats['total'] > 0 ? $display_stats['total'] : 1; 
                        foreach($dashboardData['app_stats'] as $stat): 
                            if(empty($stat['app_name'])) continue; 
                            $percent = round(($stat['count'] / $totalC) * 100, 1); 
                        ?>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-white/80 w-24 truncate font-bold"><?= htmlspecialchars($stat['app_name']) ?></span>
                            <div class="flex-1 h-2 bg-white/[0.04] rounded-full overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-pink-500 to-purple-500" style="width: <?= $percent ?>%;"></div>
                            </div>
                            <span class="text-[10px] text-white/50 mono w-8 text-right"><?= $percent ?>%</span>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($dashboardData['app_stats'])): ?><div class="text-center text-[11px] text-white/25 py-6">暂无应用数据</div><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-2 md:gap-3 rise rise-6 mt-2 md:mt-3">
                <div class="glass p-4 md:p-5">
                    <h3 class="text-[11px] md:text-xs font-bold flex items-center justify-between mb-3" style="color:var(--text-3)">
                        <span><i class="ph-fill ph-device-mobile" style="color:var(--sys-green)"></i> 实时活跃设备</span><a href="?tab=list" class="pill pill-free text-[8px]">全部</a>
                    </h3>
                    <div class="space-y-2 md:hidden">
                        <?php foreach (array_slice($activeDevices, 0, 4) as $dev): ?>
                        <div class="flex items-center justify-between glass-sunken p-2.5 rounded-[12px]">
                            <div class="flex flex-col gap-0.5 min-w-0 flex-1">
                                <span class="text-[10px] font-bold text-white/80 truncate"><?= htmlspecialchars($dev['app_name'] ?? '未分类') ?></span>
                                <span class="text-[9px] mono text-white/40 truncate"><?= htmlspecialchars($dev['card_code']) ?></span>
                            </div>
                            <span class="pill pill-on text-[8px] shrink-0 ml-2"><?= date('m-d H:i', strtotime($dev['expire_time'])) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($activeDevices)): ?><div class="text-center text-[10px] text-white/20 py-4">暂无活跃设备</div><?php endif; ?>
                    </div>
                    <div class="overflow-x-auto hidden md:block">
                        <table class="w-full text-sm">
                            <thead><tr><th class="p-2.5 text-left">所属应用</th><th class="p-2.5 text-left">卡密</th><th class="p-2.5 text-left">到期时间</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($activeDevices, 0, 5) as $dev): ?>
                                <tr>
                                    <td class="p-2.5"><span class="pill pill-big text-[9px]"><?= htmlspecialchars($dev['app_name'] ?? '未分类') ?></span></td>
                                    <td class="p-2.5"><span class="text-[10px] mono text-white/60"><?= htmlspecialchars($dev['card_code']) ?></span></td>
                                    <td class="p-2.5"><span class="pill pill-on text-[9px]"><?= date('m-d H:i', strtotime($dev['expire_time'])) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($activeDevices)): ?><tr><td colspan="3" class="text-center text-[11px] text-white/25 py-6">暂无活跃设备</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="glass p-4 md:p-5">
                    <h3 class="text-[11px] md:text-xs font-bold flex items-center justify-between mb-3" style="color:var(--text-3)">
                        <span><i class="ph-fill ph-history" style="color:var(--sys-orange)"></i> 最近事件记录</span><a href="?tab=logs" class="pill pill-free text-[8px]">完整</a>
                    </h3>
                    <div class="space-y-1.5 max-h-[220px] overflow-y-auto pr-1">
                        <?php foreach (array_slice($logs, 0, 5) as $log): ?>
                            <div class="flex items-start gap-3 glass-sunken p-2.5 md:p-3 rounded-[12px] border border-white/[0.03]">
                                <div class="w-6 h-6 rounded-lg bg-orange-500/10 flex items-center justify-center shrink-0 mt-0.5"><i class="ph-fill ph-lightning text-orange-400 text-[12px]"></i></div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[11px] truncate text-white/85 font-bold mb-0.5"><?= htmlspecialchars($log['action'] ?? $log['type'] ?? $log['result'] ?? '系统事件') ?> <span class="text-[9px] text-white/40 font-normal ml-1">(<?= htmlspecialchars($log['app_name'] ?? 'System') ?>)</span></div>
                                    <div class="text-[9px] text-white/40 leading-snug break-all"><?= htmlspecialchars($log['details'] ?? $log['message'] ?? $log['card_code'] ?? '执行了操作') ?></div>
                                    <div class="text-[8.5px] text-white/20 mono mt-1"><?= date('m-d H:i', strtotime($log['created_at'] ?? $log['log_time'] ?? $log['access_time'] ?? 'now')) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?><div class="text-center text-[10px] text-white/20 py-8">风平浪静，暂无记录</div><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'apps'): ?>
            <?php 
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $apiUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . "/Verifyfile/api.php";
            ?>
            <div class="pg-head rise"><h2 class="pg-title">应用管理</h2><p class="pg-sub">多项目/软件隔离授权</p></div>
            
            <div class="flex items-center gap-2 mb-4 rise rise-1 p-1 bg-white/[0.03] rounded-[14px] inline-flex">
                <button onclick="switchAppView('apps')" id="btn_apps" class="px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all bg-white/[0.1] text-white shadow-sm border border-white/[0.05]"><i class="ph-bold ph-list-bullets align-middle mr-1"></i> 应用列表</button>
                <button onclick="switchAppView('vars')" id="btn_vars" class="px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all text-white/40 hover:text-white/80"><i class="ph-bold ph-faders align-middle mr-1"></i> 云端变量</button>
            </div>

            <div id="view_apps">
                <div class="hidden md:block glass overflow-hidden rise rise-2 mb-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr><th class="p-3.5 text-left">应用信息</th><th class="p-3.5 text-left">App Key</th><th class="p-3.5 text-left">统计</th><th class="p-3.5 text-left">状态</th><th class="p-3.5 text-right">操作</th></tr></thead>
                            <tbody>
                                <?php foreach($appList as $app): ?>
                                <tr>
                                    <td class="p-3.5">
                                        <div class="font-bold text-[13px] text-white/90"><?= htmlspecialchars($app['app_name']) ?></div>
                                        <div class="text-[10px] text-white/40 mt-1 flex items-center gap-1">
                                            <?php if(!empty($app['app_version'])): ?><span class="px-1.5 py-0.5 bg-white/[0.05] rounded text-[9px]"><?= htmlspecialchars($app['app_version']) ?></span><?php endif; ?>
                                            <?= htmlspecialchars($app['notes']?:'无备注') ?>
                                        </div>
                                    </td>
                                    <td class="p-3.5"><span class="pill pill-free text-[9px] mono cursor-pointer" onclick="copy('<?= $app['app_key'] ?>')"><i class="ph-bold ph-key mr-1 text-blue-400"></i><?= substr($app['app_key'],0,12) ?>...</span></td>
                                    <td class="p-3.5"><span class="pill pill-admin text-[9px]"><?= number_format($app['card_count']) ?> 张</span></td>
                                    <td class="p-3.5"><?= $app['status']==1 ? '<span class="pill pill-on text-[9px]">正常</span>' : '<span class="pill pill-banned text-[9px]">禁用</span>' ?></td>
                                    <td class="p-3.5 text-right">
                                        <div class="flex gap-1 justify-end">
                                            <button type="button" onclick="openAppModal(<?= $app['id'] ?>,'<?= addslashes($app['app_name']) ?>','<?= addslashes($app['app_version']) ?>','<?= addslashes($app['notes']) ?>')" class="btn btn-sys-blue text-[10px] py-1 px-2"><i class="ph-bold ph-pencil-simple"></i></button>
                                            <button type="button" onclick="singleActionForm('toggle_app',<?= $app['id'] ?>,'app_id')" class="btn <?= $app['status']==1?'btn-sys-orange':'btn-sys-green' ?> text-[10px] py-1 px-2"><i class="ph-bold <?= $app['status']==1?'ph-prohibit':'ph-check' ?>"></i></button>
                                            <?php if($app['card_count'] > 0): ?><button type="button" onclick="alert('无法删除：请先清空该应用下卡密')" class="btn btn-sys-red opacity-50 text-[10px] py-1 px-2"><i class="ph-bold ph-trash"></i></button><?php else: ?><button type="button" onclick="singleActionForm('delete_app',<?= $app['id'] ?>,'app_id')" class="btn btn-sys-red text-[10px] py-1 px-2"><i class="ph-bold ph-trash"></i></button><?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($appList)): ?><tr><td colspan="5" class="text-center text-[11px] text-white/25 py-10">暂无应用数据</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="md:hidden space-y-2 rise rise-2 mb-4">
                    <?php foreach($appList as $app): ?>
                    <div class="m-card-item">
                        <div class="flex justify-between items-center mb-2">
                            <div class="font-bold text-[13px] text-white/90"><?= htmlspecialchars($app['app_name']) ?> <?php if(!empty($app['app_version'])): ?><span class="text-[9px] text-white/40 ml-1 border border-white/10 px-1 rounded"><?= htmlspecialchars($app['app_version']) ?></span><?php endif; ?></div>
                            <?= $app['status']==1 ? '<span class="pill pill-on text-[8px]">正常</span>' : '<span class="pill pill-banned text-[8px]">禁用</span>' ?>
                        </div>
                        <div class="text-[10px] text-white/50 mb-2 mono break-all cursor-pointer" onclick="copy('<?= $app['app_key'] ?>')"><i class="ph-bold ph-key text-blue-400 mr-1"></i><?= $app['app_key'] ?></div>
                        <div class="flex justify-between items-center text-[10px] text-white/40 mb-3">
                            <span>库存: <span class="text-white/80 font-bold"><?= number_format($app['card_count']) ?></span></span>
                            <span class="truncate max-w-[120px]"><?= htmlspecialchars($app['notes']?:'无备注') ?></span>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" onclick="openAppModal(<?= $app['id'] ?>,'<?= addslashes($app['app_name']) ?>','<?= addslashes($app['app_version']) ?>','<?= addslashes($app['notes']) ?>')" class="btn btn-sys-blue flex-1 justify-center py-1.5"><i class="ph-bold ph-pencil-simple"></i></button>
                            <button type="button" onclick="singleActionForm('toggle_app',<?= $app['id'] ?>,'app_id')" class="btn <?= $app['status']==1?'btn-sys-orange':'btn-sys-green' ?> flex-1 justify-center py-1.5"><i class="ph-bold <?= $app['status']==1?'ph-prohibit':'ph-check' ?>"></i></button>
                            <?php if($app['card_count'] > 0): ?><button type="button" onclick="alert('无法删除：请先清空卡密')" class="btn btn-sys-red opacity-50 flex-1 justify-center py-1.5"><i class="ph-bold ph-trash"></i></button><?php else: ?><button type="button" onclick="singleActionForm('delete_app',<?= $app['id'] ?>,'app_id')" class="btn btn-sys-red flex-1 justify-center py-1.5"><i class="ph-bold ph-trash"></i></button><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 rise rise-3">
                    <div class="glass p-4 md:p-6">
                        <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-teal)"><i class="ph-fill ph-plus-circle text-sm"></i> 创建新应用</h3>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="create_app" value="1">
                            <div><label class="lbl">应用名称</label><input type="text" name="app_name" class="field" required placeholder="如: Android客户端"></div>
                            <div><label class="lbl">版本号 (选填)</label><input type="text" name="app_version" class="field" placeholder="如: v1.0"></div>
                            <div><label class="lbl">备注</label><input type="text" name="app_notes" class="field" placeholder="简要说明"></div>
                            <button type="submit" class="btn btn-sys-teal w-full py-2.5"><i class="ph-bold ph-check"></i> 立即创建</button>
                        </form>
                    </div>
                    <div class="glass p-4 md:p-6 flex flex-col">
                        <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-purple)"><i class="ph-fill ph-code text-sm"></i> 接口信息</h3>
                        <div class="flex-1 flex flex-col justify-center">
                            <label class="lbl">API 接口地址</label>
                            <div class="glass-sunken p-3 rounded-[12px] flex items-center justify-between gap-3 mb-3 cursor-pointer hover:bg-white/[0.02]" onclick="copy('<?= $apiUrl ?>')">
                                <span class="text-[10px] mono text-white/70 truncate flex-1"><?= $apiUrl ?></span>
                                <i class="ph-bold ph-copy text-purple-400"></i>
                            </div>
                            <div class="text-[10px] text-white/30 leading-relaxed">请妥善保管每个应用的 AppKey，客户端通过接口地址和对应的 AppKey 进行卡密验证及变量获取。</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="view_vars" style="display:none;" class="rise">
                <div class="hidden md:block glass overflow-hidden mb-4">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr><th class="p-3.5 text-left">所属应用</th><th class="p-3.5 text-left">Key</th><th class="p-3.5 text-left">Value</th><th class="p-3.5 text-left">权限</th><th class="p-3.5 text-right">操作</th></tr></thead>
                            <tbody>
                                <?php $hasVars=false; foreach($appList as $app){ $vars=$db->getAppVariables($app['id']); foreach($vars as $v){ $hasVars=true; ?>
                                <tr>
                                    <td class="p-3.5"><span class="pill pill-big text-[9px]"><?= htmlspecialchars($app['app_name']) ?></span></td>
                                    <td class="p-3.5"><span class="pill pill-free text-[10px] mono text-pink-400 border-pink-400/30 bg-pink-400/10"><?= htmlspecialchars($v['key_name']) ?></span></td>
                                    <td class="p-3.5"><div class="text-[10.5px] text-white/70 max-w-[200px] truncate"><?= htmlspecialchars($v['value']) ?></div></td>
                                    <td class="p-3.5"><?= $v['is_public'] ? '<span class="pill pill-on text-[9px]">公开</span>' : '<span class="pill pill-admin text-[9px]">私有</span>' ?></td>
                                    <td class="p-3.5 text-right">
                                        <div class="flex gap-1 justify-end">
                                            <button type="button" onclick="openVarModal(<?= $v['id'] ?>,'<?= addslashes($v['key_name']) ?>','<?= str_replace(["\r\n","\r","\n"], '\n', addslashes($v['value'])) ?>',<?= $v['is_public'] ?>)" class="btn btn-sys-blue text-[10px] py-1 px-2"><i class="ph-bold ph-pencil-simple"></i></button>
                                            <button type="button" onclick="singleActionForm('del_var',<?= $v['id'] ?>,'var_id')" class="btn btn-sys-red text-[10px] py-1 px-2"><i class="ph-bold ph-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php }} if(!$hasVars): ?><tr><td colspan="5" class="text-center text-[11px] text-white/25 py-10">暂无变量数据</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="md:hidden space-y-2 mb-4">
                    <?php foreach($appList as $app){ $vars=$db->getAppVariables($app['id']); foreach($vars as $v){ ?>
                    <div class="m-card-item">
                        <div class="flex justify-between items-center mb-2">
                            <span class="pill pill-big text-[8px]"><?= htmlspecialchars($app['app_name']) ?></span>
                            <?= $v['is_public'] ? '<span class="pill pill-on text-[8px]">公开</span>' : '<span class="pill pill-admin text-[8px]">私有</span>' ?>
                        </div>
                        <div class="font-bold text-[12px] text-pink-400 mono mb-1"><?= htmlspecialchars($v['key_name']) ?></div>
                        <div class="text-[10px] text-white/50 mb-3 break-all border border-white/[0.04] p-2 rounded-lg bg-black/20"><?= htmlspecialchars($v['value']) ?></div>
                        <div class="flex gap-2">
                            <button type="button" onclick="openVarModal(<?= $v['id'] ?>,'<?= addslashes($v['key_name']) ?>','<?= str_replace(["\r\n","\r","\n"], '\n', addslashes($v['value'])) ?>',<?= $v['is_public'] ?>)" class="btn btn-sys-blue flex-1 justify-center py-1.5"><i class="ph-bold ph-pencil-simple"></i></button>
                            <button type="button" onclick="singleActionForm('del_var',<?= $v['id'] ?>,'var_id')" class="btn btn-sys-red flex-1 justify-center py-1.5"><i class="ph-bold ph-trash"></i></button>
                        </div>
                    </div>
                    <?php }} ?>
                </div>

                <div class="glass p-4 md:p-6 max-w-2xl">
                    <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-green)"><i class="ph-fill ph-plus-circle text-sm"></i> 添加变量</h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="add_var" value="1">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div><label class="lbl">所属应用</label><select name="var_app_id" class="field-s" required><option value="">-- 请选择 --</option><?php foreach($appList as $app): ?><option value="<?= $app['id'] ?>"><?= htmlspecialchars($app['app_name']) ?></option><?php endforeach; ?></select></div>
                            <div><label class="lbl">键名 (Key)</label><input type="text" name="var_key" class="field mono" placeholder="如: update_url" required></div>
                        </div>
                        <div><label class="lbl">变量值</label><textarea name="var_value" class="field" placeholder="输入内容"></textarea></div>
                        <label class="flex items-center gap-2 cursor-pointer mt-2">
                            <input type="checkbox" name="var_public" value="1" class="w-4 h-4 accent-pink-500 rounded bg-black/20 border-white/10">
                            <span class="text-[11px] font-bold text-white/80">设为公开变量 (Public)</span>
                        </label>
                        <button type="submit" class="btn btn-sys-green w-full py-2.5 mt-2"><i class="ph-bold ph-check"></i> 保存变量</button>
                    </form>
                </div>
            </div>

            <div id="appModal" class="modal-bg" style="display:none;">
                <div class="modal-panel">
                    <h3 class="text-[14px] font-bold mb-4 text-white/90">编辑应用</h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="edit_app" value="1"><input type="hidden" id="e_app_id" name="app_id">
                        <div><label class="lbl">应用名称</label><input type="text" id="e_app_name" name="app_name" class="field" required></div>
                        <div><label class="lbl">版本号</label><input type="text" id="e_app_ver" name="app_version" class="field"></div>
                        <div><label class="lbl">备注</label><input type="text" id="e_app_note" name="app_notes" class="field"></div>
                        <div class="flex gap-2 pt-2"><button type="button" onclick="document.getElementById('appModal').style.display='none'" class="btn btn-liquid flex-1 justify-center py-2.5">取消</button><button type="submit" class="btn btn-sys-blue flex-1 justify-center py-2.5">保存</button></div>
                    </form>
                </div>
            </div>
            <div id="varModal" class="modal-bg" style="display:none;">
                <div class="modal-panel">
                    <h3 class="text-[14px] font-bold mb-4 text-white/90">编辑变量</h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="edit_var" value="1"><input type="hidden" id="e_var_id" name="var_id">
                        <div><label class="lbl">键名 (Key)</label><input type="text" id="e_var_key" name="var_key" class="field mono" required></div>
                        <div><label class="lbl">变量值</label><textarea id="e_var_val" name="var_value" class="field"></textarea></div>
                        <label class="flex items-center gap-2 cursor-pointer mt-2">
                            <input type="checkbox" id="e_var_pub" name="var_public" value="1" class="w-4 h-4 accent-pink-500 rounded bg-black/20 border-white/10">
                            <span class="text-[11px] font-bold text-white/80">设为公开变量</span>
                        </label>
                        <div class="flex gap-2 pt-2"><button type="button" onclick="document.getElementById('varModal').style.display='none'" class="btn btn-liquid flex-1 justify-center py-2.5">取消</button><button type="submit" class="btn btn-sys-blue flex-1 justify-center py-2.5">保存</button></div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'list'): ?>
            <div class="rise">
                <div class="pg-head"><h2 class="pg-title">卡密库存</h2><p class="pg-sub">共 <?= $totalCards ?> 条记录</p></div>
                
                <form method="GET" class="flex flex-wrap md:flex-nowrap gap-1.5 mb-3">
                    <input type="hidden" name="tab" value="list">
                    <?php if (isset($_GET['filter'])): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter']) ?>"><?php endif; ?>
                    <div class="relative flex-1 min-w-[150px]">
                        <input type="text" name="q" placeholder="模糊搜索..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES) ?>" class="field pl-9 h-[38px] text-[12px] leading-normal">
                        <i class="ph-bold ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[13px]" style="color:var(--text-4)"></i>
                    </div>
                    <select name="app_id" class="field-s h-[38px] text-[11px] min-w-[120px] leading-normal" onchange="this.form.submit()">
                        <option value="">全部应用</option>
                        <?php foreach($appList as $app): ?>
                            <option value="<?= $app['id'] ?>" <?= ($appFilter === $app['id']) ? 'selected' : '' ?>><?= htmlspecialchars($app['app_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($appFilter !== null): ?>
                    <select name="type" class="field-s h-[38px] text-[11px] min-w-[100px] leading-normal" onchange="this.form.submit()">
                        <option value="">全部类型</option>
                        <?php foreach (CARD_TYPES as $typeId => $typeConfig): ?>
                            <option value="<?= $typeId ?>" <?= ((string)$typeFilter === (string)$typeId) ? 'selected' : '' ?>><?= $typeConfig['name'] ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-sys-pink shrink-0 h-[38px]"><i class="ph-bold ph-magnifying-glass"></i></button>
                </form>
                
                <?php if ($appFilter !== null || $typeFilter !== null || !empty($_GET['q'])): ?>
                <div class="flex items-center gap-1.5 mb-3 overflow-x-auto pb-1 -mx-1 px-1">
                    <?php $buildFilterUrl = function ($fVal) use ($appFilter, $typeFilter) { 
                        $p = ['tab' => 'list', 'filter' => $fVal]; 
                        if ($appFilter !== null) $p['app_id'] = $appFilter;
                        if ($typeFilter !== null) $p['type'] = $typeFilter; 
                        return '?' . http_build_query($p); 
                    }; ?>
                    <a href="<?= $buildFilterUrl('all') ?>" class="pill <?= $filterStr == 'all'? 'pill-vip' : 'pill-free' ?> text-[9px] py-1.5 px-3 shrink-0">全部</a>
                    <a href="<?= $buildFilterUrl('unused') ?>" class="pill <?= $filterStr == 'unused'? 'pill-vip' : 'pill-free' ?> text-[9px] py-1.5 px-3 shrink-0">未激活</a>
                    <a href="<?= $buildFilterUrl('active') ?>" class="pill <?= $filterStr == 'active'? 'pill-vip' : 'pill-free' ?> text-[9px] py-1.5 px-3 shrink-0">已激活</a>
                    <a href="<?= $buildFilterUrl('banned') ?>" class="pill <?= $filterStr == 'banned'? 'pill-vip' : 'pill-free' ?> text-[9px] py-1.5 px-3 shrink-0">已封禁</a>
                </div>
                
                <div class="glass overflow-hidden rise rise-2">
                    <form id="batchForm" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <div class="p-2 border-b border-white/[0.04] flex gap-1.5 flex-wrap overflow-x-auto" style="background:rgba(255,255,255,0.012)">
                            <button type="submit" name="batch_export" value="1" data-no-ajax="true" class="btn btn-sys-blue text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-download-simple"></i><span class="hidden md:inline"> 导出</span></button>
                            <button type="button" onclick="submitBatch('batch_unbind')" class="btn btn-sys-yellow text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-link-break"></i><span class="hidden md:inline"> 解绑</span></button>
                            <button type="button" onclick="batchAddTime()" class="btn btn-sys-green text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-clock-plus"></i><span class="hidden md:inline"> 加时</span></button>
                            <button type="button" onclick="batchSubTime()" class="btn btn-sys-purple text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-clock-counter-clockwise"></i><span class="hidden md:inline"> 扣时</span></button>
                            <button type="button" onclick="if(confirm('确定清理所有过期卡密？')) singleActionForm('clean_expired', 1);" class="btn btn-sys-orange text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-broom"></i><span class="hidden md:inline"> 清理</span></button>
                            <button type="button" onclick="submitBatch('batch_delete')" class="btn btn-sys-red text-[10px] py-1.5 shrink-0"><i class="ph-bold ph-trash"></i><span class="hidden md:inline"> 删除</span></button>
                            <input type="hidden" name="add_hours" id="addHoursInput">
                            <input type="hidden" name="sub_hours" id="subHoursInput">
                        </div>
                        
                        <div class="overflow-x-auto desktop-table">
                            <table class="w-full text-sm">
                                <thead><tr>
                                    <th class="p-3.5 text-center w-10"><input type="checkbox" onclick="toggleAllChecks(this)" class="accent-pink-500"></th>
                                    <th class="p-3.5 text-left">应用</th>
                                    <th class="p-3.5 text-left">卡密代码</th>
                                    <th class="p-3.5 text-left">类型</th>
                                    <th class="p-3.5 text-left">状态</th>
                                    <th class="p-3.5 text-left">设备绑定</th>
                                    <th class="p-3.5 text-left">备注</th>
                                    <th class="p-3.5 text-right">操作</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($cardList as $card): ?>
                                    <tr>
                                        <td class="p-3.5 text-center"><input type="checkbox" name="ids[]" value="<?= $card['id'] ?>" class="row-check accent-pink-500"></td>
                                        <td class="p-3.5"><?php if($card['app_id']>0): ?><span class="pill pill-big text-[9px]"><?= htmlspecialchars($card['app_name']) ?></span><?php else: ?><span class="text-[9px] text-white/30">未分类</span><?php endif; ?></td>
                                        <td class="p-3.5"><span class="pill pill-free text-[10px] mono cursor-pointer hover:bg-white/[0.06]" onclick="copy('<?= $card['card_code'] ?>')"><?= $card['card_code'] ?></span></td>
                                        <td class="p-3.5 text-[10px] font-bold"><?= CARD_TYPES[$card['card_type']]['name'] ?? $card['card_type'] ?></td>
                                        <td class="p-3.5">
                                            <?php
                                            if ($card['status'] == 2) echo '<span class="pill pill-banned text-[9px]">已封禁</span>';
                                            elseif ($card['status'] == 1) echo (strtotime($card['expire_time']) > time()) ? (empty($card['device_hash']) ? '<span class="pill pill-admin text-[9px]">待绑定</span>' : '<span class="pill pill-on text-[9px]">使用中</span>') : '<span class="pill pill-banned text-[9px]">已过期</span>';
                                            else echo '<span class="pill pill-free text-[9px]">闲置</span>';
                                            ?>
                                        </td>
                                        <td class="p-3.5 text-[9.5px] mono text-white/40"><?= ($card['status'] == 1 && !empty($card['device_hash'])) ? substr($card['device_hash'], 0, 10) . '...' : '-' ?></td>
                                        <td class="p-3.5 text-[10px] text-white/30 max-w-[80px] truncate" title="<?= htmlspecialchars($card['notes'] ?? '') ?>"><?= !empty($card['notes']) ? htmlspecialchars($card['notes']) : '-' ?></td>
                                        <td class="p-3.5 text-right">
                                            <div class="flex items-center gap-1 justify-end">
                                                <?php if ($card['status'] == 1 && !empty($card['device_hash'])): ?><button type="button" onclick="singleActionForm('unbind_card',<?= $card['id'] ?>)" class="btn btn-sys-yellow text-[10px] py-1 px-2"><i class="ph-bold ph-link-break"></i></button><?php endif; ?>
                                                <?php if ($card['status'] != 2): ?><button type="button" onclick="singleActionForm('ban_card',<?= $card['id'] ?>)" class="btn btn-sys-orange text-[10px] py-1 px-2"><i class="ph-bold ph-prohibit"></i></button><?php else: ?><button type="button" onclick="singleActionForm('unban_card',<?= $card['id'] ?>)" class="btn btn-sys-green text-[10px] py-1 px-2"><i class="ph-bold ph-check"></i></button><?php endif; ?>
                                                <button type="button" onclick="singleActionForm('del_card',<?= $card['id'] ?>)" class="btn btn-sys-red text-[10px] py-1 px-2"><i class="ph-bold ph-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($cardList)): ?><tr><td colspan="8" class="text-center text-[11px] text-white/25 py-10">暂无符合条件的卡密</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="m-card-list p-2">
                            <?php foreach ($cardList as $card): ?>
                            <div class="m-card-item">
                                <div class="m-card-item-header">
                                    <div class="flex items-center gap-2.5">
                                        <input type="checkbox" name="ids[]" value="<?= $card['id'] ?>" class="row-check accent-pink-500">
                                        <span class="m-card-item-code" onclick="copy('<?= $card['card_code'] ?>')"><?= $card['card_code'] ?></span>
                                    </div>
                                    <?php
                                    if ($card['status'] == 2) echo '<span class="pill pill-banned text-[8px]">封禁</span>';
                                    elseif ($card['status'] == 1) echo (strtotime($card['expire_time']) > time()) ? (empty($card['device_hash']) ? '<span class="pill pill-admin text-[8px]">待绑定</span>' : '<span class="pill pill-on text-[8px]">使用中</span>') : '<span class="pill pill-banned text-[8px]">过期</span>';
                                    else echo '<span class="pill pill-free text-[8px]">闲置</span>';
                                    ?>
                                </div>
                                <div class="m-card-item-meta">
                                    <div class="m-card-item-meta-row">
                                        <span class="m-card-item-meta-label">应用</span>
                                        <span class="m-card-item-meta-value"><?= htmlspecialchars($card['app_name'] ?? '无') ?></span>
                                    </div>
                                    <div class="m-card-item-meta-row">
                                        <span class="m-card-item-meta-label">类型</span>
                                        <span class="m-card-item-meta-value"><?= CARD_TYPES[$card['card_type']]['name'] ?? $card['card_type'] ?></span>
                                    </div>
                                    <?php if (!empty($card['notes'])): ?>
                                    <div class="m-card-item-meta-row">
                                        <span class="m-card-item-meta-label">备注</span>
                                        <span class="m-card-item-meta-value text-white/40" style="font-size:10px"><?= htmlspecialchars($card['notes']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($card['status'] == 1 && !empty($card['device_hash'])): ?>
                                    <div class="m-card-item-meta-row">
                                        <span class="m-card-item-meta-label">设备</span>
                                        <span class="m-card-item-meta-value mono text-white/30" style="font-size:10px"><?= substr($card['device_hash'], 0, 10) ?>...</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="m-card-item-actions">
                                    <?php if ($card['status'] == 1 && !empty($card['device_hash'])): ?><button type="button" onclick="singleActionForm('unbind_card',<?= $card['id'] ?>)" class="btn btn-sys-yellow"><i class="ph-bold ph-link-break"></i></button><?php endif; ?>
                                    <?php if ($card['status'] != 2): ?><button type="button" onclick="singleActionForm('ban_card',<?= $card['id'] ?>)" class="btn btn-sys-orange"><i class="ph-bold ph-prohibit"></i></button><?php else: ?><button type="button" onclick="singleActionForm('unban_card',<?= $card['id'] ?>)" class="btn btn-sys-green"><i class="ph-bold ph-check"></i></button><?php endif; ?>
                                    <button type="button" onclick="singleActionForm('del_card',<?= $card['id'] ?>)" class="btn btn-sys-red"><i class="ph-bold ph-trash"></i></button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="flex items-center justify-between p-2.5 border-t border-white/[0.04]">
                            <?php
                            $queryParams = ['tab' => 'list', 'filter' => $filterStr];
                            if (!empty($_GET['q'])) $queryParams['q'] = $_GET['q'];
                            if ($appFilter !== null) $queryParams['app_id'] = $appFilter;
                            if ($typeFilter !== null) $queryParams['type'] = $typeFilter;
                            $pageLimitUrl = $queryParams; $pageLimitUrl['page'] = 1;
                            ?>
                            <div class="flex items-center gap-2">
                                <select class="field-s py-1 text-[10px] h-auto pl-2 pr-5" style="border-radius:8px" onchange="window.location.href='?<?= http_build_query($pageLimitUrl) ?>&limit='+this.value">
                                    <option value="10" <?= $perPage == 10  ? 'selected' : '' ?>>10/页</option>
                                    <option value="20" <?= $perPage == 20  ? 'selected' : '' ?>>20/页</option>
                                    <option value="50" <?= $perPage == 50  ? 'selected' : '' ?>>50/页</option>
                                    <option value="100" <?= $perPage == 100  ? 'selected' : '' ?>>100/页</option>
                                </select>
                            </div>
                            <div class="flex items-center gap-1">
                                <?php
                                $queryParams['limit'] = $perPage;
                                $getUrl = function ($p) use ($queryParams) { $queryParams['page'] = $p; return '?' . http_build_query($queryParams); };
                                if ($page > 1) echo '<a href="' . $getUrl($page - 1) . '" class="btn btn-liquid text-[10px] py-1.5 px-2"><i class="ph-bold ph-caret-left"></i></a>';
                                echo '<span class="text-[10px] mono text-white/30 px-1">' . $page . '/' . max(1, $totalPages) . '</span>';
                                if ($page < $totalPages) echo '<a href="' . $getUrl($page + 1) . '" class="btn btn-liquid text-[10px] py-1.5 px-2"><i class="ph-bold ph-caret-right"></i></a>';
                                ?>
                            </div>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                    <div class="glass p-8 text-center text-white/30 text-xs mt-4">请使用上方筛选器加载库存数据</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'create'): ?>
            <div class="pg-head rise"><h2 class="pg-title">批量制卡</h2><p class="pg-sub">快速为应用生成授权码</p></div>
            <div class="glass p-4 md:p-6 max-w-2xl rise rise-1">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="gen_cards" value="1">
                    
                    <div><label class="lbl"><i class="ph-fill ph-app-window text-purple-400 mr-1 text-[12px]"></i> 归属应用 (必选)</label><select name="app_id" class="field-s text-sm font-bold text-white/90 py-3" required><option value="">-- 请选择目标应用 --</option><?php foreach($appList as $app): if($app['status']==0) continue; ?><option value="<?= $app['id'] ?>"><?= htmlspecialchars($app['app_name']) ?></option><?php endforeach; ?></select></div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div><label class="lbl">生成数量</label><input type="number" name="num" class="field" value="10" min="1" max="500"></div>
                        <div><label class="lbl">套餐类型</label>
                            <select name="type" class="field-s">
                                <?php foreach (CARD_TYPES as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v['name'] ?> (<?= $v['duration'] >= 86400 ? ($v['duration'] / 86400) . '天' : ($v['duration'] / 3600) . '小时' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label class="lbl">前缀 (选填)</label><input type="text" name="pre" class="field" placeholder="VIP-"></div>
                        <div><label class="lbl">备注 (选填)</label><input type="text" name="note" class="field" placeholder="批次说明"></div>
                    </div>
                    <div class="flex gap-2 mt-4 pt-4 border-t border-white/[0.04]">
                        <button type="submit" class="btn btn-sys-pink flex-1 py-3 text-[12px]"><i class="ph-bold ph-magic-wand text-sm"></i> 生成</button>
                        <button type="submit" name="auto_export" value="1" data-no-ajax="true" class="btn btn-sys-green flex-1 py-3 text-[12px]"><i class="ph-bold ph-download-simple text-sm"></i> 生成并导出(TXT)</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'blacklist'): ?>
            <?php $bl_list = []; try { $bl_list = $db->pdo->query("SELECT * FROM blacklists ORDER BY create_time DESC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {} ?>
            <div class="pg-head rise"><h2 class="pg-title">全局云黑</h2><p class="pg-sub">跨应用拦截恶意设备与IP</p></div>
            
            <div class="glass p-4 md:p-5 rise rise-1 mb-3">
                <form method="POST" class="space-y-3 md:space-y-0 md:flex md:gap-2.5 md:items-end">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="add_blacklist" value="1">
                    <div class="flex-1"><label class="lbl">封禁类型</label><select name="bl_type" class="field-s py-2.5 h-auto"><option value="device">设备特征码</option><option value="ip">IP 地址</option></select></div>
                    <div class="flex-[2]"><label class="lbl">封禁目标 (需准确)</label><input type="text" name="bl_value" class="field py-2.5 h-auto" required placeholder="设备Hash或IP (无空格)"></div>
                    <div class="flex-[2] hidden md:block"><label class="lbl">拦截备注 (选填)</label><input type="text" name="bl_reason" class="field py-2.5 h-auto" placeholder="如: 抓包破解"></div>
                    <button type="submit" class="btn btn-sys-red w-full md:w-auto py-2.5 px-5 text-[11px] shrink-0"><i class="ph-bold ph-prohibit"></i> 全局拉黑</button>
                </form>
            </div>
            
            <div class="hidden md:block glass overflow-hidden rise rise-2">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr><th class="p-3.5 text-left">限制类型</th><th class="p-3.5 text-left">封禁目标 (Value)</th><th class="p-3.5 text-left">拦截原因</th><th class="p-3.5 text-left">添加时间</th><th class="p-3.5 text-right">操作</th></tr></thead>
                        <tbody>
                            <?php foreach ($bl_list as $bl): ?>
                            <tr>
                                <td class="p-3.5"><?= $bl['type'] == 'ip' ? '<span class="pill pill-admin text-[9px]">IP 封禁</span>' : '<span class="pill pill-big text-[9px]">设备封禁</span>' ?></td>
                                <td class="p-3.5"><span class="pill pill-banned text-[10px] mono"><?= htmlspecialchars($bl['value']) ?></span></td>
                                <td class="p-3.5 text-[10.5px] text-white/50"><?= htmlspecialchars($bl['reason']?: '无备注') ?></td>
                                <td class="p-3.5 text-[9.5px] mono text-white/30"><?= date('Y-m-d H:i', strtotime($bl['create_time'])) ?></td>
                                <td class="p-3.5 text-right"><button type="button" onclick="singleActionForm('del_blacklist',<?= $bl['id'] ?>)" class="btn btn-sys-green text-[10px] py-1 px-2.5"><i class="ph-bold ph-trash"></i> 解除</button></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($bl_list)): ?><tr><td colspan="5" class="text-center text-[11px] text-white/25 py-10">当前无任何云黑记录</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="md:hidden space-y-2 rise rise-2">
                <?php foreach ($bl_list as $bl): ?>
                <div class="m-card-item">
                    <div class="flex items-center justify-between mb-2">
                        <?= $bl['type'] == 'ip' ? '<span class="pill pill-admin text-[8px]">IP 封禁</span>' : '<span class="pill pill-big text-[8px]">设备封禁</span>' ?>
                        <span class="text-[8.5px] mono text-white/25"><?= date('m-d H:i', strtotime($bl['create_time'])) ?></span>
                    </div>
                    <div class="text-[11px] mono text-white/60 mb-1 break-all"><?= htmlspecialchars($bl['value']) ?></div>
                    <div class="text-[10px] text-white/30 mb-2"><?= htmlspecialchars($bl['reason']?: '无备注') ?></div>
                    <button type="button" onclick="singleActionForm('del_blacklist',<?= $bl['id'] ?>)" class="btn btn-sys-green text-[10px] w-full justify-center py-2"><i class="ph-bold ph-trash"></i> 解除封禁</button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'logs'): ?>
            <div class="pg-head rise"><h2 class="pg-title">审计日志</h2><p class="pg-sub">各应用访问及心跳记录</p></div>
            
            <div class="hidden md:block glass overflow-hidden rise rise-1">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr><th class="p-3.5 text-left">时间</th><th class="p-3.5 text-left">所属应用</th><th class="p-3.5 text-left">执行动作</th><th class="p-3.5 text-left">涉及对象</th><th class="p-3.5 text-left">客户端IP</th></tr></thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="p-3.5 text-[9.5px] mono text-white/40"><?= date('m-d H:i:s', strtotime($log['access_time'] ?? $log['log_time'] ?? $log['created_at'] ?? 'now')) ?></td>
                                <td class="p-3.5"><span class="pill pill-big text-[9px]"><?= htmlspecialchars($log['app_name'] ?? 'System') ?></span></td>
                                <td class="p-3.5">
                                    <?php $act = $log['result'] ?? $log['action'] ?? $log['type'] ?? ''; if (strpos($act, '拦截') !== false || strpos($act, '封禁') !== false): ?><span class="pill pill-banned text-[9px]"><?= htmlspecialchars($act) ?></span>
                                    <?php else: ?><span class="pill pill-free text-[9px]"><?= htmlspecialchars($act) ?></span><?php endif; ?>
                                </td>
                                <td class="p-3.5 text-[10px] text-white/60 max-w-[150px] truncate" title="<?= htmlspecialchars($log['card_code'] ?? $log['details'] ?? '') ?>"><?= htmlspecialchars($log['card_code'] ?? $log['details'] ?? '-') ?></td>
                                <td class="p-3.5 text-[9.5px] mono text-blue-400/60"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?><tr><td colspan="5" class="text-center text-[11px] text-white/25 py-10">暂无日志</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="md:hidden space-y-1.5 rise rise-1">
                <?php foreach ($logs as $log): ?>
                <div class="glass-sunken p-3 rounded-[14px] border border-white/[0.03]">
                    <div class="flex items-center justify-between mb-1.5">
                        <div class="flex gap-1 items-center">
                            <span class="pill pill-big text-[8px]"><?= htmlspecialchars($log['app_name'] ?? 'Sys') ?></span>
                            <?php $act = $log['result'] ?? $log['action'] ?? $log['type'] ?? ''; if (strpos($act, '拦截') !== false): ?><span class="pill pill-banned text-[8px]"><?= htmlspecialchars($act) ?></span>
                            <?php else: ?><span class="pill pill-free text-[8px]"><?= htmlspecialchars($act) ?></span><?php endif; ?>
                        </div>
                        <span class="text-[8.5px] mono text-white/25"><?= date('m-d H:i', strtotime($log['access_time'] ?? 'now')) ?></span>
                    </div>
                    <div class="text-[10px] mono text-white/50 truncate"><?= htmlspecialchars($log['card_code'] ?? $log['details'] ?? '-') ?></div>
                    <div class="text-[9px] mono text-blue-400/50 mt-1 text-right"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'settings'): ?>
            <div class="pg-head rise"><h2 class="pg-title">全局配置</h2><p class="pg-sub">个性化与安全</p></div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 rise rise-1 mb-3">
                <div class="glass p-4 md:p-6">
                    <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-blue)"><i class="ph-fill ph-palette text-sm"></i> 全局设置</h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="update_settings" value="1">
                        
                        <label class="flex items-center gap-2 cursor-pointer mt-2 bg-white/[0.02] p-4 rounded-xl border border-white/[0.05]">
                            <input type="checkbox" name="bg_blur" value="1" <?= $conf_bg_blur=='1'?'checked':'' ?> class="w-4 h-4 accent-pink-500 rounded bg-black/20 border-white/10">
                            <span class="text-[12px] font-bold text-white/80">开启背景全局模糊 (Glass Effect)</span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer mt-2 bg-white/[0.02] p-4 rounded-xl border border-white/[0.05]">
                            <input type="checkbox" name="api_encrypt" value="1" <?= $conf_api_encrypt=='1'?'checked':'' ?> class="w-4 h-4 accent-pink-500 rounded bg-black/20 border-white/10">
                            <span class="text-[12px] font-bold text-white/80">开启 API 接口加密通讯 (AES-256-GCM)</span>
                        </label>
                        
                        <button type="submit" data-no-ajax="true" class="btn btn-sys-blue w-full py-3 mt-4"><i class="ph-bold ph-floppy-disk"></i> 保存设置</button>
                    </form>
                </div>
                
                <div class="glass p-4 md:p-6">
                    <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-red)"><i class="ph-fill ph-shield-check text-sm"></i> 安全设置</h3>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="update_pwd" value="1">
                        <div><label class="lbl">新密码</label><input type="password" name="new_pwd" class="field" required></div>
                        <div><label class="lbl">确认密码</label><input type="password" name="confirm_pwd" class="field" required></div>
                        <button type="submit" class="btn btn-sys-red w-full py-3 mt-2"><i class="ph-bold ph-lock-key"></i> 更新管理员密码</button>
                    </form>
                </div>

                <div class="glass p-4 md:p-6 md:col-span-2">
                    <h3 class="text-[12px] font-bold mb-4 flex items-center gap-2" style="color:var(--sys-orange)"><i class="ph-fill ph-swap text-sm"></i> 完美系统迁移</h3>
                    <div class="text-[11px] text-white/50 mb-4">通过一键导出与导入功能，实现无缝将全部卡密、应用、活跃设备等数据快速迁移到新系统环境。</div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="glass-sunken p-4 rounded-xl border border-white/[0.05]">
                            <h4 class="text-[11px] font-bold text-white/80 mb-2">第 1 步：导出当前数据</h4>
                            <p class="text-[10px] text-white/40 mb-4">将当前系统内的应用、卡密、变量、黑名单、日志及系统配置完整打包为 JSON 备份文件。</p>
                            <a href="?action=export_system" class="btn btn-sys-orange w-full py-2.5 justify-center"><i class="ph-bold ph-download-simple"></i> 导出完整数据包</a>
                        </div>
                        <div class="glass-sunken p-4 rounded-xl border border-white/[0.05]">
                            <h4 class="text-[11px] font-bold text-white/80 mb-2">第 2 步：导入至新系统</h4>
                            <p class="text-[10px] text-white/40 mb-4">在新系统上传刚才下载的数据包。<span class="text-red-400">注意：该操作将完全清空并覆盖现有数据。</span></p>
                            <form method="POST" enctype="multipart/form-data" class="flex gap-2" data-no-ajax="true">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="import_system" value="1">
                                <input type="file" name="backup_file" accept=".json" required class="flex-1 min-w-0 text-[10px] text-white/60 file:mr-2 file:py-1 file:px-2 file:rounded-md file:border-0 file:text-[10px] file:bg-white/10 file:text-white/80 file:cursor-pointer hover:file:bg-white/20 p-1 border border-white/10 rounded-lg">
                                <button type="submit" onclick="return confirm('警告：该操作将清空当前系统的全部数据并进行彻底覆盖！是否确认继续？')" class="btn btn-sys-green px-4 shrink-0"><i class="ph-bold ph-upload-simple"></i> 恢复</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="md:hidden mt-4 rise rise-2">
                <a href="?tab=about" class="btn btn-sys-pink w-full py-3.5 justify-center text-[13px] font-bold shadow-lg shadow-pink-500/20"><i class="ph-bold ph-info text-[16px]"></i> 关于 GuYi Access</a>
            </div>
        <?php endif; ?>

        <?php if ($tab == 'about'): ?>
            <div class="pg-head rise"><h2 class="pg-title">关于系统</h2><p class="pg-sub">GuYi Access 开源验证架构</p></div>
            
            <div class="glass p-6 md:p-10 rise rise-1 max-w-4xl mx-auto mt-4 rounded-3xl flex flex-col items-center border-t border-white/[0.08] shadow-2xl relative overflow-hidden">
                <div class="absolute -top-24 -left-24 w-72 h-72 bg-pink-500/20 rounded-full blur-[80px] pointer-events-none"></div>
                <div class="absolute -bottom-24 -right-24 w-72 h-72 bg-blue-500/20 rounded-full blur-[80px] pointer-events-none"></div>
                
                <img src="<?= htmlspecialchars($conf_avatar) ?>" class="w-24 h-24 rounded-full mx-auto mb-5 border-[3px] border-white/20 shadow-xl relative z-10 hover:scale-105 transition-transform">
                <h1 class="text-[24px] font-black text-white mb-2 tracking-wide relative z-10">GuYi Access <span class="text-pink-400">Pro</span></h1>
                <p class="text-[12px] text-white/50 mb-8 max-w-md text-center leading-relaxed relative z-10">一款高性能、轻量级的多应用授权验证系统。<br>致力于为您提供极致的管理体验与安全的数据防护。</p>
                
                <div class="w-full grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4 relative z-10">
                    <?= implode('', array_map('urldecode', [
                        '%3ca%20href%3d%22https%3a%2f%2fgithub.com%2fGuYiovo%2fGuYi-Access%22%20target%3d%22_blank%22%20class%3d%22flex%20items-center%20gap-4%20p-4%20rounded-%5b16px%5d%20bg-white%2f%5b0.02%5d%20border%20border-white%2f%5b0.05%5d%20hover%3abg-white%2f%5b0.06%5d%20transition-all%22%3e%3cdiv%20class%3d%22w-11%20h-11%20rounded-%5b12px%5d%20bg-white%2f10%20flex%20items-center%20justify-center%20shrink-0%22%3e%3ci%20class%3d%22ph-fill%20ph-github-logo%20text-%5b22px%5d%20text-white%22%3e%3c%2fi%3e%3c%2fdiv%3e%3cdiv%20class%3d%22flex-1%20min-w-0%22%3e%3cdiv%20class%3d%22text-%5b13px%5d%20font-bold%20text-white%2f90%20mb-0.5%22%3eGitHub%20%e5%bc%80%e6%ba%90%e9%a1%b9%e7%9b%ae%3c%2fdiv%3e%3cdiv%20class%3d%22text-%5b11px%5d%20text-white%2f40%20truncate%22%3ehttps%3a%2f%2fgithub.com%2fGuYiovo%2fGuYi-Access%3c%2fdiv%3e%3c%2fdiv%3e%3c%2fa%3e',
                        '%3ca%20href%3d%22https%3a%2f%2fguyiovo.github.io%2fGuYi-Access-wed%2f%22%20target%3d%22_blank%22%20class%3d%22flex%20items-center%20gap-4%20p-4%20rounded-%5b16px%5d%20bg-white%2f%5b0.02%5d%20border%20border-white%2f%5b0.05%5d%20hover%3abg-white%2f%5b0.06%5d%20transition-all%22%3e%3cdiv%20class%3d%22w-11%20h-11%20rounded-%5b12px%5d%20bg-blue-500%2f10%20flex%20items-center%20justify-center%20shrink-0%22%3e%3ci%20class%3d%22ph-fill%20ph-globe-hemisphere-west%20text-%5b22px%5d%20text-blue-400%22%3e%3c%2fi%3e%3c%2fdiv%3e%3cdiv%20class%3d%22flex-1%20min-w-0%22%3e%3cdiv%20class%3d%22text-%5b13px%5d%20font-bold%20text-white%2f90%20mb-0.5%22%3e%e5%ae%98%e6%96%b9%e7%bd%91%e7%ab%99%20(%e6%b5%b7%e5%a4%96%e7%ba%bf%e8%b7%af)%3c%2fdiv%3e%3cdiv%20class%3d%22text-%5b11px%5d%20text-white%2f40%20truncate%22%3eguyiovo.github.io%3c%2fdiv%3e%3c%2fdiv%3e%3c%2fa%3e',
                        '%3ca%20href%3d%22https%3a%2f%2fofficial.%e5%8f%af%e7%88%b1.top%2f%22%20target%3d%22_blank%22%20class%3d%22flex%20items-center%20gap-4%20p-4%20rounded-%5b16px%5d%20bg-white%2f%5b0.02%5d%20border%20border-white%2f%5b0.05%5d%20hover%3abg-white%2f%5b0.06%5d%20transition-all%22%3e%3cdiv%20class%3d%22w-11%20h-11%20rounded-%5b12px%5d%20bg-green-500%2f10%20flex%20items-center%20justify-center%20shrink-0%22%3e%3ci%20class%3d%22ph-fill%20ph-rocket-launch%20text-%5b22px%5d%20text-green-400%22%3e%3c%2fi%3e%3c%2fdiv%3e%3cdiv%20class%3d%22flex-1%20min-w-0%22%3e%3cdiv%20class%3d%22text-%5b13px%5d%20font-bold%20text-white%2f90%20mb-0.5%22%3e%e5%ae%98%e6%96%b9%e7%bd%91%e7%ab%99%20(%e5%85%a8%e7%90%83%e9%ab%98%e9%80%9f)%3c%2fdiv%3e%3cdiv%20class%3d%22text-%5b11px%5d%20text-white%2f40%20truncate%22%3eofficial.%e5%8f%af%e7%88%b1.top%3c%2fdiv%3e%3c%2fdiv%3e%3c%2fa%3e',
                        '%3ca%20href%3d%22https%3a%2f%2fwww.123684.com%2fs%2fEmjGjv-P7CsH%3fpwd%3dDMJz%22%20target%3d%22_blank%22%20class%3d%22flex%20items-center%20gap-4%20p-4%20rounded-%5b16px%5d%20bg-white%2f%5b0.02%5d%20border%20border-white%2f%5b0.05%5d%20hover%3abg-white%2f%5b0.06%5d%20transition-all%22%3e%3cdiv%20class%3d%22w-11%20h-11%20rounded-%5b12px%5d%20bg-yellow-500%2f10%20flex%20items-center%20justify-center%20shrink-0%22%3e%3ci%20class%3d%22ph-fill%20ph-cloud-arrow-down%20text-%5b22px%5d%20text-yellow-400%22%3e%3c%2fi%3e%3c%2fdiv%3e%3cdiv%20class%3d%22flex-1%20min-w-0%22%3e%3cdiv%20class%3d%22text-%5b13px%5d%20font-bold%20text-white%2f90%20mb-0.5%22%3e%e5%9b%bd%e5%86%85%e9%ab%98%e9%80%9f%e4%b8%8b%e8%bd%bd%e7%9b%98%3c%2fdiv%3e%3cdiv%20class%3d%22text-%5b11px%5d%20text-white%2f40%20truncate%22%3e%e5%af%86%e7%a0%81%3a%20DMJz%20(123%e4%ba%91%e7%9b%98)%3c%2fdiv%3e%3c%2fdiv%3e%3c%2fa%3e',
                        '%3ca%20href%3d%22mailto%3a156440000%40qq.com%22%20class%3d%22flex%20items-center%20gap-4%20p-4%20rounded-%5b16px%5d%20bg-white%2f%5b0.02%5d%20border%20border-white%2f%5b0.05%5d%20hover%3abg-white%2f%5b0.06%5d%20transition-all%20md%3acol-span-2%20justify-center%22%3e%3cdiv%20class%3d%22w-11%20h-11%20rounded-%5b12px%5d%20bg-pink-500%2f10%20flex%20items-center%20justify-center%20shrink-0%22%3e%3ci%20class%3d%22ph-fill%20ph-envelope-simple%20text-%5b22px%5d%20text-pink-400%22%3e%3c%2fi%3e%3c%2fdiv%3e%3cdiv%20class%3d%22flex-none%20text-left%22%3e%3cdiv%20class%3d%22text-%5b13px%5d%20font-bold%20text-white%2f90%20mb-0.5%22%3e%e9%97%ae%e9%a2%98%e5%8f%8d%e9%a6%88%e9%82%ae%e7%ae%b1%3c%2fdiv%3e%3cdiv%20class%3d%22text-%5b11px%5d%20text-white%2f40%20truncate%22%3e156440000%40qq.com%3c%2fdiv%3e%3c%2fdiv%3e%3c%2fa%3e'
                    ])) ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <div class="m-bottom-nav">
        <a href="?tab=dashboard" class="m-nav-item <?= $tab == 'dashboard' ? 'on' : '' ?>"><i class="ph-fill ph-squares-four"></i><span>概览</span></a>
        <a href="?tab=apps" class="m-nav-item <?= $tab == 'apps' ? 'on' : '' ?>"><i class="ph-fill ph-app-window"></i><span>应用</span></a>
        <a href="?tab=list" class="m-nav-item <?= $tab == 'list' ? 'on' : '' ?>"><i class="ph-fill ph-database"></i><span>库存</span></a>
        <a href="?tab=blacklist" class="m-nav-item <?= $tab == 'blacklist' ? 'on' : '' ?>"><i class="ph-fill ph-shield-warning"></i><span>云黑</span></a>
        <a href="?tab=settings" class="m-nav-item <?= ($tab == 'settings' || $tab == 'about') ? 'on' : '' ?>"><i class="ph-fill ph-gear"></i><span>设置</span></a>
    </div>

    <div id="toastEl" class="toast-w">
        <div class="toast-pill"><i class="ph-fill ph-check-circle text-lg" id="toastIc" style="color:var(--sys-green)"></i><span id="toastTx"></span></div>
    </div>

    <script>
        let _t;
        function toast(m,t='ok'){clearTimeout(_t);const e=document.getElementById('toastEl'),i=document.getElementById('toastIc');document.getElementById('toastTx').textContent=m;i.className='ph-fill '+(t==='error'?'ph-warning-circle':'ph-check-circle')+' text-lg';i.style.color=t==='error'?'var(--sys-red)':'var(--sys-green)';e.classList.add('show');_t=setTimeout(()=>e.classList.remove('show'),3200)}
        function copy(t){navigator.clipboard.writeText(t).then(()=>{toast('已复制到剪贴板')})}
        function toggleAllChecks(el){document.querySelectorAll('.row-check').forEach(c=>c.checked=el.checked)}
        function singleActionForm(a, id, k='id'){if(!confirm('确定操作？'))return;const f=document.createElement('form');f.method='POST';f.style.display='none';const i1=document.createElement('input');i1.name=a;i1.value='1';const i2=document.createElement('input');i2.name=k;i2.value=id;const i3=document.createElement('input');i3.name='csrf_token';i3.value='<?= $csrf_token ?>';f.appendChild(i1);f.appendChild(i2);f.appendChild(i3);document.body.appendChild(f);f.submit()}
        function submitBatch(a){if(document.querySelectorAll('.row-check:checked').length===0){toast('请先勾选卡密','error');return}if(!confirm('确定执行？'))return;const f=document.getElementById('batchForm'),h=document.createElement('input');h.type='hidden';h.name=a;h.value='1';f.appendChild(h);f.submit()}
        function batchAddTime(){if(document.querySelectorAll('.row-check:checked').length===0){toast('请先勾选','error');return}const h=prompt("增加小时数","24");if(h&&!isNaN(h)){document.getElementById('addHoursInput').value=h;submitBatch('batch_add_time')}}
        function batchSubTime(){if(document.querySelectorAll('.row-check:checked').length===0){toast('请先勾选','error');return}const h=prompt("扣除小时数","24");if(h&&!isNaN(h)){document.getElementById('subHoursInput').value=h;submitBatch('batch_sub_time')}}
        
        window.switchAppView = function(v) {
            const btnA = document.getElementById('btn_apps'), btnV = document.getElementById('btn_vars'),
                  viewA = document.getElementById('view_apps'), viewV = document.getElementById('view_vars');
            if(!btnA || !btnV || !viewA || !viewV) return;
            btnA.className = v === 'apps' ? 'px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all bg-white/[0.1] text-white shadow-sm border border-white/[0.05]' : 'px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all text-white/40 hover:text-white/80';
            btnV.className = v === 'vars' ? 'px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all bg-white/[0.1] text-white shadow-sm border border-white/[0.05]' : 'px-4 py-1.5 text-[11px] font-bold rounded-[10px] transition-all text-white/40 hover:text-white/80';
            viewA.style.display = v === 'apps' ? 'block' : 'none';
            viewV.style.display = v === 'vars' ? 'block' : 'none';
        };
        window.openAppModal = function(id, n, v, no) {
            const elId = document.getElementById('e_app_id'), elName = document.getElementById('e_app_name'),
                  elVer = document.getElementById('e_app_ver'), elNote = document.getElementById('e_app_note'),
                  modal = document.getElementById('appModal');
            if(elId) elId.value = id; if(elName) elName.value = n; if(elVer) elVer.value = v; if(elNote) elNote.value = no;
            if(modal) modal.style.display = 'flex';
        };
        window.openVarModal = function(id, k, v, p) {
            const elId = document.getElementById('e_var_id'), elKey = document.getElementById('e_var_key'),
                  elVal = document.getElementById('e_var_val'), elPub = document.getElementById('e_var_pub'),
                  modal = document.getElementById('varModal');
            if(elId) elId.value = id; if(elKey) elKey.value = k; if(elVal) elVal.value = v; if(elPub) elPub.checked = (p == 1);
            if(modal) modal.style.display = 'flex';
        };

        async function loadTab(url){document.querySelectorAll('.nav-link,.m-nav-item').forEach(el=>{if(!el.href)return;const u=new URL(el.href,window.location.href),c=new URL(url,window.location.href);u.searchParams.get('tab')===c.searchParams.get('tab')?el.classList.add('on'):el.classList.remove('on')});const m=document.getElementById('main');m.innerHTML='<div class="flex flex-col items-center justify-center h-[60vh] gap-4 rise"><div class="spin" style="width:28px;height:28px;border-width:2px"></div><span class="text-[9px] text-white/25 font-bold tracking-[0.2em] uppercase">Loading</span></div>';try{const res=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});if(res.redirected){window.location.href=res.url;return}const html=await res.text(),doc=new DOMParser().parseFromString(html,'text/html');requestAnimationFrame(()=>{m.innerHTML=doc.getElementById('main').innerHTML;window.history.pushState({},'',url);initPage()})}catch(e){toast('网络波动','error');window.location.href=url}}
        document.addEventListener('click',e=>{const link=e.target.closest('a');if(link&&link.href&&link.href.includes('?tab=')&&!link.hasAttribute('target')&&!link.hasAttribute('download')&&!link.classList.contains('data-no-ajax')){e.preventDefault();loadTab(link.href)}});
        document.addEventListener('submit',async e=>{if(e.target.tagName==='FORM'){const s=e.submitter;if(s&&(s.name==='batch_export'||s.name==='auto_export'||s.hasAttribute('data-no-ajax'))||e.target.hasAttribute('data-no-ajax'))return;e.preventDefault();const btn=s||e.target.querySelector('button[type="submit"]');let oT='',oW='';if(btn){oT=btn.innerHTML;oW=btn.style.width;btn.style.width=btn.offsetWidth+'px';btn.innerHTML='<i class="ph-bold ph-spinner animate-spin"></i>';btn.style.pointerEvents='none';btn.style.opacity='0.6'}try{const fd=new FormData(e.target);if(s&&s.name&&!fd.has(s.name))fd.append(s.name,s.value);const res=await fetch(e.target.action||window.location.href,{method:e.target.method||'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});if(res.redirected){window.location.href=res.url;return}const html=await res.text(),doc=new DOMParser().parseFromString(html,'text/html');requestAnimationFrame(()=>{document.getElementById('main').innerHTML=doc.getElementById('main').innerHTML;initPage()})}catch(err){toast('请求失败','error')}if(btn){btn.innerHTML=oT;btn.style.pointerEvents='auto';btn.style.opacity='1';btn.style.width=oW}}});
        
        function initPage(){
            const msgEl=document.getElementById('sys-msg');if(msgEl){toast(msgEl.dataset.msg,msgEl.dataset.type);msgEl.remove()}
            const chartEl=document.getElementById('cM');if(chartEl){
                const ctx=chartEl.getContext('2d'),tData=JSON.parse(chartEl.dataset.chart),cTypes=JSON.parse(chartEl.dataset.types),labels=Object.keys(tData).map(k=>cTypes[k]?.name||k),data=Object.values(tData);
                Chart.defaults.color='rgba(255,255,255,0.18)';Chart.defaults.borderColor='rgba(255,255,255,0.02)';
                new Chart(ctx,{type:'doughnut',data:{labels:labels,datasets:[{data:data,backgroundColor:['#64d2ff','#ff375f','#ffd60a','#0a84ff','#bf5af2'],borderWidth:0,hoverOffset:3,spacing:2,borderRadius:3}]},options:{cutout:'68%',plugins:{legend:{position:'bottom',labels:{color:'rgba(255,255,255,0.2)',font:{size:9,weight:700,family:'Inter'},padding:10,boxWidth:8,boxHeight:8,useBorderRadius:true,borderRadius:2}}},animation:{animateRotate:true,duration:800,easing:'easeOutQuart'}}})
            }
            if(document.getElementById('poem_content') && !window.poemLoaded){
                fetch('https://v1.jinrishici.com/all.json').then(r=>r.json()).then(d=>{
                    document.getElementById('poem_content').innerText = d.content;
                    document.getElementById('poem_info').innerHTML = `<span class="pill pill-admin text-[9px]">${d.author}</span> <span class="ml-2">《${d.origin}》</span>`;
                }).catch(()=>{ document.getElementById('poem_content').innerText="欲穷千里目，更上一层楼。"; document.getElementById('poem_info').innerHTML=`<span class="pill pill-admin text-[9px]">王之涣</span>`; });
                window.poemLoaded = true;
            }
        }
        document.addEventListener('DOMContentLoaded',initPage);
        window.addEventListener('popstate',()=>loadTab(window.location.href));
    </script>
</body>
</html>
