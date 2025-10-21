<?php
session_start();
header('Content-Type: application/json');
require_once 'db.php';

$mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
$full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$otp_code = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';

if (!$mobile || !$otp_code) {
    echo json_encode(['status' => 'error', 'message' => 'لطفاً شماره موبایل و کد تایید را وارد کنید.']);
    exit;
}

// Check OTP
$stmt = $pdo->prepare("SELECT * FROM subscribers WHERE mobile=? AND otp_code=? AND verified=0");
$stmt->execute([$mobile, $otp_code]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'کد وارد شده صحیح نیست یا قبلاً تأیید شده است.']);
    exit;
}

// If full_name provided (new user), store it. Otherwise keep existing name.
$stmtGet = $pdo->prepare("SELECT full_name FROM subscribers WHERE mobile=?");
$stmtGet->execute([$mobile]);
$existingName = $stmtGet->fetchColumn();

$newName = $existingName;
if ($full_name) {
    $newName = $full_name;
}

$stmt = $pdo->prepare("UPDATE subscribers SET verified=1, full_name=? WHERE mobile=?");
$stmt->execute([$newName, $mobile]);

// Fetch user id for session
$stmt = $pdo->prepare("SELECT * FROM subscribers WHERE mobile=?");
$stmt->execute([$mobile]);
$user = $stmt->fetch();
if ($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['mobile'] = $user['mobile'];
            // Send welcome SMS via Kavenegar
            require_once 'config.php';
            $api_key = KAVENEGAR_API_KEY;
            $receptor = $mobile;
            $message = "پوشاک میدر\nبه باشگاه مشتریان فروشگاه خوش آمدید.\nشعبه ساری\nmiderclub.ir";
            $url = "https://api.kavenegar.com/v1/$api_key/sms/send.json";
            $postfields = http_build_query([
                'receptor' => $receptor,
                'sender' => KAVENEGAR_SENDER,
                'message' => $message
            ]);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
}

echo json_encode(['status' => 'success']);
