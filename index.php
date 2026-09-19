<?php
/**
 * ==========================================================
 *   TELEGRAM FORCE-JOIN FILE BOT — FINAL PROFESSIONAL
 * ==========================================================
 *   Features:
 *   - Admin Panel (inline buttons)
 *   - Manage Channels (add/delete, 5 default protected)
 *   - Auto file add (just send file, no /save needed)
 *   - /Dn or DN button → generates ONE share link
 *   - Broadcast (text/photo/video)
 *   - Force join 5+ channels
 *   - Already verified users get files instantly
 *   - Professional card UI matching pro bots
 *   - Nothing auto-deletes
 * ==========================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ================== CONFIG ==================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YAHAN_APNA_TOKEN');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'FileShere4bot');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'bmwytx2024');

$ADMIN_IDS = [8980897228, 5997885135];

// Default 5 channels — cannot be deleted from admin panel
$DEFAULT_CHANNELS = [
    ['id' => '-1000000000001', 'link' => 'https://t.me/+JQTJ0zj84ftlZDdl', 'name' => 'Channel 1'],
    ['id' => '-1000000000002', 'link' => 'https://t.me/+UxP0ioC9Kp00MjVl', 'name' => 'Channel 2'],
    ['id' => '-1000000000003', 'link' => 'https://t.me/+WftPXj9G49w2YmFl', 'name' => 'Channel 3'],
    ['id' => '-1000000000004', 'link' => 'https://t.me/+dhTIGNKd66BlMDVl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);

// ================== JSON ==================
function jload($file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function jsave($file, $data) {
    file_put_contents(DATA_DIR . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
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

// ================== WEBHOOK ==================
if (isset($_GET['set']) && $_GET['set'] === WEBHOOK_SECRET) {
    header('Content-Type: application/json');
    echo json_encode(api('setWebhook', ['url' => baseUrl() . '/index.php']), JSON_PRETTY_PRINT); exit;
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

// ================== BATCH ==================
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
        [['text' => '📢 Manage Channels', 'callback_data' => 'menu_channels']],
        [['text' => '📁 New Batch / Add Files', 'callback_data' => 'menu_upload']],
        [['text' => '🔗 All Links', 'callback_data' => 'menu_links']],
        [['text' => '📊 Statistics', 'callback_data' => 'menu_stats']],
        [['text' => '📢 Broadcast', 'callback_data' => 'menu_broadcast']],
    ]]);
}
function showMainMenu($chat_id, $edit_id = null) {
    $text = "🛠️ <b>Admin Panel</b>\n\nChoose an option below:";
    $kb = mainMenuKeyboard();
    if ($edit_id) api('editMessageText', ['chat_id' => $chat_id, 'message_id' => $edit_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
    else api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb]);
}
function showChannelsMenu($chat_id, $edit_id = null) {
    $channels = getChannels();
    $text = "📢 <b>Channel Management</b>\n\nTotal: <b>" . count($channels) . "</b>\n\nFirst 5 are default (cannot delete).\nOthers can be deleted.";
    $kb = [];
    foreach ($channels as $idx => $ch) $kb[] = [['text' => "🗑️ " . $ch['name'], 'callback_data' => 'delch:' . $idx]];
    $kb[] = [['text' => '➕ Add Channel', 'callback_data' => 'addch']];
    $kb[] = [['text' => '◀️ Back', 'callback_data' => 'menu_main']];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}
function showUploadMenu($chat_id, $admin_id, $edit_id = null) {
    $active = getActiveBatch($admin_id);
    if ($active === null) {
        $text = "📁 <b>File Upload</b>\n\nNo active batch. Start a new batch below.";
        $kb = [
            [['text' => '🆕 Start New Batch', 'callback_data' => 'newbatch']],
            [['text' => '◀️ Back', 'callback_data' => 'menu_main']],
        ];
    } else {
        $batches = jload('batches.json');
        $cnt = count($batches[$active]['files'] ?? []);
        $text = "📁 <b>Active Batch #{$active}</b>\n\nFiles added: <b>{$cnt}</b>\n\n";
        $text .= "➡️ Just send any file/video/photo to this bot.\nIt will be added automatically.\n\nWhen done, press <b>✅ DN (Create Link)</b> or type <code>/Dn</code>.";
        $kb = [
            [['text' => '✅ DN (Create Link)', 'callback_data' => 'finishbatch']],
            [['text' => '❌ Cancel Batch', 'callback_data' => 'cancelbatch']],
            [['text' => '◀️ Back', 'callback_data' => 'menu_main']],
        ];
    }
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}
function showLinksMenu($chat_id, $edit_id = null) {
    $batches = jload('batches.json');
    $published = array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published');
    if (!$published) {
        $text = "🔗 <b>All Links</b>\n\nNo links created yet.";
        $kb = [[['text' => '◀️ Back', 'callback_data' => 'menu_main']]];
    } else {
        $text = "🔗 <b>All Published Links</b>\n\n";
        $kb = [];
        foreach (array_reverse($published, true) as $bid => $b) {
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $b['token'];
            $cnt = count($b['files'] ?? []);
            $text .= "#{$bid} — {$cnt} files\n<code>{$link}</code>\n\n";
            $kb[] = [['text' => "🗑️ Delete Link #{$bid}", 'callback_data' => 'dellink:' . $bid]];
        }
        $kb[] = [['text' => '◀️ Back', 'callback_data' => 'menu_main']];
    }
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $kb]), 'disable_web_page_preview' => true];
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
    $text = "📊 <b>Statistics</b>\n\n👥 Users: <b>{$total}</b>\n✅ Verified: <b>{$verified}</b>\n🔗 Links: <b>{$published}</b>\n📁 Files: <b>{$files}</b>";
    $kb = [[['text' => '🔄 Refresh', 'callback_data' => 'menu_stats'], ['text' => '◀️ Back', 'callback_data' => 'menu_main']]];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}
function showBroadcastMenu($chat_id, $edit_id = null) {
    $text = "📢 <b>Broadcast</b>\n\nPress below to start. Then send the message you want to deliver to all users.";
    $kb = [
        [['text' => '🚀 Start Broadcast', 'callback_data' => 'bcast_start']],
        [['text' => '◀️ Back', 'callback_data' => 'menu_main']],
    ];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}

// ================== CARDS ==================
function fileAddedCard($chat_id, $file_name, $batch_id, $count, $caption) {
    $cap = $caption ?: 'Saved';
    $text = "┏━━━━━━━━━━━━━━━┓\n";
    $text .= "   📥 <b>FILE ADDED</b>   \n";
    $text .= "┗━━━━━━━━━━━━━━━┛\n\n";
    $text .= "📁 <code>" . htmlspecialchars($file_name) . "</code>\n";
    $text .= "📦 Batch: <b>{$batch_id}</b> File(s)\n";
    $text .= "📝 Caption: <b>{$cap}</b>\n\n";
    $text .= "➕ Send more files\n";
    $text .= "🔗 When finished use /Dn";
    api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML']);
}

function downloadReadyCard($chat_id, $count, $link) {
    $card = "┏━━━━━━━━━━━━━━━┓\n";
    $card .= "   ✅ <b>DOWNLOAD READY</b>   \n";
    $card .= "┗━━━━━━━━━━━━━━━┛\n\n";
    $card .= "📦 Files: <b>{$count}</b>\n";
    $card .= "🔐 Status: <b>Protected</b>\n\n";
    $card .= "🔗 <b>YOUR SHARE LINK</b>\n\n";
    $card .= "<code>{$link}</code>";
    api('sendMessage', ['chat_id' => $chat_id, 'text' => $card, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
}

// ================== FINISH BATCH (shared by /Dn and button) ==================
function finishBatch($chat_id, $admin_id) {
    $active = getActiveBatch($admin_id);
    if ($active === null) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ No active batch. Start one from admin panel."]);
        return;
    }
    $batches = jload('batches.json');
    $cnt = count($batches[$active]['files'] ?? []);
    if ($cnt === 0) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ Batch is empty. Send some files first."]);
        return;
    }
    $batches[$active]['status'] = 'published';
    $token = $batches[$active]['token'];
    jsave('batches.json', $batches);
    setActiveBatch($admin_id, null);
    $link = "https://t.me/bmwytxh4ckbot" . BOT_USERNAME . "?start=" . $token;
    downloadReadyCard($chat_id, $cnt, $link);
}

// ================== SEND FILES ==================
function sendFilesByBatch($chat_id, $batch_id) {
    $batches = jload('batches.json');
    if (!isset($batches[$batch_id])) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 Batch not found."]); return;
    }
    $files = $batches[$batch_id]['files'] ?? [];
    if (!$files) { api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 No files."]); return; }

    api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
        'text' => "✅ <b>Verification Successful!</b>\n\n📦 Total: <b>" . count($files) . "</b> files\nSending now..."]);

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
    $text = "🔒 <b>Join all channels below to unlock the files</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($channels as $ch) {
        $text .= "{$i}. " . $ch['name'] . "\n";
        $kb[] = [['text' => "📢 Join " . $ch['name'], 'url' => $ch['link']]];
        $i++;
    }
    $kb[] = [['text' => "✅ Verify / I have joined", 'callback_data' => 'verify:' . $token]];
    api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb])]);
}

// ================== GET UPDATE ==================
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) { echo "Bot is running. No update."; exit; }
try { handleUpdate($update); }
catch (Throwable $e) { file_put_contents(DATA_DIR . '/error.log', date('c') . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND); }

// ================== MAIN HANDLER ==================
function handleUpdate($update) {
    // ============ CALLBACK ============
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
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ Verified!']);
                markVerified($user_id);
                $batches = jload('batches.json');
                $batch_id = null;
                foreach ($batches as $bid => $b) {
                    if (($b['token'] ?? '') === $token && ($b['status'] ?? '') === 'published') { $batch_id = $bid; break; }
                }
                if ($batch_id) { api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]); sendFilesByBatch($chat_id, $batch_id); }
                else api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Invalid link.', 'show_alert' => true]);
            } else {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Join all channels first!', 'show_alert' => true]);
            }
            return;
        }

        // ---- Admin panel ----
        if (!isAdmin($user_id)) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Not admin.', 'show_alert' => true]); return; }

        if ($data === 'menu_main')     { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showMainMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_channels') { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showChannelsMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_upload')   { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showUploadMenu($chat_id, $user_id, $msg_id); return; }
        if ($data === 'menu_links')    { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showLinksMenu($chat_id, $msg_id); return; }
        if ($data === 'menu_stats')    { api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showStats($chat_id, $msg_id); return; }
        if ($data === 'menu_broadcast'){ api('answerCallbackQuery', ['callback_query_id' => $cq['id']]); showBroadcastMenu($chat_id, $msg_id); return; }

        if ($data === 'addch') {
            $state = jload('state.json');
            $state[$user_id]['awaiting'] = 'addchannel';
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "➕ <b>Add New Channel</b>\n\nSend in this format:\n\n<code>Channel Name | @username_or_-100ID | https://t.me/link</code>\n\nExample:\n<code>My Channel | @mychannel | https://t.me/mychannel</code>"]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }

        if (strpos($data, 'delch:') === 0) {
            $idx = (int)substr($data, 6);
            $channels = getChannels();
            if (!isset($channels[$idx]) || $idx < 5) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Default channel, cannot delete.', 'show_alert' => true]); return;
            }
            $extra = jload('channels.json');
            $extra_idx = $idx - 5;
            if (isset($extra[$extra_idx])) {
                $removed = $extra[$extra_idx];
                unset($extra[$extra_idx]);
                jsave('channels.json', array_values($extra));
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ {$removed['name']} deleted"]);
            }
            showChannelsMenu($chat_id, $msg_id);
            return;
        }

        if ($data === 'newbatch') {
            $existing = getActiveBatch($user_id);
            if ($existing !== null) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "⚠️ Batch #{$existing} already open.", 'show_alert' => true]); return;
            }
            $bid = createBatch($user_id);
            setActiveBatch($user_id, $bid);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "✅ Batch #{$bid} started"]);
            api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "📁 <b>Batch #{$bid} started</b>\n\nNow send any file/video/photo to this bot.\nIt will be added automatically.\n\nWhen finished use /Dn"]);
            return;
        }

        if ($data === 'cancelbatch') {
            $active = getActiveBatch($user_id);
            if ($active !== null) {
                $batches = jload('batches.json');
                unset($batches[$active]);
                jsave('batches.json', $batches);
                setActiveBatch($user_id, null);
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ Cancelled"]);
            }
            showUploadMenu($chat_id, $user_id, $msg_id);
            return;
        }

        if ($data === 'finishbatch') {
            $active = getActiveBatch($user_id);
            if ($active === null) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⚠️ No active batch.', 'show_alert' => true]); return; }
            $batches = jload('batches.json');
            $cnt = count($batches[$active]['files'] ?? []);
            if ($cnt === 0) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⚠️ Empty batch.', 'show_alert' => true]); return; }
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ Link created!']);
            finishBatch($chat_id, $user_id);
            return;
        }

        if (strpos($data, 'dellink:') === 0) {
            $bid = (int)substr($data, 8);
            $batches = jload('batches.json');
            if (isset($batches[$bid])) {
                unset($batches[$bid]);
                jsave('batches.json', $batches);
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ Link #{$bid} deleted"]);
            }
            showLinksMenu($chat_id, $msg_id);
            return;
        }

        if ($data === 'bcast_start') {
            $state = jload('state.json');
            $state[$user_id]['awaiting'] = 'broadcast';
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "📢 <b>Broadcast Mode Active</b>\n\nNow send the message (text/photo/video) to deliver to all users.\n\nCancel: /cancel"]);
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

        if ($text === '/cancel') {
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Cancelled."]);
            return;
        }

        // ---- Channel add pending ----
        if ($awaiting === 'addchannel' && $text) {
            $parts = array_map('trim', explode('|', $text));
            if (count($parts) !== 3 || !$parts[0] || !$parts[1] || !$parts[2]) {
                api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                    'text' => "❌ Wrong format. Send:\n<code>Name | @username | https://t.me/link</code>"]); return;
            }
            $extra = jload('channels.json');
            $extra[] = ['name' => $parts[0], 'id' => $parts[1], 'link' => $parts[2]];
            jsave('channels.json', $extra);
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Added: {$parts[0]}"]);
            showChannelsMenu($chat_id);
            return;
        }

        // ---- Broadcast pending ----
        if ($awaiting === 'broadcast') {
            unset($state[$user_id]['awaiting']);
            jsave('state.json', $state);
            $users = jload('users.json');
            $total = count($users);
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "📤 Broadcasting to {$total} users..."]);
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
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Broadcast done\nSuccess: {$ok}\nFailed: {$fail}"]);
            showMainMenu($chat_id);
            return;
        }

        // ---- /Dn command ----
        if (strtolower($text) === '/dn') {
            finishBatch($chat_id, $user_id);
            return;
        }

        // ---- AUTO FILE ADD ----
        if (isset($msg['document']) || isset($msg['video']) || isset($msg['photo']) || isset($msg['audio'])) {
            $active = getActiveBatch($user_id);
            if ($active !== null) {
                $type = null; $fid = null; $fname = '';
                if (isset($msg['document'])) { $type = 'document'; $fid = $msg['document']['file_id']; $fname = $msg['document']['file_name'] ?? 'document'; }
                elseif (isset($msg['video'])) { $type = 'video'; $fid = $msg['video']['file_id']; $fname = $msg['video']['file_name'] ?? 'video'; }
                elseif (isset($msg['photo'])) { $type = 'photo'; $fid = end($msg['photo'])['file_id']; $fname = 'photo'; }
                elseif (isset($msg['audio'])) { $type = 'audio'; $fid = $msg['audio']['file_id']; $fname = $msg['audio']['file_name'] ?? 'audio'; }
                $caption = trim($msg['caption'] ?? 'Saved');
                $batches = jload('batches.json');
                $batches[$active]['files'][] = ['type' => $type, 'file_id' => $fid, 'caption' => $caption, 'file_name' => $fname];
                jsave('batches.json', $batches);
                $cnt = count($batches[$active]['files']);
                fileAddedCard($chat_id, $fname, $active, $cnt, $caption);
                return;
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                    'text' => "⚠️ No active batch.\n\nUse /start → 📁 New Batch / Add Files → 🆕 Start New Batch"]);
                return;
            }
        }

        if ($text === '/start' || $text === '/admin') { showMainMenu($chat_id); return; }
        return;
    }

    // ============ USER ============
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
        if ($batch_id === null) { api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Invalid link."]); return; }
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
    api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
        'text' => "👋 <b>Welcome!</b>\n\nOpen me from a share link to get files."]);
}
