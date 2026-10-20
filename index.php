<?php
/**
 * Telegram Gmail Task & Earning Bot (Production Ready)
 * Features: Dynamic Admin Panel, Daily Serialized Private Channel Proofs/Logs, User Search, Dynamic Payment Numbers.
 */

// ==========================================
// 1. CONFIGURATION SETTINGS
// ==========================================
$botToken      = "8760332791:AAHLlAZdTKyW1lzdfvfiYIg0tZGKRf8zu7w";
$adminUsername = "raiyan_07j";
$adminChatId   = "7278071284";
$botUsername   = "rs1_espbot";

$website       = "https://api.telegram.org/bot" . $botToken;

// Database Files Path
$usersDbFile    = __DIR__ . "/users.json";
$pendingDbFile  = __DIR__ . "/pending.json";
$withdrawDbFile = __DIR__ . "/withdrawals.json";
$depositDbFile  = __DIR__ . "/deposits.json";
$settingsDbFile = __DIR__ . "/settings.json";

// Helper Database Functions
function loadData($file) {
    if (!file_exists($file)) {
        file_put_contents($file, json_encode([], JSON_PRETTY_PRINT));
        return [];
    }
    $content = file_get_contents($file);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// Load All Databases
$users       = loadData($usersDbFile);
$pending     = loadData($pendingDbFile);
$withdrawals = loadData($withdrawDbFile);
$deposits    = loadData($depositDbFile);
$settings    = loadData($settingsDbFile);

// Set Default Settings
if (!isset($settings['gmail_price'])) {
    $settings['gmail_price'] = 17.0;
}
if (!isset($settings['bkash_number'])) {
    $settings['bkash_number'] = "01852261415";
}
if (!isset($settings['nagad_number'])) {
    $settings['nagad_number'] = "01852261415";
}
if (!isset($settings['min_deposit'])) {
    $settings['min_deposit'] = 20.0;
}
if (!isset($settings['proof_channel'])) {
    $settings['proof_channel'] = "";
}
if (!isset($settings['daily_approved_count'])) {
    $settings['daily_approved_count'] = 0;
}
if (!isset($settings['last_reset_date'])) {
    $settings['last_reset_date'] = date("Y-m-d");
}
if (!isset($settings['manual_tasks']) || !is_array($settings['manual_tasks'])) {
    $settings['manual_tasks'] = [];
}
saveData($settingsDbFile, $settings);

$gmailPrice  = floatval($settings['gmail_price']);
$bkashNumber = $settings['bkash_number'];
$nagadNumber = $settings['nagad_number'];
$minDeposit  = floatval($settings['min_deposit']);

// ==========================================
// 2. HELPER & TELEGRAM API FUNCTIONS
// ==========================================
function sendMessage($chatId, $text, $keyboard = null) {
    global $website;
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $payload['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init($website . '/sendMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function copyMessageToUser($toChatId, $fromChatId, $messageId) {
    global $website;
    $payload = [
        'chat_id' => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id' => $messageId
    ];
    $ch = curl_init($website . '/copyMessage');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function editMessageText($chatId, $messageId, $text, $keyboard = null) {
    global $website;
    $payload = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($keyboard) {
        $payload['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init($website . '/editMessageText');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function generateCredentials() {
    $firstNames = ["tamim", "sakib", "rahim", "karim", "tanvir", "hasan", "sabbir", "arafat", "jubayer"];
    $lastNames  = ["ahmed", "khan", "chy", "hossain", "mahmud", "islam", "rahan"];
    $num        = rand(100, 9999);
    $username   = $firstNames[array_rand($firstNames)] . $lastNames[array_rand($lastNames)] . $num . "@gmail.com";

    $chars    = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#";
    $password = substr(str_shuffle($chars), 0, 10);
    return [$username, $password];
}

// Reset User State Helper
function resetUserState(&$users, $userId, $file) {
    $users[$userId]['awaiting_submission']      = false;
    $users[$userId]['awaiting_withdraw_number'] = false;
    $users[$userId]['awaiting_withdraw_amount'] = false;
    $users[$userId]['awaiting_deposit_details'] = false;
    $users[$userId]['awaiting_broadcast']       = false;
    $users[$userId]['awaiting_price_change']    = false;
    $users[$userId]['awaiting_number_change']   = false;
    $users[$userId]['awaiting_channel_change']  = false;
    $users[$userId]['awaiting_find_user']       = false;
    $users[$userId]['awaiting_balance_user']    = false;
    $users[$userId]['awaiting_balance_amount']  = false;
    $users[$userId]['awaiting_manual_task']     = false;
    $users[$userId]['awaiting_manual_submission'] = false;
    $users[$userId]['withdraw_method']          = null;
    $users[$userId]['withdraw_number']          = null;
    $users[$userId]['deposit_method']           = null;
    saveData($file, $users);
}

// Function to generate Keyboard
function getMainKeyboard($isAdmin = false) {
    $rows = [
        [["text" => "📝 কাজ ▸"], ["text" => "💵 ব্যালেন্স"]],
        [["text" => "💰 টাকা উত্তোলন"], ["text" => "📥 ডিপোজিট"]],
        [["text" => "🎁 My Referrals"], ["text" => "🙋‍♂️ সাপোর্ট"]]
    ];

    if ($isAdmin) {
        $rows[] = [["text" => "⚙️ এডমিন প্যানেল"]];
    } else {
        $rows[] = [["text" => "👶 আমি নতুন"]];
    }

    return [
        'keyboard' => $rows,
        'resize_keyboard' => true
    ];
}

// ==========================================
// 3. KEYBOARDS & LAYOUTS
// ==========================================
$withdrawKeyboard = [
    'inline_keyboard' => [
        [
            ["text" => "🔴 বিকাশ (bKash)", "callback_data" => "wmethod_bkash"],
            ["text" => "🟠 নগদ (Nagad)", "callback_data" => "wmethod_nagad"]
        ],
        [
            ["text" => "❌ বাতিল করুন", "callback_data" => "cancel_action"]
        ]
    ]
];

$depositKeyboard = [
    'inline_keyboard' => [
        [
            ["text" => "🔴 বিকাশ (bKash)", "callback_data" => "depmethod_bkash"],
            ["text" => "🟠 নগদ (Nagad)", "callback_data" => "depmethod_nagad"]
        ],
        [
            ["text" => "❌ বাতিল করুন", "callback_data" => "cancel_action"]
        ]
    ]
];

$cancelKeyboard = [
    'inline_keyboard' => [
        [
            ["text" => "❌ বাতিল করুন", "callback_data" => "cancel_action"]
        ]
    ]
];

// ==========================================
// 4. INCOMING WEBHOOK HANDLING
// ==========================================
$content = file_get_contents("php://input");
$update  = json_decode($content, true);

if (!$update) {
    exit("OK - Webhook Active");
}

// ------------------------------------------
// A. CALLBACK QUERY HANDLERS (Inline Buttons)
// ------------------------------------------
if (isset($update['callback_query'])) {
    $callback  = $update['callback_query'];
    $chatId    = $callback['message']['chat']['id'];
    $messageId = $callback['message']['message_id'];
    $data      = $callback['data'];
    $fromUser  = $callback['from'];

    $isAdmin = (in_array(($fromUser['username'] ?? ''), [$adminUsername])) or ($chatId == $adminChatId);

    // --- Action Cancel Button ---
    if (in_array($data, ["cancel_action", "user_task_cancel"])) {
        resetUserState($users, $chatId, $usersDbFile);
        $users[$chatId]['generated_gmail'] = null;
        $users[$chatId]['generated_pass']  = null;
        saveData($usersDbFile, $users);

        editMessageText($chatId, $messageId, "❌ **অপারেশনটি বাতিল করা হয়েছে!**");
        sendMessage($chatId, "প্রধান মেনু:", getMainKeyboard($isAdmin));
        exit;
    }

    // --- Manual task submission start ---
    if ($data === "user_manual_complete") {
        if (empty($users[$chatId]['active_manual_task'])) {
            sendMessage($chatId, "⚠️ আপনার জন্য কোনো সক্রিয় ম্যানুয়াল টাস্ক পাওয়া যায়নি। নতুন টাস্ক নিতে 📝 কাজ বাটনে চাপ দিন।");
            exit;
        }
        $users[$chatId]['awaiting_manual_submission'] = true;
        saveData($usersDbFile, $users);
        editMessageText($chatId, $messageId, "📩 **টাস্ক সাবমিশন**\n\nটাস্ক সম্পন্ন করার প্রমাণ/রিপোর্ট লিখে পাঠান। ছবি বা ফাইলের প্রমাণ হলে সেটিও পাঠাতে পারেন।", $cancelKeyboard);
        exit;
    }

    // --- Task Submission Start ---
    if ($data === "user_task_complete") {
        $users[$chatId]['awaiting_submission'] = true;
        saveData($usersDbFile, $users);

        editMessageText($chatId, $messageId, "📥 **কাজ সাবমিশন নির্দেশাবলী:**\n\nআপনি যে জিমেইল একাউন্টটি তৈরি করেছেন, সেটি টাইপ করে বা কপি করে নিচে মেসেজ পাঠান।", $cancelKeyboard);
        exit;
    }

    // --- Cashout Method Selection ---
    if (in_array($data, ["wmethod_bkash", "wmethod_nagad"])) {
        $method = ($data === "wmethod_bkash") ? "বিকাশ" : "নগদ";
        $users[$chatId]['withdraw_method']          = $method;
        $users[$chatId]['awaiting_withdraw_number'] = true;
        saveData($usersDbFile, $users);

        sendMessage($chatId, "📲 **{$method} পেমেন্ট গেটওয়ে**\n\nঅনুগ্রহ করে আপনার **{$method} নম্বরটি** লিখে মেসেজ পাঠান:", $cancelKeyboard);
        exit;
    }

    // --- Deposit Method Selection ---
    if (in_array($data, ["depmethod_bkash", "depmethod_nagad"])) {
        $isBkash = ($data === "depmethod_bkash");
        $method  = $isBkash ? "বিকাশ (bKash)" : "নগদ (Nagad)";
        $number  = $isBkash ? $bkashNumber : $nagadNumber;

        $users[$chatId]['deposit_method']           = $method;
        $users[$chatId]['awaiting_deposit_details'] = true;
        saveData($usersDbFile, $users);

        $depMsg = "📥 **{$method} ডিপোজিট**\n\n" .
                  "নিচের নম্বরে টাকা Send Money করুন:\n" .
                  "📱 **নম্বর:** `{$number}` *(Personal)*\n" .
                  "💵 **সর্বনিম্ন ডিপোজিট:** **{$minDeposit} টাকা**\n\n" .
                  "⚠️ **টাকা পাঠানোর পর নিচের মতো এক মেসেজে পাঠান:**\n" .
                  "`[টাকার পরিমাণ] [TrxID]`\n\n" .
                  "👉 **উদাহরণ:** `50 9J82KS10`";

        sendMessage($chatId, $depMsg, $cancelKeyboard);
        exit;
    }

    // --- Admin Panel Callback Actions ---
    if ($isAdmin) {
        if ($data === "admin_broadcast") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_broadcast'] = true;
            saveData($usersDbFile, $users);

            sendMessage($chatId, "📢 **ব্রডকাস্ট মেসেজ অপশন**\n\nযে কনটেন্টটি সকল বটের ইউজারের কাছে পাঠাতে চান, সেটি পাঠান। টেক্সট, ইমোজি, ছবি, ভিডিও, ফাইল, লিংক ও ক্যাপশন সাপোর্ট করে:", $cancelKeyboard);
            exit;
        }

        if ($data === "admin_set_price") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_price_change'] = true;
            saveData($usersDbFile, $users);

            sendMessage($chatId, "💰 **জিমেইল প্রাইস পরিবর্তন**\n\nবর্তমান প্রাইস: **{$gmailPrice} টাকা**\n\nপ্রতি জিমেইলের নতুন দাম (টাকায়) লিখে পাঠান (যেমন: `18` বা `20`):", $cancelKeyboard);
            exit;
        }

        if ($data === "admin_set_numbers") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_number_change'] = true;
            saveData($usersDbFile, $users);

            $msg = "📱 **বিকাশ ও নগদ নম্বর পরিবর্তন**\n\n" .
                   "🔴 বর্তমান বিকাশ: `{$bkashNumber}`\n" .
                   "🟠 বর্তমান নগদ: `{$nagadNumber}`\n\n" .
                   "নতুন দুটি নম্বর এক লাইনে স্পেস দিয়ে লিখে পাঠান:\n" .
                   "`[বিকাশ_নম্বর] [নগদ_নম্বর]`\n\n" .
                   "👉 **উদাহরণ:** `01852261415 01852261415`";
            sendMessage($chatId, $msg, $cancelKeyboard);
            exit;
        }

        if ($data === "admin_set_channel") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_channel_change'] = true;
            saveData($usersDbFile, $users);

            $currChan = !empty($settings['proof_channel']) ? $settings['proof_channel'] : "সেট করা নেই";
            $msg = "📢 **প্রুফ ও লগের প্রাইভেট চ্যানেল সেটআপ**\n\n" .
                   "বর্তমান চ্যানেল/Chat ID: `{$currChan}`\n\n" .
                   "প্রাইভেট চ্যানেলের ক্ষেত্রে **Chat ID** (যেমন: `-1001234567890`)\n" .
                   "অথবা পাবলিক চ্যানেলের ক্ষেত্রে **Username** (যেমন: `@MyChannel`) লিখে পাঠান:\n\n" .
                   "⚠️ **মনে রাখবেন:** বটকে অবশ্যই আপনার চ্যানেলে **Admin** বানাতে হবে যাতে সে পোস্ট করতে পারে।";
            sendMessage($chatId, $msg, $cancelKeyboard);
            exit;
        }

        if ($data === "admin_find_user") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_find_user'] = true;
            saveData($usersDbFile, $users);

            sendMessage($chatId, "🔍 **ইউজার খুঁজুন**\n\nযে ইউজারের তথ্য জানতে চান, তার **Telegram User ID** লিখে পাঠান:", $cancelKeyboard);
            exit;
        }

        if ($data === "admin_users" || preg_match('/^admin_users_page_\\d+$/', $data)) {
            $page = ($data === "admin_users") ? 0 : (int)substr($data, strlen("admin_users_page_"));
            $perPage = 15;
            $allIds = array_values(array_filter(array_keys($users), 'is_numeric'));
            sort($allIds, SORT_NUMERIC);
            $total = count($allIds);
            $pages = max(1, (int)ceil($total / $perPage));
            $page = max(0, min($page, $pages - 1));
            $slice = array_slice($allIds, $page * $perPage, $perPage);
            $list = "👥 **সকল ইউজারের তথ্য**\n━━━━━━━━━━━━━━━━━━\n👤 মোট ইউজার: **{$total}**\n📄 পৃষ্ঠা: " . ($page + 1) . "/{$pages}\n\n";
            foreach ($slice as $uid) {
                $u = $users[$uid];
                $uname = ltrim(trim((string)($u['username'] ?? '')), '@');
                $safeUname = str_replace('_', '\\_', $uname);
                $nameLink = ($uname !== '') ? '[@' . $safeUname . '](https://t.me/' . rawurlencode($uname) . ')' : 'username নেই';
                $bal = number_format((float)($u['balance'] ?? 0), 2);
                $list .= "🆔 `{$uid}`\n👤 {$nameLink}\n💰 ব্যালেন্স: **{$bal} টাকা**\n──────────────\n";
            }
            $nav = [];
            if ($page > 0) $nav[] = ["text" => "⬅️ আগের", "callback_data" => "admin_users_page_" . ($page - 1)];
            if ($page < $pages - 1) $nav[] = ["text" => "পরের ➡️", "callback_data" => "admin_users_page_" . ($page + 1)];
            $kb = ['inline_keyboard' => []];
            if ($nav) $kb['inline_keyboard'][] = $nav;
            $kb['inline_keyboard'][] = [["text" => "🔙 এডমিন প্যানেল", "callback_data" => "admin_back"]];
            sendMessage($chatId, $list, $kb);
            exit;
        }

        if ($data === "admin_add_balance") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_balance_user'] = true;
            saveData($usersDbFile, $users);
            sendMessage($chatId, "💰 **ইউজারের ব্যালেন্স যোগ করুন**\nপ্রথমে ইউজারের Telegram ID পাঠান:", $cancelKeyboard);
            exit;
        }

        if ($data === "admin_manual_task") {
            resetUserState($users, $chatId, $usersDbFile);
            $users[$chatId]['awaiting_manual_task'] = true;
            saveData($usersDbFile, $users);
            sendMessage($chatId, "🎯 **ম্যানুয়াল টাস্ক যোগ করুন**\n\nএখন টাস্কের মেসেজটি পাঠান। এটি টাস্ক তালিকায় সংরক্ষিত হবে। ইউজার **📝 কাজ ▸** বাটনে চাপলে তালিকা থেকে একটি টাস্ক র‍্যান্ডমভাবে তাকে দেওয়া হবে। টেক্সট, ইমোজি, ছবি, ফাইল ও লিংক সমর্থিত।", $cancelKeyboard);
            exit;
        }

        if ($data === "admin_back") {
            sendMessage($chatId, "এডমিন প্যানেলে ফিরতে /admin লিখুন।", getMainKeyboard(true));
            exit;
        }
    }

    // --- Admin Approval Handlers ---
    $parts  = explode("_", $data);
    $action = $parts[0] ?? '';

    // 1. Gmail Task Approval
    if (in_array($action, ["accept", "reject"])) {
        if (!$isAdmin) {
            sendMessage($chatId, "⚠️ **অ্যাক্সেস Denied!** আপনি এডমিন নন।");
            exit;
        }

        $subId = ($parts[1] ?? '') . "_" . ($parts[2] ?? '');

        if (!isset($pending[$subId])) {
            editMessageText($chatId, $messageId, "⚠️ **এই কাজটি ইতোমধ্যে রিভিউ করা শেষ!** 🛑");
            exit;
        }

        $subData    = $pending[$subId];
        $targetUser = $subData['user_id'];
        unset($pending[$subId]);
        saveData($pendingDbFile, $pending);

        if ($action === "accept") {
            // Daily Reset Logic
            $today = date("Y-m-d");
            if (!isset($settings['last_reset_date']) or $settings['last_reset_date'] !== $today) {
                $settings['last_reset_date']      = $today;
                $settings['daily_approved_count'] = 0;
            }

            $settings['daily_approved_count']++;
            saveData($settingsDbFile, $settings);

            $serialNum = $settings['daily_approved_count'];

            if (isset($users[$targetUser])) {
                $users[$targetUser]['pending'] = max(0, ($users[$targetUser]['pending'] ?? 0) - $gmailPrice);
                $users[$targetUser]['balance'] = ($users[$targetUser]['balance'] ?? 0) + $gmailPrice;
                saveData($usersDbFile, $users);
            }

            editMessageText($chatId, $messageId, "✅ **কাজ অনুমোদিত (Approved)!**\n👤 ইউজার `{$targetUser}`-কে **{$gmailPrice} টাকা** যুক্ত করা হয়েছে।\n🔢 আজকের সিরিয়াল: **#{$serialNum}**");
            sendMessage($targetUser, "🎉 **অভিনন্দন!** আপনার জমাকৃত জিমেইলটি এডমিন কর্তৃক এপ্রুভ করা হয়েছে এবং **{$gmailPrice} টাকা** 💵 আপনার ব্যালেন্সে যোগ করা হয়েছে। 🥳");

            // Send Detailed Task Log to Private Proof Channel
            if (!empty($settings['proof_channel'])) {$subGmail = $subData['gmail'] ?? 'N/A';$subPass  = $subData['pass'] ?? 'N/A';$timeNow  = date("Y-m-d h:i A");

                $channelMsg = "📋 **[COMPLETED TASK LOG]**\n" .
                              "━━━━━━━━━━━━━━━━━━━\n" .
                              "🔢 **দৈনিক সিরিয়াল:** #{$serialNum}\n" .
                              "👤 **ইউজার ID:** `{$targetUser}`\n" .
                              "📧 **ইমেইল:** `{$subGmail}`\n" .
                              "🔑 **পাসওয়ার্ড:** `{$subPass}`\n" .
                              "📅 **তারিখ ও সময়:** {$timeNow}\n" .
                              "━━━━━━━━━━━━━━━━━━━";

                sendMessage($settings['proof_channel'],$channelMsg);
            }
        } elseif ($action === "reject") {
            if (isset($users[$targetUser])) {$users[$targetUser]['pending'] = max(0, ($users[$targetUser]['pending'] ?? 0) -$gmailPrice);
                saveData($usersDbFile,$users);
            }
            editMessageText($chatId,$messageId, "❌ **কাজ বাতিল করা হয়েছে (Rejected)!**");
            sendMessage($targetUser, "❌ **দুঃখিত!** আপনার জমাকৃত কাজটিতে ত্রুটি থাকায় এডমিন তা বাতিল করেছে। ⚠️");
        }
        exit;
    }

    // Manual task review (admin only)
    if (in_array($action, ["maccept", "mreject"], true)) {
        if (!$isAdmin) { sendMessage($chatId, "⛔ এই কাজটি রিভিউ করার অনুমতি আপনার নেই।"); exit; }
        $manualId = implode('_', array_slice($parts, 1));
        $manualPendingFile = __DIR__ . "/manual_pending.json";
        $manualPending = loadData($manualPendingFile);
        if (!isset($manualPending[$manualId])) { editMessageText($chatId, $messageId, "⚠️ এই ম্যানুয়াল টাস্কটি ইতোমধ্যে রিভিউ করা হয়েছে।"); exit; }
        $item = $manualPending[$manualId]; unset($manualPending[$manualId]); saveData($manualPendingFile, $manualPending);
        $target = (string)$item['user_id'];
        if ($action === "maccept") {
            $reward = max(0, (float)($item['reward'] ?? $gmailPrice));
            if (isset($users[$target])) { $users[$target]['balance'] = (float)($users[$target]['balance'] ?? 0) + $reward; saveData($usersDbFile, $users); }
            editMessageText($chatId, $messageId, "✅ ম্যানুয়াল টাস্ক অনুমোদিত।\nইউজার ID: {$target}\nপুরস্কার: " . number_format($reward, 2) . " টাকা");
            sendMessage($target, "🎉 আপনার ম্যানুয়াল টাস্ক অনুমোদিত হয়েছে!\n💰 ব্যালেন্সে " . number_format($reward, 2) . " টাকা যোগ হয়েছে।");
        } else {
            editMessageText($chatId, $messageId, "❌ ম্যানুয়াল টাস্ক বাতিল করা হয়েছে।");
            sendMessage($target, "❌ আপনার ম্যানুয়াল টাস্কটি অনুমোদিত হয়নি। প্রয়োজনে নির্দেশনা দেখে আবার চেষ্টা করুন।");
        }
        exit;
    }

    // 2. Cashout Approval
    if (in_array($action, ["waccept", "wreject"])) {
        if (!$isAdmin) {
            sendMessage($chatId, "⚠️ **অ্যাক্সেস Denied!**");
            exit;
        }

        $wId = ($parts[1] ?? '') . "_" . ($parts[2] ?? '');

        if (!isset($withdrawals[$wId])) {
            editMessageText($chatId,$messageId, "⚠️ **এই ক্যাশআউট রিকোয়েস্টটি ইতোমধ্যেই প্রসেস করা হয়েছে!**");
            exit;
        }

        $wData      = $withdrawals[$wId];
        $targetUser =$wData['user_id'];
        $amount     =$wData['amount'];
        $method     =$wData['method'];
        $number     =$wData['number'];

        unset($withdrawals[$wId]);
        saveData($withdrawDbFile,$withdrawals);

        if ($action === "waccept") {
            editMessageText($chatId,$messageId, "✅ **পেমেন্ট সম্পন্ন হয়েছে!**\n👤 ইউজার: `{$targetUser}`\n💳 মেথড: {$method}\n📱 নম্বর: `{$number}`\n💰 পরিমাণ: **{$amount} টাকা**");
            sendMessage($targetUser, "🎉 **অভিনন্দন!** আপনার **{$amount} টাকা** উত্তোলন সফল হয়েছে। **{$method} ({$number})** অ্যাকাউন্টে টাকা পাঠিয়ে দেওয়া হয়েছে। 💸");
        } elseif ($action === "wreject") {
            if (isset($users[$targetUser])) {$users[$targetUser]['balance'] = ($users[$targetUser]['balance'] ?? 0) +$amount;
                saveData($usersDbFile,$users);
            }
            editMessageText($chatId,$messageId, "❌ **ক্যাশআউট রিকোয়েস্ট বাতিল করা হয়েছে!** (টাকা ওয়ালেটে রিফান্ড করা হয়েছে)");
            sendMessage($targetUser, "❌ **দুঃখিত!** আপনার **{$amount} টাকা** ক্যাশআউট রিকোয়েস্ট বাতিল করা হয়েছে এবং টাকা আপনার ওয়ালেটে ফেরত দেওয়া হয়েছে।");
        }
        exit;
    }

    // 3. Deposit Approval
    if (in_array($action, ["daccept", "dreject"])) {
        if (!$isAdmin) {
            sendMessage($chatId, "⚠️ **অ্যাক্সেস Denied!**");
            exit;
        }

        $depId = ($parts[1] ?? '') . "_" . ($parts[2] ?? '');

        if (!isset($deposits[$depId])) {
            editMessageText($chatId,$messageId, "⚠️ **এই ডিপোজিট রিকোয়েস্টটি প্রসেস করা হয়ে গেছে!**");
            exit;
        }

        $depData    = $deposits[$depId];
        $targetUser =$depData['user_id'];
        $amount     =$depData['amount'];
        $trx        =$depData['trx'];
        $method     =$depData['method'];

        unset($deposits[$depId]);
        saveData($depositDbFile,$deposits);

        if ($action === "daccept") {
            if (isset($users[$targetUser])) {$users[$targetUser]['balance'] = ($users[$targetUser]['balance'] ?? 0) +$amount;
                saveData($usersDbFile,$users);
            }
            editMessageText($chatId,$messageId, "✅ **ডিপোজিট সফলভাবে এপ্রুভড!**\n👤 ইউজার: `{$targetUser}`\n💰 পরিমাণ: **{$amount} টাকা**\n🆔 TrxID: `{$trx}`");
            sendMessage($targetUser, "🎉 **অভিনন্দন!** আপনার **{$amount} টাকা** ডিপোজিট সফলভাবে গ্রহণ করা হয়েছে এবং ব্যালেন্সে যোগ করা হয়েছে! 💸");
        } elseif ($action === "dreject") {
            editMessageText($chatId,$messageId, "❌ **ডিপোজিট রিকোয়েস্ট বাতিল করা হয়েছে!**");
            sendMessage($targetUser, "❌ **দুঃখিত!** আপনার **{$amount} টাকা** ডিপোজিট রিকোয়েস্টটি (TrxID: `{$trx}`) বাতিল করা হয়েছে। সঠিক তথ্য দিন।");
        }
        exit;
    }
}

