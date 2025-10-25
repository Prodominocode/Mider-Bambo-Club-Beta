<?php
header('Content-Type: application/json');
require_once 'db.php';
require_once 'config.php';
require_once 'branch_utils.php';

$mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
if (!$mobile) {
    echo json_encode(['status' => 'error', 'message' => 'لطفاً شماره موبایل را وارد کنید.']);
    exit;
}

// Get current branch ID
$branch_id = get_current_branch();

// Generate random 5-digit code
$otp_code = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);

// Check if mobile exists
$stmt = $pdo->prepare("SELECT id FROM subscribers WHERE mobile=?");
$stmt->execute([$mobile]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // Login flow: update OTP for existing user and update branch_id to current branch
    $stmt = $pdo->prepare("UPDATE subscribers SET otp_code=?, verified=0, branch_id=?, created_at=NOW() WHERE mobile=?");
    $stmt->execute([$otp_code, $branch_id, $mobile]);
} else {
    // Registration flow: create new user
    $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, otp_code, verified, credit, branch_id, created_at) VALUES (?, ?, 0, 10, ?, NOW())");
    $stmt->execute([$mobile, $otp_code, $branch_id]);
}

// Send SMS via Kavenegar
// Get branch information for the message
require_once 'branch_utils.php';
$branch_id = get_current_branch();
$message_label = get_branch_message_label($branch_id);

$api_key = KAVENEGAR_API_KEY;
$receptor = $mobile;
// Get the branch domain
$branch_domain = get_branch_domain($branch_id);
$message = "$message_label\nکد تاییدیه شما جهت ورود به باشگاه مشتریان : $otp_code\n$branch_domain";
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
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['status' => 'error', 'message' => 'ارسال پیامک با خطا مواجه شد. لطفاً دوباره تلاش کنید.']);
    exit;
}

// Parse Kavenegar response
$res = json_decode($response, true);
if (isset($res['return']['status']) && $res['return']['status'] == 200) {
    echo json_encode(['status' => 'success', 'exists' => (bool)$exists]);
} else {
    $msg = isset($res['return']['message']) ? $res['return']['message'] : 'خطا در ارسال پیامک.';
    echo json_encode(['status' => 'error', 'message' => $msg]);
}
