<?php
/**
 * Telegram Gmail Task Bot (Production Ready)
 * Optimized for GitHub Hosting & Render/Railway/VPS Services
 */

// ==========================================
// 1. CONFIGURATION SETTINGS
// ==========================================
$botToken      = "8760332791:AAHLlAZdTKyW1lzdfvfiYIg0tZGKRf8zu7w";
$adminUsername = "raiyan_07j"; 
$adminChatId   = "7278071284"; // আপনার নির্দিষ্ট চ্যাট আইডি যুক্ত করা হয়েছে

$website       = "https://api.telegram.org/bot" . $botToken;

// Database Files Path
$usersDbFile    = __DIR__ . "/users.json";
$pendingDbFile  = __DIR__ . "/pending.json";
$withdrawDbFile = __DIR__ . "/withdrawals.json";

// Initialize Databases
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

$users       = loadData($usersDbFile);
$pending     = loadData($pendingDbFile);
$withdrawals = loadData($withdrawDbFile);

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

// ==========================================
// 3. KEYBOARDS & LAYOUTS
// ==========================================
$mainKeyboard = [
    'keyboard' => [
        [["text" => "📝 কাজ ▸"], ["text" => "💵 ব্যালেন্স"]],
        [["text" => "💰 টাকা উত্তোলন"], ["text" => "🎁 My Referrals"]],
        [["text" => "🙋‍♂️ সাপোর্ট"], ["text" => "👶 আমি নতুন"]]
    ],
    'resize_keyboard' => true
];