// ------------------------------------------
// B. TEXT MESSAGE HANDLERS
// ------------------------------------------
if (isset($update['message'])) {
    $message =$update['message'];
    $chatId  =$message['chat']['id'];
    $text    = trim($message['text'] ?? '');
    $user    =$message['from'];
    $userId  =$user['id'];

    $isAdmin = (in_array(($user['username'] ?? ''), [$adminUsername])) or ($chatId ==$adminChatId);

    // Ensure User Database Structure
    if (!isset($users[$userId])) {
        $users[$userId] = [
            'balance'                  => 0.0,
            'pending'                  => 0.0,
            'username'                 => $user['username'] ?? '',
            'first_name'               => $user['first_name'] ?? '',
            'referrals'                => 0,
            'referred_by'              => null,
            'awaiting_submission'      => false,
            'awaiting_withdraw_number' => false,
            'awaiting_withdraw_amount' => false,
            'awaiting_deposit_details' => false,
            'awaiting_broadcast'       => false,
            'awaiting_price_change'    => false,
            'awaiting_number_change'   => false,
            'awaiting_channel_change'  => false,
            'awaiting_find_user'       => false,
            'awaiting_balance_user'    => false,
            'awaiting_balance_amount'  => false,
            'awaiting_manual_task'     => false,
            'withdraw_method'          => null,
            'withdraw_number'          => null,
            'deposit_method'           => null,
            'generated_gmail'          => null,
            'generated_pass'           => null
        ];
        saveData($usersDbFile,$users);
    }
    // Keep Telegram username/name current for admin search and user directory.
    $users[$userId]['username'] = $user['username'] ?? '';
    $users[$userId]['first_name'] = $user['first_name'] ?? '';
    saveData($usersDbFile, $users);

    // Command Filter: /start Always Resets States
    if (strpos($text, "/start") === 0) {
        resetUserState($users, $userId,$usersDbFile);

        // Check for Referral Link (`/start 12345678`)
        $parts = explode(" ", $text);
        if (count($parts) > 1 and !empty($parts[1])) {
            $refId = trim($parts[1]);
            if ($refId !== (string)$userId and isset($users[$refId]) and empty($users[$userId]['referred_by'])) {$users[$userId]['referred_by'] =$refId;
                $users[$refId]['referrals']    = ($users[$refId]['referrals'] ?? 0) + 1;
                saveData($usersDbFile,$users);
            }
        }

        $firstName = trim((string)($user['first_name'] ?? 'বন্ধু'));
        $firstName = preg_replace('/[\x00-\x1F\x7F]/u', '', $firstName);
        $firstName = str_replace(['*', '_', '`', '['], ['\\*', '\\_', '\\`', '\\['], $firstName);
        $welcomeText = "🌟 **E-GMAIL BD — স্বাগতম!** 🌟\n" .
                       "━━━━━━━━━━━━━━━━━━━━\n" .
                       "👋 প্রিয় **" . $firstName . "**, আপনার আয় করার যাত্রা শুরু হোক এখান থেকেই!\n\n" .
                       "🎯 **সহজ ধাপ:**\n" .
                       "1️⃣ নিচের **📝 কাজ ▸** বাটনে চাপ দিয়ে কাজ নিন।\n" .
                       "2️⃣ কাজের নির্দেশনা ভালোভাবে অনুসরণ করুন।\n" .
                       "3️⃣ প্রমাণ জমা দিন এবং এডমিনের রিভিউয়ের জন্য অপেক্ষা করুন।\n\n" .
                       "💰 **ব্যালেন্স** — আপনার আয় দেখুন\n" .
                       "📥 **ডিপোজিট** — অ্যাকাউন্টে টাকা যোগ করুন\n" .
                       "💸 **টাকা উত্তোলন** — উত্তোলনের অনুরোধ দিন\n" .
                       "🎁 **My Referrals** — বন্ধুদের আমন্ত্রণ জানান\n\n" .
                       "━━━━━━━━━━━━━━━━━━━━\n" .
                       "✅ নিয়ম মেনে কাজ করুন • তথ্য সঠিক দিন • শুভকামনা!";
        sendMessage($chatId, $welcomeText, getMainKeyboard($isAdmin));
        exit;
    }

    // Step 1: Broadcast any Telegram message type (text, emoji, photo, document, link, etc.)
    if (!empty($users[$userId]['awaiting_broadcast']) && $isAdmin) {
        $users[$userId]['awaiting_broadcast'] = false;
        saveData($usersDbFile, $users);
        $count = 0;
        $failed = 0;
        foreach ($users as $uId => $uData) {
            if (is_numeric($uId) && (string)$uId !== (string)$userId) {
                $result = copyMessageToUser($uId, $chatId, $message['message_id']);
                $decoded = json_decode((string)$result, true);
                if (is_array($decoded) && !empty($decoded['ok'])) $count++;
                else $failed++;
            }
        }
        sendMessage($chatId, "✅ **ব্রডকাস্ট সম্পন্ন হয়েছে!**\nসফল: **{$count}** জন\nব্যর্থ: **{$failed}** জন", getMainKeyboard(true));
        exit;
    }

    // Admin: choose user ID, then enter the balance amount to add.
    if (!empty($users[$userId]['awaiting_balance_user']) && $isAdmin) {
        $targetId = trim($text);
        if (!ctype_digit($targetId) || !isset($users[$targetId])) {
            sendMessage($chatId, "❌ ইউজার পাওয়া যায়নি। সঠিক Telegram User ID দিন:", $cancelKeyboard);
            exit;
        }
        $users[$userId]['balance_target_id'] = $targetId;
        $users[$userId]['awaiting_balance_user'] = false;
        $users[$userId]['awaiting_balance_amount'] = true;
        saveData($usersDbFile, $users);
        $targetName = !empty($users[$targetId]['username']) ? '@' . ltrim($users[$targetId]['username'], '@') : 'username নেই';
        sendMessage($chatId, "👤 ইউজার: `{$targetId}` ({$targetName})\nবর্তমান ব্যালেন্স: " . number_format((float)($users[$targetId]['balance'] ?? 0), 2) . " টাকা\n\nকত টাকা যোগ করবেন? শুধু পরিমাণ লিখুন:", $cancelKeyboard);
        exit;
    }

    if (!empty($users[$userId]['awaiting_balance_amount']) && $isAdmin) {
        $amountText = trim($text);
        if (!is_numeric($amountText) || (float)$amountText <= 0 || (float)$amountText > 100000000) {
            sendMessage($chatId, "❌ সঠিক পরিমাণ দিন। পরিমাণ অবশ্যই ০-এর বেশি হতে হবে:", $cancelKeyboard);
            exit;
        }
        $targetId = (string)($users[$userId]['balance_target_id'] ?? '');
        $amount = round((float)$amountText, 2);
        if (!isset($users[$targetId])) {
            $users[$userId]['awaiting_balance_amount'] = false;
            saveData($usersDbFile, $users);
            sendMessage($chatId, "❌ ইউজার পাওয়া যায়নি। আবার চেষ্টা করুন।", getMainKeyboard(true));
            exit;
        }
        $users[$targetId]['balance'] = (float)($users[$targetId]['balance'] ?? 0) + $amount;
        $users[$userId]['awaiting_balance_amount'] = false;
        unset($users[$userId]['balance_target_id']);
        saveData($usersDbFile, $users);
        sendMessage($targetId, "🎉 এডমিন আপনার ব্যালেন্সে " . number_format($amount, 2) . " টাকা যোগ করেছেন।", getMainKeyboard(false));
        sendMessage($chatId, "✅ সফলভাবে `{$targetId}` ইউজারের ব্যালেন্সে " . number_format($amount, 2) . " টাকা যোগ হয়েছে।\nনতুন ব্যালেন্স: " . number_format((float)$users[$targetId]['balance'], 2) . " টাকা", getMainKeyboard(true));
        exit;
    }

    // Admin manual task: save source message; assign only when a user taps the Work button.
    if (!empty($users[$userId]['awaiting_manual_task']) && $isAdmin) {
        $users[$userId]['awaiting_manual_task'] = false;
        $settings['manual_tasks'] = is_array($settings['manual_tasks'] ?? null) ? $settings['manual_tasks'] : [];
        $settings['manual_tasks'][] = [
            'chat_id' => (string)$chatId,
            'message_id' => (int)$message['message_id'],
            'added_by' => (string)$userId,
            'added_at' => time()
        ];
        saveData($settingsDbFile, $settings);
        saveData($usersDbFile, $users);
        sendMessage($chatId, "✅ **ম্যানুয়াল টাস্ক সংরক্ষিত হয়েছে!**\n\n📚 টাস্ক তালিকায় মোট: **" . count($settings['manual_tasks']) . "টি**\nইউজার **📝 কাজ ▸** বাটনে চাপলে একটি টাস্ক র‍্যান্ডমভাবে পাবে।", getMainKeyboard(true));
        exit;
    }

    // Step 2: Receiving Admin New Gmail Price
    if (!empty($users[$userId]['awaiting_price_change']) and$isAdmin) {
        $newPrice = floatval($text);
        if ($newPrice > 0) {
            $settings['gmail_price'] =$newPrice;
            saveData($settingsDbFile,$settings);
            $gmailPrice =$newPrice;

            $users[$userId]['awaiting_price_change'] = false;
            saveData($usersDbFile,$users);

            sendMessage($chatId, "✅ **জিমেইল প্রাইস আপডেট সফল হয়েছে!**\nএখন থেকে প্রতি জিমেইলে ইউজার পাবে: **{$newPrice} টাকা**", getMainKeyboard(true));
        } else {
            sendMessage($chatId, "❌ অকার্যকর ইনপুট! সঠিক মূল্য লিখুন (যেমন: 18, 20):", $cancelKeyboard);
        }
        exit;
    }

    // Step 3: Receiving Admin New Payment Numbers
    if (!empty($users[$userId]['awaiting_number_change']) and$isAdmin) {
        $parts = preg_split('/\s+/',$text);
        if (count($parts) >= 2) {
            $settings['bkash_number'] = trim($parts[0]);
            $settings['nagad_number'] = trim($parts[1]);
            saveData($settingsDbFile,$settings);

            $bkashNumber =$settings['bkash_number'];
            $nagadNumber =$settings['nagad_number'];

            $users[$userId]['awaiting_number_change'] = false;
            saveData($usersDbFile,$users);

            sendMessage($chatId, "✅ **নম্বর পরিবর্তন সফল হয়েছে!**\n\n🔴 বিকাশ: `{$bkashNumber}`\n🟠 নগদ: `{$nagadNumber}`", getMainKeyboard(true));
        } else {
            sendMessage($chatId, "⚠️ **ভুল ফরম্যাট!** দুটি নম্বর স্পেস দিয়ে লিখুন।\nউদাহরণ: `01852261415 01852261415`", $cancelKeyboard);
        }
        exit;
    }

    // Step 4: Receiving Admin Proof Channel Setting
    if (!empty($users[$userId]['awaiting_channel_change']) and$isAdmin) {
        $settings['proof_channel'] = trim($text);
        saveData($settingsDbFile,$settings);

        $users[$userId]['awaiting_channel_change'] = false;
        saveData($usersDbFile,$users);

        sendMessage($chatId, "✅ **প্রুফ/লগ চ্যানেল আপডেট করা হয়েছে!**\nবর্তমান চ্যানেল/Chat ID: `{$text}`", getMainKeyboard(true));
        exit;
    }

    // Step 5: Receiving Admin Find User Inquiry
    if (!empty($users[$userId]['awaiting_find_user']) and$isAdmin) {
        $targetId = trim($text);
        $users[$userId]['awaiting_find_user'] = false;
        saveData($usersDbFile,$users);

        if (isset($users[$targetId])) {$u      = $users[$targetId];
            $uName = ltrim(trim((string)($u['username'] ?? '')), '@');
            $safeUName = str_replace('_', '\\_', $uName);
            $uNameDisplay = ($uName !== '') ? '[@' . $safeUName . '](https://t.me/' . rawurlencode($uName) . ')' : 'username নেই';
            $uBal   = number_format($u['balance'] ?? 0, 2);
            $uPend  = number_format($u['pending'] ?? 0, 2);
            $uRef   =$u['referrals'] ?? 0;
            $uRefBy =$u['referred_by'] ?? 'None';

            $userInfo = "👤 **ইউজার প্রোফাইল বিবরণ**\n\n" .
                        "🆔 **Telegram ID:** `{$targetId}`\n" .
                        "📛 **Username:** {$uNameDisplay}\n" .
                        "💰 **মেইন ব্যালেন্স:** `{$uBal} BDT`\n" .
                        "⏳ **পেন্ডিং ব্যালেন্স:** `{$uPend} BDT`\n" .
                        "👥 **মোট রেফারেল:** `{$uRef} জন`\n" .
                        "🔗 **Referred By ID:** `{$uRefBy}`";

            sendMessage($chatId,$userInfo, getMainKeyboard(true));
        } else {
            sendMessage($chatId, "❌ **ইউজার পাওয়া যায়নি!**\n`{$targetId}` আইডি দিয়ে কোনো ইউজার বট ব্যবহার শুরু করেনি।", getMainKeyboard(true));
        }
        exit;
    }

    // Step 6: Receiving Deposit Details (Amount + TrxID)
    if (!empty($users[$userId]['awaiting_deposit_details'])) {$parts  = preg_split('/\s+/', $text);$amount = isset($parts[0]) ? floatval($parts[0]) : 0;
        $trx    = isset($parts[1]) ? trim($parts[1]) : '';

        if ($amount < $minDeposit or empty($trx)) {
            sendMessage($chatId, "⚠️ **ভুল ফরম্যাট অথবা সর্বনিম্ন ডিপোজিট ফি পুরন হয়নি!**\n\nসর্বনিম্ন ডিপোজিট: **{$minDeposit} টাকা**\nফরম্যাট: `[টাকার পরিমাণ] [TrxID]`\n\n**উদাহরণ:** `50 9J82KS10`", $cancelKeyboard);
            exit;
        }

        $method = $users[$userId]['deposit_method'] ?? 'bKash/Nagad';
        $users[$userId]['awaiting_deposit_details'] = false;
        saveData($usersDbFile,$users);

        $depId =$userId . "_" . rand(1000, 9999);
        $deposits[$depId] = [
            'user_id' => $userId,
            'method'  => $method,
            'amount'  => $amount,
            'trx'     => $trx
        ];
        saveData($depositDbFile,$deposits);

        sendMessage($chatId, "✅ **ডিপোজিট রিকোয়েস্ট জমা হয়েছে!** 📩\n\nমেথড: **{$method}**\nপরিমাণ: **{$amount} টাকা**\nTrxID: `{$trx}`\n\n⏳ এডমিন রিভিউ করে দ্রুত আপনার ব্যালেন্স যুক্ত করে দেবে।", getMainKeyboard($isAdmin));

        $adminDepKb = [
            'inline_keyboard' => [
                [
                    ["text" => "✅ Approve Deposit", "callback_data" => "daccept_" . $depId],
                    ["text" => "❌ Reject", "callback_data" => "dreject_" . $depId]
                ]
            ]
        ];

        $adminDepMsg = "🚨 **নতুন ডিপোজিট রিকোয়েস্ট!** 📥\n\n" .
                       "👤 **ইউজার ID:** `{$userId}`\n" .
                       "💳 **মেথড:** {$method}\n" .
                       "💰 **পরিমাণ:** {$amount} BDT\n" .
                       "🆔 **TrxID:** `{$trx}`";

        sendMessage($adminChatId, "--- [ADMIN DEPOSIT NOTIFICATION] ---\n" . $adminDepMsg,$adminDepKb);
        exit;
    }

    // Step 7: Receiving Withdraw Number
    if (!empty($users[$userId]['awaiting_withdraw_number'])) {$users[$userId]['withdraw_number']          =$text;
        $users[$userId]['awaiting_withdraw_number'] = false;
        $users[$userId]['awaiting_withdraw_amount'] = true;
        saveData($usersDbFile,$users);

        $bal = number_format($users[$userId]['balance'] ?? 0, 2);
        sendMessage($chatId, "💰 **কত টাকা তুলতে চান?**\nপরিমাণ লিখে পাঠান (সর্বনিম্ন ৫০.০০ টাকা):\n\n*(বর্তমান ব্যালেন্স: {$bal} টাকা)*", $cancelKeyboard);
        exit;
    }

    // Step 8: Receiving Withdraw Amount
    if (!empty($users[$userId]['awaiting_withdraw_amount'])) {$amount  = floatval($text);$userBal = $users[$userId]['balance'] ?? 0.0;

        if ($amount < 50) {
            sendMessage($chatId, "⚠️ **সর্বনিম্ন উত্তোলন সীমা ৫০.০০ টাকা!**\nঅনুগ্রহ করে সঠিকভাবে টাকার পরিমাণ পাঠান:", $cancelKeyboard);
            exit;
        }

        if ($amount >$userBal) {
            sendMessage($chatId, "❌ **অপর্যাপ্ত ব্যালেন্স!** আপনার ওয়ালেটে এই পরিমাণ টাকা নেই। সঠিক পরিমাণ পাঠিয়েন:", $cancelKeyboard);
            exit;
        }

        $method =$users[$userId]['withdraw_method'];$number = $users[$userId]['withdraw_number'];

        $users[$userId]['balance'] -=$amount;
        $users[$userId]['awaiting_withdraw_amount'] = false;
        saveData($usersDbFile,$users);

        $wId =$userId . "_" . rand(1000, 9999);
        $withdrawals[$wId] = [
            'user_id' => $userId,
            'method'  => $method,
            'number'  => $number,
            'amount'  => $amount
        ];
        saveData($withdrawDbFile,$withdrawals);

        sendMessage($chatId, "✅ **ক্যাশআউট রিকোয়েস্ট জমা হয়েছে!** 💸\n\nমেথড: **{$method}**\nনম্বর: `{$number}`\nপরিমাণ: **{$amount} টাকা**\n\n⏳ এডমিন রিভিউ সম্পন্ন করে পেমেন্ট পাঠিয়ে দেবে।", getMainKeyboard($isAdmin));

        $adminWithdrawKb = [
            'inline_keyboard' => [
                [
                    ["text" => "✅ Approve Payment", "callback_data" => "waccept_" . $wId],
                    ["text" => "❌ Reject", "callback_data" => "wreject_" . $wId]
                ]
            ]
        ];

        $adminWithdrawMsg = "🚨 **নতুন ক্যাশআউট রিকোয়েস্ট!** 💸\n\n" .
                            "👤 **ইউজার ID:** `{$userId}`\n" .
                            "💳 **মেথড:** {$method}\n" .
                            "📱 **নম্বর:** `{$number}`\n" .
                            "💰 **পরিমাণ:** {$amount} BDT";

        sendMessage($adminChatId, "--- [ADMIN CASHOUT NOTIFICATION] ---\n" . $adminWithdrawMsg,$adminWithdrawKb);
        exit;
    }

    // Manual task proof submission: accept text, photo, document, video, etc.
    if (!empty($users[$userId]['awaiting_manual_submission']) && !empty($users[$userId]['active_manual_task'])) {
        $manualPendingFile = __DIR__ . "/manual_pending.json";
        $manualPending = loadData($manualPendingFile);
        $manualId = (string)$userId . "_" . time() . "_" . random_int(100, 999);
        $users[$userId]['awaiting_manual_submission'] = false;
        $task = $users[$userId]['active_manual_task'];
        unset($users[$userId]['active_manual_task']);
        saveData($usersDbFile, $users);
        $manualPending[$manualId] = [
            'user_id' => (string)$userId,
            'proof_text' => (string)($message['text'] ?? $message['caption'] ?? '[মিডিয়া/ফাইল প্রমাণ]'),
            'proof_message_id' => (int)$message['message_id'],
            'proof_chat_id' => (string)$chatId,
            'reward' => (float)($task['reward'] ?? $gmailPrice),
            'submitted_at' => time()
        ];
        saveData($manualPendingFile, $manualPending);
        $reviewKb = ['inline_keyboard' => [[['text' => '✅ Approve', 'callback_data' => 'maccept_' . $manualId], ['text' => '❌ Reject', 'callback_data' => 'mreject_' . $manualId]]]];
        sendMessage($chatId, "✅ আপনার টাস্কের প্রমাণ অ্যাডমিনের কাছে জমা হয়েছে। রিভিউ শেষে ফলাফল জানানো হবে।", getMainKeyboard($isAdmin));
        $proofText = (string)($manualPending[$manualId]['proof_text'] ?? '[মিডিয়া/ফাইল প্রমাণ]');
        $proofText = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $proofText);
        $adminUsernameText = ltrim((string)($users[$userId]['username'] ?? ''), '@');
        $adminUsernameText = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $adminUsernameText);
        $adminMsg = "📥 **ম্যানুয়াল টাস্ক সাবমিশন — রিভিউ প্রয়োজন**\n\n" .
                    "👤 ইউজার ID: `{$userId}`\n" .
                    "🔎 Username: " . ($adminUsernameText !== '' ? '@' . $adminUsernameText : 'নেই') . "\n" .
                    "🧾 প্রমাণের লেখা: " . $proofText . "\n" .
                    "💰 অনুমোদন করলে পুরস্কার: " . number_format((float)$manualPending[$manualId]['reward'], 2) . " টাকা\n\n" .
                    "নিচের Approve/Reject বাটন ব্যবহার করে সিদ্ধান্ত নিন।";
        sendMessage($adminChatId, $adminMsg, $reviewKb);
        // Forward original proof (including media) to admin for review.
        if ((string)$chatId !== (string)$adminChatId) { copyMessageToUser($adminChatId, $chatId, (int)$message['message_id']); }
        exit;
    }

    // Step 9: Processing Task Submission
    if (!empty($users[$userId]['awaiting_submission'])) {
        $submittedGmail =$text;
        $genGmail       =$users[$userId]['generated_gmail'];$genPass        = $users[$userId]['generated_pass'];

        $users[$userId]['pending']             = ($users[$userId]['pending'] ?? 0) +$gmailPrice;
        $users[$userId]['awaiting_submission'] = false;
        saveData($usersDbFile,$users);

        $submissionId           =$userId . "_" . rand(1000, 9999);
        $pending[$submissionId] = [
            'user_id'        => $userId,
            'gmail'          => $submittedGmail,
            'expected_gmail' => $genGmail,
            'pass'           => $genPass
        ];
        saveData($pendingDbFile,$pending);

        sendMessage($chatId, "✅ **কাজটি সফলভাবে এডমিনের কাছে পাঠানো হয়েছে!** 📩\n⏳ এডমিন চেক করে এপ্রুভ করলে **{$gmailPrice} টাকা** মেইন ব্যালেন্সে জমা হবে। 💰", getMainKeyboard($isAdmin));

        $adminKeyboard = [
            'inline_keyboard' => [
                [
                    ["text" => "✅ Approve ({$gmailPrice} Tk)", "callback_data" => "accept_" . $submissionId],
                    ["text" => "❌ Reject", "callback_data" => "reject_" . $submissionId]
                ]
            ]
        ];

        $adminMsg = "🔔 **নতুন জিমেইল জমা পড়েছে (Review Queue)** 📥\n\n" .
                    "👤 **ইউজার ID:** `{$userId}`\n" .
                    "📧 **অ্যাসাইন করা জিমেইল:** `{$genGmail}`\n" .
                    "🔑 **পাসওয়ার্ড:** `{$genPass}`\n" .
                    "📩 **ইউজারের পাঠানো ডাটা:** `{$submittedGmail}`";

        sendMessage($adminChatId, "--- [ADMIN REVIEW REQUIRED] ---\n" . $adminMsg,$adminKeyboard);
        exit;
    }

    // --- Navigation Commands ---
    if (in_array($text, ["⚙️ এডমিন প্যানেল", "/admin"])) {
        if ($isAdmin) {
            $totalUsers       = count($users);
            $pendingWorks     = count($pending);
            $pendingWithdraws = count($withdrawals);
            $pendingDeposits  = count($deposits);

            $adminControlKb = [
                'inline_keyboard' => [
                    [
                        ["text" => "📢 ব্রডকাস্ট মেসেজ", "callback_data" => "admin_broadcast"],
                        ["text" => "💰 জিমেইল প্রাইস", "callback_data" => "admin_set_price"]
                    ],
                    [
                        ["text" => "📱 নম্বর পরিবর্তন", "callback_data" => "admin_set_numbers"],
                        ["text" => "📢 প্রুফ চ্যানেল সেটিং", "callback_data" => "admin_set_channel"]
                    ],
                    [
                        ["text" => "🔍 ইউজার খুঁজুন (User ID)", "callback_data" => "admin_find_user"],
                        ["text" => "👥 সকল ইউজার ডাটা", "callback_data" => "admin_users"]
                    ],
                    [
                        ["text" => "➕ ব্যালেন্স যোগ", "callback_data" => "admin_add_balance"],
                        ["text" => "🎯 ম্যানুয়াল টাস্ক", "callback_data" => "admin_manual_task"]
                    ]
                ]
            ];

            $adminStats = "⚙️ **এডমিন কন্ট্রোল প্যানেল** ⚙️\n\n" .
                          "📊 **সার্বিক তথ্য:**\n" .
                          "👥 মোট ইউজার: **{$totalUsers}**\n" .
                          "⏳ পেন্ডিং জিমেইল কাজ: **{$pendingWorks}**\n" .
                          "💸 পেন্ডিং উইথড্র: **{$pendingWithdraws}**\n" .
                          "📥 পেন্ডিং ডিপোজিট: **{$pendingDeposits}**\n" .
                          "💰 বর্তমান জিমেইল রেট: **{$gmailPrice} টাকা**\n" .
                          "🔴 বিকাশ: `{$bkashNumber}` | 🟠 নগদ: `{$nagadNumber}`\n\n" .
                          "👇 অপশন সিলেক্ট করুন:";
            sendMessage($chatId, $adminStats,$adminControlKb);
        } else {
            sendMessage($chatId, "❌ আপনি এই বটের এডমিন নন।");
        }
    } 
    elseif ($text === "📝 কাজ ▸") {
        // If manual tasks are queued, randomly choose one and deliver it to the user who tapped Work.
        $manualTasks = is_array($settings['manual_tasks'] ?? null) ? $settings['manual_tasks'] : [];
        if (!empty($manualTasks)) {
            $taskIndex = array_rand($manualTasks);
            $task = $manualTasks[$taskIndex];
            $copyResult = copyMessageToUser($chatId, $task['chat_id'], (int)$task['message_id']);
            $copyDecoded = json_decode((string)$copyResult, true);
            if (is_array($copyDecoded) && !empty($copyDecoded['ok'])) {
                unset($manualTasks[$taskIndex]);
                $settings['manual_tasks'] = array_values($manualTasks);
                $users[$userId]['active_manual_task'] = ['assigned_at' => time(), 'reward' => (float)($task['reward'] ?? $gmailPrice)];
                $users[$userId]['awaiting_manual_submission'] = false;
                saveData($settingsDbFile, $settings);
                saveData($usersDbFile, $users);
                $manualKb = ['inline_keyboard' => [[['text' => '📩 টাস্ক সাবমিট করুন', 'callback_data' => 'user_manual_complete'], ['text' => '❌ বাতিল', 'callback_data' => 'user_task_cancel']]]];
                sendMessage($chatId, "🎯 **আপনার জন্য নতুন ম্যানুয়াল টাস্ক!**\n\n📌 নির্দেশনা অনুযায়ী কাজটি সম্পন্ন করুন। এরপর নিচের **📩 টাস্ক সাবমিট করুন** বাটনে চাপ দিয়ে প্রমাণ পাঠান।\n\n💰 অনুমোদিত পুরস্কার: **" . number_format((float)($task['reward'] ?? $gmailPrice), 2) . " টাকা**", $manualKb);
                exit;
            }
            // Keep the task in queue if delivery failed; continue to the regular task flow.
        }
        list($gmUser, $gmPass) = generateCredentials();$users[$userId]['generated_gmail'] =$gmUser;
        $users[$userId]['generated_pass']  =$gmPass;
        saveData($usersDbFile,$users);

        $taskKeyboard = [
            'inline_keyboard' => [
                [
                    ["text" => "✅ Complete Task", "callback_data" => "user_task_complete"],
                    ["text" => "❌ Cancel Task", "callback_data" => "user_task_cancel"]
                ]
            ]
        ];

        $msg = "📧 **নতুন জিমেইল তৈরির কাজ** 💼\n\n" .
               "🔹 **ইউজারনেম:** `{$gmUser}` 📋\n" .
               "🔹 **পাসওয়ার্ড:** `{$gmPass}` 🔑\n\n" .
               "⚠️ **মূল্য:** প্রতি এপ্রুভড জিমেইলে **{$gmailPrice} টাকা**।\n" .
               "নির্দেশনা অনুযায়ী জিমেইল খুলে **Complete Task** বাটন চাপুন।";
        sendMessage($chatId, $msg,$taskKeyboard);
    } 
    elseif ($text === "💵 ব্যালেন্স") {
        $bal  = number_format($users[$userId]['balance'] ?? 0, 2);
        $pend = number_format($users[$userId]['pending'] ?? 0, 2);$msg  = "📊 **আপনার অ্যাকাউন্ট তথ্য** 👤\n\n" .
               "💰 **মূল ব্যালেন্স:** `{$bal} টাকা` 🤑\n" .
               "⏳ **পেন্ডিং ব্যালেন্স:** `{$pend} টাকা` 🔄";
        sendMessage($chatId,$msg);
    } 
    elseif ($text === "💰 টাকা উত্তোলন") {
        sendMessage($chatId, "💳 **টাকা উত্তোলন সেকশন** 💸\n\nপেমেন্ট নেওয়ার জন্য বিকাশ বা নগদ নির্বাচন করুন 👇", $withdrawKeyboard);
    }
    elseif ($text === "📥 ডিপোজিট") {
        sendMessage($chatId, "📥 **ব্যালেন্স রিচার্জ / ডিপোজিট** 💳\n\nটাকা যোগ করতে নিচে বিকাশ বা নগদ নির্বাচন করুন 👇", $depositKeyboard);
    }
    elseif ($text === "🎁 My Referrals") {
        $refCount = $users[$userId]['referrals'] ?? 0;
        $refLink  = "https://t.me/" . $botUsername . "?start=" . $userId;

        $msg = "🎁 **রেফারেল প্রোগ্রাম** 👥\n\n" .
               "আপনার রেফারেল লিংক ব্যবহার করে বন্ধুদের বট শেয়ার করুন!\n\n" .
               "🔗 **আপনার রেফারেল লিংক:**\n`{$refLink}`\n\n" .
               "📊 **আপনার সফল রেফার:** `{$refCount} জন`";
        sendMessage($chatId,$msg);
    }
    elseif ($text === "🙋‍♂️ সাপোর্ট") {
        $supportKeyboard = [
            'inline_keyboard' => [
                [
                    ["text" => "💬 এডমিনের সাথে কথা বলুন", "url" => "https://t.me/" . $adminUsername]
                ]
            ]
        ];
        $msg = "🙋‍♂️ **সাপোর্ট সেন্টার** 📞\n\n" .
               "যেকোনো সমস্যা বা সহযোগিতার জন্য নিচের বাটনে ক্লিক করে সরাসরি এডমিনের সাথে কথা বলুন:";
        sendMessage($chatId, $msg,$supportKeyboard);
    } 
    elseif ($text === "👶 আমি নতুন") {
        sendMessage($chatId, "ℹ️ **বটের কাজ শুরু করতে '📝 কাজ ▸' বাটনে চাপ দিন।**");
    }
}
?>
