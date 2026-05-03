<?php
// database.php - 核心数据库类 (安全增强版 + 性能优化 + 自动修补升级 + 完美迁移)
require_once 'config.php';

if (!class_exists('Database')) {
    
    class Database {
        public $pdo;
        
        public function __construct() {
            try {
                $dsn = "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ];
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $this->createTables();
                
            } catch (PDOException $e) {
                error_log('DB Connection Error: ' . $e->getMessage());
                die('System Error: Database connection failed. Please check error logs.');
            }
        }
        
        private function createTables() {
            $tableOptions = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS applications (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                app_name VARCHAR(100) NOT NULL UNIQUE, 
                app_key VARCHAR(64) NOT NULL UNIQUE, 
                app_version VARCHAR(32) DEFAULT '', 
                status TINYINT DEFAULT 1, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                notes TEXT
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_variables (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                app_id INT NOT NULL, 
                key_name VARCHAR(50) NOT NULL, 
                value TEXT, 
                is_public TINYINT DEFAULT 0, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                INDEX idx_app_var (app_id, key_name)
            ) $tableOptions");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS cards (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                card_code VARCHAR(50) UNIQUE NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                status TINYINT DEFAULT 0, 
                device_hash VARCHAR(100), 
                used_time DATETIME, 
                expire_time DATETIME, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                notes TEXT, 
                app_id INT DEFAULT 0, 
                INDEX idx_card_app (app_id), 
                INDEX idx_card_hash (device_hash)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS usage_logs (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                card_code VARCHAR(50) NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                device_hash VARCHAR(100) NOT NULL, 
                ip_address VARCHAR(45), 
                user_agent TEXT, 
                access_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                result VARCHAR(100), 
                app_name VARCHAR(100) DEFAULT 'System', 
                INDEX idx_log_time (access_time)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS active_devices (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                device_hash VARCHAR(100) NOT NULL, 
                card_code VARCHAR(50) UNIQUE NOT NULL, 
                card_type VARCHAR(20) NOT NULL, 
                activate_time DATETIME DEFAULT CURRENT_TIMESTAMP, 
                expire_time DATETIME NOT NULL, 
                status TINYINT DEFAULT 1, 
                app_id INT DEFAULT 0, 
                INDEX idx_dev_hash (device_hash), 
                INDEX idx_dev_expire (expire_time)
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin (
                id INT PRIMARY KEY, 
                username VARCHAR(50) UNIQUE NOT NULL, 
                password_hash VARCHAR(255) NOT NULL
            ) $tableOptions");
            
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
                key_name VARCHAR(50) PRIMARY KEY,
                value TEXT
            ) $tableOptions");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS blacklists (
                id INT AUTO_INCREMENT PRIMARY KEY, 
                type VARCHAR(20) NOT NULL, 
                value VARCHAR(100) NOT NULL UNIQUE, 
                reason TEXT, 
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_bl_type_value (type, value)
            ) $tableOptions");
            
            if ($this->pdo->query("SELECT COUNT(*) FROM admin")->fetchColumn() == 0) {
                $this->pdo->prepare("INSERT IGNORE INTO admin (id, username, password_hash) VALUES (1, 'GuYi', ?)")->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
            }

            try {
                $checkCards = $this->pdo->query("SHOW COLUMNS FROM `cards` LIKE 'app_id'");
                if ($checkCards->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `cards` ADD `app_id` INT DEFAULT 0");
                    $this->pdo->exec("ALTER TABLE `cards` ADD INDEX `idx_card_app` (`app_id`)");
                }
                $checkDevices = $this->pdo->query("SHOW COLUMNS FROM `active_devices` LIKE 'app_id'");
                if ($checkDevices->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `active_devices` ADD `app_id` INT DEFAULT 0");
                }
                $checkLogs = $this->pdo->query("SHOW COLUMNS FROM `usage_logs` LIKE 'app_name'");
                if ($checkLogs->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE `usage_logs` ADD `app_name` VARCHAR(100) DEFAULT 'System'");
                }
            } catch (PDOException $e) {}
        }

        public function getSystemSettings() {
            $stmt = $this->pdo->query("SELECT * FROM system_settings");
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[$row['key_name']] = $row['value'];
            }
            return $data;
        }

        public function saveSystemSettings($settings) {
            $stmt = $this->pdo->prepare("REPLACE INTO system_settings (key_name, value) VALUES (?, ?)");
            foreach ($settings as $key => $val) {
                $stmt->execute([$key, $val]);
            }
        }
        
        public function getAdminUsername() {
            return $this->pdo->query("SELECT username FROM admin WHERE id=1")->fetchColumn() ?: 'GuYi';
        }
        
        public function updateAdminUsername($newUsername) {
            $this->pdo->prepare("UPDATE admin SET username=? WHERE id=1")->execute([$newUsername]);
        }

        public function createApp($name, $version = '', $notes = '') {
            $appKey = bin2hex(random_bytes(32));
            $stmt = $this->pdo->prepare("INSERT INTO applications (app_name, app_key, app_version, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $appKey, $version, $notes]);
            return $appKey;
        }

        public function updateApp($id, $name, $version, $notes) {
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM applications WHERE app_name = ? AND id != ?");
            $check->execute([$name, $id]);
            if ($check->fetchColumn() > 0) throw new Exception("应用名称已存在");

            $stmt = $this->pdo->prepare("UPDATE applications SET app_name = ?, app_version = ?, notes = ? WHERE id = ?");
            $stmt->execute([$name, $version, $notes, $id]);
        }

        public function getApps() {
            return $this->pdo->query("SELECT *, (SELECT COUNT(*) FROM cards WHERE cards.app_id = applications.id) as card_count FROM applications ORDER BY create_time DESC")->fetchAll(PDO::FETCH_ASSOC);
        }

        public function toggleAppStatus($id) { $this->pdo->prepare("UPDATE applications SET status = CASE WHEN status = 1 THEN 0 ELSE 1 END WHERE id = ?")->execute([$id]); }

        public function deleteApp($id) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM cards WHERE app_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) throw new Exception("无法删除：该应用下仍有 {$count} 张卡密。");
            $this->pdo->prepare("DELETE FROM app_variables WHERE app_id = ?")->execute([$id]);
            $this->pdo->prepare("DELETE FROM applications WHERE id = ?")->execute([$id]);
        }

        public function addAppVariable($appId, $key, $value, $isPublic) {
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM app_variables WHERE app_id = ? AND key_name = ?");
            $check->execute([$appId, $key]);
            if ($check->fetchColumn() > 0) throw new Exception("变量名重复");
            $stmt = $this->pdo->prepare("INSERT INTO app_variables (app_id, key_name, value, is_public) VALUES (?, ?, ?, ?)");
            $stmt->execute([$appId, $key, $value, $isPublic]);
        }

        public function deleteAppVariable($id) { $this->pdo->prepare("DELETE FROM app_variables WHERE id = ?")->execute([$id]); }

        public function getAppVariables($appId, $onlyPublic = false) {
            $sql = "SELECT * FROM app_variables WHERE app_id = ?";
            if ($onlyPublic) $sql .= " AND is_public = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$appId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function updateAppVariable($id, $key, $value, $isPublic) {
            $stmt = $this->pdo->prepare("SELECT app_id FROM app_variables WHERE id = ?");
            $stmt->execute([$id]);
            $appId = $stmt->fetchColumn();
            if (!$appId) throw new Exception("变量不存在");
            $check = $this->pdo->prepare("SELECT COUNT(*) FROM app_variables WHERE app_id = ? AND key_name = ? AND id != ?");
            $check->execute([$appId, $key, $id]);
            if ($check->fetchColumn() > 0) throw new Exception("变量名重复");
            $this->pdo->prepare("UPDATE app_variables SET key_name=?, value=?, is_public=? WHERE id=?")->execute([$key, $value, $isPublic, $id]);
        }
        
        public function getAppIdByKey($appKey) {
            $stmt = $this->pdo->prepare("SELECT id, status, app_name FROM applications WHERE app_key = ?");
            $stmt->execute([$appKey]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        public function verifyCard($cardCode, $deviceHash, $appKey = null) {
            if (mt_rand(1, 100) === 1) {
                $this->cleanupExpiredDevices();
            }
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; 
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            $blStmt = $this->pdo->prepare("SELECT reason FROM blacklists WHERE (type = 'ip' AND value = ?) OR (type = 'device' AND value = ?)");
            $blStmt->execute([$ip, $deviceHash]);
            $blRecord = $blStmt->fetch(PDO::FETCH_ASSOC);
            if ($blRecord) {
                $reason = !empty($blRecord['reason']) ? $blRecord['reason'] : '触发系统安全规则';
                return ['success' => false, 'message' => "访问受限：设备或IP已被云端封禁 ({$reason})"];
            }

            if (empty($appKey)) return ['success' => false, 'message' => '鉴权失败：未提供AppKey'];

            $app = $this->getAppIdByKey($appKey);
            if (!$app) return ['success' => false, 'message' => '应用密钥无效'];
            if ($app['status'] == 0) return ['success' => false, 'message' => '应用已被禁用'];
            
            $currentAppId = $app['id'];
            $appNameForLog = $app['app_name'];

            $deviceStmt = $this->pdo->prepare("SELECT * FROM active_devices WHERE device_hash = ? AND status = 1 AND expire_time > NOW() AND app_id = ?");
            $deviceStmt->execute([$deviceHash, $currentAppId]);
            $activeInfo = $deviceStmt->fetch(PDO::FETCH_ASSOC);

            if ($activeInfo) {
                if ($activeInfo['card_code'] === $cardCode) {
                    $cardCheck = $this->pdo->prepare("SELECT status FROM cards WHERE card_code = ?");
                    $cardCheck->execute([$cardCode]);
                    $cardStatus = $cardCheck->fetchColumn();

                    if ($cardStatus === false) {
                        $this->pdo->prepare("DELETE FROM active_devices WHERE card_code = ?")->execute([$cardCode]);
                        return ['success' => false, 'message' => '卡密已失效'];
                    }
                    if ($cardStatus == 2) {
                        return ['success' => false, 'message' => '此卡密已被管理员封禁'];
                    }

                    $this->logUsage($activeInfo['card_code'], $activeInfo['card_type'], $deviceHash, $ip, $ua, '设备活跃', $appNameForLog);
                    return ['success' => true, 'message' => '设备已激活', 'expire_time' => $activeInfo['expire_time'], 'app_id' => $currentAppId];
                }
            }
            
            $cardStmt = $this->pdo->prepare("SELECT * FROM cards WHERE card_code = ? AND app_id = ?");
            $cardStmt->execute([$cardCode, $currentAppId]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$card) return ['success' => false, 'message' => '无效的卡密 (或不属于当前应用)'];
            if ($card['status'] == 2) return ['success' => false, 'message' => '此卡密已被管理员封禁'];
            
            if ($card['status'] == 1) {
                if (strtotime($card['expire_time']) <= time()) return ['success' => false, 'message' => '卡密已过期'];
                if (!empty($card['device_hash']) && $card['device_hash'] !== $deviceHash) return ['success' => false, 'message' => '卡密已绑定其他设备'];
                
                if ($card['device_hash'] !== $deviceHash) $this->pdo->prepare("UPDATE cards SET device_hash=? WHERE id=?")->execute([$deviceHash, $card['id']]);
                
                $this->pdo->prepare("REPLACE INTO active_devices (device_hash, card_code, card_type, expire_time, status, app_id) VALUES (?, ?, ?, ?, 1, ?)")->execute([$deviceHash, $cardCode, $card['card_type'], $card['expire_time'], $currentAppId]);
                return ['success' => true, 'message' => '验证通过', 'expire_time' => $card['expire_time'], 'app_id' => $currentAppId];
            } else {
                $duration = CARD_TYPES[$card['card_type']]['duration'] ?? 86400;
                $this->pdo->prepare("UPDATE cards SET status=1, device_hash=?, used_time=NOW(), expire_time=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?")->execute([$deviceHash, $duration, $card['id']]);
                
                $newExpStmt = $this->pdo->prepare("SELECT expire_time FROM cards WHERE id=?");
                $newExpStmt->execute([$card['id']]);
                $expireTime = $newExpStmt->fetchColumn();

                $this->pdo->prepare("INSERT INTO active_devices (device_hash, card_code, card_type, expire_time, status, app_id) VALUES (?, ?, ?, ?, 1, ?)")->execute([$deviceHash, $cardCode, $card['card_type'], $expireTime, $currentAppId]);
                $this->logUsage($cardCode, $card['card_type'], $deviceHash, $ip, $ua, '激活成功', $appNameForLog);
                return ['success' => true, 'message' => '首次激活成功', 'expire_time' => $expireTime, 'app_id' => $currentAppId];
            }
        }

        public function batchDeleteCards($ids) { 
            if (empty($ids)) return 0; 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $this->pdo->beginTransaction();
            try {
                if (!empty($codes)) {
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes);
                }
                $delStmt = $this->pdo->prepare("DELETE FROM cards WHERE id IN ($placeholders)");
                $delStmt->execute($ids);
                $count = $delStmt->rowCount();
                $this->pdo->commit();
                return $count;
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        public function cleanupExpiredCards() {
            $stmt = $this->pdo->query("SELECT id, card_code FROM cards WHERE status = 1 AND expire_time < NOW()");
            $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($cards)) return 0;

            $ids = array_column($cards, 'id');
            $codes = array_column($cards, 'card_code');

            $this->pdo->beginTransaction();
            try {
                if (!empty($codes)) {
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?'));
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes);
                }
                $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $this->pdo->prepare("DELETE FROM cards WHERE id IN ($idPlaceholders)")->execute($ids);
                $count = count($ids);
                $this->pdo->commit();
                return $count;
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        public function batchUnbindCards($ids) { 
            if (empty($ids)) return 0; 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $this->pdo->beginTransaction(); 
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders)"); 
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("DELETE FROM active_devices WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->prepare("UPDATE cards SET device_hash = NULL WHERE id IN ($placeholders)")->execute($ids); 
                $this->pdo->commit(); 
                return count($ids); 
            } catch (Exception $e) { $this->pdo->rollBack(); return 0; } 
        }
        
        public function batchAddTime($ids, $hours) { 
            if (empty($ids) || $hours <= 0) return 0; 
            $seconds = intval($hours * 3600); 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $this->pdo->beginTransaction(); 
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders) AND status = 1"); 
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("UPDATE cards SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE id IN ($placeholders) AND status = 1")->execute($ids); 
                    $this->pdo->prepare("UPDATE active_devices SET expire_time = DATE_ADD(expire_time, INTERVAL {$seconds} SECOND) WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->commit(); 
                return count($codes); 
            } catch (Exception $e) { $this->pdo->rollBack(); return 0; } 
        }

        public function batchSubTime($ids, $hours) { 
            if (empty($ids) || $hours <= 0) return 0; 
            $seconds = intval($hours * 3600); 
            $placeholders = implode(',', array_fill(0, count($ids), '?')); 
            $this->pdo->beginTransaction(); 
            try { 
                $stmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id IN ($placeholders) AND status = 1"); 
                $stmt->execute($ids); 
                $codes = $stmt->fetchAll(PDO::FETCH_COLUMN); 
                if($codes) { 
                    $codePlaceholders = implode(',', array_fill(0, count($codes), '?')); 
                    $this->pdo->prepare("UPDATE cards SET expire_time = DATE_SUB(expire_time, INTERVAL {$seconds} SECOND) WHERE id IN ($placeholders) AND status = 1")->execute($ids); 
                    $this->pdo->prepare("UPDATE active_devices SET expire_time = DATE_SUB(expire_time, INTERVAL {$seconds} SECOND) WHERE card_code IN ($codePlaceholders)")->execute($codes); 
                } 
                $this->pdo->commit(); 
                return count($codes); 
            } catch (Exception $e) { $this->pdo->rollBack(); return 0; } 
        }
        
        public function getCardsByIds($ids) { if(empty($ids)) return []; $placeholders = implode(',', array_fill(0, count($ids), '?')); $stmt = $this->pdo->prepare("SELECT * FROM cards WHERE id IN ($placeholders)"); $stmt->execute($ids); return $stmt->fetchAll(PDO::FETCH_ASSOC); }
        public function resetDeviceBindingByCardId($id) { return $this->batchUnbindCards([$id]); }
        
        public function updateCardStatus($id, $status) { 
            if ($status == 1) { 
                $check = $this->pdo->prepare("SELECT expire_time FROM cards WHERE id = ?"); 
                $check->execute([$id]); 
                $row = $check->fetch(PDO::FETCH_ASSOC); 
                if ($row && empty($row['expire_time'])) { $status = 0; } 
            } 
            $this->pdo->prepare("UPDATE cards SET status=? WHERE id=?")->execute([$status, $id]); 
            if ($status == 2) { 
                $codeStmt = $this->pdo->prepare("SELECT card_code FROM cards WHERE id = ?"); 
                $codeStmt->execute([$id]); 
                $code = $codeStmt->fetchColumn(); 
                if ($code) { $this->pdo->prepare("DELETE FROM active_devices WHERE card_code = ?")->execute([$code]); } 
            } 
        }
        
        public function getDashboardData() { 
            $total = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE app_id > 0")->fetchColumn(); 
            $unused = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE status = 0 AND app_id > 0")->fetchColumn(); 
            $used = $this->pdo->query("SELECT COUNT(*) FROM cards WHERE status = 1 AND app_id > 0")->fetchColumn(); 
            $active = $this->pdo->query("SELECT COUNT(*) FROM active_devices WHERE status = 1 AND expire_time > NOW() AND app_id > 0")->fetchColumn(); 
            $types = $this->pdo->query("SELECT card_type, COUNT(*) as count FROM cards WHERE app_id > 0 GROUP BY card_type")->fetchAll(PDO::FETCH_KEY_PAIR); 
            $appStats = $this->pdo->query("SELECT T2.app_name, COUNT(T1.id) as count FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE T1.app_id > 0 GROUP BY T1.app_id ORDER BY count DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC); 
            return ['stats' => ['total' => $total, 'unused' => $unused, 'used' => $used, 'active' => $active], 'chart_types' => $types, 'app_stats' => $appStats]; 
        }
        
        public function getTotalCardCount($statusFilter = null, $appId = null, $typeFilter = null) {
            $sql = "SELECT COUNT(*) FROM cards WHERE app_id > 0";
            $params = [];
            if ($statusFilter !== null) { $sql .= " AND status = ?"; $params[] = $statusFilter; }
            if ($appId !== null) { $sql .= " AND app_id = ?"; $params[] = $appId; }
            if ($typeFilter !== null) { $sql .= " AND card_type = ?"; $params[] = $typeFilter; }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        }
        
        public function getCardsPaginated($limit, $offset, $statusFilter = null, $appId = null, $typeFilter = null) {
            $sql = "SELECT T1.*, T2.app_name FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE 1=1 ";
            if ($statusFilter !== null) $sql .= "AND T1.status = :status ";
            if ($appId !== null) $sql .= "AND T1.app_id = :app_id ";
            if ($typeFilter !== null) $sql .= "AND T1.card_type = :type ";
            
            $sql .= "ORDER BY T1.create_time DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $this->pdo->prepare($sql);
            if ($statusFilter !== null) $stmt->bindValue(':status', $statusFilter, PDO::PARAM_INT);
            if ($appId !== null) $stmt->bindValue(':app_id', $appId, PDO::PARAM_INT);
            if ($typeFilter !== null) $stmt->bindValue(':type', $typeFilter, PDO::PARAM_STR);
            
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        public function searchCards($k) { 
            $s="%$k%"; 
            $q=$this->pdo->prepare("SELECT T1.*, T2.app_name FROM cards T1 JOIN applications T2 ON T1.app_id = T2.id WHERE (T1.card_code LIKE ? OR T1.notes LIKE ? OR T1.device_hash LIKE ? OR T2.app_name LIKE ? OR T1.card_type LIKE ?) AND T1.app_id > 0"); 
            $q->execute([$s,$s,$s,$s,$s]); 
            return $q->fetchAll(PDO::FETCH_ASSOC); 
        }

        public function getUsageLogs($l, $o) { $q=$this->pdo->prepare("SELECT * FROM usage_logs ORDER BY access_time DESC LIMIT ? OFFSET ?"); $q->bindValue(1,$l,PDO::PARAM_INT); $q->bindValue(2,$o,PDO::PARAM_INT); $q->execute(); return $q->fetchAll(PDO::FETCH_ASSOC); }
        public function getActiveDevices() { return $this->pdo->query("SELECT T1.*, T2.app_name FROM active_devices T1 JOIN applications T2 ON T1.app_id = T2.id WHERE T1.status=1 AND T1.expire_time > NOW() AND T1.app_id > 0 ORDER BY T1.activate_time DESC")->fetchAll(PDO::FETCH_ASSOC); }
        
        public function generateCards($count, $type, $pre, $suf, $len, $note, $appId) { 
            if(empty($appId) || $appId <= 0) throw new Exception("必须指定有效的应用 ID");
            $this->pdo->beginTransaction(); 
            $generatedCodes = [];
            try { 
                $stmt = $this->pdo->prepare("INSERT INTO cards (card_code, card_type, notes, app_id) VALUES (?, ?, ?, ?)"); 
                for ($i=0; $i<$count; $i++) { 
                    $code = $pre . $this->secureRandStr($len) . $suf; 
                    $stmt->execute([$code, $type, $note, $appId]); 
                    $generatedCodes[] = $code;
                } 
                $this->pdo->commit(); 
                return $generatedCodes;
            } catch(Exception $e) { 
                $this->pdo->rollBack(); 
                throw $e; 
            } 
        }

        public function deleteCard($id) { $this->batchDeleteCards([$id]); }
        public function getAdminHash() { return $this->pdo->query("SELECT password_hash FROM admin WHERE id=1")->fetchColumn(); }
        public function updateAdminPassword($pwd) { $this->pdo->prepare("UPDATE admin SET password_hash=? WHERE id=1")->execute([password_hash($pwd, PASSWORD_DEFAULT)]); }
        
        public function cleanupExpiredDevices() { $this->pdo->exec("UPDATE active_devices SET status=0 WHERE status=1 AND expire_time <= NOW()"); }
        private function logUsage($c, $t, $d, $i, $u, $r, $appName = 'System') { $this->pdo->prepare("INSERT INTO usage_logs (card_code, card_type, device_hash, ip_address, user_agent, result, app_name, access_time) VALUES (?,?,?,?,?,?,?,NOW())")->execute([$c,$t,$d,$i,$u,$r,$appName]); }
        
        private function secureRandStr($length) {
            $keyspace = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $str = '';
            $max = mb_strlen($keyspace, '8bit') - 1;
            for ($i = 0; $i < $length; ++$i) {
                $str .= $keyspace[random_int(0, $max)];
            }
            return $str;
        }

        // ==========================================
        // [新增] 完美系统迁移功能 - 导出与导入
        // ==========================================
        public function exportAllData() {
            $tables = ['applications', 'app_variables', 'cards', 'active_devices', 'usage_logs', 'blacklists', 'system_settings', 'admin'];
            $data = [];
            foreach ($tables as $table) {
                try {
                    $stmt = $this->pdo->query("SELECT * FROM {$table}");
                    $data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {}
            }
            return $data;
        }

        public function importAllData($data) {
            $tables = ['applications', 'app_variables', 'cards', 'active_devices', 'usage_logs', 'blacklists', 'system_settings', 'admin'];
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                foreach ($tables as $table) {
                    if (isset($data[$table]) && is_array($data[$table])) {
                        $this->pdo->exec("TRUNCATE TABLE {$table}");
                        if (!empty($data[$table])) {
                            $columns = array_keys($data[$table][0]);
                            $colStr = implode(',', array_map(function($c){ return "`$c`"; }, $columns));
                            $placeholders = implode(',', array_fill(0, count($columns), '?'));
                            $sql = "INSERT INTO {$table} ({$colStr}) VALUES ({$placeholders})";
                            $stmt = $this->pdo->prepare($sql);
                            foreach ($data[$table] as $row) {
                                $values = [];
                                foreach ($columns as $col) {
                                    $values[] = $row[$col];
                                }
                                $stmt->execute($values);
                            }
                        }
                    }
                }
                $this->pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                $this->pdo->commit();
                return true;
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }
    }
}
?>