$withdrawKeyboard = [
    'inline_keyboard' => [
        [
            ["text" => "🔴 বিকাশ (bKash)", "callback_data" => "wmethod_bkash"],
            ["text" => "🟠 নগদ (Nagad)", "callback_data" => "wmethod_nagad"]
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
// A. CALLBACK QUERY (BUTTON CLICK HANDLERS)
// ------------------------------------------
if (isset($update['callback_query'])) {
    $callback  = $update['callback_query'];
    $chatId    = $callback['message']['chat']['id'];
    $messageId =$callback['message']['message_id'];
    $data      =$callback['data'];
    $fromUser  =$callback['from'];

    // --- User Task Actions (Complete / Cancel) ---
    if ($data === "user_task_complete") {
        $users[$chatId]['awaiting_submission'] = true;
        saveData($usersDbFile,$users);

        editMessageText($chatId,$messageId, "📥 **কাজ সাবমিশন নির্দেশাবলী:**\n\nআপনি যে জিমেইল একাউন্টটি তৈরি করেছেন, সেটি নিচে লিখে এখনই পাঠিয়ে দিন।");
        exit;
    }

    if ($data === "user_task_cancel") {
        $users[$chatId]['awaiting_submission'] = false;
        $users[$chatId]['generated_gmail']     = null;
        $users[$chatId]['generated_pass']      = null;
        saveData($usersDbFile,$users);

        editMessageText($chatId,$messageId, "❌ **কাজটি বাতিল করা হয়েছে!**\n\nআপনি আবার নতুন কাজ শুরু করতে পারবেন।");
        sendMessage($chatId, "প্রধান মেনু:", $mainKeyboard);
        exit;
    }

    // --- Cashout Method Selection ---
    if ($data === "wmethod_bkash" || $data === "wmethod_nagad") {
        $method = ($data === "wmethod_bkash") ? "বিকাশ" : "নগদ";
        $users[$chatId]['withdraw_method']          =$method;
        $users[$chatId]['awaiting_withdraw_number'] = true;
        saveData($usersDbFile,$users);

        sendMessage($chatId, "📲 **{$method} পেমেন্ট গেটওয়ে**\n\nঅনুগ্রহ করে আপনার **{$method} নম্বরটি** লিখে মেসেজ পাঠিয়েন:");
        exit;
    }

    // --- Admin Approval Handlers ---
    $parts  = explode("_", $data);
    $action =$parts[0] ?? '';

    // Admin Verification Authorization (username OR chat_id check)
    $isAdmin = (($fromUser['username'] ?? '') ===$adminUsername) || ($chatId ==$adminChatId);

    // 1. Task Approval/Rejection by Admin
    if ($action === "accept" || $action === "reject") {
        if (!$isAdmin) {
            sendMessage($chatId, "⚠️ **অ্যাক্সেস Denied!** আপনি এডমিন নন।");
            exit;
        }

        $subId = ($parts[1] ?? '') . "_" . ($parts[2] ?? '');

        if (!isset($pending[$subId])) {
            editMessageText($chatId,$messageId, "⚠️ **এই কাজটি ইতোমধ্যে রিভিউ করা শেষ!** 🛑");
            exit;
        }

        $subData    = $pending[$subId];
        $targetUser =$subData['user_id'];
        unset($pending[$subId]);
        saveData($pendingDbFile,$pending);

        if ($action === "accept") {
            if (isset($users[$targetUser])) {
                $users[$targetUser]['pending'] = max(0, ($users[$targetUser]['pending'] ?? 0) - 17.0);
                $users[$targetUser]['balance'] = ($users[$targetUser]['balance'] ?? 0) + 17.0;
                saveData($usersDbFile,$users);
            }
            editMessageText($chatId,$messageId, "✅ **কাজ অনুমোদিত (Approved)!**\n👤 ইউজার `{$targetUser}`-কে **১৭.০০ টাকা** যুক্ত করা হয়েছে।");
            sendMessage($targetUser, "🎉 **অভিনন্দন!** আপনার জমাকৃত জিমেইলটি এডমিন কর্তৃক এপ্রুভ করা হয়েছে এবং **১৭.০০ টাকা** 💵 আপনার ব্যালেন্সে যোগ করা হয়েছে। 🥳");
        } elseif ($action === "reject") {
            if (isset($users[$targetUser])) {
                $users[$targetUser]['pending'] = max(0, ($users[$targetUser]['pending'] ?? 0) - 17.0);
                saveData($usersDbFile,$users);
            }
            editMessageText($chatId,$messageId, "❌ **কাজ বাতিল করা হয়েছে (Rejected)!**");
            sendMessage($targetUser, "❌ **দুঃখিত!** আপনার জমাকৃত কাজটিতে ত্রুটি থাকায় এডমিন তা বাতিল করেছে। ⚠️");
        }
        exit;
    }

    // 2. Cashout Approval/Rejection by Admin
    if ($action === "waccept" \vert{}\vert{} $action === "wreject") {
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

    // Register User Profile
    if (!isset($users[$userId])) {
        $users[$userId] = [
            'balance'                  => 0.0,
            'pending'                  => 0.0,
            'username'                 => $user['username'] ?? $user['first_name'],
            'awaiting_submission'      => false,
            'awaiting_withdraw_number' => false,
            'awaiting_withdraw_amount' => false,
            'withdraw_method'          => null,
            'withdraw_number'          => null,
            'generated_gmail'          => null,
            'generated_pass'           => null
        ];
        saveData($usersDbFile,$users);
    }

    // Step 1: Receiving Withdraw Number
    if (!empty($users[$userId]['awaiting_withdraw_number'])) {$users[$userId]['withdraw_number']          =$text;
        $users[$userId]['awaiting_withdraw_number'] = false;
        $users[$userId]['awaiting_withdraw_amount'] = true;
        saveData($usersDbFile,$users);

        $bal = number_format($users[$userId]['balance'] ?? 0, 2);
        sendMessage($chatId, "💰 **কত টাকা তুলতে চান?**\nপরিমাণ লিখে পাঠান (সর্বনিম্ন ৫০.০০ টাকা):\n\n*(বর্তমান ব্যালেন্স: {$bal} টাকা)*");
        exit;
    }

    // Step 2: Receiving Withdraw Amount
    if (!empty($users[$userId]['awaiting_withdraw_amount'])) {$amount  = floatval($text);$userBal = $users[$userId]['balance'] ?? 0.0;

        if ($amount < 50) {
            sendMessage($chatId, "⚠️ **সর্বনিম্ন উত্তোলন সীমা ৫০.০০ টাকা!**\nঅনুগ্রহ করে সঠিকভাবে টাকার পরিমাণ পাঠান:");
            exit;
        }

        if ($amount >$userBal) {
            sendMessage($chatId, "❌ **অপর্যাপ্ত ব্যালেন্স!** আপনার ওয়ালেটে এই পরিমাণ টাকা নেই। সঠিক পরিমাণ পাঠিয়েন:");
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

        sendMessage($chatId, "✅ **ক্যাশআউট রিকোয়েস্ট জমা হয়েছে!** 💸\n\nমেথড: **{$method}**\nনম্বর: `{$number}`\nপরিমাণ: **{$amount} টাকা**\n\n⏳ এডমিন রিভিউ সম্পন্ন করে পেমেন্ট পাঠিয়ে দেবে।", $mainKeyboard);

        // Admin Notification Sent DIRECTLY to Admin Chat ID
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

    // --- Standard Navigation Commands ---
    if ($text === "/start") {
        $users[$userId]['awaiting_submission']      = false;
        $users[$userId]['awaiting_withdraw_number'] = false;
        $users[$userId]['awaiting_withdraw_amount'] = false;
        saveData($usersDbFile,$users);

        $welcomeText = "🥰 **স্বাগতম, " . htmlspecialchars($user['first_name']) . "!** 👋\n\n" .
                       "✅ **কাজ শুরু করতে নিচের বাটনগুলো ব্যবহার করুন** 👇";
        sendMessage($chatId, $welcomeText,$mainKeyboard);
    } 
    elseif ($text === "/admin") {
        if ((($user['username'] ?? '') ===$adminUsername) || ($chatId ==$adminChatId)) {
            $totalUsers       = count($users);
            $pendingWorks     = count($pending);
            $pendingWithdraws = count($withdrawals);

            $adminStats = "⚙️ **এডমিন ড্যাশবোর্ড** ⚙️\n\n" .
                          "👥 **মোট ইউজার:** {$totalUsers}\n" .
                          "⏳ **পেন্ডিং কাজ:** {$pendingWorks}\n" .
                          "💸 **পেন্ডিং ক্যাশআউট:** {$pendingWithdraws}";
            sendMessage($chatId,$adminStats);
        } else {
            sendMessage($chatId, "❌ আপনি এই বটের এডমিন নন।");
        }
    }
    elseif ($text === "📝 কাজ ▸") {
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
               "⚠️ **নির্দেশনা:** উপরের তথ্যগুলো ব্যবহার করে জিমেইল খুলুন। খোলা শেষ হলে **Complete Task** এ চাপ দিন অথবা কাজ না করতে চাইলে **Cancel Task** চাপুন।";
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
    elseif ($text === "🙋‍♂️ সাপোর্ট") {
        $msg = "🙋‍♂️ **সাপোর্ট সেন্টার** 📞\n\n" .
               "যেকোনো প্রয়োজনে এডমিনের সাথে যোগাযোগ করুন:\n" .
               "👤 **এডমিন:** @" . $adminUsername;
        sendMessage($chatId,$msg);
    } 
    elseif (in_array($text, ["🎁 My Referrals", "👶 আমি নতুন"])) {
        sendMessage($chatId, "ℹ️ '**{$text}**' ফিচারটি শীঘ্রই চালু হবে!");
    } 
    // Step 3: Processing Gmail Submission from User
    elseif (!empty($users[$userId]['awaiting_submission'])) {
        $submittedGmail =$text;
        $genGmail       =$users[$userId]['generated_gmail'];$genPass        = $users[$userId]['generated_pass'];

        $users[$userId]['pending']             = ($users[$userId]['pending'] ?? 0) + 17.0;
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

        sendMessage($chatId, "✅ **কাজটি সফলভাবে এডমিনের কাছে পাঠানো হয়েছে!** 📩\n⏳ এডমিন চেক করে এপ্রুভ করলে **১৭.০০ টাকা** মেইন ব্যালেন্সে জমা হবে। 💰", $mainKeyboard);

        // Admin Approval Panel Notification Sent DIRECTLY to Admin Chat ID (7278071284)
        $adminKeyboard = [
            'inline_keyboard' => [
                [
                    ["text" => "✅ Approve (17 Tk)", "callback_data" => "accept_" . $submissionId],
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
    }
}
?>