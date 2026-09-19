<?php
/**
 * Telegram Force-Join Bot - Complete index.php
 * Features: Force join 5 channels, verify button, send files,
 *           user stats, broadcast (text/photo/video), admin panel
 */

// ================== CONFIG ==================
define('BOT_TOKEN', '8665909582:AAGs4JqjB4CBhCeVY0Ns_X3KU5_AuqqtQXQ'); // <-- इसे revoke करके नया डालो

// Admin IDs
$ADMIN_IDS = [8980897228, 5997885135];

// Channels: चैनल की numeric ID और invite link दोनों डालो
// Numeric ID पाने के लिए: चैनल में कोई मैसेज @RawDataBot को फॉरवर्ड करो
// या बॉट को चैनल में एडमिन बनाकर: https://api.telegram.org/bot<TOKEN>/getChat?chat_id=@username
$CHANNELS = [
    ['id' => '-1000000000001', 'link' => 'https://t.me/+JQTJ0zj84ftlZDdl', 'name' => 'Channel 1'],
    ['id' => '-1000000000002', 'link' => 'https://t.me/+UxP0ioC9Kp00MjVl', 'name' => 'Channel 2'],
    ['id' => '-1000000000003', 'link' => 'https://t.me/+WftPXj9G49w2YmFl', 'name' => 'Channel 3'],
    ['id' => '-1000000000004', 'link' => 'https://t.me/+dhTIGNKd66BlMDVl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

// MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'telegram_bot');
define('DB_USER', 'root');
define('DB_PASS', '');

// ================== DB CONNECT ==================
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id BIGINT PRIMARY KEY,
        username VARCHAR(64) DEFAULT NULL,
        first_name VARCHAR(128) DEFAULT NULL,
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        verified TINYINT DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_type VARCHAR(16),
        file_id TEXT,
        caption TEXT,
        added_on DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT
    )");
} catch (Exception $e) {
    file_put_contents('bot_error.log', date('c').' DB: '.$e->getMessage()."\n", FILE_APPEND);
    exit;
}

// ================== TELEGRAM API ==================
function api($method, $params = []) {
    $url = "https://api.telegram.org/bot".BOT_TOKEN."/".$method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function isAdmin($uid) {
    global $ADMIN_IDS;
    return in_array((int)$uid, array_map('intval', $ADMIN_IDS), true);
}

// ================== CHANNEL CHECK ==================
function checkAllChannels($user_id) {
    global $CHANNELS;
    foreach ($CHANNELS as $ch) {
        $r = api('getChatMember', [
            'chat_id' => $ch['id'],
            'user_id' => $user_id
        ]);
        if (!isset($r['ok']) || !$r['ok']) return false;
        $status = $r['result']['status'] ?? '';
        if (!in_array($status, ['creator','administrator','member'])) return false;
    }
    return true;
}

// ================== UI ==================
function sendVerifyMessage($chat_id, $edit_message_id = null) {
    global $CHANNELS;
    $text = "🔒 <b>बॉट इस्तेमाल करने के लिए पहले नीचे दिए गए सभी चैनल जॉइन करें</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($CHANNELS as $ch) {
        $text .= "{$i}. {$ch['name']}\n";
        $kb[] = [['text' => "📢 Join ".$ch['name'], 'url' => $ch['link']]];
        $i++;
    }
    $kb[] = [['text' => "✅ Verify / मैंने जॉइन कर लिया", 'callback_data' => 'verify_join']];

    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb])
    ];
    if ($edit_message_id) {
        $params['message_id'] = $edit_message_id;
        return api('editMessageText', $params);
    }
    return api('sendMessage', $params);
}

