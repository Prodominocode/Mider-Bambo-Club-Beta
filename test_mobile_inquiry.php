<?php
// mobile_inquiry.php: Minimal version for testing
header('Content-Type: text/html; charset=utf-8');
session_start();

// Direct database connection without config.php
$host = 'localhost';
$db   = 'sasadiir_miderCDB'; 
$user = 'sasadiir_MiderclUs';      
$pass = '5TcCpBoXz7W71oi9';          

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// Helper function to normalize digits
function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'get_member_info') {
        $mobile = norm_digits($input['mobile'] ?? '');
        
        if (empty($mobile)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره موبایل الزامی است']);
            exit;
        }
        
        try {
            // Check if subscriber exists
            $stmt = $pdo->prepare("SELECT id, name, birthday, total_credit FROM subscribers WHERE mobile = ?");
            $stmt->execute([$mobile]);
            $subscriber = $stmt->fetch();
            
            if (!$subscriber) {
                echo json_encode(['status' => 'error', 'message' => 'شماره موبایل یافت نشد']);
                exit;
            }
            
            // Get gift credits for this user
            $gift_credits = [];
            $total_gift_credit_toman = 0;
            try {
                $stmt = $pdo->prepare("
                    SELECT id, credit_amount, gift_amount_toman, created_at, notes as description
                    FROM gift_credits 
                    WHERE mobile = ? AND active = 1
                    ORDER BY created_at DESC
                ");
                $stmt->execute([$mobile]);
                $gift_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate total gift credits in Toman
                $stmt = $pdo->prepare("
                    SELECT SUM(gift_amount_toman) as total_gift_toman
                    FROM gift_credits 
                    WHERE mobile = ? AND active = 1
                ");
                $stmt->execute([$mobile]);
                $gift_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_gift_credit_toman = $gift_result['total_gift_toman'] ?? 0;
            } catch (Exception $e) {
                error_log("Error fetching gift credits: " . $e->getMessage());
                $gift_credits = [];
                $total_gift_credit_toman = 0;
            }
            
            // Get purchases
            $stmt = $pdo->prepare("
                SELECT id, credit_amount, purchase_amount, purchase_date, description
                FROM purchases 
                WHERE mobile = ? 
                ORDER BY purchase_date DESC
            ");
            $stmt->execute([$mobile]);
            $purchases = $stmt->fetchAll();
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'subscriber' => $subscriber,
                    'purchases' => $purchases,
                    'gift_credits' => $gift_credits,
                    'total_gift_credit_toman' => $total_gift_credit_toman
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'خطا در بازیابی اطلاعات']);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استعلام موجودی</title>
    <style>
        body { font-family: IRANYekan, Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; direction: rtl; }
        .container { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        input[type="text"] { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; box-sizing: border-box; }
        .btn { background: #007bff; color: white; padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #0056b3; }
        .member-info { margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .member-info h3 { margin-top: 0; color: #333; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .info-label { font-weight: bold; color: #666; }
        .info-value { color: #333; }
        .purchases-table, .gift-credits-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .purchases-table th, .purchases-table td, .gift-credits-table th, .gift-credits-table td { 
            padding: 8px; border: 1px solid #ddd; text-align: center; 
        }
        .purchases-table th, .gift-credits-table th { background: #f1f1f1; font-weight: bold; }
        .error { color: #dc3545; margin-top: 10px; }
        .loading { color: #007bff; margin-top: 10px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h2>استعلام موجودی</h2>
        
        <div class="form-group">
            <label for="mobile">شماره موبایل:</label>
            <input type="text" id="mobile" placeholder="09123456789" maxlength="11">
        </div>
        
        <button class="btn" onclick="getMemberInfo()">استعلام</button>
        
        <div id="loading" class="loading hidden">در حال بارگذاری...</div>
        <div id="error" class="error hidden"></div>
        <div id="member-info" class="member-info hidden"></div>
    </div>

    <script>
        function getMemberInfo() {
            const mobile = document.getElementById('mobile').value.trim();
            
            if (!mobile) {
                showError('لطفا شماره موبایل را وارد کنید');
                return;
            }
            
            showLoading();
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_member_info', mobile: mobile })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.status === 'success') {
                    showMemberInfo(data.data);
                } else {
                    showError(data.message);
                }
            })
            .catch(error => {
                hideLoading();
                showError('خطا در اتصال به سرور');
                console.error('Error:', error);
            });
        }
        
        function showMemberInfo(data) {
            const subscriber = data.subscriber;
            const purchases = data.purchases;
            const giftCredits = data.gift_credits;
            const totalGiftToman = data.total_gift_credit_toman;
            
            console.log('Showing member info:', data);
            console.log('Gift credits:', giftCredits);
            
            let html = '<h3>اطلاعات عضو</h3>';
            html += '<div class="info-row"><span class="info-label">نام:</span><span class="info-value">' + subscriber.name + '</span></div>';
            html += '<div class="info-row"><span class="info-label">موجودی کل:</span><span class="info-value">' + (subscriber.total_credit * 5000).toLocaleString() + ' تومان</span></div>';
            
            if (totalGiftToman > 0) {
                html += '<div class="info-row"><span class="info-label">موجودی هدیه:</span><span class="info-value">' + totalGiftToman.toLocaleString() + ' تومان</span></div>';
            }
            
            // Gift credits table
            if (giftCredits && giftCredits.length > 0) {
                html += '<h4>جزئیات اعتبار هدیه:</h4>';
                html += '<table class="gift-credits-table">';
                html += '<tr><th>مبلغ (تومان)</th><th>تاریخ</th><th>توضیحات</th></tr>';
                
                giftCredits.forEach(gift => {
                    const date = new Date(gift.created_at);
                    const persianDate = date.toLocaleDateString('fa-IR');
                    html += '<tr>';
                    html += '<td>' + gift.gift_amount_toman.toLocaleString() + '</td>';
                    html += '<td>' + persianDate + '</td>';
                    html += '<td>' + (gift.description || '') + '</td>';
                    html += '</tr>';
                });
                html += '</table>';
            }
            
            // Purchases table
            if (purchases && purchases.length > 0) {
                html += '<h4>تاریخچه خرید:</h4>';
                html += '<table class="purchases-table">';
                html += '<tr><th>مبلغ خرید</th><th>اعتبار</th><th>تاریخ</th><th>توضیحات</th></tr>';
                
                purchases.forEach(purchase => {
                    const date = new Date(purchase.purchase_date);
                    const persianDate = date.toLocaleDateString('fa-IR');
                    html += '<tr>';
                    html += '<td>' + purchase.purchase_amount.toLocaleString() + '</td>';
                    html += '<td>' + (purchase.credit_amount * 5000).toLocaleString() + '</td>';
                    html += '<td>' + persianDate + '</td>';
                    html += '<td>' + (purchase.description || '') + '</td>';
                    html += '</tr>';
                });
                html += '</table>';
            }
            
            document.getElementById('member-info').innerHTML = html;
            document.getElementById('member-info').classList.remove('hidden');
            document.getElementById('error').classList.add('hidden');
        }
        
        function showError(message) {
            document.getElementById('error').textContent = message;
            document.getElementById('error').classList.remove('hidden');
            document.getElementById('member-info').classList.add('hidden');
        }
        
        function showLoading() {
            document.getElementById('loading').classList.remove('hidden');
            document.getElementById('error').classList.add('hidden');
            document.getElementById('member-info').classList.add('hidden');
        }
        
        function hideLoading() {
            document.getElementById('loading').classList.add('hidden');
        }
    </script>
</body>
</html>