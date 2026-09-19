<?php
/**
 * Telegram Force-Join Bot - Professional Admin Panel
 * Features:
 *  - Inline button based admin panel
 *  - Add/Delete channels dynamically
 *  - Upload files (auto-detect forwarded media)
 *  - /dn generates share link
 *  - User: force join -> verify -> files delivered
 *  - Already verified users get files instantly
 *  - Broadcast with text/photo/video support
 *  - Nothing auto-deletes
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ================== CONFIG ==================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YAHAN_APNA_TOKEN');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'bmwytxh4ckbot');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'bmwytx2024');

$ADMIN_IDS = [8980897228, 5997885135];

// Default 5 channels — inhe admin panel se delete nahi kar sakte
$DEFAULT_CHANNELS = [
    ['id' => '-1000000000001', 'link' => 'https://t.me/+JQTJ0zj84ftlZDdl', 'name' => 'Channel 1'],
    ['id' => '-1000000000002', 'link' => 'https://t.me/+UxP0ioC9Kp00MjVl', 'name' => 'Channel 2'],
    ['id' => '-1000000000003', 'link' => 'https://t.me/+WftPXj9G49w2YmFl', 'name' => 'Channel 3'],
    ['id' => '-1000000000004', 'link' => 'https://t.me/+dhTIGNKd66BlMDVl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);

// ================== JSON HELPERS ==================
function jload($file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function jsave($file, $data) {
    file_put_contents(DATA_DIR . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Channels load — merge default + extra, extra deletable
function getChannels() {
    global $DEFAULT_CHANNELS;
    $extra = jload('channels.json');
    return array_merge($DEFAULT_CHANNELS, $extra);
}

// ================== TELEGRAM API ==================
function api($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function isAdmin($uid) {
    global $ADMIN_IDS;
    return in_array((int)$uid, array_map('intval', $ADMIN_IDS), true);
}

function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}

// ================== CHANNEL CHECK ==================
function checkAllChannels($user_id) {
    $channels = getChannels();
    if (!$channels) return true;
    foreach ($channels as $ch) {
        $r = api('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        if (!$r || empty($r['ok'])) return false;
        $status = $r['result']['status'] ?? '';
        if (!in_array($status, ['creator', 'administrator', 'member'], true)) return false;
    }
    return true;
}

// ================== WEBHOOK SETUP ==================
if (isset($_GET['set']) && $_GET['set'] === WEBHOOK_SECRET) {
    $r = api('setWebhook', ['url' => baseUrl() . '/index.php']);
    header('Content-Type: application/json');
    echo json_encode($r, JSON_PRETTY_PRINT);
    exit;
}
if (isset($_GET['info'])) { header('Content-Type: application/json'); echo json_encode(api('getWebhookInfo'), JSON_PRETTY_PRINT); exit; }
if (isset($_GET['ping'])) { echo "OK - Bot is alive"; exit; }

// ================== USERS ==================
function saveUser($user_id, $from) {
    $users = jload('users.json');
    $users[$user_id] = [
        'user_id' => $user_id,
        'username' => $from['username'] ?? '',
        'first_name' => $from['first_name'] ?? '',
        'first_seen' => $users[$user_id]['first_seen'] ?? date('Y-m-d H:i:s'),
        'verified' => $users[$user_id]['verified'] ?? 0,
    ];
    jsave('users.json', $users);
}

function markVerified($user_id) {
    $users = jload('users.json');
    if (isset($users[$user_id])) { $users[$user_id]['verified'] = 1; jsave('users.json', $users); }
}

// ================== BATCH HELPERS ==================
function getActiveBatch($admin_id) {
    $state = jload('state.json');
    return $state[$admin_id]['active_batch'] ?? null;
}
function setActiveBatch($admin_id, $bid) {
    $state = jload('state.json');
    if ($bid === null) unset($state[$admin_id]['active_batch']);
    else $state[$admin_id]['active_batch'] = $bid;
    jsave('state.json', $state);
}
function createBatch($admin_id) {
    $batches = jload('batches.json');
    $new_id = (count($batches) > 0 ? max(array_map('intval', array_keys($batches))) + 1 : 1);
    $batches[$new_id] = [
        'id' => $new_id,
        'token' => bin2hex(random_bytes(8)),
        'created_by' => $admin_id,
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'draft',
        'files' => []
    ];
    jsave('batches.json', $batches);
    return $new_id;
}

// ================== UI BUILDERS ==================
function mainMenuKeyboard() {
    return json_encode(['inline_keyboard' => [
        [['text' => '📢 चैनल मैनेज करें', 'callback_data' => 'menu_channels']],
        [['text' => '📁 नया बैच / फाइल जोड़ें', 'callback_data' => 'menu_upload']],
        [['text' => '🔗 सभी लिंक', 'callback_data' => 'menu_links']],
        [['text' => '📊 स्टेटिस्टिक्स', 'callback_data' => 'menu_stats']],
        [['text' => '📢 ब्रॉडकास्ट', 'callback_data' => 'menu_broadcast']],
    ]]);
}

function showMainMenu($chat_id, $edit_id = null) {
    $text = "🛠️ <b>एडमिन पैनल</b>\n\nनीचे से कोई option चुनो:";
    $kb = mainMenuKeyboard();
    if ($edit_id) {
        api('editMessageText', ['chat_id' => $chat_id, 'message_id' => $edit_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
    } else {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
    }
}

function showChannelsMenu($chat_id, $edit_id = null) {
    $channels = getChannels();
    $text = "📢 <b>चैनल मैनेजमेंट</b>\n\n";
    $text .= "कुल चैनल: <b>" . count($channels) . "</b>\n\n";
    $text .= "पहले 5 default हैं (डिलीट नहीं हो सकते)।\nबाकी extra हैं जिन्हें डिलीट किया जा सकता है।\n\n";
    $text .= "नया चैनल जोड़ने के लिए नीचे <b>➕ चैनल जोड़ें</b> दबाओ।";

    $kb = [];
    foreach ($channels as $idx => $ch) {
        $kb[] = [['text' => "🗑️ " . $ch['name'], 'callback_data' => 'delch:' . $idx]];
    }
    $kb[] = [['text' => '➕ चैनल जोड़ें', 'callback_data' => 'addch']];
    $kb[] = [['text' => '◀️ वापस', 'callback_data' => 'menu_main']];

    $params = [
        'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
        'disable_web_page_preview' => true
    ];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

function showUploadMenu($chat_id, $admin_id, $edit_id = null) {
    $active = getActiveBatch($admin_id);
    if ($active === null) {
        $text = "📁 <b>फाइल अपलोड</b>\n\nकोई एक्टिव बैच नहीं है। नया बैच शुरू करने के लिए नीचे दबाओ।";
        $kb = [
            [['text' => '🆕 नया बैच शुरू करें', 'callback_data' => 'newbatch']],
            [['text' => '◀️ वापस', 'callback_data' => 'menu_main']],
        ];
    } else {
        $batches = jload('batches.json');
        $cnt = count($batches[$active]['files'] ?? []);
        $text = "📁 <b>एक्टिव बैच #{$active}</b>\n\n";
        $text .= "अब तक जोड़ी गई फाइलें: <b>{$cnt}</b>\n\n";
        $text .= "कैसे फाइल जोड़ें:\n";
        $text .= "1. बॉट को कोई video/photo/document भेजो\n";
        $text .= "2. उस पर reply करके <code>/save caption</code> लिखो\n\n";
        $text .= "जब सब हो जाए, तो नीचे <b>✅ DN (लिंक बनाओ)</b> दबाओ।";

        $kb = [
            [['text' => '✅ DN (लिंक बनाओ)', 'callback_data' => 'finishbatch']],
            [['text' => '❌ बैच कैंसिल करें', 'callback_data' => 'cancelbatch']],
            [['text' => '◀️ वापस', 'callback_data' => 'menu_main']],
        ];
    }
    $params = [
        'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $kb])
    ];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

function showLinksMenu($chat_id, $edit_id = null) {
    $batches = jload('batches.json');
    $published = array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published');
    if (!$published) {
        $text = "🔗 <b>सभी लिंक</b>\n\nकोई लिंक नहीं बना अभी।";
        $kb = [[['text' => '◀️ वापस', 'callback_data' => 'menu_main']]];
    } else {
        $text = "🔗 <b>सभी पब्लिश्ड लिंक</b>\n\n";
        $kb = [];
        foreach (array_reverse($published, true) as $bid => $b) {
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $b['token'];
            $cnt = count($b['files'] ?? []);
            $text .= "#{$bid} — {$cnt} फाइलें\n<code>{$link}</code>\n\n";
            $kb[] = [['text' => "🗑️ डिलीट लिंक #{$bid}", 'callback_data' => 'dellink:' . $bid]];
        }
        $kb[] = [['text' => '◀️ वापस', 'callback_data' => 'menu_main']];
    }
    $params = [
        'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $kb]),
        'disable_web_page_preview' => true
    ];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

function showStats($chat_id, $edit_id = null) {
    $users = jload('users.json');
    $batches = jload('batches.json');
    $total = count($users);
    $verified = count(array_filter($users, fn($u) => !empty($u['verified'])));
    $published = count(array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published'));
    $files = 0;
    foreach ($batches as $b) $files += count($b['files'] ?? []);

    $text = "📊 <b>स्टेटिस्टिक्स</b>\n\n"
        . "👥 कुल यूज़र्स: <b>{$total}</b>\n"
        . "✅ वेरिफाइड: <b>{$verified}</b>\n"
        . "🔗 पब्लिश्ड लिंक्स: <b>{$published}</b>\n"
        . "📁 कुल फाइलें: <b>{$files}</b>";

    $kb = [[['text' => '🔄 रिफ्रेश', 'callback_data' => 'menu_stats'], ['text' => '◀️ वापस', 'callback_data' => 'menu_main']]];

    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

function showBroadcastMenu($chat_id, $edit_id = null) {
    $text = "📢 <b>ब्रॉडकास्ट</b>\n\nसभी users को message भेजने के लिए नीचे दबाओ।\n\nफिर अगला message जो भेजोगे (text/photo/video), वो सबको चला जाएगा।";
    $kb = [
        [['text' => '🚀 ब्रॉडकास्ट शुरू करें', 'callback_data' => 'bcast_start']],
        [['text' => '◀️ वापस', 'callback_data' => 'menu_main']],
    ];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

// ================== SEND FILES ==================
function sendFilesByBatch($chat_id, $batch_id) {
    $batches = jload('batches.json');
    if (!isset($batches[$batch_id])) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 यह बैच नहीं मिला।"]);
        return;
    }
    $files = $batches[$batch_id]['files'] ?? [];
    if (!$files) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 इस बैच में कोई फाइल नहीं है।"]);
        return;
    }
    api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
        'text' => "✅ <b>वेरिफिकेशन सफल!</b>\n\n📦 कुल फाइलें: <b>" . count($files) . "</b>\nभेजी जा रही हैं..."]);

    foreach ($files as $f) {
        $p = ['chat_id' => $chat_id, 'caption' => $f['caption'] ?? ''];
        switch ($f['type']) {
            case 'document': $p['document'] = $f['file_id']; api('sendDocument', $p); break;
            case 'video':    $p['video'] = $f['file_id'];    api('sendVideo', $p);    break;
            case 'photo':    $p['photo'] = $f['file_id'];    api('sendPhoto', $p);    break;
            case 'audio':    $p['audio'] = $f['file_id'];    api('sendAudio', $p);    break;
            default:         $p['document'] = $f['file_id']; api('sendDocument', $p);
        }
        usleep(400000);
    }
}

// ================== VERIFY MESSAGE ==================
function sendVerifyMessage($chat_id, $token) {
    $channels = getChannels();
    $text = "🔒 <b>फाइलें पाने के लिए पहले नीचे दिए गए सभी चैनल जॉइन करें</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($channels as $ch) {
        $text .= "{$i}. " . $ch['name'] . "\n";
        $kb[] = [['text' => "📢 Join " . $ch['name'], 'url' => $ch['link']]];
        $i++;
    }
    $kb[] = [['text' => "✅ Verify / मैंने जॉइन कर लिया", 'callback_data' => 'verify:' . $token]];
    api('sendMessage', [
        'chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb])
    ]);
}

// ================== GET UPDATE ==================
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) { echo "Bot is running. No update."; exit; }

try { handleUpdate($update); }
catch (Throwable $e) { file_put_contents(DATA_DIR . '/error.log', date('c') . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND); }

// ================== MAIN HANDLER ==================
function handleUpdate($update) {
    // ============ CALLBACK QUERY ============
    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        $chat_id = $cq['message']['chat']['id'];
        $msg_id  = $cq['message']['message_id'];
        $user_id = $cq['from']['id'];
        $data    = $cq['data'] ?? '';

        // ---- User verify ----
        if (strpos($data, 'verify:') === 0) {
            $token = substr($data, 7);
            if (checkAllChannels($user_id)) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ वेरिफिकेशन सफल!']);
                markVerified($user_id);
                $batches = jload('batches.json');
                $batch_id = null;
                foreach ($batches as $bid => $b) {
                    if (($b['token'] ?? '') === $token && ($b['status'] ?? '') === 'published') { $batch_id = $bid; break; }
                }
                if ($batch_id) {
                    api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                    sendFilesByBatch($chat_id, $batch_id);
                } else {
                    api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ लिंक अमान्य है।', 'show_alert' => true]);
                }
            } else {
                api('answerCallbackQuery', [
                    'callback_query_id' => $cq['id'],
                    'text' => '❌ कृपया पहले सभी चैनल जॉइन करें!',
                    'show_alert' => true
                ]);
            }
            return;
        }

        // ---- Admin panel ----
        if (!isAdmin($user_id)) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ आप एडमिन नहीं हैं।', 'show_alert' => true]); return; }

        if ($data === 'menu_main')     { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showMainMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_channels') { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showChannelsMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_upload')   { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showUploadMenu($chat_id, $user_id, $msg_id); return; }
        if ($data === 'menu_links')    { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showLinksMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_stats')    { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showStats($chat_id, $msg_id); return; }
        if ($data === 'menu_broadcast'){ api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showBroadcastMenu($chat_id, $msg_id); return; }

        // ---- Channel management ----
        if ($data === 'addch') {
            $state = jload('state.json');
            $state[$user_id]['awaiting'] = 'addchannel';
            jsave('state.json', $state);
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "➕ <b>नया चैनल जोड़ें</b>\n\nनीचे format में message भेजो:\n\n<code>Channel Name | @username_or_-100ID | https://t.me/invitelink</code>\n\nउदाहरण:\n<code>My Channel | @mychannel | https://t.me/mychannel</code>\n\nया private के लिए:\n<code>My Channel | -1001234567890 | https://t.me/+invite_code</code>"
            ]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }

        if (strpos($data, 'delch:') === 0) {
            $idx = (int)substr($data, 6);
            $channels = getChannels();
            if (!isset($channels[$idx]) || $idx < 5) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ यह default चैनल है, डिलीट नहीं हो सकता।', 'show_alert' => true]);
                return;
            }
            $extra = jload('channels.json');
            $extra_idx = $idx - 5;
            if (isset($extra[$extra_idx])) {
                $removed = $extra[$extra_idx];
                unset($extra[$extra_idx]);
                $extra = array_values($extra);
                jsave('channels.json', $extra);
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ {$removed['name']} डिलीट हो गया"]);
            } else {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ नहीं मिला', 'show_alert' => true]);
            }
            showChannelsMenu($chat_id, $msg_id);
            return;
        }

        // ---- Batch management ----
        if ($data === 'newbatch') {
            $existing = getActiveBatch($user_id);
            if ($existing !== null) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "⚠️ पहले वाला बैच #{$existing} खुला है।", 'show_alert' => true]);
                return;
            }
            $bid = createBatch($user_id);
            setActiveBatch($user_id, $bid);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "✅ बैच #{$bid} शुरू हुआ"]);
            showUploadMenu($chat_id, $user_id, $msg_id);
            return;
        }

        if ($data === 'cancelbatch') {
            $active = getActiveBatch($user_id);
            if ($active !== null) {
                $batches = jload('batches.json');
                unset($batches[$active]);
                jsave('batches.json', $batches);
                setActiveBatch($user_id, null);
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ बैच #{$active} कैंसिल हो गया"]);
            }
            showUploadMenu($chat_id, $user_id, $msg_id);
            return;
        }

        if ($data === 'finishbatch') {
            $active = getActiveBatch($user_id);
            if ($active === null) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⚠️ कोई एक्टिव बैच नहीं', 'show_alert' => true]);
                return;
            }
            $batches = jload('batches.json');
            $cnt = count($batches[$active]['files'] ?? []);
            if ($cnt === 0) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⚠️ बैच खाली है, पहले फाइल जोड़ो।', 'show_alert' => true]);
                return;
            }
            $batches[$active]['status'] = 'published';
            $token = $batches[$active]['token'];
            jsave('batches.json', $batches);
            setActiveBatch($user_id, null);

            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $token;
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ लिंक बन गया!']);
            api('sendMessage', [
                'chat_id' => $chat_id, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
                'text' => "🎉 <b>लिंक तैयार!</b>\n\n🆔 बैच: #{$active}\n📁 फाइलें: <b>{$cnt}</b>\n\n🔗 शेयर लिंक:\n<code>{$link}</code>\n\nइसे कहीं भी शेयर करो।"
            ]);
            return;
        }

        if (strpos($data, 'dellink:') === 0) {
            $bid = (int)substr($data, 8);
            $batches = jload('batches.json');
            if (isset($batches[$bid])) {
                unset($batches[$bid]);
                jsave('batches.json', $batches);
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ लिंक #{$bid} डिलीट हो गया"]);
            } else {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ नहीं मिला', 'show_alert' => true]);
            }
            showLinksMenu($chat_id, $msg_id);
            return;
        }

        // ---- Broadcast ----
        if ($data === 'bcast_start') {
            $state = jload('state.json');
            $state[$user_id]['awaiting'] = 'broadcast';
            jsave('state.json', $state);
            api('sendMessage', [
                'chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "📢 <b>ब्रॉडकास्ट मोड चालू</b>\n\nअब जो message भेजोगे (text / photo / video / document), वो सभी users को चला जाएगा।\n\nरद्द करने के लिए: /cancel"
            ]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }

        api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
        return;
    }

    // ============ MESSAGE ============
    if (!isset($update['message'])) return;
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $user_id = $msg['from']['id'];
    $text = $msg['text'] ?? '';
    $from = $msg['from'];

    saveUser($user_id, $from);

    // ============ ADMIN ============
    if (isAdmin($user_id)) {
        $state = jload('state.json');
        $awaiting = $state[$user_id]['awaiting'] ?? null;

        // /cancel
        if ($text === '/cancel') {
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ रद्द कर दिया।"]);
            return;
        }

        // Channel add pending
        if ($awaiting === 'addchannel' && $text) {
            $parts = array_map('trim', explode('|', $text));
            if (count($parts) !== 3 || !$parts[0] || !$parts[1] || !$parts[2]) {
                api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                    'text' => "❌ Format गलत है। ऐसे भेजो:\n\n<code>Channel Name | @username | https://t.me/link</code>"]);
                return;
            }
            $extra = jload('channels.json');
            $extra[] = ['name' => $parts[0], 'id' => $parts[1], 'link' => $parts[2]];
            jsave('channels.json', $extra);
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ चैनल जुड़ गया: {$parts[0]}"]);
            showChannelsMenu($chat_id);
            return;
        }

        // Broadcast pending
        if ($awaiting === 'broadcast') {
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);

            $users = jload('users.json');
            $total = count($users);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "📤 ब्रॉडकास्ट शुरू ({$total} users)..."]);
            $ok = 0; $fail = 0;
            foreach ($users as $uid => $u) {
                $params = ['chat_id' => $uid];
                if (isset($msg['text'])) { $params['text'] = $msg['text']; $r = api('sendMessage', $params); }
                elseif (isset($msg['photo'])) { $params['photo'] = end($msg['photo'])['file_id']; $params['caption'] = $msg['caption'] ?? ''; $r = api('sendPhoto', $params); }
                elseif (isset($msg['video'])) { $params['video'] = $msg['video']['file_id']; $params['caption'] = $msg['caption'] ?? ''; $r = api('sendVideo', $params); }
                elseif (isset($msg['document'])) { $params['document'] = $msg['document']['file_id']; $params['caption'] = $msg['caption'] ?? ''; $r = api('sendDocument', $params); }
                else { $r = null; }

                if ($r && !empty($r['ok'])) $ok++; else $fail++;
                usleep(70000);
            }
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ब्रॉडकास्ट पूरा\nसफल: {$ok}\nफेल: {$fail}"]);
            showMainMenu($chat_id);
            return;
        }

        // /save reply
        if (strpos($text, '/save') === 0 && isset($msg['reply_to_message'])) {
            $active = getActiveBatch($user_id);
            if ($active === null) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ पहले एडमिन पैनल से नया बैच शुरू करो।"]);
                return;
            }
            $rt = $msg['reply_to_message'];
            $caption = trim(substr($text, strlen('/save')));
            $type = null; $fid = null;
            if (isset($rt['document'])) { $type = 'document'; $fid = $rt['document']['file_id']; }
            elseif (isset($rt['video'])) { $type = 'video'; $fid = $rt['video']['file_id']; }
            elseif (isset($rt['photo'])) { $type = 'photo'; $fid = end($rt['photo'])['file_id']; }
            elseif (isset($rt['audio'])) { $type = 'audio'; $fid = $rt['audio']['file_id']; }

            if ($type && $fid) {
                $batches = jload('batches.json');
                $batches[$active]['files'][] = ['type' => $type, 'file_id' => $fid, 'caption' => $caption];
                jsave('batches.json', $batches);
                $cnt = count($batches[$active]['files']);
                api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                    'text' => "✅ फाइल जुड़ी। बैच #{$active} में कुल: <b>{$cnt}</b>\n\nऔर जोड़ो या एडमिन पैनल से <b>DN</b> दबाओ।"]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Media नहीं मिली।"]);
            }
            return;
        }

        // /start or /admin -> main menu
        if ($text === '/start' || $text === '/admin' || $text === '') {
            showMainMenu($chat_id);
            return;
        }

        return;
    }

    // ============ USER SIDE ============
    $token = null;
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', trim($text), 2);
        if (isset($parts[1])) $token = trim($parts[1]);
    }

    if ($token) {
        $batches = jload('batches.json');
        $batch_id = null;
        foreach ($batches as $bid => $b) {
            if (($b['token'] ?? '') === $token && ($b['status'] ?? '') === 'published') { $batch_id = $bid; break; }
        }
        if ($batch_id === null) {
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ यह लिंक अमान्य है या डिलीट हो चुका है।"]);
            return;
        }

        // Already verified? -> direct files
        $users = jload('users.json');
        $isVerified = !empty($users[$user_id]['verified']);

        if ($isVerified || checkAllChannels($user_id)) {
            markVerified($user_id);
            sendFilesByBatch($chat_id, $batch_id);
        } else {
            sendVerifyMessage($chat_id, $token);
        }
        return;
    }

    api('sendMessage', [
        'chat_id' => $chat_id, 'parse_mode' => 'HTML',
        'text' => "👋 <b>स्वागत है!</b>\n\nफाइलें पाने के लिए मुझे किसी शेयर लिंक से खोलो।"
    ]);
}