function sendFiles($chat_id) {
    global $pdo;
    $rows = $pdo->query("SELECT * FROM files ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 अभी कोई फाइल उपलब्ध नहीं है। एडमिन से संपर्क करें।"]);
        return;
    }
    api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ वेरिफिकेशन सफल! आपकी फाइल भेजी जा रही है..."]);
    foreach ($rows as $f) {
        $p = ['chat_id' => $chat_id];
        if ($f['file_type'] === 'document') {
            $p['document'] = $f['file_id'];
            $p['caption'] = $f['caption'] ?: '';
            api('sendDocument', $p);
        } elseif ($f['file_type'] === 'video') {
            $p['video'] = $f['file_id'];
            $p['caption'] = $f['caption'] ?: '';
            api('sendVideo', $p);
        } elseif ($f['file_type'] === 'photo') {
            $p['photo'] = $f['file_id'];
            $p['caption'] = $f['caption'] ?: '';
            api('sendPhoto', $p);
        } elseif ($f['file_type'] === 'audio') {
            $p['audio'] = $f['file_id'];
            $p['caption'] = $f['caption'] ?: '';
            api('sendAudio', $p);
        } else {
            $p['document'] = $f['file_id'];
            api('sendDocument', $p);
        }
        usleep(400000);
    }
}

// ================== UPDATE HANDLER ==================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

// Callback
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $chat_id = $cq['message']['chat']['id'];
    $user_id = $cq['from']['id'];
    $msg_id = $cq['message']['message_id'];
    $data = $cq['data'] ?? '';

    if ($data === 'verify_join') {
        if (checkAllChannels($user_id)) {
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ वेरिफिकेशन सफल!']);
            $pdo->prepare("UPDATE users SET verified=1 WHERE user_id=?")->execute([$user_id]);
            api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            sendFiles($chat_id);
        } else {
            api('answerCallbackQuery', [
                'callback_query_id' => $cq['id'],
                'text' => '❌ कृपया पहले सभी चैनल जॉइन करें!',
                'show_alert' => true
            ]);
        }
    } elseif ($data === 'admin_stats') {
        if (!isAdmin($user_id)) exit;
        $total = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $verified = $pdo->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn();
        $today = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(first_seen)=CURDATE()")->fetchColumn();
        $files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
        api('sendMessage', [
            'chat_id' => $chat_id,
            'parse_mode' => 'HTML',
            'text' => "📊 <b>बॉट स्टेटिस्टिक्स</b>\n\n"
                ."👥 कुल यूज़र्स: <b>{$total}</b>\n"
                ."✅ वेरिफाइड: <b>{$verified}</b>\n"
                ."📅 आज जुड़े: <b>{$today}</b>\n"
                ."📁 कुल फाइल्स: <b>{$files}</b>"
        ]);
    }
    exit;
}

// Message
if (!isset($update['message'])) exit;
$msg = $update['message'];
$chat_id = $msg['chat']['id'];
$user_id = $msg['from']['id'];
$text = $msg['text'] ?? '';
$from = $msg['from'];

// Save user
$pdo->prepare("INSERT IGNORE INTO users (user_id, username, first_name) VALUES (?,?,?)")
    ->execute([$user_id, $from['username'] ?? null, $from['first_name'] ?? null]);

