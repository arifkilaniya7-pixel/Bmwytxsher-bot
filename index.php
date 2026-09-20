<?php
/**
 * Telegram Force-Join File Bot — FINAL (FIXED)
 *
 * Fix 1: access to files now ALWAYS re-checks live channel membership via
 * getChatMember, instead of trusting a cached "verified" flag. This closes
 * the bug where a user who joined once, got verified, then left the
 * channels could still get files on any future /start link without
 * rejoining.
 *
 * Fix 2: support for "Request to Join" (approval-required) invite links.
 * The bot now listens for chat_join_request updates, remembers who has a
 * pending request per channel, shows that in the verify message (⏳ Pending
 * vs ❌ Not joined), and gives the admin a one-tap "✅ Approve All Pending"
 * button to bulk-approve every pending request across all channels at once.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/php_error.log');

// ================== CONFIG ==================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YAHAN_APNA_TOKEN');
define('BOT_USERNAME', 'bmwytxh4ckbot');
define('WEBHOOK_SECRET', 'bmwytx2024');

$GLOBALS['ADMIN_IDS'] = [8980897228, 5997885135];

// ===== ASLI CHANNEL IDs =====
$GLOBALS['DEFAULT_CHANNELS'] = [
    ['id' => '-1004300951043', 'link' => 'https://t.me/+ou-f7trdWvBlYjFl', 'name' => 'Channel 1'],
    ['id' => '-1004319519511', 'link' => 'https://t.me/+jw9fTzLxdQY3NTg1', 'name' => 'Channel 2'],
    ['id' => '-1003937510791', 'link' => 'https://t.me/+9JGQJu9n1tJlMzg1', 'name' => 'Channel 3'],
    ['id' => '-1002718394198', 'link' => 'https://t.me/+BXNrpH0h3o1kNThl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0777, true);
@chmod(DATA_DIR, 0777);

// ================== JSON ==================
function jload($file) {
    $path = DATA_DIR . '/' . $file;
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function jsave($file, $data) {
    @file_put_contents(DATA_DIR . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function getChannels() {
    $extra = jload('channels.json');
    return array_merge($GLOBALS['DEFAULT_CHANNELS'], $extra);
}

// ================== PENDING JOIN REQUESTS ==================
// Structure: pending[user_id][chat_id] = ['requested_at' => ..., 'first_name' => ...]
function loadPending() { return jload('pending.json'); }
function savePending($p) { jsave('pending.json', $p); }

function addPendingRequest($user_id, $chat_id, $first_name = '') {
    $p = loadPending();
    $p[$user_id][$chat_id] = ['requested_at' => date('Y-m-d H:i:s'), 'first_name' => $first_name];
    savePending($p);
}
function removePendingRequest($user_id, $chat_id) {
    $p = loadPending();
    if (isset($p[$user_id][$chat_id])) {
        unset($p[$user_id][$chat_id]);
        if (empty($p[$user_id])) unset($p[$user_id]);
        savePending($p);
    }
}
function hasPendingRequest($user_id, $chat_id) {
    $p = loadPending();
    return isset($p[$user_id][$chat_id]);
}
function countPendingRequests() {
    $p = loadPending();
    $n = 0;
    foreach ($p as $chats) $n += count($chats);
    return $n;
}

// ================== API ==================
function api($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
function isAdmin($uid) {
    return in_array((int)$uid, array_map('intval', $GLOBALS['ADMIN_IDS']), true);
}
function baseUrl() {
    return 'https://bmwytxsher-bot.onrender.com';
}

// ================== CHANNEL CHECK ==================
function checkAllChannels($user_id) {
    $channels = getChannels();
    foreach ($channels as $ch) {
        $r = api('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        $status = $r['result']['status'] ?? 'error';
        if (!$r || empty($r['ok'])) {
            @file_put_contents(DATA_DIR . '/check.log',
                date('c') . " FAIL {$ch['name']} id={$ch['id']} resp=" . json_encode($r) . "\n", FILE_APPEND);
            return false;
        }
        if (!in_array($status, ['creator', 'administrator', 'member'], true)) {
            @file_put_contents(DATA_DIR . '/check.log',
                date('c') . " NOTMEMBER {$ch['name']} user=$user_id status=$status\n", FILE_APPEND);
            return false;
        }
    }
    return true;
}

// ================== ENDPOINTS ==================
if (isset($_GET['set']) && $_GET['set'] === WEBHOOK_SECRET) {
    header('Content-Type: application/json');
    echo json_encode(api('setWebhook', [
        'url' => baseUrl() . '/index.php',
        'allowed_updates' => json_encode(['message', 'callback_query', 'edited_message', 'channel_post', 'edited_channel_post', 'chat_join_request'])
    ]), JSON_PRETTY_PRINT); exit;
}
if (isset($_GET['info'])) { header('Content-Type: application/json'); echo json_encode(api('getWebhookInfo'), JSON_PRETTY_PRINT); exit; }
if (isset($_GET['ping'])) { echo "OK - Bot is alive"; exit; }
if (isset($_GET['logs'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== check.log ===\n"; echo @file_get_contents(DATA_DIR . '/check.log') ?: "(empty)";
    echo "\n\n=== callback.log ===\n"; echo @file_get_contents(DATA_DIR . '/callback.log') ?: "(empty)";
    echo "\n\n=== error.log ===\n"; echo @file_get_contents(DATA_DIR . '/error.log') ?: "(empty)";
    echo "\n\n=== php_error.log ===\n"; echo @file_get_contents(DATA_DIR . '/php_error.log') ?: "(empty)";
    exit;
}
if (isset($_GET['clearlogs'])) {
    @unlink(DATA_DIR . '/check.log');
    @unlink(DATA_DIR . '/callback.log');
    @unlink(DATA_DIR . '/error.log');
    @unlink(DATA_DIR . '/php_error.log');
    echo "Logs cleared"; exit;
}
if (isset($_GET['debug'])) {
    header('Content-Type: application/json');
    $links = jload('links.json');
    echo json_encode([
        'bot_username' => BOT_USERNAME,
        'total_links' => count($links),
        'data_dir_writable' => is_writable(DATA_DIR),
        'admin_ids' => $GLOBALS['ADMIN_IDS'],
        'channels' => getChannels(),
    ], JSON_PRETTY_PRINT);
    exit;
}

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
function markUnverified($user_id) {
    $users = jload('users.json');
    if (isset($users[$user_id])) { $users[$user_id]['verified'] = 0; jsave('users.json', $users); }
}

// ================== DRAFT ==================
function getDraft($admin_id) {
    $drafts = jload('draft.json');
    return $drafts[$admin_id] ?? null;
}
function setDraft($admin_id, $data) {
    $drafts = jload('draft.json');
    if ($data === null) unset($drafts[$admin_id]);
    else $drafts[$admin_id] = $data;
    jsave('draft.json', $drafts);
}

// ================== UI ==================
function mainMenuKeyboard() {
    return json_encode(['inline_keyboard' => [
        [['text' => '📢 Manage Channels', 'callback_data' => 'menu_channels']],
        [['text' => '📁 Upload Files', 'callback_data' => 'menu_upload']],
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
    $pendingCount = countPendingRequests();
    $text = "📢 <b>Channel Management</b>\n\nTotal: <b>" . count($channels) . "</b>\n⏳ Pending join requests: <b>{$pendingCount}</b>\n\nFirst 5 are default (cannot delete).\nOthers can be deleted.";
    $kb = [];
    foreach ($channels as $idx => $ch) $kb[] = [['text' => "🗑️ " . $ch['name'], 'callback_data' => 'delch:' . $idx]];
    $kb[] = [['text' => '➕ Add Channel', 'callback_data' => 'addch']];
    if ($pendingCount > 0) {
        $kb[] = [['text' => "✅ Approve All Pending ({$pendingCount})", 'callback_data' => 'approveall']];
    }
    $kb[] = [['text' => '◀️ Back', 'callback_data' => 'menu_main']];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}
function showUploadMenu($chat_id, $admin_id, $edit_id = null) {
    $draft = getDraft($admin_id);
    $cnt = $draft ? count($draft['files'] ?? []) : 0;
    $text = "📁 <b>Upload Files</b>\n\nFiles added: <b>{$cnt}</b>\n\n➡️ Just send any file/video/photo/zip/apk to this bot.\nIt will be added automatically.\n\nWhen finished, press <b>✅ DN (Create Link)</b> or type <code>/Dn</code>.";
    $kb = [
        [['text' => '✅ DN (Create Link)', 'callback_data' => 'finishdraft']],
        [['text' => '🗑️ Clear Files', 'callback_data' => 'cleardraft']],
        [['text' => '◀️ Back', 'callback_data' => 'menu_main']],
    ];
    $params = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $kb])];
    if ($edit_id) { $params['message_id'] = $edit_id; api('editMessageText', $params); }
    else api('sendMessage', $params);
}
function showLinksMenu($chat_id, $edit_id = null) {
    $links = jload('links.json');
    if (!$links) {
        $text = "🔗 <b>All Links</b>\n\nNo links created yet.";
        $kb = [[['text' => '◀️ Back', 'callback_data' => 'menu_main']]];
    } else {
        $text = "🔗 <b>All Published Links</b>\n\n";
        $kb = [];
        foreach (array_reverse($links, true) as $lid => $b) {
            $url = "https://t.me/" . BOT_USERNAME . "?start=" . $b['token'];
            $cnt = count($b['files'] ?? []);
            $text .= "#{$lid} — {$cnt} files\n<code>{$url}</code>\n\n";
            $kb[] = [['text' => "🗑️ Delete Link #{$lid}", 'callback_data' => 'dellink:' . $lid]];
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
    $links = jload('links.json');
    $total = count($users);
    $verified = count(array_filter($users, fn($u) => !empty($u['verified'])));
    $files = 0;
    foreach ($links as $b) $files += count($b['files'] ?? []);
    $text = "📊 <b>Statistics</b>\n\n👥 Users: <b>{$total}</b>\n✅ Verified: <b>{$verified}</b>\n🔗 Links: <b>" . count($links) . "</b>\n📁 Files: <b>{$files}</b>";
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
function fileAddedCard($chat_id, $file_name, $count, $caption) {
    $cap = $caption ?: 'Saved';
    $text = "┏━━━━━━━━━━━━━━━┓\n";
    $text .= "   📥 <b>FILE ADDED</b>   \n";
    $text .= "┗━━━━━━━━━━━━━━━┛\n\n";
    $text .= "📁 <code>" . htmlspecialchars($file_name) . "</code>\n";
    $text .= "📦 Total: <b>{$count}</b> File(s)\n";
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

// ================== FINISH ==================
function finishDraft($chat_id, $admin_id) {
    $draft = getDraft($admin_id);
    if (!$draft || empty($draft['files'])) {
        api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ No files added."]);
        return;
    }
    $links = jload('links.json');
    $new_id = (count($links) > 0 ? max(array_map('intval', array_keys($links))) + 1 : 1);
    $token = bin2hex(random_bytes(8));
    $links[$new_id] = [
        'id' => $new_id,
        'token' => $token,
        'created_at' => date('Y-m-d H:i:s'),
        'files' => $draft['files'],
    ];
    jsave('links.json', $links);
    setDraft($admin_id, null);
    $cnt = count($draft['files']);
    $link = "https://t.me/" . BOT_USERNAME . "?start=" . $token;
    downloadReadyCard($chat_id, $cnt, $link);
}

// ================== SEND FILES ==================
function sendFilesByLink($chat_id, $link_id) {
    $links = jload('links.json');
    if (!isset($links[$link_id])) { api('sendMessage', ['chat_id' => $chat_id, 'text' => "📂 Link invalid."]); return; }
    $files = $links[$link_id]['files'] ?? [];
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

// ================== VERIFY ==================
function sendVerifyMessage($chat_id, $token, $user_id = null) {
    $channels = getChannels();
    $text = "🔒 <b>Join all channels below to unlock the files</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($channels as $ch) {
        $icon = '❌';
        if ($user_id !== null) {
            // Live status for channels the bot can read directly.
            $r = api('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
            $status = $r['result']['status'] ?? null;
            if (in_array($status, ['creator', 'administrator', 'member'], true)) {
                $icon = '✅';
            } elseif (hasPendingRequest($user_id, $ch['id'])) {
                $icon = '⏳ Pending approval';
            }
        }
        $text .= "{$i}. {$icon} " . $ch['name'] . "\n";
        $kb[] = [['text' => "📢 Join " . $ch['name'], 'url' => $ch['link']]];
        $i++;
    }
    $text .= "\n⏳ = aapne request bhej di hai, admin approve karega. Approve hote hi neeche <b>Verify</b> dabao.";
    $kb[] = [['text' => "✅ Verify / I have joined", 'callback_data' => 'verify:' . $token]];
    api('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'reply_markup' => json_encode(['inline_keyboard' => $kb])]);
}

// ================== GET UPDATE ==================
$raw = file_get_contents('php://input');
if ($raw) {
    @file_put_contents(DATA_DIR . '/callback.log', date('c') . " RAW: " . substr($raw, 0, 500) . "\n", FILE_APPEND);
}
$update = json_decode($raw, true);
if (!$update) { echo "Bot is running. No update."; exit; }

try {
    handleUpdate($update);
} catch (Throwable $e) {
    $err = date('c') . ' ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    @file_put_contents(DATA_DIR . '/error.log', $err, FILE_APPEND);
}

// ================== HANDLER ==================
function handleUpdate($update) {

    // ---- Someone tapped "Request to Join" on one of our channels ----
    if (isset($update['chat_join_request'])) {
        $jr = $update['chat_join_request'];
        $chat_id = $jr['chat']['id'];
        $user_id = $jr['from']['id'];
        $first_name = $jr['from']['first_name'] ?? '';
        addPendingRequest($user_id, $chat_id, $first_name);
        saveUser($user_id, $jr['from']);
        @file_put_contents(DATA_DIR . '/check.log', date('c') . " JOINREQUEST user=$user_id chat=$chat_id\n", FILE_APPEND);
        return;
    }

    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        $chat_id = $cq['message']['chat']['id'];
        $msg_id  = $cq['message']['message_id'];
        $user_id = $cq['from']['id'];
        $data    = $cq['data'] ?? '';

        @file_put_contents(DATA_DIR . '/callback.log', date('c') . " CB user=$user_id data=$data\n", FILE_APPEND);

        // ---- Verify (always a fresh, live check) ----
        if (strpos($data, 'verify:') === 0) {
            $token = substr($data, 7);
            $ok = checkAllChannels($user_id);
            @file_put_contents(DATA_DIR . '/check.log', date('c') . " verify user=$user_id result=" . ($ok ? 'PASS' : 'FAIL') . "\n", FILE_APPEND);
            if ($ok) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ Verified!']);
                markVerified($user_id);
                $links = jload('links.json');
                $link_id = null;
                foreach ($links as $lid => $b) if (($b['token'] ?? '') === $token) { $link_id = $lid; break; }
                if ($link_id !== null) {
                    api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                    sendFilesByLink($chat_id, $link_id);
                } else {
                    api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Invalid link.', 'show_alert' => true]);
                }
            } else {
                markUnverified($user_id);
                $pendingCount = 0;
                foreach (getChannels() as $ch) if (hasPendingRequest($user_id, $ch['id'])) $pendingCount++;
                $alertText = $pendingCount > 0
                    ? "⏳ Aapki {$pendingCount} request(s) abhi pending hain, admin approve karega. Thodi der baad try karo."
                    : "❌ Join all channels first!";
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => $alertText, 'show_alert' => true]);
                sendVerifyMessage($chat_id, $token, $user_id);
                api('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            }
            return;
        }

        if (!isAdmin($user_id)) {
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Not admin.', 'show_alert' => true]);
            return;
        }

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
                'text' => "➕ <b>Add New Channel</b>\n\nSend in this format:\n\n<code>Channel Name | @username_or_-100ID | https://t.me/link</code>"]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }

        if (strpos($data, 'delch:') === 0) {
            $idx = (int)substr($data, 6);
            $channels = getChannels();
            if (!isset($channels[$idx]) || $idx < 5) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '❌ Default channel.', 'show_alert' => true]); return; }
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

        if ($data === 'finishdraft') {
            $draft = getDraft($user_id);
            if (!$draft || empty($draft['files'])) { api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⚠️ No files.', 'show_alert' => true]); return; }
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ Link created!']);
            finishDraft($chat_id, $user_id);
            return;
        }

        if ($data === 'cleardraft') {
            setDraft($user_id, null);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '🗑️ Cleared']);
            showUploadMenu($chat_id, $user_id, $msg_id);
            return;
        }

        if (strpos($data, 'dellink:') === 0) {
            $lid = (int)substr($data, 8);
            $links = jload('links.json');
            if (isset($links[$lid])) { unset($links[$lid]); jsave('links.json', $links); api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => "🗑️ Link #{$lid} deleted"]); }
            showLinksMenu($chat_id, $msg_id);
            return;
        }

        if ($data === 'approveall') {
            api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '⏳ Approving...']);
            $pending = loadPending();
            $approved = 0; $failed = 0;
            $notifyUsers = [];
            foreach ($pending as $uid => $chats) {
                foreach ($chats as $chat_id_p => $info) {
                    $r = api('approveChatJoinRequest', ['chat_id' => $chat_id_p, 'user_id' => $uid]);
                    if ($r && !empty($r['ok'])) {
                        $approved++;
                        removePendingRequest($uid, $chat_id_p);
                        $notifyUsers[$uid] = true;
                    } else {
                        $failed++;
                        @file_put_contents(DATA_DIR . '/check.log',
                            date('c') . " APPROVE_FAIL user=$uid chat=$chat_id_p resp=" . json_encode($r) . "\n", FILE_APPEND);
                    }
                    usleep(60000);
                }
            }
            // Notify each user so they know to press Verify again.
            foreach (array_keys($notifyUsers) as $uid) {
                api('sendMessage', ['chat_id' => $uid, 'parse_mode' => 'HTML',
                    'text' => "✅ <b>Aapki join request approve ho gayi hai!</b>\n\nAb apne purane message me <b>Verify / I have joined</b> button dabao, ya link dubara open karo."]);
                usleep(60000);
            }
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Approved: {$approved}\n❌ Failed: {$failed}"]);
            showChannelsMenu($chat_id);
            return;
        }

        if ($data === 'bcast_start') {
            $state = jload('state.json');
            $state[$user_id]['awaiting'] = 'broadcast';
            jsave('state.json', $state);
            api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "📢 <b>Broadcast Mode</b>\n\nSend the message to deliver to all users.\n\nCancel: /cancel"]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
            return;
        }

        api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
        return;
    }

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

        if ($awaiting === 'addchannel' && $text) {
            $parts = array_map('trim', explode('|', $text));
            if (count($parts) !== 3 || !$parts[0] || !$parts[1] || !$parts[2]) {
                api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                    'text' => "❌ Wrong format.\n<code>Name | @username | https://t.me/link</code>"]); return;
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
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ Done\nSuccess: {$ok}\nFailed: {$fail}"]);
            showMainMenu($chat_id);
            return;
        }

        if (strtolower(trim($text)) === '/dn') { finishDraft($chat_id, $user_id); return; }

        if (isset($msg['document']) || isset($msg['video']) || isset($msg['photo']) || isset($msg['audio'])) {
            $type = null; $fid = null; $fname = '';
            if (isset($msg['document'])) { $type = 'document'; $fid = $msg['document']['file_id']; $fname = $msg['document']['file_name'] ?? 'document'; }
            elseif (isset($msg['video'])) { $type = 'video'; $fid = $msg['video']['file_id']; $fname = $msg['video']['file_name'] ?? 'video'; }
            elseif (isset($msg['photo'])) { $type = 'photo'; $fid = end($msg['photo'])['file_id']; $fname = 'photo'; }
            elseif (isset($msg['audio'])) { $type = 'audio'; $fid = $msg['audio']['file_id']; $fname = $msg['audio']['file_name'] ?? 'audio'; }
            $caption = trim($msg['caption'] ?? 'Saved');
            $draft = getDraft($user_id) ?? ['files' => []];
            $draft['files'][] = ['type' => $type, 'file_id' => $fid, 'caption' => $caption, 'file_name' => $fname];
            setDraft($user_id, $draft);
            fileAddedCard($chat_id, $fname, count($draft['files']), $caption);
            return;
        }

        if ($text === '/start' || $text === '/admin') { showMainMenu($chat_id); return; }
        return;
    }

    // ============ USER ============
    $token = null;
    if (stripos($text, '/start') === 0) {
        $parts = preg_split('/\s+/', trim($text), 2);
        if (isset($parts[1])) $token = trim($parts[1]);
    }

    if ($token) {
        $links = jload('links.json');
        $link_id = null;
        foreach ($links as $lid => $b) if (($b['token'] ?? '') === $token) { $link_id = $lid; break; }
        if ($link_id === null) { api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ This link is invalid or expired."]); return; }

        // FIX: har baar live check — cached 'verified' flag par kabhi
        // access decide nahi karte. Isse leave-and-rejoin-bypass band ho jaata hai.
        if (checkAllChannels($user_id)) {
            markVerified($user_id);
            sendFilesByLink($chat_id, $link_id);
        } else {
            markUnverified($user_id);
            sendVerifyMessage($chat_id, $token, $user_id);
        }
        return;
    }

    api('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
        'text' => "👋 <b>Welcome!</b>\n\nOpen me from a share link to get files."]);
}
