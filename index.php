<?php
/**
 * ==========================================================
 *   TELEGRAM FORCE-JOIN FILE DELIVERY BOT  (Professional)
 * ==========================================================
 *   Features:
 *   - Admin adds unlimited files in a batch (reply /save)
 *   - /dn finalizes the batch and generates ONE share link
 *   - Users click link -> force join 5 channels -> verify -> get files
 *   - /stats, /broadcast, /listlinks, /deltlink
 *   - Nothing auto-deletes. All data persists in MySQL.
 *   - Full error logging, safe against crashes.
 * ==========================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_error.log');

// ================== CONFIG ==================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8665909582:AAGs4JqjB4CBhCeVY0Ns_X3KU5_AuqqtQXQ');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'bmwytxh4ckbot');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'change_this_secret_123');

$ADMIN_IDS = [8980897228, 5997885135];

// Channels: 'id' => numeric ID ya @username, 'link' => invite link
$CHANNELS = [
    ['id' => '-1000000000001', 'link' => 'https://t.me/+JQTJ0zj84ftlZDdl', 'name' => 'Channel 1'],
    ['id' => '-1000000000002', 'link' => 'https://t.me/+UxP0ioC9Kp00MjVl', 'name' => 'Channel 2'],
    ['id' => '-1000000000003', 'link' => 'https://t.me/+WftPXj9G49w2YmFl', 'name' => 'Channel 3'],
    ['id' => '-1000000000004', 'link' => 'https://t.me/+dhTIGNKd66BlMDVl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

// ================== DATABASE ==================
$DB_HOST = getenv('MYSQLHOST')     ?: getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
$DB_USER = getenv('MYSQLUSER')     ?: getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
$DB_PORT = getenv('MYSQLPORT')     ?: '3306';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id BIGINT PRIMARY KEY,
        username VARCHAR(64) DEFAULT NULL,
        first_name VARCHAR(128) DEFAULT NULL,
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        verified TINYINT DEFAULT 0,
        INDEX idx_verified (verified)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(40) UNIQUE,
        title VARCHAR(255) DEFAULT NULL,
        created_by BIGINT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('draft','published') DEFAULT 'draft',
        INDEX idx_token (token),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT,
        file_type VARCHAR(16),
        file_id TEXT,
        caption TEXT,
        added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_batch (batch_id),
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_state (
        admin_id BIGINT PRIMARY KEY,
        active_batch INT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (Exception $e) {
    log_err('DB: ' . $e->getMessage());
    http_response_code(200);
    exit;
}

// ================== HELPERS ==================
function log_err($msg) {
    @file_put_contents(__DIR__ . '/bot_error.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function api($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { log_err("cURL $method: $err"); return null; }
    $json = json_decode($res, true);
    if (!$json || empty($json['ok'])) {
        log_err("API $method failed: " . substr($res, 0, 400));
    }
    return $json;
}

function isAdmin($uid) {
    global $ADMIN_IDS;
    return in_array((int)$uid, array_map('intval', $ADMIN_IDS), true);
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return $scheme . '://' . $host . $path;
}

// ================== CHANNEL CHECK ==================
function checkAllChannels($user_id) {
    global $CHANNELS;
    foreach ($CHANNELS as $ch) {
        $r = api('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        if (!$r || empty($r['ok'])) return false;
        $status = $r['result']['status'] ?? '';
        if (!in_array($status, ['creator', 'administrator', 'member'], true)) return false;
    }
    return true;
}

// ================== ADMIN BATCH FUNCTIONS ==================
function getActiveBatch($admin_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT active_batch FROM admin_state WHERE admin_id=?");
    $stmt->execute([$admin_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['active_batch']) return null;

    $stmt = $pdo->prepare("SELECT * FROM batches WHERE id=? AND status='draft'");
    $stmt->execute([$row['active_batch']]);
    return $stmt->fetch() ?: null;
}

function createActiveBatch($admin_id, $title = null) {
    global $pdo;
    $token = bin2hex(random_bytes(10));
    $pdo->prepare("INSERT INTO batches (token, title, created_by, status) VALUES (?,?,'draft')")
        ->execute([$token, $title]);
    $batch_id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO admin_state (admin_id, active_batch) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE active_batch=VALUES(active_batch)")
        ->execute([$admin_id, $batch_id]);

    return $batch_id;
}

function addFileToBatch($batch_id, $type, $file_id, $caption) {
    global $pdo;
    $pdo->prepare("INSERT INTO files (batch_id, file_type, file_id, caption) VALUES (?,?,?,?)")
        ->execute([$batch_id, $type, $file_id, $caption]);
    return (int)$pdo->lastInsertId();
}

function batchFileCount($batch_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE batch_id=?");
    $stmt->execute([$batch_id]);
    return (int)$stmt->fetchColumn();
}

// ================== SEND FILES ==================
function sendFilesByBatch($chat_id, $batch_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM files WHERE batch_id=? ORDER BY id ASC");
    $stmt->execute([$batch_id]);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 इस लिंक में अभी कोई फाइल नहीं है।"]);
        return;
    }

    api('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "✅ <b>वेरिफिकेशन सफल!</b>\n\n📦 कुल फाइलें: <b>" . count($rows) . "</b>\nनीचे भेजी जा रही हैं...",
        'parse_mode' => 'HTML'
    ]);

    foreach ($rows as $f) {
        $p = ['chat_id' => $chat_id, 'caption' => $f['caption'] ?: ''];
        switch ($f['file_type']) {
            case 'document': $p['document'] = $f['file_id']; api('sendDocument', $p); break;
            case 'video':    $p['video'] = $f['file_id'];    api('sendVideo', $p);    break;
            case 'photo':    $p['photo'] = $f['file_id'];    api('sendPhoto', $p);    break;
            case 'audio':    $p['audio'] = $f['file_id'];    api('sendAudio', $p);    break;
            default:         $p['document'] = $f['file_id']; api('sendDocument', $p);
        }
        usleep(400000); // rate limit safe
    }
}

// ================== WEBHOOK SECURITY ==================
if (isset($_GET['set']) && $_GET['set'] === WEBHOOK_SECRET) {
    $hook = baseUrl();
    $r = api('setWebhook', ['url' => $hook, 'secret_token' => WEBHOOK_SECRET]);
    header('Content-Type: application/json');
    echo json_encode($r);
    exit;
}

$secret_header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (WEBHOOK_SECRET !== 'change_this_secret_123' && $secret_header !== WEBHOOK_SECRET) {
    http_response_code(200);
    exit;
}

// ================== GET UPDATE ==================
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) exit;

try {
    handleUpdate($update);
} catch (Throwable $e) {
    log_err('Handler: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// ================== MAIN HANDLER ==================
function handleUpdate($update) {
    global $pdo;

    // ---------- CALLBACK QUERY ----------
    if (isset($update['callback_query'])) {
        $cq       = $update['callback_query'];
        $chat_id  = $cq['message']['chat']['id'];
        $msg_id   = $cq['message']['message_id'];
        $user_id  = $cq['from']['id'];
        $data     = $cq['data'] ?? '';

        // verify button
        if (strpos($data, 'verify:') === 0) {
            $token = substr($data, 7);
            if (checkAllChannels($user_id)) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ वेरिफिकेशन सफल!']);

                $pdo->prepare("UPDATE users SET verified=1 WHERE user_id=?")->execute([$user_id]);

                $stmt = $pdo->prepare("SELECT id FROM batches WHERE token=? AND status='published'");
                $stmt->execute([$token]);
                $batch = $stmt->fetch();

                if ($batch) {
                    api('editMessageText', [
                        'chat_id' => $chat_id,
                        'message_id' => $msg_id,
                        'text' => "✅ वेरिफिकेशन पूरा! फाइलें नीचे भेजी जा रही हैं...",
                        'parse_mode' => 'HTML'
                    ]);
                    sendFilesByBatch($chat_id, $batch['id']);
                } else {
                    api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ लिंक अमान्य है।', 'show_alert' => true]);
                }
            } else {
                api('answerCallbackQuery', [
                    'callback_query_id' => $cq['id'],
                    'text' => '❌ कृपया पहले सभी 5 चैनल जॉइन करें!',
                    'show_alert' => true
                ]);
            }
            return;
        }

        // admin quick stats
        if ($data === 'admin_stats' && isAdmin($user_id)) {
            $stats = getStats();
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => formatStats($stats)
            ]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }
        return;
    }

    // ---------- MESSAGE ----------
    if (!isset($update['message'])) return;
    $msg      = $update['message'];
    $chat_id  = $msg['chat']['id'];
    $user_id  = $msg['from']['id'];
    $text     = $msg['text'] ?? '';
    $from     = $msg['from'];

    // Save / update user
    $pdo->prepare("INSERT INTO users (user_id, username, first_name) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE username=VALUES(username), first_name=VALUES(first_name)")
        ->execute([$user_id, $from['username'] ?? null, $from['first_name'] ?? null]);

    // ================= ADMIN COMMANDS =================
    if (isAdmin($user_id)) {

        // /admin
        if ($text === '/admin' || $text === '/start') {
            $active = getActiveBatch($user_id);
            $msgTxt = "🛠️ <b>एडमिन पैनल</b>\n\n";
            if ($active) {
                $cnt = batchFileCount($active['id']);
                $msgTxt .= "📝 <b>एक्टिव बैच:</b> #{$active['id']}\n";
                $msgTxt .= "📁 जोड़ी गई फाइलें: <b>{$cnt}</b>\n\n";
                $msgTxt .= "फाइल भेजने के लिए उसे <b>reply</b> करके लिखो:\n<code>/save caption</code>\n\n";
                $msgTxt .= "सब हो जाने पर: <code>/dn</code> — लिंक बनेगा\n";
                $msgTxt .= "कैंसिल: <code>/cancel</code>";
            } else {
                $msgTxt .= "कोई एक्टिव बैच नहीं है।\n\n";
                $msgTxt .= "नया बैच शुरू करने के लिए: <code>/new</code>\n\n";
                $msgTxt .= "<b>अन्य कमांड्स:</b>\n";
                $msgTxt .= "/stats - आंकड़े\n";
                $msgTxt .= "/broadcast &lt;msg&gt; - सबको भेजो\n";
                $msgTxt .= "/listlinks - सभी लिंक\n";
                $msgTxt .= "/deltlink &lt;token&gt; - लिंक डिलीट";
            }
            api('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $msgTxt,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[['text' => '📊 स्टेटिस्टिक्स', 'callback_data' => 'admin_stats']]]
                ])
            ]);
            return;
        }

        // /new
        if ($text === '/new') {
            $existing = getActiveBatch($user_id);
            if ($existing) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ पहले वाला बैच #{$existing['id']} अभी खुला है। पहले /dn या /cancel करो।"]);
                return;
            }
            $bid = createActiveBatch($user_id);
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "✅ <b>नया बैच #{$bid} शुरू हुआ</b>\n\n"
                    . "अब फाइलें भेजो और हर फाइल पर <b>reply</b> करके लिखो:\n"
                    . "<code>/save तुम्हारा कैप्शन</code>\n\n"
                    . "जितनी चाहो फाइलें जोड़ो (1 या 10, कोई लिमिट नहीं)।\n"
                    . "सब हो जाने पर: <code>/dn</code>"
            ]);
            return;
        }

        // /cancel
        if ($text === '/cancel') {
            $active = getActiveBatch($user_id);
            if ($active) {
                $pdo->prepare("DELETE FROM files WHERE batch_id=?")->execute([$active['id']]);
                $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$active['id']]);
                $pdo->prepare("UPDATE admin_state SET active_batch=NULL WHERE admin_id=?")->execute([$user_id]);
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "🗑️ बैच #{$active['id']} कैंसिल कर दिया।"]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "कोई एक्टिव बैच नहीं है।"]);
            }
            return;
        }

        // /save (reply to media)
        if (strpos($text, '/save') === 0 && isset($msg['reply_to_message'])) {
            $active = getActiveBatch($user_id);
            if (!$active) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ पहले /new से बैच शुरू करो।"]);
                return;
            }
            $rt = $msg['reply_to_message'];
            $caption = trim(substr($text, strlen('/save')));
            $type = null; $fid = null;

            if (isset($rt['document']))     { $type = 'document'; $fid = $rt['document']['file_id']; }
            elseif (isset($rt['video']))    { $type = 'video';    $fid = $rt['video']['file_id']; }
            elseif (isset($rt['photo']))    { $type = 'photo';    $fid = end($rt['photo'])['file_id']; }
            elseif (isset($rt['audio']))    { $type = 'audio';    $fid = $rt['audio']['file_id']; }

            if ($type && $fid) {
                $newid = addFileToBatch($active['id'], $type, $fid, $caption);
                $cnt = batchFileCount($active['id']);
                api('sendMessage', [
                    'chat_id' => $chat_id,
                    'parse_mode' => 'HTML',
                    'text' => "✅ फाइल जुड़ी (ID #{$newid})\n"
                        . "📁 बैच #{$active['id']} में कुल: <b>{$cnt}</b> फाइलें\n\n"
                        . "और जोड़ो, या <code>/dn</code> से लिंक बनाओ।"
                ]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ मीडिया नहीं मिली। किसी video/document/photo को reply करके /save लिखो।"]);
            }
            return;
        }

        // /dn — finalize batch, produce link
        if ($text === '/dn') {
            $active = getActiveBatch($user_id);
            if (!$active) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ कोई एक्टिव बैच नहीं। /new से शुरू करो।"]);
                return;
            }
            $cnt = batchFileCount($active['id']);
            if ($cnt === 0) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ बैच खाली है। पहले /save से फाइलें जोड़ो।"]);
                return;
            }

            $pdo->prepare("UPDATE batches SET status='published' WHERE id=?")->execute([$active['id']]);
            $pdo->prepare("UPDATE admin_state SET active_batch=NULL WHERE admin_id=?")->execute([$user_id]);

            $bot_link = "https://t.me/" . BOT_USERNAME . "?start=" . $active['token'];

            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'text' => "🎉 <b>लिंक तैयार है!</b>\n\n"
                    . "🆔 बैच: #{$active['id']}\n"
                    . "📁 फाइलें: <b>{$cnt}</b>\n\n"
                    . "🔗 <b>शेयर लिंक:</b>\n<code>{$bot_link}</code>\n\n"
                    . "इस लिंक को कहीं भी शेयर करो। जो भी खोलेगा, उसे पहले 5 चैनल जॉइन करने होंगे, फिर verify करते ही सारी {$cnt} फाइलें मिल जाएँगी।"
            ]);
            return;
        }

        // /listlinks
        if ($text === '/listlinks') {
            $rows = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM files f WHERE f.batch_id=b.id) AS cnt
                                 FROM batches b WHERE status='published' ORDER BY id DESC LIMIT 50")->fetchAll();
            if (!$rows) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "कोई पब्लिश्ड लिंक नहीं है।"]);
                return;
            }
            $out = "🔗 <b>पब्लिश्ड लिंक्स</b>\n\n";
            foreach ($rows as $r) {
                $link = "https://t.me/" . BOT_USERNAME . "?start=" . $r['token'];
                $out .= "#{$r['id']} ({$r['cnt']} files)\n<code>{$link}</code>\n\n";
            }
            $out .= "डिलीट: <code>/deltlink TOKEN</code>";
            api('sendMessage', ['chat_id' => $chat_id, 'text' => $out, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            return;
        }

        // /deltlink
        if (strpos($text, '/deltlink') === 0) {
            $token = trim(substr($text, strlen('/deltlink')));
            if ($token === '') {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "उपयोग: /deltlink TOKEN"]);
                return;
            }
            $stmt = $pdo->prepare("SELECT id FROM batches WHERE token=?");
            $stmt->execute([$token]);
            $b = $stmt->fetch();
            if (!$b) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ ऐसा कोई लिंक नहीं मिला।"]);
                return;
            }
            $pdo->prepare("DELETE FROM files WHERE batch_id=?")->execute([$b['id']]);
            $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$b['id']]);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "🗑️ लिंक डिलीट हो गया।"]);
            return;
        }

        // /stats
        if ($text === '/stats') {
            api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML', 'text' => formatStats(getStats())]);
            return;
        }

        // /broadcast
        if (strpos($text, '/broadcast') === 0) {
            $bmsg = trim(substr($text, strlen('/broadcast')));
            if ($bmsg === '') {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "उपयोग: /broadcast तुम्हारा मैसेज"]);
                return;
            }
            $users = $pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $ok = 0; $fail = 0;
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "📤 ब्रॉडकास्ट शुरू (" . count($users) . " यूज़र्स)..."]);
            foreach ($users as $uid) {
                $r = api('sendMessage', ['chat_id' => $uid, 'text' => $bmsg]);
                if ($r && !empty($r['ok'])) $ok++; else $fail++;
                usleep(60000);
            }
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ब्रॉडकास्ट पूरा\nसफल: {$ok}\nफेल: {$fail}"]);
            return;
        }

        if ($text === '/help') {
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "🛠️ <b>एडमिन कमांड्स</b>\n\n"
                    . "/admin - पैनल\n/new - नया बैच\n/save - (reply) फाइल जोड़ो\n/dn - लिंक बनाओ\n/cancel - बैच कैंसिल\n"
                    . "/listlinks - सभी लिंक\n/deltlink TOKEN - लिंक डिलीट\n/stats - आंकड़े\n/broadcast msg - सबको भेजो"
            ]);
            return;
        }
        // admin ने कोई और मैसेज भेजा तो भी कुछ नहीं करना
        return;
    }

    // ================= USER SIDE =================
    // /start के साथ deep-link token:  /start <token>
    $token = null;
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', trim($text), 2);
        if (isset($parts[1])) $token = trim($parts[1]);
    } elseif ($text === '/verify') {
        // पुराने मैसेज से token निकालने की जरूरत नहीं — यहाँ verify मैसेज पर बटन ही होगा
    }

    if ($token) {
        $stmt = $pdo->prepare("SELECT * FROM batches WHERE token=? AND status='published'");
        $stmt->execute([$token]);
        $batch = $stmt->fetch();

        if (!$batch) {
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ यह लिंक अमान्य है या डिलीट हो चुका है।"]);
            return;
        }

        if (checkAllChannels($user_id)) {
            $pdo->prepare("UPDATE users SET verified=1 WHERE user_id=?")->execute([$user_id]);
            sendFilesByBatch($chat_id, $batch['id']);
        } else {
            sendVerifyMessage($chat_id, $token);
        }
        return;
    }

    if ($text === '/start' || $text === '/verify') {
        api('sendMessage', [
            'chat_id' => $chat_id,
            'parse_mode' => 'HTML',
            'text' => "👋 <b>स्वागत है!</b>\n\nफाइलें पाने के लिए मुझे कोई शेयर लिंक से खोलो।"
        ]);
        return;
    }

    // कोई और मैसेज → friendly reply
    api('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "फाइल पाने के लिए शेयर लिंक खोलो। कुछ और मदद चाहिए तो एडमिन से संपर्क करें।"
    ]);
}

// ================== VERIFY MESSAGE ==================
function sendVerifyMessage($chat_id, $token) {
    global $CHANNELS;
    $text = "🔒 <b>फाइलें पाने के लिए पहले नीचे दिए गए सभी चैनल जॉइन करें</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($CHANNELS as $ch) {
        $text .= "{$i}. " . esc($ch['name']) . "\n";
        $kb[] = [['text' => "📢 Join " . $ch['name'], 'url' => $ch['link']]];
        $i++;
    }
    $kb[] = [['text' => "✅ Verify / मैंने जॉइन कर लिया", 'callback_data' => 'verify:' . $token]];

    api('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb])
    ]);
}

// ================== STATS ==================
function getStats() {
    global $pdo;
    return [
        'total'    => (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'verified' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn(),
        'today'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(first_seen)=CURDATE()")->fetchColumn(),
        'batches'  => (int)$pdo->query("SELECT COUNT(*) FROM batches WHERE status='published'")->fetchColumn(),
        'files'    => (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn(),
    ];
}

function formatStats($s) {
    return "📊 <b>बॉट स्टेटिस्टिक्स</b>\n\n"
        . "👥 कुल यूज़र्स: <b>{$s['total']}</b>\n"
        . "✅ वेरिफाइड: <b>{$s['verified']}</b>\n"
        . "📅 आज जुड़े: <b>{$s['today']}</b>\n"
        . "🔗 पब्लिश्ड लिंक्स: <b>{$s['batches']}</b>\n"
        . "📁 कुल फाइलें: <b>{$s['files']}</b>";
}
