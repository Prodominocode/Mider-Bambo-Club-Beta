<?php
// Combined admin login + panel with DB-backed OTP, ADMIN_ALLOWED check, long-lived session
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';
require_once 'db.php';

function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

function send_kavenegar_sms($receptor, $message){
    $api_key = KAVENEGAR_API_KEY;
    $url = "https://api.kavenegar.com/v1/$api_key/sms/send.json";
    $postfields = http_build_query(['receptor'=>$receptor,'sender'=>KAVENEGAR_SENDER,'message'=>$message]);
    if (function_exists('curl_init')){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // 3 second connection timeout
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok'=>false,'error'=>$err];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    } else {
        $opts = ['http'=>['method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded','content'=>$postfields,'timeout'=>5]]; // 5 second timeout
        $context = stream_context_create($opts);
        $resp = @file_get_contents($url,false,$context);
        if ($resp === false) return ['ok'=>false,'error'=>'file_get_contents_failed'];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    }
}

// Helper to check allowed admin numbers
function is_admin_allowed($mobile){
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return false;
    foreach($allowed as $a){ if (norm_digits($a) === $m) return true; }
    return false;
}

// Actions: send_otp, verify_otp, logout, add_subscriber, check_member, use_credit, get_today_report
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'get_today_report') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        try {
            // Get date parameter or use today as default
            $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d'); // Fall back to today if invalid format
            }
            
            // Set date range for the specified day
            $today_start = $date . ' 00:00:00';
            $today_end = $date . ' 23:59:59';
            
            // Get today's purchases
            $purchases = [];
            $stmt = $pdo->prepare('
                SELECT p.mobile, p.amount, p.created_at, s.full_name 
                FROM purchases p
                LEFT JOIN subscribers s ON p.subscriber_id = s.id
                WHERE p.created_at BETWEEN ? AND ?
                ORDER BY p.created_at DESC
            ');
            $stmt->execute([$today_start, $today_end]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $purchases[] = [
                    'mobile' => $row['mobile'],
                    'full_name' => $row['full_name'],
                    'amount' => (int)$row['amount'],
                    'date' => $row['created_at']
                ];
            }
            
            // Get today's credit usages
            $credits = [];
            $stmt = $pdo->prepare('
                SELECT user_mobile, amount, datetime, is_refund, admin_mobile
                FROM credit_usage
                WHERE datetime BETWEEN ? AND ?
                ORDER BY datetime DESC
            ');
            $stmt->execute([$today_start, $today_end]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $credits[] = [
                    'mobile' => $row['user_mobile'],
                    'amount' => (int)$row['amount'],
                    'date' => $row['datetime'],
                    'is_refund' => (bool)$row['is_refund'],
                    'admin' => $row['admin_mobile']
                ];
            }
            
            // Calculate totals
            $total_purchases = 0;
            foreach ($purchases as $p) {
                $total_purchases += $p['amount'];
            }
            
            $total_credits = 0;
            foreach ($credits as $c) {
                $total_credits += $c['amount'];
            }
            
            echo json_encode([
                'status' => 'success',
                'purchases' => $purchases,
                'credits' => $credits,
                'total_purchases' => $total_purchases,
                'total_credits' => $total_credits,
                'date' => $date
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('get_today_report error: ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); 
            exit;
        }
    } elseif ($action === 'use_credit') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $admin = $_SESSION['admin_mobile'];
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        $amount = isset($_POST['amount']) ? (int)norm_digits($_POST['amount']) : 0;
        $is_refund = isset($_POST['is_refund']) && $_POST['is_refund'] === 'true';
        
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        if (!$amount) { echo json_encode(['status'=>'error','message'=>'amount_required']); exit; }
        
        try {
            // Check if member exists and get current credit
            $stmt = $pdo->prepare('SELECT id, credit FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$mobile]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['status'=>'error','message'=>'not_a_member']); exit;
            }
            
            // Calculate credit points to subtract (convert from Toman to credit points)
            $creditToSubtract = round(($amount / 5000), 1);
            
            // Check if member has enough credit
            if ($member['credit'] < $creditToSubtract) {
                echo json_encode(['status'=>'error','message'=>'insufficient_credit']); exit;
            }
            
            // Update credit balance
            $pdo->beginTransaction();
            
            // 1. Update subscriber's credit
            $upd = $pdo->prepare('UPDATE subscribers SET credit = credit - ? WHERE id = ?');
            $upd->execute([$creditToSubtract, $member['id']]);
            
            // 2. Insert record into credit_usage table
            $ins = $pdo->prepare('INSERT INTO credit_usage (amount, credit_value, is_refund, user_mobile, admin_mobile) 
                               VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$amount, $creditToSubtract, $is_refund ? 1 : 0, $mobile, $admin]);
            
            // 3. Get updated credit
            $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1');
            $sel->execute([$member['id']]);
            $newCredit = (float)$sel->fetchColumn();
            
            $pdo->commit();
            
            // 4. Send SMS based on is_refund flag
            $formatted_amount = number_format($amount);
            $formatted_remaining_credit = number_format((int)($newCredit * 5000));
            
            if ($is_refund) {
                $message = "پوشاک میدر
با توجه به مرجوع کردن خریدتان مبلغ $formatted_amount تومان از اعتبار باشگاه مشتریان شما کسر شد. 
اعتبار باقیمانده : $formatted_remaining_credit
شعبه ساری
miderclub.ir";
            } else {
                $message = "پوشاک میدر
شما مبلغ $formatted_amount تومان از اعتبار باشگاه مشتریان خود را در خرید فعلی به عنوان تخفیف نقدی استفاده نمودید.
اعتبار باقیمانده : $formatted_remaining_credit
شعبه ساری
miderclub.ir";
            }
            
            $sms = send_kavenegar_sms($mobile, $message);
            
            echo json_encode([
                'status' => 'success',
                'credit' => $newCredit,
                'credit_value' => (int)($newCredit * 5000),
                'sms_sent' => $sms['ok']
            ]);
            exit;
        } catch (Throwable $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch(Throwable $_) {}
            error_log('use_credit error: ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); exit;
        }
    } elseif ($action === 'check_member') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        try {
            // Check if member exists and get credit (use a single query to get both member info and latest purchases)
            $stmt = $pdo->prepare('SELECT id, credit FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$mobile]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['status'=>'error','message'=>'not_a_member']); exit;
            }
            
            // Get combined purchase history and credit usage - limit to recent 20 records for better performance
            $transactions = [];
            
            // 1. Get purchases (positive credits)
            $stmt = $pdo->prepare('
                SELECT amount, created_at as date, "purchase" as type 
                FROM purchases 
                WHERE mobile = ?
            ');
            $stmt->execute([$mobile]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $transactions[] = [
                    'amount' => (int)$row['amount'],
                    'date' => $row['date'],
                    'type' => 'purchase'
                ];
            }
            
            // 2. Get credit usage (negative credits)
            $stmt = $pdo->prepare('
                SELECT amount, datetime as date, is_refund, "usage" as type 
                FROM credit_usage 
                WHERE user_mobile = ?
            ');
            $stmt->execute([$mobile]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $transactions[] = [
                    'amount' => -(int)$row['amount'], // Negative amount
                    'date' => $row['date'],
                    'type' => 'usage',
                    'is_refund' => (bool)$row['is_refund']
                ];
            }
            
            // 3. Sort combined records by date (newest first)
            usort($transactions, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            
            // 4. Limit to most recent 20 transactions
            $transactions = array_slice($transactions, 0, 20);
            
            echo json_encode([
                'status' => 'success',
                'credit' => (int)$member['credit'],
                'credit_value' => (int)($member['credit'] * 5000),
                'transactions' => $transactions
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('check_member error: '.$e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); exit;
        }
    } elseif ($action === 'send_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        if (!is_admin_allowed($mobile)) { echo json_encode(['status'=>'error','message'=>'not_allowed']); exit; }
        // generate OTP and write to subscribers table (insert or update)
        $otp = str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
        try {
            $ch = $pdo->prepare('SELECT id FROM subscribers WHERE mobile=? LIMIT 1'); $ch->execute([$mobile]); $exists = $ch->fetchColumn();
            if ($exists){ $u = $pdo->prepare('UPDATE subscribers SET otp_code=?, verified=0, created_at=NOW() WHERE mobile=?'); $u->execute([$otp,$mobile]); }
            else { $u = $pdo->prepare('INSERT INTO subscribers (mobile, otp_code, verified, credit, created_at) VALUES (?, ?, 0, 10, NOW())'); $u->execute([$mobile,$otp]); }
        } catch (Throwable $e){ error_log('admin send_otp db: '.$e->getMessage()); }
        // send SMS
        $message = "کد تایید ورود مدیریت: $otp";
        $sms = send_kavenegar_sms($mobile,$message);
        if (!$sms['ok']) { error_log('admin send_otp sms: '.json_encode($sms)); echo json_encode(['status'=>'error','message'=>'sms_failed']); exit; }
        // store mobile in session temporary ui marker
        $_SESSION['admin_otp_ui_mobile'] = $mobile; $_SESSION['admin_otp_ui_expires'] = time()+300;
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'verify_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        $code = isset($_POST['otp']) ? trim($_POST['otp']) : '';
        if (!$mobile || !$code) { echo json_encode(['status'=>'error','message'=>'missing']); exit; }
        try {
            $stmt = $pdo->prepare('SELECT * FROM subscribers WHERE mobile=? AND otp_code=? LIMIT 1'); $stmt->execute([$mobile,$code]); $row = $stmt->fetch();
            if (!$row) { echo json_encode(['status'=>'error','message'=>'invalid_code']); exit; }
            // clear otp_code
            $u2 = $pdo->prepare('UPDATE subscribers SET otp_code=NULL, verified=1 WHERE id=?'); $u2->execute([$row['id']]);
            if (function_exists('session_regenerate_id')) session_regenerate_id(true);
            $_SESSION['admin_mobile'] = $mobile; $_SESSION['is_admin']=true;
            // persistent cookie (10 years)
            $cookieParams = session_get_cookie_params(); $lifetime = 10*365*24*60*60;
            setcookie(session_name(), session_id(), time()+$lifetime, $cookieParams['path']?:'/', $cookieParams['domain']?:'', $cookieParams['secure']??false, $cookieParams['httponly']??true);
            echo json_encode(['status'=>'success']); exit;
        } catch (Throwable $e){ error_log('admin verify otp: '.$e->getMessage()); echo json_encode(['status'=>'error','message'=>'server_error']); exit; }
    } elseif ($action === 'logout'){
        // logout
        $_SESSION = [];
        if (ini_get('session.use_cookies')){
            $params = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'add_subscriber'){
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $admin = $_SESSION['admin_mobile'];
        $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        try {
            // check existing
            $ch = $pdo->prepare('SELECT id FROM subscribers WHERE mobile = ? LIMIT 1'); $ch->execute([$mobile]); $existingId = $ch->fetchColumn();
            if ($existingId){
                if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,created_at) VALUES (?, ?, ?, ?, NOW())'); $ins->execute([$existingId,$mobile,$amount,$admin]);
                    $creditToAdd = round(((float)$amount)/100000.0, 1);
                    if ($creditToAdd>0){ $upd = $pdo->prepare('UPDATE subscribers SET credit = credit + ? WHERE id = ?'); $upd->execute([$creditToAdd,$existingId]); }
                    $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); $sel->execute([$existingId]); $newCredit = (int)$sel->fetchColumn();
                    $pdo->commit();
                    $message = "پوشاک میدر\nاز خرید شما متشکریم.\nامتیاز کسب‌ شده از خرید امروز: " . intval($creditToAdd * 5000) . " تومان\nامتیاز کل شما در باشگاه مشتریان: " . intval($newCredit * 5000) . " تومان\nشعبه ساری\nmiderclub.ir";
                    $sms = send_kavenegar_sms($mobile,$message);
                    echo json_encode(['status'=>'success','note'=>'existing_user_with_amount','new_credit'=>$newCredit,'sms'=>$sms]); exit;
                }
                echo json_encode(['status'=>'success','note'=>'existing_user','admin_message'=>'Subscriber is already a member.']); exit;
            }
            // create new subscriber
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO subscribers (mobile, verified, credit, created_at, admin_number) VALUES (?, 0, 0, NOW(), ?)'); $stmt->execute([$mobile,$admin]); $id = $pdo->lastInsertId();
            $newCredit = null;
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,created_at) VALUES (?, ?, ?, ?, NOW())'); $ins->execute([$id,$mobile,$amount,$admin]);
                $creditToAdd = round(((float)$amount)/100000.0, 1);
                if ($creditToAdd>0){ $upd = $pdo->prepare('UPDATE subscribers SET credit = credit + ? WHERE id = ?'); $upd->execute([$creditToAdd,$id]); }
                $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); $sel->execute([$id]); $newCredit = intval($sel->fetchColumn());
            }
            $pdo->commit();
            // send SMS
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                $message = "پوشاک میدر\nبه باشگاه مشتریان فروشگاه خوش آمدید.\nامتیاز شما از خرید امروز : " . ($creditToAdd * 5000) . " تومان\nامتیاز کل شما در باشگاه مشتریان: " . ($newCredit * 5000) . " تومان\nشعبه ساری\nmiderclub.ir";
            } else {
                $message = "پوشاک میدر\nبه باشگاه مشتریان فروشگاه خوش آمدید.\nشعبه ساری\nmiderclub.ir";
            }
            $sms = send_kavenegar_sms($mobile,$message);
            echo json_encode(['status'=>'success','note'=>'new_user','id'=>$id,'new_credit'=>$newCredit,'sms'=>$sms]); exit;
        } catch (Throwable $e){ try{ if ($pdo->inTransaction()) $pdo->rollBack(); }catch(Throwable $_){} echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
    }
}

