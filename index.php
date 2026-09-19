<?php
/**
 * Telegram Force-Join Bot - No Database Version
 * Data files: data/users.json, data/batches.json, data/state.json
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ================== CONFIG ==================
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'YAHAN_APNA_TOKEN_DAALO');
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: 'bmwytxh4ckbot');
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'bmwytx2024');

$ADMIN_IDS = [8980897228, 5997885135];

$CHANNELS = [
    ['id' => '-1000000000001', 'link' => 'https://t.me/+JQTJ0zj84ftlZDdl', 'name' => 'Channel 1'],
    ['id' => '-1000000000002', 'link' => 'https://t.me/+UxP0ioC9Kp00MjVl', 'name' => 'Channel 2'],
    ['id' => '-1000000000003', 'link' => 'https://t.me/+WftPXj9G49w2YmFl', 'name' => 'Channel 3'],
    ['id' => '-1000000000004', 'link' => 'https://t.me/+dhTIGNKd66BlMDVl', 'name' => 'Channel 4'],
    ['id' => '@FREEFIRE_HACK_MOD_LINKS', 'link' => 'https://t.me/FREEFIRE_HACK_MOD_LINKS', 'name' => 'Channel 5'],
];

// Data directory
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
    $path = DATA_DIR . '/' . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
    global $CHANNELS;
    foreach ($CHANNELS as $ch) {
        $r = api('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        if (!$r || empty($r['ok'])) return false;
        $status = $r['result']['status'] ?? '';
        if (!in_array($status, ['creator', 'administrator', 'member'], true)) return false;
    }
    return true;
}

// ================== WEBHOOK SETUP ==================
if (isset($_GET['set']) && $_GET['set'] === WEBHOOK_SECRET) {
    $r = api('setWebhook', ['url' => baseUrl()]);
    header('Content-Type: application/json');
    echo json_encode($r, JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['info'])) {
    header('Content-Type: application/json');
    echo json_encode(api('getWebhookInfo'), JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['ping'])) {
    echo "OK - Bot is alive";
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

    api('sendMessage', [
        'chat_id' => $chat_id,
        'parse_mode' => 'HTML',
        'text' => "✅ <b>वेरिफिकेशन सफल!</b>\n\n📦 कुल फाइलें: <b>" . count($files) . "</b>\nभेजी जा रही हैं..."
    ]);

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
    global $CHANNELS;
    $text = "🔒 <b>फाइलें पाने के लिए पहले नीचे दिए गए सभी चैनल जॉइन करें</b>\n\n";
    $kb = [];
    $i = 1;
    foreach ($CHANNELS as $ch) {
        $text .= "{$i}. " . $ch['name'] . "\n";
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

// ================== GET UPDATE ==================
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);
if (!$update) { echo "Bot is running. No update."; exit; }

try { handleUpdate($update); }
catch (Throwable $e) { file_put_contents(DATA_DIR . '/error.log', date('c') . ' ' . $e->getMessage() . "\n", FILE_APPEND); }

// ================== MAIN HANDLER ==================
function handleUpdate($update) {
    // ---------- CALLBACK ----------
    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        $chat_id = $cq['message']['chat']['id'];
        $msg_id  = $cq['message']['message_id'];
        $user_id = $cq['from']['id'];
        $data    = $cq['data'] ?? '';

        if (strpos($data, 'verify:') === 0) {
            $token = substr($data, 7);
            if (checkAllChannels($user_id)) {
                api('answerCallbackQuery', ['callback_query_id' => $cq['id'], 'text' => '✅ वेरिफिकेशन सफल!']);

                $users = jload('users.json');
                if (isset($users[$user_id])) { $users[$user_id]['verified'] = 1; jsave('users.json', $users); }

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
        }

        if ($data === 'admin_stats' && isAdmin($user_id)) {
            $users = jload('users.json');
            $batches = jload('batches.json');
            $total = count($users);
            $verified = count(array_filter($users, fn($u) => !empty($u['verified'])));
            $published = count(array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published'));
            $files = 0;
            foreach ($batches as $b) $files += count($b['files'] ?? []);

            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "📊 <b>स्टेटिस्टिक्स</b>\n\n👥 कुल: <b>{$total}</b>\n✅ वेरिफाइड: <b>{$verified}</b>\n🔗 लिंक्स: <b>{$published}</b>\n📁 फाइलें: <b>{$files}</b>"
            ]);
            api('answerCallbackQuery', ['callback_query_id' => $cq['id']]);
        }
        return;
    }

    // ---------- MESSAGE ----------
    if (!isset($update['message'])) return;
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $user_id = $msg['from']['id'];
    $text = $msg['text'] ?? '';
    $from = $msg['from'];

    saveUser($user_id, $from);

    // ===== ADMIN =====
    if (isAdmin($user_id)) {
        $state = jload('state.json');
        $active_batch = $state[$user_id]['active_batch'] ?? null;

        if ($text === '/admin' || ($text === '/start' && !isset($msg['entities']))) {
            $m = "🛠️ <b>एडमिन पैनल</b>\n\n";
            if ($active_batch) {
                $batches = jload('batches.json');
                $cnt = count($batches[$active_batch]['files'] ?? []);
                $m .= "📝 एक्टिव बैच: #{$active_batch}\n📁 फाइलें: <b>{$cnt}</b>\n\n";
                $m .= "फाइल भेजकर reply करो:\n<code>/save caption</code>\n\n";
                $m .= "लिंक बनाने के लिए: <code>/dn</code>\nकैंसिल: <code>/cancel</code>";
            } else {
                $m .= "नया बैच: <code>/new</code>\n\n";
                $m .= "कमांड्स:\n/stats - आंकड़े\n/listlinks - सभी लिंक\n/broadcast msg - सबको भेजो\n/deltlink TOKEN - लिंक डिलीट";
            }
            api('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $m,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '📊 स्टेटिस्टिक्स', 'callback_data' => 'admin_stats']]]])
            ]);
            return;
        }

        if ($text === '/new') {
            if ($active_batch) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ पहले वाला बैच #{$active_batch} खुला है। पहले /dn या /cancel करो।"]);
                return;
            }
            $batches = jload('batches.json');
            $new_id = (count($batches) > 0 ? max(array_map('intval', array_keys($batches))) + 1 : 1);
            $token = bin2hex(random_bytes(8));

            $batches[$new_id] = [
                'id' => $new_id,
                'token' => $token,
                'created_by' => $user_id,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 'draft',
                'files' => []
            ];
            jsave('batches.json', $batches);

            $state[$user_id]['active_batch'] = $new_id;
            jsave('state.json', $state);

            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "✅ <b>नया बैच #{$new_id} शुरू हुआ</b>\n\nअब फाइलें भेजो और हर फाइल पर reply करके <code>/save caption</code> लिखो।\n\nजितनी चाहो जोड़ो, सब हो जाने पर <code>/dn</code>।"
            ]);
            return;
        }

        if ($text === '/cancel') {
            if ($active_batch) {
                $batches = jload('batches.json');
                unset($batches[$active_batch]);
                jsave('batches.json', $batches);
                unset($state[$user_id]['active_batch']);
                jsave('state.json', $state);
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "🗑️ बैच #{$active_batch} कैंसिल कर दिया।"]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "कोई एक्टिव बैच नहीं है।"]);
            }
            return;
        }

        if (strpos($text, '/save') === 0 && isset($msg['reply_to_message'])) {
            if (!$active_batch) {
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
                $batches = jload('batches.json');
                $batches[$active_batch]['files'][] = ['type' => $type, 'file_id' => $fid, 'caption' => $caption];
                jsave('batches.json', $batches);
                $cnt = count($batches[$active_batch]['files']);
                api('sendMessage', [
                    'chat_id' => $chat_id,
                    'parse_mode' => 'HTML',
                    'text' => "✅ फाइल जुड़ी।\n📁 बैच #{$active_batch} में कुल: <b>{$cnt}</b>\n\nऔर जोड़ो या <code>/dn</code> करो।"
                ]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ मीडिया नहीं मिली। किसी video/document/photo को reply करके /save लिखो।"]);
            }
            return;
        }

        if ($text === '/dn') {
            if (!$active_batch) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ कोई एक्टिव बैच नहीं। /new से शुरू करो।"]);
                return;
            }
            $batches = jload('batches.json');
            $cnt = count($batches[$active_batch]['files'] ?? []);
            if ($cnt === 0) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "⚠️ बैच खाली है। पहले /save से फाइलें जोड़ो।"]);
                return;
            }
            $batches[$active_batch]['status'] = 'published';
            $token = $batches[$active_batch]['token'];
            jsave('batches.json', $batches);

            unset($state[$user_id]['active_batch']);
            jsave('state.json', $state);

            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $token;
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'text' => "🎉 <b>लिंक तैयार!</b>\n\n🆔 बैच: #{$active_batch}\n📁 फाइलें: <b>{$cnt}</b>\n\n🔗 शेयर लिंक:\n<code>{$link}</code>\n\nइसे कहीं भी शेयर करो — users पहले 5 चैनल जॉइन करेंगे, फिर सारी {$cnt} फाइलें मिलेंगी।"
            ]);
            return;
        }

        if ($text === '/listlinks') {
            $batches = jload('batches.json');
            $published = array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published');
            if (!$published) {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "कोई पब्लिश्ड लिंक नहीं।"]);
                return;
            }
            $out = "🔗 <b>पब्लिश्ड लिंक्स</b>\n\n";
            foreach (array_reverse($published, true) as $bid => $b) {
                $link = "https://t.me/" . BOT_USERNAME . "?start=" . $b['token'];
                $cnt = count($b['files'] ?? []);
                $out .= "#{$bid} ({$cnt} files)\n<code>{$link}</code>\n\n";
            }
            $out .= "डिलीट: <code>/deltlink TOKEN</code>";
            api('sendMessage', ['chat_id' => $chat_id, 'text' => $out, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            return;
        }

        if (strpos($text, '/deltlink') === 0) {
            $token = trim(substr($text, strlen('/deltlink')));
            $batches = jload('batches.json');
            $found = null;
            foreach ($batches as $bid => $b) {
                if (($b['token'] ?? '') === $token) { $found = $bid; break; }
            }
            if ($found !== null) {
                unset($batches[$found]);
                jsave('batches.json', $batches);
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "🗑️ लिंक डिलीट हो गया।"]);
            } else {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ लिंक नहीं मिला।"]);
            }
            return;
        }

        if ($text === '/stats') {
            $users = jload('users.json');
            $batches = jload('batches.json');
            $total = count($users);
            $verified = count(array_filter($users, fn($u) => !empty($u['verified'])));
            $published = count(array_filter($batches, fn($b) => ($b['status'] ?? '') === 'published'));
            $files = 0;
            foreach ($batches as $b) $files += count($b['files'] ?? []);
            api('sendMessage', [
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
                'text' => "📊 <b>स्टेटिस्टिक्स</b>\n\n👥 कुल यूज़र्स: <b>{$total}</b>\n✅ वेरिफाइड: <b>{$verified}</b>\n🔗 पब्लिश्ड लिंक्स: <b>{$published}</b>\n📁 कुल फाइलें: <b>{$files}</b>"
            ]);
            return;
        }

        if (strpos($text, '/broadcast') === 0) {
            $bmsg = trim(substr($text, strlen('/broadcast')));
            if ($bmsg === '') {
                api('sendMessage', ['chat_id' => $chat_id, 'text' => "उपयोग: /broadcast तुम्हारा मैसेज"]);
                return;
            }
            $users = jload('users.json');
            $ok = 0; $fail = 0;
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "📤 ब्रॉडकास्ट शुरू (" . count($users) . " यूज़र्स)..."]);
            foreach ($users as $uid => $u) {
                $r = api('sendMessage', ['chat_id' => $uid, 'text' => $bmsg]);
                if ($r && !empty($r['ok'])) $ok++; else $fail++;
                usleep(60000);
            }
            api('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ब्रॉडकास्ट पूरा\nसफल: {$ok}\nफेल: {$fail}"]);
            return;
        }
        return;
    }

    // ===== USER =====
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

        if (checkAllChannels($user_id)) {
            $users = jload('users.json');
            if (isset($users[$user_id])) { $users[$user_id]['verified'] = 1; jsave('users.json', $users); }
            sendFilesByBatch($chat_id, $batch_id);
        } else {
            sendVerifyMessage($chat_id, $token);
        }
        return;
    }

    api('sendMessage', [
        'chat_id' => $chat_id,
        'parse_mode' => 'HTML',
        'text' => "👋 <b>स्वागत है!</b>\n\nफाइलें पाने के लिए मुझे किसी शेयर लिंक से खोलो।"
    ]);
}
