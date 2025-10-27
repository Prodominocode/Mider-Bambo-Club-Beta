<?php
// mobile_inquiry.php: Public page for checking member info with mobile number + OTP
header('Content-Type: text/html; charset=utf-8');
session_start();

// Use direct database connection to avoid config conflicts
$host = 'localhost';
$db   = 'sasadiir_miderCDB'; 
$user = 'sasadiir_MiderclUs';      
$pass = '5TcCpBoXz7W71oi9';          

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// SMS settings
define('KAVENEGAR_API_KEY', '524651466876735449564C3647317575745461764B513D3D');
define('KAVENEGAR_SENDER', '9981802012');

// Helper function to normalize digits
function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

// Function to send SMS
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok'=>false,'error'=>$err];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    } else {
        $opts = ['http'=>['method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded','content'=>$postfields,'timeout'=>5]];
        $context = stream_context_create($opts);
        $resp = @file_get_contents($url,false,$context);
        if ($resp === false) return ['ok'=>false,'error'=>'file_get_contents_failed'];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    try {
        if ($_POST['action'] === 'send_otp') {
            $mobile = isset($_POST['mobile']) ? norm_digits(trim($_POST['mobile'])) : '';
            
            if (empty($mobile)) {
                echo json_encode(['success' => false, 'message' => 'شماره موبایل الزامی است']);
                exit;
            }
            
            if (!preg_match('/^09\d{9}$/', $mobile)) {
                echo json_encode(['success' => false, 'message' => 'شماره موبایل معتبر نیست']);
                exit;
            }
            
            // Check if user exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscribers WHERE mobile = ?");
            $stmt->execute([$mobile]);
            $exists = $stmt->fetchColumn();
            
            if (!$exists) {
                echo json_encode(['success' => false, 'message' => 'این شماره موبایل در سیستم ثبت نشده است']);
                exit;
            }
            
            // Generate and store OTP
            $otp = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
            
            // Store OTP in session with expiry
            $_SESSION['inquiry_otp'] = $otp;
            $_SESSION['inquiry_mobile'] = $mobile;
            $_SESSION['inquiry_otp_time'] = time();
            
            // Send SMS
            $message = "کد تایید استعلام باشگاه مشتریان میدر: $otp";
            $sms_result = send_kavenegar_sms($mobile, $message);
            
            if ($sms_result['ok']) {
                echo json_encode(['success' => true, 'message' => 'کد تایید ارسال شد']);
            } else {
                echo json_encode(['success' => false, 'message' => 'خطا در ارسال کد تایید']);
            }
            exit;
        }
        
        if ($_POST['action'] === 'verify_and_inquiry') {
            $mobile = isset($_POST['mobile']) ? norm_digits(trim($_POST['mobile'])) : '';
            $otp = isset($_POST['otp']) ? norm_digits(trim($_POST['otp'])) : '';
            
            if (empty($mobile) || empty($otp)) {
                echo json_encode(['success' => false, 'message' => 'شماره موبایل و کد تایید الزامی است']);
                exit;
            }
            
            // Check OTP
            if (!isset($_SESSION['inquiry_otp']) || 
                !isset($_SESSION['inquiry_mobile']) || 
                !isset($_SESSION['inquiry_otp_time'])) {
                echo json_encode(['success' => false, 'message' => 'لطفاً ابتدا کد تایید را درخواست کنید']);
                exit;
            }
            
            // Check OTP expiry (5 minutes)
            if (time() - $_SESSION['inquiry_otp_time'] > 300) {
                echo json_encode(['success' => false, 'message' => 'کد تایید منقضی شده است']);
                exit;
            }
            
            if ($otp !== $_SESSION['inquiry_otp'] || $mobile !== $_SESSION['inquiry_mobile']) {
                echo json_encode(['success' => false, 'message' => 'کد تایید یا شماره موبایل اشتباه است']);
                exit;
            }
            
            // Clear OTP from session
            unset($_SESSION['inquiry_otp']);
            unset($_SESSION['inquiry_mobile']);
            unset($_SESSION['inquiry_otp_time']);
            
            // Get member information
            $stmt = $pdo->prepare("
                SELECT 
                    mobile, 
                    full_name, 
                    credit, 
                    birthday, 
                    created_at,
                    verified 
                FROM subscribers 
                WHERE mobile = ?
            ");
            $stmt->execute([$mobile]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['success' => false, 'message' => 'عضو یافت نشد']);
                exit;
            }
            
            // Get recent transactions from purchases and credit_usage tables
            $transactions = [];
            
            // Get purchases (positive credits)
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        amount, 
                        created_at,
                        'purchase' as type,
                        admin_number,
                        description
                    FROM purchases 
                    WHERE mobile = ? AND active = 1 
                    ORDER BY created_at DESC 
                    LIMIT 5
                ");
                $stmt->execute([$mobile]);
                $purchase_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $transactions = array_merge($transactions, $purchase_transactions);
            } catch (Exception $e) {
                error_log("Error fetching purchases: " . $e->getMessage());
                // Continue without purchase history
            }
            
            // Get credit usage (negative credits)
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        amount, 
                        datetime as created_at,
                        'usage' as type,
                        admin_mobile as admin_number,
                        is_refund
                    FROM credit_usage 
                    WHERE user_mobile = ? AND active = 1 
                    ORDER BY datetime DESC 
                    LIMIT 5
                ");
                $stmt->execute([$mobile]);
                $usage_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $transactions = array_merge($transactions, $usage_transactions);
            } catch (Exception $e) {
                error_log("Error fetching credit usage: " . $e->getMessage());
                // Continue without usage history
            }
            
            // Sort combined transactions by date
            usort($transactions, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            // Limit to 10 most recent transactions
            $transactions = array_slice($transactions, 0, 10);
            
            // Get gift credits for this user
            $gift_credits = [];
            $total_gift_credit_toman = 0;
            try {
                error_log("Fetching gift credits for mobile: " . $mobile);
                
                $stmt = $pdo->prepare("
                    SELECT id, credit_amount, gift_amount_toman, created_at, notes as description
                    FROM gift_credits 
                    WHERE mobile = ? AND active = 1
                    ORDER BY created_at DESC
                ");
                $stmt->execute([$mobile]);
                $gift_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("Gift credits fetched: " . count($gift_credits) . " records");
                
                if (count($gift_credits) > 0) {
                    error_log("Sample gift credit: " . json_encode($gift_credits[0]));
                }
                
                // Calculate total gift credits in Toman
                $stmt = $pdo->prepare("
                    SELECT SUM(gift_amount_toman) as total_gift_toman
                    FROM gift_credits 
                    WHERE mobile = ? AND active = 1
                ");
                $stmt->execute([$mobile]);
                $gift_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_gift_credit_toman = $gift_result['total_gift_toman'] ?? 0;
                error_log("Total gift credit toman calculated: " . $total_gift_credit_toman);
            } catch (Exception $e) {
                // Gift credits table might not exist, continue without it
                error_log("Error fetching gift credits: " . $e->getMessage());
                $gift_credits = [];
                $total_gift_credit_toman = 0;
            }
            
            // Get pending credits for this user
            $total_pending_credits = 0;
            try {
                $stmt = $pdo->prepare("
                    SELECT SUM(credit_amount) as total_pending
                    FROM pending_credits 
                    WHERE mobile = ?
                ");
                $stmt->execute([$mobile]);
                $pending_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_pending_credits = $pending_result['total_pending'] ?? 0;
            } catch (Exception $e) {
                error_log("Error fetching pending credits: " . $e->getMessage());
                // Continue without pending credits
                $total_pending_credits = 0;
            }
            
            // Format data for response
            $response_data = [
                'member_info' => [
                    'mobile' => $member['mobile'],
                    'name' => trim($member['full_name'] ?? ''),
                    'credit' => number_format(($member['credit'] ?? 0) * 5000),
                    'pending_credits' => number_format($total_pending_credits * 5000),
                    'gift_credit_toman' => number_format($total_gift_credit_toman),
                    'birthday' => $member['birthday'] ?? '',
                    'gender' => 'نامشخص', // Gender not available in current schema
                    'status' => $member['verified'] ? 'فعال' : 'غیرفعال',
                    'member_since' => date('Y/m/d', strtotime($member['created_at']))
                ],
                'transactions' => [],
                'gift_credits' => []
            ];
            
            foreach ($transactions as $trans) {
                $transaction_type = 'خرید'; // Default
                $amount_display = number_format($trans['amount']);
                $description = '';
                
                if ($trans['type'] === 'purchase') {
                    $transaction_type = 'خرید';
                    $description = $trans['description'] ?? '';
                } elseif ($trans['type'] === 'usage') {
                    $transaction_type = isset($trans['is_refund']) && $trans['is_refund'] ? 'مرجوع کالا' : 'استفاده اعتبار';
                    $amount_display = '-' . number_format($trans['amount']); // Negative for usage
                    $description = $transaction_type;
                }
                
                $response_data['transactions'][] = [
                    'date' => date('Y/m/d', strtotime($trans['created_at'])),
                    'time' => date('H:i', strtotime($trans['created_at'])),
                    'type' => $transaction_type,
                    'amount' => $amount_display,
                    'description' => $description
                ];
            }
            
            // Add gift credits to response
            foreach ($gift_credits as $gift) {
                $response_data['gift_credits'][] = [
                    'credit_amount' => number_format($gift['credit_amount']),
                    'gift_amount_toman' => number_format($gift['gift_amount_toman'] ?? 0),
                    'date' => date('Y/m/d', strtotime($gift['created_at'])),
                    'description' => $gift['description'] ?? 'اعتبار هدیه'
                ];
            }
            
            // Debug logging
            error_log("Gift credits count: " . count($gift_credits));
            error_log("Total gift credit toman: " . $total_gift_credit_toman);
            error_log("Response gift_credits: " . json_encode($response_data['gift_credits']));
            
            echo json_encode(['success' => true, 'data' => $response_data]);
            exit;
        }
        
    } catch (Exception $e) {
        // Log the actual error for debugging (remove this in production)
        error_log("Mobile inquiry error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        echo json_encode(['success' => false, 'message' => 'خطا در پردازش درخواست']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>استعلام با شماره موبایل - باشگاه مشتریان MIDER</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .inquiry-container {
      max-width: 500px;
      margin: 50px auto;
      padding: 30px;
      background: rgba(0, 0, 0, 0.8);
      border-radius: 12px;
      border: 1px solid rgba(76, 175, 80, 0.3);
      box-shadow: 0 4px 20px rgba(76, 175, 80, 0.2);
      position: relative;
      z-index: 10;
    }
    
    .inquiry-title {
      text-align: center;
      font-size: 24px;
      color: #a5d6a7;
      margin-bottom: 20px;
      font-weight: bold;
    }
    
    .inquiry-subtitle {
      text-align: center;
      font-size: 14px;
      color: #888;
      margin-bottom: 30px;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #a5d6a7;
      font-weight: bold;
    }
    
    .form-group input {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #4caf50;
      border-radius: 8px;
      background: #0d0d0d;
      color: #fff;
      font-size: 16px;
      text-align: center;
      box-sizing: border-box;
    }
    
    .form-group input:focus {
      outline: none;
      border-color: #a5d6a7;
      box-shadow: 0 0 10px rgba(76, 175, 80, 0.3);
    }
    
    .btn-inquiry {
      width: 100%;
      padding: 12px;
      background: linear-gradient(45deg, #4caf50, #a5d6a7);
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.3s ease;
      margin-bottom: 10px;
    }
    
    .btn-inquiry:hover {
      background: linear-gradient(45deg, #43a047, #81c784);
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(76, 175, 80, 0.4);
    }
    
    .btn-inquiry:disabled {
      background: #444;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
    
    .message {
      margin: 15px 0;
      padding: 12px;
      border-radius: 6px;
      text-align: center;
      font-weight: bold;
    }
    
    .message.error {
      background: rgba(244, 67, 54, 0.2);
      color: #ff5252;
      border: 1px solid rgba(244, 67, 54, 0.3);
    }
    
    .message.success {
      background: rgba(76, 175, 80, 0.2);
      color: #4caf50;
      border: 1px solid rgba(76, 175, 80, 0.3);
    }
    
    .member-info {
      background: rgba(76, 175, 80, 0.1);
      border: 1px solid rgba(76, 175, 80, 0.3);
      border-radius: 8px;
      padding: 20px;
      margin-top: 20px;
    }
    
    .member-card {
      background: linear-gradient(45deg, #4caf50, #a5d6a7);
      color: white;
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      margin-bottom: 20px;
      box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
    }
    
    .credit-amount {
      font-size: 24px;
      font-weight: bold;
      margin-bottom: 8px;
    }
    
    .credit-label {
      font-size: 12px;
      opacity: 0.9;
    }
    
    .info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 15px;
      margin-bottom: 20px;
    }
    
    .info-item {
      background: rgba(0, 0, 0, 0.3);
      padding: 15px;
      border-radius: 8px;
      text-align: center;
    }
    
    .info-value {
      font-size: 18px;
      font-weight: bold;
      color: #a5d6a7;
      margin-bottom: 5px;
    }
    
    .info-label {
      font-size: 12px;
      color: #888;
    }
    
    .member-details {
      background: rgba(0, 0, 0, 0.3);
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 15px;
    }
    
    .member-details h4 {
      color: #a5d6a7;
      margin: 0 0 10px 0;
      font-size: 16px;
    }
    
    .detail-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
      color: #ccc;
    }
    
    .detail-row:last-child {
      margin-bottom: 0;
    }
    
    .history-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 8px;
      overflow: hidden;
    }
    
    .history-table th {
      background: rgba(76, 175, 80, 0.2);
      color: #a5d6a7;
      padding: 12px 8px;
      text-align: center;
      font-weight: bold;
      border-bottom: 1px solid rgba(76, 175, 80, 0.3);
    }
    
    .history-table td {
      padding: 10px 8px;
      text-align: center;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      color: #ccc;
      font-size: 14px;
    }
    
    .history-table tr:last-child td {
      border-bottom: none;
    }
    
    .back-link {
      display: block;
      text-align: center;
      margin-top: 20px;
      color: #888;
      text-decoration: none;
      font-size: 14px;
    }
    
    .back-link:hover {
      color: #a5d6a7;
    }
    
    .loading {
      text-align: center;
      color: #a5d6a7;
      font-style: italic;
    }
    
    .step-inactive {
      display: none;
    }
    
    .countdown {
      font-size: 12px;
      color: #888;
      margin-top: 5px;
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="inquiry-container">
    <div class="inquiry-title">📱 استعلام با شماره موبایل</div>
    <div class="inquiry-subtitle">شماره موبایل خود را وارد کرده و کد تایید را دریافت کنید</div>
    
    <!-- Step 1: Mobile Number -->
    <div id="step1">
      <form id="mobile-form">
        <div class="form-group">
          <label for="mobile_number">شماره موبایل</label>
          <input type="text" id="mobile_number" name="mobile_number" placeholder="09123456789" maxlength="11" required>
        </div>
        
        <button type="submit" id="send-otp-btn" class="btn-inquiry">ارسال کد تایید</button>
        
        <div id="mobile-message" class="message" style="display:none;"></div>
      </form>
    </div>
    
    <!-- Step 2: OTP Verification -->
    <div id="step2" class="step-inactive">
      <form id="otp-form">
        <div class="form-group">
          <label for="otp_code">کد تایید 5 رقمی</label>
          <input type="text" id="otp_code" name="otp_code" placeholder="12345" maxlength="5" required>
          <div class="countdown" id="countdown"></div>
        </div>
        
        <button type="submit" id="verify-btn" class="btn-inquiry">تایید و نمایش اطلاعات</button>
        <button type="button" id="back-to-mobile" class="btn-inquiry" style="background:#666;">بازگشت</button>
        
        <div id="otp-message" class="message" style="display:none;"></div>
      </form>
    </div>
    
    <!-- Step 3: Member Information -->
    <div id="member-result" class="step-inactive">
      <div class="member-card">
        <div class="credit-amount" id="member-credit">0 تومان</div>
        <div class="credit-label">اعتبار موجود</div>
      </div>
      
      <div class="info-grid">
        <div class="info-item">
          <div class="info-value" id="gift-credit">0</div>
          <div class="info-label">اعتبار هدیه (تومان)</div>
        </div>
        <div class="info-item">
          <div class="info-value" id="pending-credits">0</div>
          <div class="info-label">اعتبار در انتظار تایید</div>
        </div>
      </div>
      
      <div class="member-details">
        <h4>اطلاعات عضویت</h4>
        <div class="detail-row">
          <span>نام:</span>
          <span id="member-name">-</span>
        </div>
        <div class="detail-row">
          <span>موبایل:</span>
          <span id="member-mobile">-</span>
        </div>
        <!-- <div class="detail-row">
          <span>جنسیت:</span>
          <span id="member-gender">-</span>
        </div> -->
        <div class="detail-row">
          <span>تاریخ تولد:</span>
          <span id="member-birthday">-</span>
        </div>
        <div class="detail-row">
          <span>وضعیت:</span>
          <span id="member-status">-</span>
        </div>
      </div>
      
      <div id="transaction-history" style="display:none;">
        <h4 style="color:#a5d6a7;margin-bottom:10px;">آخرین تراکنش‌ها</h4>
        <table class="history-table">
          <thead>
            <tr>
              <th>تاریخ</th>
              <th>ساعت</th>
              <th>نوع</th>
              <th>مبلغ</th>
              <th>توضیحات</th>
            </tr>
          </thead>
          <tbody id="history-tbody">
          </tbody>
        </table>
      </div>
      
      <div id="no-history" style="display:none;">
        <div style="text-align:center;color:#888;margin-top:15px;padding:20px;background:rgba(0,0,0,0.2);border-radius:8px;">
          هیچ تراکنشی برای این کاربر ثبت نشده است
        </div>
      </div>
      
      <div id="gift-credits-section" style="display:none;">
        <h4 style="color:#a5d6a7;margin-bottom:10px;">اعتبارات هدیه</h4>
        <table class="history-table">
          <thead>
            <tr>
              <th>تاریخ</th>
              <th>امتیاز</th>
              <th>مبلغ (تومان)</th>
              <th>توضیحات</th>
            </tr>
          </thead>
          <tbody id="gift-credits-tbody">
          </tbody>
        </table>
      </div>
      
      <button type="button" id="new-inquiry" class="btn-inquiry" style="margin-top:20px;">استعلام جدید</button>
    </div>
    
    <a href="index.php" class="back-link">← بازگشت به صفحه اصلی</a>
  </div>

  <script>
    let countdownInterval;
    
    // Format Persian date (same method as vcard_balance.php)
    function formatPersianDate(dateString) {
      const date = new Date(dateString);
      return new Intl.DateTimeFormat('fa-IR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      }).format(date);
    }
    
    // Format Persian time
    function formatPersianTime(dateString) {
      const date = new Date(dateString);
      return new Intl.DateTimeFormat('fa-IR', {
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    }
    
    document.getElementById('mobile-form').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const mobile = document.getElementById('mobile_number').value.trim();
      const btn = document.getElementById('send-otp-btn');
      const msg = document.getElementById('mobile-message');
      
      if (!mobile) {
        showMessage(msg, 'شماره موبایل را وارد کنید', 'error');
        return;
      }
      
      btn.disabled = true;
      btn.textContent = 'در حال ارسال...';
      
      fetch('mobile_inquiry.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=send_otp&mobile=' + encodeURIComponent(mobile)
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showMessage(msg, data.message, 'success');
          setTimeout(() => {
            document.getElementById('step1').classList.add('step-inactive');
            document.getElementById('step2').classList.remove('step-inactive');
            startCountdown();
          }, 1000);
        } else {
          showMessage(msg, data.message, 'error');
        }
      })
      .catch(error => {
        showMessage(msg, 'خطا در اتصال به سرور', 'error');
      })
      .finally(() => {
        btn.disabled = false;
        btn.textContent = 'ارسال کد تایید';
      });
    });
    
    document.getElementById('otp-form').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const mobile = document.getElementById('mobile_number').value.trim();
      const otp = document.getElementById('otp_code').value.trim();
      const btn = document.getElementById('verify-btn');
      const msg = document.getElementById('otp-message');
      
      if (!otp) {
        showMessage(msg, 'کد تایید را وارد کنید', 'error');
        return;
      }
      
      btn.disabled = true;
      btn.textContent = 'در حال تایید...';
      
      fetch('mobile_inquiry.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=verify_and_inquiry&mobile=' + encodeURIComponent(mobile) + '&otp=' + encodeURIComponent(otp)
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          clearInterval(countdownInterval);
          showMemberInfo(data.data);
          document.getElementById('step2').classList.add('step-inactive');
          document.getElementById('member-result').classList.remove('step-inactive');
        } else {
          showMessage(msg, data.message, 'error');
        }
      })
      .catch(error => {
        console.error('Request error:', error);
        showMessage(msg, 'خطا در اتصال به سرور', 'error');
      })
      .finally(() => {
        btn.disabled = false;
        btn.textContent = 'تایید و نمایش اطلاعات';
      });
    });
    
    document.getElementById('back-to-mobile').addEventListener('click', function() {
      clearInterval(countdownInterval);
      document.getElementById('step2').classList.add('step-inactive');
      document.getElementById('step1').classList.remove('step-inactive');
      document.getElementById('otp_code').value = '';
    });
    
    document.getElementById('new-inquiry').addEventListener('click', function() {
      document.getElementById('member-result').classList.add('step-inactive');
      document.getElementById('step1').classList.remove('step-inactive');
      document.getElementById('mobile_number').value = '';
      document.getElementById('otp_code').value = '';
      document.getElementById('mobile-message').style.display = 'none';
      document.getElementById('otp-message').style.display = 'none';
      document.getElementById('gift-credits-section').style.display = 'none';
    });
    
    function showMessage(element, message, type) {
      element.textContent = message;
      element.className = 'message ' + type;
      element.style.display = 'block';
    }
    
    function showMemberInfo(data) {
      console.log('showMemberInfo called with data:', data);
      
      document.getElementById('member-credit').textContent = data.member_info.credit + ' تومان';
      document.getElementById('pending-credits').textContent = data.member_info.pending_credits + ' تومان';
      document.getElementById('member-name').textContent = data.member_info.name || 'نامشخص';
      document.getElementById('member-mobile').textContent = data.member_info.mobile;
      
      // Birthday should NOT be converted to Persian date
      document.getElementById('member-birthday').textContent = data.member_info.birthday || 'نامشخص';
      
      document.getElementById('member-status').textContent = data.member_info.status;
      
      // Show transaction history if available
      console.log('Checking transactions:', data.transactions);
      if (data.transactions && data.transactions.length > 0) {
        const tbody = document.getElementById('history-tbody');
        tbody.innerHTML = '';
        
        data.transactions.forEach(trans => {
          const persianDate = formatPersianDate(trans.date + ' ' + trans.time);
          const row = document.createElement('tr');
          row.innerHTML = `
            <td>${persianDate}</td>
            <td>${formatPersianTime(trans.date + ' ' + trans.time)}</td>
            <td>${trans.type}</td>
            <td>${trans.amount} امتیاز</td>
            <td>${trans.description}</td>
          `;
          tbody.appendChild(row);
        });
        
        document.getElementById('transaction-history').style.display = 'block';
        document.getElementById('no-history').style.display = 'none';
      } else {
        document.getElementById('transaction-history').style.display = 'none';
        document.getElementById('no-history').style.display = 'block';
      }
      
      // Debug gift credits
      console.log('Checking gift credits:', data.gift_credits);
      console.log('Gift credits length:', data.gift_credits ? data.gift_credits.length : 'undefined');
      console.log('Gift credit toman from member_info:', data.member_info.gift_credit_toman);
      
      // Show gift credits if available
      if (data.gift_credits && data.gift_credits.length > 0) {
        console.log('Gift credits found, rendering table...');
        const giftTbody = document.getElementById('gift-credits-tbody');
        console.log('Gift credits tbody element:', giftTbody);
        
        if (!giftTbody) {
          console.error('Gift credits tbody element not found!');
          return;
        }
        
        giftTbody.innerHTML = '';
        
        data.gift_credits.forEach((gift, index) => {
          console.log(`Processing gift credit ${index}:`, gift);
          const persianDate = formatPersianDate(gift.date);
          const row = document.createElement('tr');
          row.innerHTML = `
            <td>${persianDate}</td>
            <td>${gift.credit_amount} امتیاز</td>
            <td>${gift.gift_amount_toman} تومان</td>
            <td>${gift.description}</td>
          `;
          giftTbody.appendChild(row);
        });
        
        // Update gift credit display from server data
        document.getElementById('gift-credit').textContent = data.member_info.gift_credit_toman;
        
        const giftSection = document.getElementById('gift-credits-section');
        console.log('Gift credits section element:', giftSection);
        giftSection.style.display = 'block';
        console.log('Gift credits table should now be visible');
      } else {
        console.log('No gift credits found, hiding table');
        document.getElementById('gift-credit').textContent = '0';
        document.getElementById('gift-credits-section').style.display = 'none';
      }
    }
    
    function startCountdown() {
      let timeLeft = 300; // 5 minutes
      const countdownElement = document.getElementById('countdown');
      
      countdownInterval = setInterval(() => {
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        countdownElement.textContent = `کد تایید تا ${minutes}:${seconds.toString().padStart(2, '0')} دقیقه معتبر است`;
        
        timeLeft--;
        
        if (timeLeft < 0) {
          clearInterval(countdownInterval);
          countdownElement.textContent = 'کد تایید منقضی شده است. لطفاً مجدداً درخواست کنید.';
        }
      }, 1000);
    }
    
    // Auto-focus on inputs
    document.getElementById('mobile_number').focus();
    
    // Only allow digits in mobile and OTP fields
    document.getElementById('mobile_number').addEventListener('input', function(e) {
      e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
    
    document.getElementById('otp_code').addEventListener('input', function(e) {
      e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
  </script>
</body>
</html>