// If not POST or after actions, render page
$is_admin = !empty($_SESSION['admin_mobile']);
$admin_mobile = $is_admin ? $_SESSION['admin_mobile'] : '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>پنل مدیریت</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="assets/js/jalaali.js"></script>
  <style>
    body{font-family:Tahoma,Arial;background:#181a1b url('assets/images/bg.jpg') no-repeat center center fixed;background-size:cover;color:#fff;position:relative}
    .overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(24,26,27,0.85);backdrop-filter:blur(6px);z-index:1}
    .centered-container{max-width:720px;margin:40px auto;position:relative;z-index:2}
    .box{background:rgba(34,36,38,0.95);padding:20px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.25)}
    .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
    .btn-primary{background:#4caf50;color:#fff}
    .btn-ghost{background:transparent;color:#cfcfcf;border:1px solid rgba(255,255,255,0.04)}
    .small{font-size:14px}
    input[type=text]{padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;width:100%}
    .msg{margin-top:8px}
    /* New styles for large buttons */
    .btn-large{
      display:flex;
      align-items:center;
      background:#4caf50;
      color:#fff;
      padding:20px;
      border:0;
      border-radius:8px;
      font-size:18px;
      cursor:pointer;
      width:100%;
      transition:background 0.2s ease;
    }
    .btn-large:hover{
      background:#43a047;
    }
    .btn-icon{
      font-size:24px;
      margin-left:12px;
    }
    .btn-text{
      flex:1;
      text-align:right;
      font-weight:bold;
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="centered-container">
    <div class="box">
<?php if (!$is_admin): ?>
      <h2>ورود مدیریت</h2>
      <div id="login-area">
        <div id="login-form">
          <label>شماره موبایل</label>
          <input type="text" id="login_mobile" placeholder="شماره موبایل">
          <div style="margin-top:8px"><button id="send_otp" class="btn btn-primary">ارسال کد</button></div>
          <div id="login_msg" class="msg"></div>
        </div>
        <div id="otp-form" style="display:none;margin-top:12px">
          <label>کد تایید</label>
          <input type="text" id="otp_code" placeholder="کد ۵ رقمی">
          <div style="margin-top:8px"><button id="verify_otp" class="btn btn-primary">تایید و ورود</button></div>
          <div id="otp_msg" class="msg"></div>
        </div>
      </div>
<?php else: ?>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h2>پنل مدیریت</h2>
          <div class="small">ادمین: <?php echo htmlspecialchars($admin_mobile); ?></div>
        </div>
        <div>
          <button id="logout" class="btn btn-ghost">خروج</button>
        </div>
      </div>
      <hr>
      <!-- Main navigation buttons -->
      <div style="display:flex;flex-direction:column;gap:16px;margin:20px 0">
        <button id="btn_purchase" class="btn-large">
          <div class="btn-icon">💰</div>
          <div class="btn-text">ثبت خرید</div>
        </button>
        <button id="btn_inquiry" class="btn-large">
          <div class="btn-icon">🔍</div>
          <div class="btn-text">استعلام</div>
        </button>
        <button id="btn_today_report" class="btn-large">
          <div class="btn-icon">📊</div>
          <div class="btn-text">تراکنش های امروز</div>
        </button>
      </div>
      
      <!-- Purchase/subscription form (initially hidden) -->
      <div id="purchase_form" style="display:none">
        <h3>افزودن مشترک / ثبت خرید</h3>
        <div>
          <label>موبایل (الزامی)</label>
          <input type="text" id="sub_mobile">
        </div>
        <div style="margin-top:8px">
          <label>مبلغ خرید (تومان، اختیاری)</label>
          <input type="text" id="sub_amount" placeholder="مثال: 250.000" inputmode="numeric">
          <input type="hidden" id="sub_amount_raw">
        </div>
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="add_submit" class="btn btn-primary">ارسال</button>
          <button id="back_to_menu" class="btn btn-ghost">بازگشت</button>
        </div>
        <div id="add_msg" class="msg"></div>
      </div>
      
      <!-- Inquiry form (initially hidden) -->
      <div id="inquiry_form" style="display:none">
        <h3>استعلام وضعیت مشتری</h3>
        <div>
          <label>شماره موبایل مشتری</label>
          <input type="text" id="inquiry_mobile" placeholder="شماره موبایل">
        </div>
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="check_member" class="btn btn-primary">استعلام</button>
          <button id="back_to_menu_inquiry" class="btn btn-ghost">بازگشت</button>
        </div>
        <div id="inquiry_msg" class="msg"></div>
        
        <!-- Member info (initially hidden) -->
        <div id="member_info" style="display:none;margin-top:20px;background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;">
          <div id="member_credit" style="font-size:18px;color:#4caf50;margin-bottom:12px;"></div>
          <h4 style="margin-bottom:10px;">تاریخچه تراکنش‌ها</h4>
          <div id="purchase_history">
            <table style="width:100%;border-collapse:collapse;">
              <thead>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                  <th style="text-align:right;padding:8px 4px;">تاریخ</th>
                  <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                </tr>
              </thead>
              <tbody id="purchases_table_body">
                <!-- Transactions will be inserted here -->
              </tbody>
            </table>
          </div>
          <div id="no_purchases" style="display:none;color:#aaa;padding:10px 0;">هیچ تراکنشی ثبت نشده است.</div>
          
          <!-- Credit Use Section -->
          <div style="margin-top:20px;border-top:1px solid rgba(255,255,255,0.1);padding-top:16px;">
            <h4 style="margin-bottom:10px;">استفاده از امتیاز</h4>
            <div>
              <label>استفاده از امتیاز (تومان)</label>
              <input type="text" id="credit_use_amount" inputmode="numeric" placeholder="مثال: 200.000">
              <input type="hidden" id="credit_use_amount_raw">
            </div>
            <div style="margin-top:8px;display:flex;align-items:center;">
              <input type="checkbox" id="credit_refund" style="margin-left:8px;">
              <label for="credit_refund">مرجوعی</label>
            </div>
            <div id="credit_preview" style="margin-top:8px;color:#ffc107;display:none;">امتیاز پس از کسر: <span id="credit_remaining"></span> تومان</div>
            <div style="margin-top:12px;">
              <button id="submit_credit_use" class="btn btn-primary">ثبت</button>
            </div>
            <div id="credit_use_msg" class="msg"></div>
          </div>
        </div>
      </div>
      
      <!-- Today's Transactions Report (initially hidden) -->
      <div id="today_report_form" style="display:none">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <h3>گزارش تراکنش های امروز</h3>
          <div style="display:flex;align-items:center;gap:12px">
            <button id="prev_day_report" class="btn btn-ghost" style="padding:4px 8px;min-width:40px">◀</button>
            <div id="today_date" style="color:#b0b3b8;"></div>
            <button id="next_day_report" class="btn btn-ghost" style="padding:4px 8px;min-width:40px">▶</button>
          </div>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="refresh_today_report" class="btn btn-primary">بروزرسانی</button>
          <button id="go_to_today" class="btn btn-primary">امروز</button>
          <button id="back_to_menu_report" class="btn btn-ghost">بازگشت</button>
        </div>
        
        <div id="today_report_msg" class="msg"></div>
        
        <div style="margin-top:20px;">
          <h4 style="color:#4caf50;margin-bottom:10px;">خریدهای ثبت شده امروز</h4>
          <div style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;margin-bottom:20px;">
            <div id="today_purchases_container" style="max-height:300px;overflow-y:auto;">
              <table style="width:100%;border-collapse:collapse;" id="today_purchases_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">موبایل</th>
                    <th style="text-align:right;padding:8px 4px;">زمان</th>
                    <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                  </tr>
                </thead>
                <tbody id="today_purchases_list">
                  <tr><td colspan="3" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          
          <h4 style="color:#ff5252;margin-bottom:10px;">اعتبارهای استفاده شده امروز</h4>
          <div style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;margin-bottom:20px;">
            <div id="today_credits_container" style="max-height:300px;overflow-y:auto;">
              <table style="width:100%;border-collapse:collapse;" id="today_credits_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">موبایل</th>
                    <th style="text-align:right;padding:8px 4px;">زمان</th>
                    <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                    <th style="text-align:center;padding:8px 4px;">نوع</th>
                  </tr>
                </thead>
                <tbody id="today_credits_list">
                  <tr><td colspan="4" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          
          <div style="display:flex;justify-content:space-between;margin-top:30px;background:#1a1c1e;padding:15px;border-radius:8px;">
            <div style="flex:1;">
              <div style="font-weight:bold;margin-bottom:5px;">مجموع خریدهای امروز:</div>
              <div id="total_purchases" style="color:#4caf50;font-size:18px;font-weight:bold;">0 تومان</div>
            </div>
            <div style="flex:1;text-align:left;">
              <div style="font-weight:bold;margin-bottom:5px;">مجموع اعتبارهای استفاده شده:</div>
              <div id="total_credits_used" style="color:#ff5252;font-size:18px;font-weight:bold;">0 تومان</div>
            </div>
          </div>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
  <script>
    function postJSON(body){ return fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)}).then(r=>r.json()); }
    
    // Number formatting function - optimized
    function formatWithDots(s){
      if (s === null || s === undefined) return '';
      
      // Direct conversion to number and floor
      const num = Math.floor(Number(s));
      
      // Early return if not a number
      if (isNaN(num)) return '0';
      
      // Convert to string and format with dots
      return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Function to extract raw number (remove formatting) - optimized
    function extractRawNumber(s){
      if (!s) return '';
      // For Persian content, maintain conversion
      if (/[\u06F0-\u06F9]/.test(s)) {
        s = s.replace(/[\u06F0-\u06F9]/g, function(d){ return String('0123456789').charAt(d.charCodeAt(0)-0x06F0); });
      }
      // Remove non-digits - faster approach
      return s.replace(/\D/g,'');
    }

    <?php if (!$is_admin): ?>
    document.getElementById('send_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); var msg = document.getElementById('login_msg'); msg.textContent='';
      if (!m) { msg.textContent='لطفاً شماره موبایل را وارد کنید.'; return; }
      postJSON({action:'send_otp',mobile:m}).then(json=>{ if (json.status==='success'){ document.getElementById('login-form').style.display='none'; document.getElementById('otp-form').style.display='block'; } else { msg.textContent = json.message || 'خطا'; } }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    document.getElementById('verify_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); var code = document.getElementById('otp_code').value.trim(); var msg = document.getElementById('otp_msg'); msg.textContent='';
      if (!m || !code) { msg.textContent='لطفاً شماره و کد را وارد کنید.'; return; }
      postJSON({action:'verify_otp',mobile:m,otp:code}).then(json=>{ if (json.status==='success'){ location.reload(); } else { msg.textContent = json.message || 'کد نامعتبر'; } }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    <?php else: ?>
    
    // Set up amount input formatting
    const subAmountInput = document.getElementById('sub_amount');
    const subAmountRawInput = document.getElementById('sub_amount_raw');
    
    if (subAmountInput && subAmountRawInput) {
      subAmountInput.addEventListener('input', function(){
        var raw = extractRawNumber(this.value);
        subAmountRawInput.value = raw;
        var formatted = formatWithDots(raw);
        this.value = formatted;
      });
      
      subAmountInput.addEventListener('blur', function(){
        this.value = formatWithDots(subAmountRawInput.value);
      });
    }
    
    document.getElementById('logout').addEventListener('click', function(){ postJSON({action:'logout'}).then(()=>location.reload()); });
    
    // Main menu buttons
    document.getElementById('btn_purchase').addEventListener('click', function(){
      document.getElementById('btn_purchase').parentNode.style.display = 'none';
      document.getElementById('purchase_form').style.display = 'block';
    });
    
    document.getElementById('btn_inquiry').addEventListener('click', function(){
      document.getElementById('btn_inquiry').parentNode.style.display = 'none';
      document.getElementById('inquiry_form').style.display = 'block';
    });
    
    document.getElementById('btn_today_report').addEventListener('click', function(){
      document.getElementById('btn_today_report').parentNode.style.display = 'none';
      document.getElementById('today_report_form').style.display = 'block';
      
      // Reset to server's current date when first opening the report
      setCurrentServerDate(null); // Will be updated with server's date when loadTodayReport runs
      loadTodayReport();
    });
    
    // Back buttons
    document.getElementById('back_to_menu').addEventListener('click', function(){
      document.getElementById('purchase_form').style.display = 'none';
      document.getElementById('btn_purchase').parentNode.style.display = 'flex';
    });
    
    document.getElementById('back_to_menu_inquiry').addEventListener('click', function(){
      document.getElementById('inquiry_form').style.display = 'none';
      document.getElementById('btn_inquiry').parentNode.style.display = 'flex';
      // Reset the form when going back to menu
      document.getElementById('inquiry_mobile').value = '';
      document.getElementById('inquiry_msg').textContent = '';
      document.getElementById('member_info').style.display = 'none';
    });
    
    document.getElementById('back_to_menu_report').addEventListener('click', function(){
      document.getElementById('today_report_form').style.display = 'none';
      document.getElementById('btn_today_report').parentNode.style.display = 'flex';
    });
    
    document.getElementById('refresh_today_report').addEventListener('click', function(){
      loadTodayReport();
    });
    
    // Navigate to previous day (left arrow ◀)
    document.getElementById('prev_day_report').addEventListener('click', function(){
      const prevDate = new Date(currentReportDate);
      prevDate.setDate(prevDate.getDate() - 1);
      loadTodayReport(prevDate.toISOString().split('T')[0]);
    });
    
    // Navigate to next day (right arrow ▶)
    document.getElementById('next_day_report').addEventListener('click', function(){
      const nextDate = new Date(currentReportDate);
      nextDate.setDate(nextDate.getDate() + 1);
      
      // Don't allow going to future dates
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      if (nextDate > today) {
        nextDate.setTime(today.getTime());
      }
      
      loadTodayReport(nextDate.toISOString().split('T')[0]);
    });
    
    // Return to today
    document.getElementById('go_to_today').addEventListener('click', function(){
      // Reset to get server's current date
      postJSON({action: 'get_today_report'})
        .then(function(response) {
          if (response.status === 'success') {
            // Use server's date to ensure accuracy
            loadTodayReport(response.date);
          }
        })
        .catch(function() {
          // Fallback to client date if server request fails
          const today = new Date();
          today.setHours(0, 0, 0, 0);
          loadTodayReport(today.toISOString().split('T')[0]);
        });
    });
    
    // Handle member inquiry - optimized
    document.getElementById('check_member').addEventListener('click', function(){
      const mobile = document.getElementById('inquiry_mobile').value.trim();
      const msg = document.getElementById('inquiry_msg');
      const memberInfo = document.getElementById('member_info');
      
      msg.textContent = '';
      memberInfo.style.display = 'none';
      
      if (!mobile) {
        msg.textContent = 'لطفاً شماره موبایل را وارد کنید.';
        return;
      }
      
      // Show loading state
      msg.textContent = 'در حال بررسی...';
      
      // Cache DOM elements to avoid multiple lookups
      const tableBody = document.getElementById('purchases_table_body');
      const purchaseHistory = document.getElementById('purchase_history');
      const noPurchases = document.getElementById('no_purchases');
      const memberCredit = document.getElementById('member_credit');
      
      // Reset credit use section
      document.getElementById('credit_use_amount').value = '';
      document.getElementById('credit_use_amount_raw').value = '';
      document.getElementById('credit_refund').checked = false;
      document.getElementById('credit_preview').style.display = 'none';
      document.getElementById('credit_use_msg').textContent = '';
      
      // Simple date formatter function
      const formatDate = dateString => {
        try {
          const parts = dateString.split('T')[0].split('-');
          const gregorianYear = parseInt(parts[0]);
          const gregorianMonth = parseInt(parts[1]);
          const gregorianDay = parseInt(parts[2]);
          
          // Convert to Jalali using the jalaali.js library
          const jalaliDate = window.toJalali(gregorianYear, gregorianMonth, gregorianDay);
          
          // Format with leading zeros for month and day
          const jMonth = jalaliDate.jm < 10 ? '0' + jalaliDate.jm : jalaliDate.jm;
          const jDay = jalaliDate.jd < 10 ? '0' + jalaliDate.jd : jalaliDate.jd;
          
          // Return in Jalali format: yyyy/mm/dd
          return `${jalaliDate.jy}/${jMonth}/${jDay}`;
        } catch (e) {
          console.error('Date conversion error:', e);
          return dateString;
        }
      };
      
      // Use a documentFragment for better performance when adding multiple rows
      const createTransactionRows = transactions => {
        const fragment = document.createDocumentFragment();
        
        transactions.forEach(transaction => {
          const row = document.createElement('tr');
          row.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
          
          const dateCell = document.createElement('td');
          dateCell.style.padding = '8px 4px';
          dateCell.style.textAlign = 'right';
          dateCell.textContent = formatDate(transaction.date);
          
          const amountCell = document.createElement('td');
          amountCell.style.padding = '8px 4px';
          amountCell.style.textAlign = 'left';
          
          // Style positive and negative amounts differently
          if (transaction.amount < 0) {
            // Negative amount (credit usage)
            amountCell.innerHTML = '<span style="color:#ff5252;">-' + formatWithDots(Math.abs(transaction.amount)) + '</span>';
            
            // Add a red minus icon for negative amounts
            const iconSpan = document.createElement('span');
            iconSpan.textContent = '−';  // Unicode minus sign
            iconSpan.style.color = '#ff5252';
            iconSpan.style.marginLeft = '5px';
            iconSpan.style.fontWeight = 'bold';
            amountCell.appendChild(iconSpan);
            
            // Add refund indicator if applicable
            if (transaction.is_refund) {
              const refundSpan = document.createElement('span');
              refundSpan.textContent = ' (مرجوع)';
              refundSpan.style.fontSize = '0.8em';
              refundSpan.style.color = '#ff9800';
              amountCell.appendChild(refundSpan);
            }
          } else {
            // Positive amount (purchase)
            amountCell.textContent = formatWithDots(transaction.amount);
          }
          
          row.appendChild(dateCell);
          row.appendChild(amountCell);
          fragment.appendChild(row);
        });
        
        return fragment;
      };
      
      // Make AJAX request with proper error handling
      postJSON({action: 'check_member', mobile: mobile})
        .then(json => {
          if (json.status === 'success') {
            // Update credit display
            memberCredit.textContent = 'امتیاز کل: ' + formatWithDots(json.credit_value) + ' تومان';
            currentCreditValue = json.credit_value; // Store current credit value for calculations
            
            // Clear previous transaction history
            tableBody.innerHTML = '';
            
            // Handle transaction history (both purchases and credit usage)
            if (json.transactions && json.transactions.length > 0) {
              purchaseHistory.style.display = 'block';
              noPurchases.style.display = 'none';
              
              // Batch DOM updates for better performance
              tableBody.appendChild(createTransactionRows(json.transactions));
            } else {
              purchaseHistory.style.display = 'none';
              noPurchases.style.display = 'block';
            }
            
            // Show the member info section
            memberInfo.style.display = 'block';
            msg.textContent = '';
          } else if (json.message === 'not_a_member') {
            msg.textContent = 'این شماره عضو باشگاه مشتریان نیست.';
          } else {
            msg.textContent = 'خطا: ' + (json.message || 'خطای ناشناخته');
          }
        })
        .catch(error => {
          console.error('Inquiry error:', error);
          msg.textContent = 'خطا در اتصال به سرور';
        });
    });
    
    document.getElementById('add_submit').addEventListener('click', function(){
      var mobile = document.getElementById('sub_mobile').value.trim(); 
      var amount = document.getElementById('sub_amount_raw').value.trim(); // Use raw value
      var msg = document.getElementById('add_msg'); msg.textContent='';
      if (!mobile) { msg.textContent='لطفاً موبایل را وارد کنید.'; return; }
      postJSON({action:'add_subscriber',mobile:mobile,amount:amount}).then(json=>{
        if (json.status==='success'){
          if (json.note==='existing_user') msg.textContent = json.admin_message || 'Subscriber is already a member.';
          else msg.textContent = 'عملیات با موفقیت انجام شد.';
          document.getElementById('sub_mobile').value=''; 
          document.getElementById('sub_amount').value='';
          document.getElementById('sub_amount_raw').value='';
        } else {
          msg.textContent = json.message || 'خطا';
        }
      }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    
    // Credit use amount input formatting
    const creditUseInput = document.getElementById('credit_use_amount');
    const creditUseRawInput = document.getElementById('credit_use_amount_raw');
    let currentCreditValue = 0;
    
    if (creditUseInput && creditUseRawInput) {
      creditUseInput.addEventListener('input', function(){
        var raw = extractRawNumber(this.value);
        creditUseRawInput.value = raw;
        var formatted = formatWithDots(raw);
        this.value = formatted;
        
        // Show credit preview if there's a value
        if (raw) {
          calculateAndShowCreditPreview();
        } else {
          document.getElementById('credit_preview').style.display = 'none';
        }
      });
      
      creditUseInput.addEventListener('blur', function(){
        this.value = formatWithDots(creditUseRawInput.value);
      });
    }
    
    // Function to calculate and display credit preview
    function calculateAndShowCreditPreview() {
      const creditRawAmount = parseInt(creditUseRawInput.value) || 0;
      
      if (creditRawAmount > 0) {
        const creditInPoints = Math.round((creditRawAmount / 5000) * 10) / 10; // Round to 1 decimal place
        const remainingCredit = Math.max(0, (currentCreditValue / 5000) - creditInPoints);
        const remainingCreditToman = Math.round(remainingCredit * 5000);
        
        document.getElementById('credit_remaining').textContent = formatWithDots(remainingCreditToman);
        document.getElementById('credit_preview').style.display = 'block';
      } else {
        document.getElementById('credit_preview').style.display = 'none';
      }
    }
    
    // Submit credit use
    document.getElementById('submit_credit_use').addEventListener('click', function() {
      const creditAmount = creditUseRawInput.value.trim();
      const isRefund = document.getElementById('credit_refund').checked;
      const mobile = document.getElementById('inquiry_mobile').value.trim();
      const msg = document.getElementById('credit_use_msg');
      msg.textContent = '';
      
      if (!creditAmount) {
        msg.textContent = 'لطفاً مبلغ را وارد کنید.';
        return;
      }
      
      // Validate that the amount doesn't exceed available credit
      const creditRawAmount = parseInt(creditAmount) || 0;
      const creditInPoints = Math.round((creditRawAmount / 5000) * 10) / 10; // Round to 1 decimal place
      const currentCreditPoints = currentCreditValue / 5000;
      
      if (creditInPoints > currentCreditPoints) {
        msg.textContent = 'مبلغ وارد شده بیشتر از امتیاز موجود است.';
        return;
      }
      
      // Show loading state
      msg.textContent = 'در حال پردازش...';
      
      // Send to server
      postJSON({
        action: 'use_credit',
        mobile: mobile,
        amount: creditRawAmount,
        is_refund: isRefund ? 'true' : 'false'
      }).then(json => {
        if (json.status === 'success') {
          // Success message with SMS status
          if (json.sms_sent) {
            msg.textContent = 'امتیاز با موفقیت استفاده شد و پیامک اطلاع‌رسانی ارسال گردید.';
          } else {
            msg.textContent = 'امتیاز با موفقیت استفاده شد ولی ارسال پیامک با خطا مواجه شد.';
          }
          
          // Update displayed credit with value from server
          document.getElementById('member_credit').textContent = 'امتیاز کل: ' + formatWithDots(json.credit_value) + ' تومان';
          currentCreditValue = json.credit_value;
          
          // Reset the form
          document.getElementById('credit_use_amount').value = '';
          document.getElementById('credit_use_amount_raw').value = '';
          document.getElementById('credit_refund').checked = false;
          document.getElementById('credit_preview').style.display = 'none';
        } else {
          msg.textContent = 'خطا: ' + (json.message || 'خطای ناشناخته');
        }
      }).catch(error => {
        console.error('Credit use error:', error);
        msg.textContent = 'خطا در اتصال به سرور';
      });
    });
    
    // Format time (HH:MM) from datetime string
    function formatTime(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      let hours = date.getHours();
      let minutes = date.getMinutes();
      
      // Add leading zeros
      hours = hours < 10 ? '0' + hours : hours;
      minutes = minutes < 10 ? '0' + minutes : minutes;
      
      return hours + ':' + minutes;
    }
    
    // Format persian date (YYYY/MM/DD) from datetime string
    function formatPersianDate(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      // Use jalali date if available
      if (window.Jalaali && typeof Jalaali.toJalali === 'function') {
        const jalali = Jalaali.toJalali(date.getFullYear(), date.getMonth() + 1, date.getDate());
        return jalali.jy + '/' + 
               (jalali.jm < 10 ? '0' + jalali.jm : jalali.jm) + '/' + 
               (jalali.jd < 10 ? '0' + jalali.jd : jalali.jd);
      }
      
      // Fallback to Gregorian date
      return date.getFullYear() + '/' +
             (date.getMonth() + 1).toString().padStart(2, '0') + '/' +
             date.getDate().toString().padStart(2, '0');
    }
    
    // Format gregorian date in a readable format (e.g., "21 October 2023")
    function formatGregorianDate(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
      ];
      
      return date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();
    }
    
    // Current report date storage - use server's date
    let currentReportDate = new Date();
    
    // Ensure we're using the current server date by requesting it initially
    function setCurrentServerDate(serverDate) {
      if (serverDate) {
        currentReportDate = new Date(serverDate);
      } else {
        currentReportDate = new Date();
      }
      currentReportDate.setHours(0, 0, 0, 0); // Reset time part
    }
    
    // Load transactions report for specific date (default: today)
    function loadTodayReport(dateString) {
      const purchasesList = document.getElementById('today_purchases_list');
      const creditsList = document.getElementById('today_credits_list');
      const totalPurchases = document.getElementById('total_purchases');
      const totalCreditsUsed = document.getElementById('total_credits_used');
      const todayDate = document.getElementById('today_date');
      const msg = document.getElementById('today_report_msg');
      
      // If no date provided, use current report date
      if (!dateString) {
        dateString = currentReportDate.toISOString().split('T')[0];
      } else {
        // Update current report date
        currentReportDate = new Date(dateString);
      }
      
      // Reset message
      msg.textContent = '';
      
      // Set loading state
      purchasesList.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      creditsList.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      
      // Fetch report for specified date
      postJSON({action: 'get_today_report', date: dateString})
        .then(function(response) {
          if (response.status === 'success') {
            // Update current report date based on server date
            setCurrentServerDate(response.date);
            
            // Update date display (using Gregorian format)
            todayDate.textContent = formatGregorianDate(response.date);
            
            // Update purchases list
            if (response.purchases && response.purchases.length > 0) {
              let purchasesHtml = '';
              response.purchases.forEach(function(purchase) {
                purchasesHtml += `
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:8px 4px;">${purchase.mobile}</td>
                    <td style="padding:8px 4px;">${formatTime(purchase.date)}</td>
                    <td style="padding:8px 4px;text-align:left;color:#4caf50;">${formatWithDots(purchase.amount)}</td>
                  </tr>
                `;
              });
              purchasesList.innerHTML = purchasesHtml;
            } else {
              purchasesList.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:15px;color:#b0b3b8;">امروز خریدی ثبت نشده است</td></tr>';
            }
            
            // Update credits list
            if (response.credits && response.credits.length > 0) {
              let creditsHtml = '';
              response.credits.forEach(function(credit) {
                creditsHtml += `
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                    <td style="padding:8px 4px;">${credit.mobile}</td>
                    <td style="padding:8px 4px;">${formatTime(credit.date)}</td>
                    <td style="padding:8px 4px;text-align:left;color:#ff5252;">${formatWithDots(credit.amount)}</td>
                    <td style="padding:8px 4px;text-align:center;">${credit.is_refund ? 
                      '<span style="color:#ff9800;">مرجوعی</span>' : 
                      '<span style="color:#03a9f4;">استفاده</span>'}</td>
                  </tr>
                `;
              });
              creditsList.innerHTML = creditsHtml;
            } else {
              creditsList.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:15px;color:#b0b3b8;">امروز اعتباری استفاده نشده است</td></tr>';
            }
            
            // Update totals
            totalPurchases.textContent = formatWithDots(response.total_purchases) + ' تومان';
            totalCreditsUsed.textContent = formatWithDots(response.total_credits) + ' تومان';
          } else {
            msg.textContent = response.message || 'خطا در دریافت اطلاعات';
            purchasesList.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
            creditsList.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
          }
        })
        .catch(function(error) {
          console.error('Error loading today report:', error);
          msg.textContent = 'خطا در اتصال به سرور';
          purchasesList.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
          creditsList.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
        });
    }
    <?php endif; ?>
  </script>
</body>
</html>