// ---- ADMIN COMMANDS ----
if (isAdmin($user_id)) {

    if ($text === '/admin') {
        $kb = [
            [['text' => '📊 स्टेटिस्टिक्स', 'callback_data' => 'admin_stats']],
        ];
        api('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🛠️ <b>एडमिन पैनल</b>\n\nकमांड्स:\n"
                ."/stats - यूज़र काउंट\n"
                ."/broadcast <msg> - सबको टेक्स्ट भेजो\n"
                ."/addfile - फाइल जोड़ने के निर्देश\n"
                ."/listfiles - सभी फाइल्स\n"
                ."/delfile <id> - फाइल डिलीट",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $kb])
        ]);
        exit;
    }

    if ($text === '/stats') {
        $total = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $verified = $pdo->query("SELECT COUNT(*) FROM users WHERE verified=1")->fetchColumn();
        $today = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(first_seen)=CURDATE()")->fetchColumn();
        $files = $pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
        api('sendMessage', [
            'chat_id' => $chat_id,
            'parse_mode' => 'HTML',
            'text' => "📊 <b>स्टेटिस्टिक्स</b>\n\n👥 कुल: <b>{$total}</b>\n✅ वेरिफाइड: <b>{$verified}</b>\n📅 आज: <b>{$today}</b>\n📁 फाइल्स: <b>{$files}</b>"
        ]);
        exit;
    }

    if (strpos($text, '/broadcast') === 0) {
        $broadcast_msg = trim(substr($text, strlen('/broadcast')));
        if ($broadcast_msg === '') {
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "उपयोग: /broadcast आपका मैसेज"]);
            exit;
        }
        $users = $pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $ok = 0; $fail = 0;
        foreach ($users as $uid) {
            $r = api('sendMessage', ['chat_id' => $uid, 'text' => $broadcast_msg]);
            if (!empty($r['ok'])) $ok++; else $fail++;
            usleep(60000);
        }
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ सफल: $ok\n❌ फेल: $fail"]);
        exit;
    }

    if ($text === '/addfile') {
        api('sendMessage', [
            'chat_id' => $chat_id,
            'parse_mode' => 'HTML',
            'text' => "📁 <b>फाइल जोड़ने का तरीका</b>\n\n"
                ."1. कोई भी video/document/photo इस बॉट को भेजो\n"
                ."2. उसे <b>reply</b> करके लिखो:\n<code>/save caption यहाँ लिखो</code>\n\n"
                ."बस, फाइल सेव हो जाएगी और users को मिलेगी।"
        ]);
        exit;
    }

    if ($text === '/listfiles') {
        $rows = $pdo->query("SELECT * FROM files ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "कोई फाइल नहीं है।"]);
        } else {
            $out = "📁 <b>फाइल्स लिस्ट</b>\n\n";
            foreach ($rows as $r) {
                $out .= "#{$r['id']} [{$r['file_type']}] ".mb_substr($r['caption'] ?: '-', 0, 40)."\n";
            }
            $out .= "\nडिलीट: /delfile ID";
            api('sendMessage', ['chat_id' => $chat_id, 'text' => $out, 'parse_mode' => 'HTML']);
        }
        exit;
    }

    if (strpos($text, '/delfile') === 0) {
        $id = (int)trim(substr($text, strlen('/delfile')));
        if ($id > 0) {
            $pdo->prepare("DELETE FROM files WHERE id=?")->execute([$id]);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "🗑️ फाइल #$id डिलीट हो गई।"]);
        }
        exit;
    }

    // /save — reply to media
    if (strpos($text, '/save') === 0 && isset($msg['reply_to_message'])) {
        $rt = $msg['reply_to_message'];
        $caption = trim(substr($text, strlen('/save')));
        $type = null; $fid = null;
        if (isset($rt['document'])) { $type = 'document'; $fid = $rt['document']['file_id']; }
        elseif (isset($rt['video'])) { $type = 'video'; $fid = $rt['video']['file_id']; }
        elseif (isset($rt['photo'])) { $type = 'photo'; $fid = end($rt['photo'])['file_id']; }
        elseif (isset($rt['audio'])) { $type = 'audio'; $fid = $rt['audio']['file_id']; }

        if ($type && $fid) {
            $pdo->prepare("INSERT INTO files (file_type, file_id, caption) VALUES (?,?,?)")
                ->execute([$type, $fid, $caption]);
            $new_id = $pdo->lastInsertId();
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ फाइल सेव हो गई! ID: #$new_id"]);
        } else {
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ मीडिया नहीं मिली। किसी video/document/photo को reply करके /save लिखो।"]);
        }
        exit;
    }
}

// ---- NORMAL USER ----
if ($text === '/start' || $text === '/verify') {
    if (checkAllChannels($user_id)) {
        sendFiles($chat_id);
    } else {
        sendVerifyMessage($chat_id);
    }
    exit;
}

// किसी और मैसेज पर भी वेरिफाई चेक
if (checkAllChannels($user_id)) {
    sendFiles($chat_id);
} else {
    sendVerifyMessage($chat_id);
}
