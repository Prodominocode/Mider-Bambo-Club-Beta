<?php
// Mobile Digit Converter - Standalone PHP Script
// Converts Persian/Farsi digits to English digits in subscribers table
header('Content-Type: text/html; charset=utf-8');
session_start();

try {
    require_once 'config.php';
    
    // Include db.php but handle connection failure gracefully
    ob_start();
    require_once 'db.php';
    $db_output = ob_get_contents();
    ob_end_clean();
    
    // Check if db.php outputted an error
    if (!empty($db_output)) {
        http_response_code(500);
        die('Database connection failed. Please check database configuration.');
    }
    
    // Check if $pdo variable exists
    if (!isset($pdo)) {
        http_response_code(500);
        die('Database connection not available.');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    die('Error loading required files: ' . $e->getMessage());
}

// Set time zone to Tehran for all date/time operations
date_default_timezone_set('Asia/Tehran');

// Function to normalize digits (convert Persian/Farsi to English)
function norm_digits($s) {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    return $s;
}

// Function to check if string contains Persian/Farsi digits
function has_persian_digits($s) {
    $persian_digits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    foreach ($persian_digits as $digit) {
        if (strpos($s, $digit) !== false) {
            return true;
        }
    }
    return false;
}

// Get all subscribers with Persian digits in mobile field
function getSubscribersWithPersianDigits($pdo) {
    $sql = "SELECT id, full_name, mobile FROM subscribers ORDER BY id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $all_subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subscribers_with_persian = [];
    foreach ($all_subscribers as $subscriber) {
        if (has_persian_digits($subscriber['mobile'])) {
            $subscriber['converted_mobile'] = norm_digits($subscriber['mobile']);
            $subscribers_with_persian[] = $subscriber;
        }
    }
    
    return $subscribers_with_persian;
}

// Handle form submission for update
$update_performed = false;
$update_results = [];
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mobiles'])) {
    try {
        $pdo->beginTransaction();
        
        $subscribers_to_update = getSubscribersWithPersianDigits($pdo);
        $updated_count = 0;
        
        foreach ($subscribers_to_update as $subscriber) {
            $original_mobile = $subscriber['mobile'];
            $converted_mobile = $subscriber['converted_mobile'];
            
            // Update the mobile number
            $update_sql = "UPDATE subscribers SET mobile = ? WHERE id = ?";
            $update_stmt = $pdo->prepare($update_sql);
            $result = $update_stmt->execute([$converted_mobile, $subscriber['id']]);
            
            if ($result) {
                $updated_count++;
                $update_results[] = [
                    'id' => $subscriber['id'],
                    'name' => $subscriber['full_name'],
                    'original' => $original_mobile,
                    'converted' => $converted_mobile,
                    'status' => 'success'
                ];
            } else {
                $update_results[] = [
                    'id' => $subscriber['id'],
                    'name' => $subscriber['full_name'],
                    'original' => $original_mobile,
                    'converted' => $converted_mobile,
                    'status' => 'failed'
                ];
            }
        }
        
        $pdo->commit();
        $update_performed = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = 'Error during update: ' . $e->getMessage();
    }
}

// Get current subscribers with Persian digits
$subscribers_with_persian = getSubscribersWithPersianDigits($pdo);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تبدیل اعداد فارسی به انگلیسی - شماره موبایل</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            color: #fff;
            direction: rtl;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .title {
            font-size: 2rem;
            font-weight: 700;
            color: #ffb300;
            margin-bottom: 8px;
        }
        
        .subtitle {
            font-size: 1.1rem;
            color: #e3f2fd;
            opacity: 0.9;
        }
        
        .stats-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .stat-item {
            text-align: center;
            padding: 16px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #ffb300;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: #e3f2fd;
            font-size: 0.9rem;
        }
        
        .table-container {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .data-table th {
            background: rgba(255,179,0,0.2);
            color: #ffb300;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .data-table td {
            padding: 10px 8px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.02);
        }
        
        .data-table tr:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .mobile-original {
            font-family: 'Courier New', monospace;
            color: #ff5252;
            font-weight: bold;
            direction: ltr;
            text-align: center;
        }
        
        .mobile-converted {
            font-family: 'Courier New', monospace;
            color: #4caf50;
            font-weight: bold;
            direction: ltr;
            text-align: center;
        }
        
        .update-section {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 24px;
        }
        
        .btn-update {
            background: linear-gradient(135deg, #4caf50, #45a049);
            color: white;
            border: none;
            padding: 16px 32px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(76,175,80,0.3);
        }
        
        .btn-update:hover {
            background: linear-gradient(135deg, #45a049, #4caf50);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76,175,80,0.4);
        }
        
        .btn-update:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .success-message {
            background: rgba(76,175,80,0.2);
            border: 1px solid #4caf50;
            color: #4caf50;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .error-message {
            background: rgba(244,67,54,0.2);
            border: 1px solid #f44336;
            color: #f44336;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .warning-box {
            background: rgba(255,179,0,0.2);
            border: 1px solid #ffb300;
            color: #ffb300;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #4caf50;
            font-size: 1.2rem;
        }
        
        .results-table {
            margin-top: 20px;
        }
        
        .status-success {
            color: #4caf50;
            font-weight: bold;
        }
        
        .status-failed {
            color: #f44336;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title">تبدیل اعداد فارسی به انگلیسی</div>
            <div class="subtitle">بررسی و تبدیل اعداد فارسی در شماره‌های موبایل کاربران</div>
        </div>
        
        <?php if ($error_message): ?>
            <div class="error-message">
                <strong>خطا:</strong> <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($update_performed): ?>
            <div class="success-message">
                <strong>✅ عملیات به‌روزرسانی با موفقیت انجام شد!</strong>
                <br>تعداد <?php echo count(array_filter($update_results, function($r) { return $r['status'] === 'success'; })); ?> شماره موبایل به‌روزرسانی شد.
            </div>
            
            <div class="table-container results-table">
                <h3 style="color: #ffb300; margin-bottom: 16px; text-align: center;">نتایج به‌روزرسانی</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>نام کاربر</th>
                            <th>شماره اصلی</th>
                            <th>شماره تبدیل شده</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($update_results as $result): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($result['id']); ?></td>
                                <td><?php echo htmlspecialchars($result['name'] ?: 'نام ثبت نشده'); ?></td>
                                <td class="mobile-original"><?php echo htmlspecialchars($result['original']); ?></td>
                                <td class="mobile-converted"><?php echo htmlspecialchars($result['converted']); ?></td>
                                <td class="status-<?php echo $result['status']; ?>">
                                    <?php echo $result['status'] === 'success' ? '✅ موفق' : '❌ ناموفق'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="stats-card">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($subscribers_with_persian); ?></div>
                        <div class="stat-label">کاربران با اعداد فارسی</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">
                            <?php 
                            $stmt = $pdo->query("SELECT COUNT(*) FROM subscribers");
                            echo $stmt->fetchColumn();
                            ?>
                        </div>
                        <div class="stat-label">کل کاربران</div>
                    </div>
                </div>
            </div>
            
            <?php if (empty($subscribers_with_persian)): ?>
                <div class="table-container">
                    <div class="no-data">
                        ✅ همه شماره‌های موبایل از اعداد انگلیسی استفاده می‌کنند!<br>
                        هیچ تبدیلی لازم نیست.
                    </div>
                </div>
            <?php else: ?>
                <div class="warning-box">
                    <strong>⚠️ توجه:</strong> قبل از انجام به‌روزرسانی، حتماً از دیتابیس پشتیبان تهیه کنید!
                </div>
                
                <div class="table-container">
                    <h3 style="color: #ffb300; margin-bottom: 16px; text-align: center;">
                        کاربران با اعداد فارسی در شماره موبایل (<?php echo count($subscribers_with_persian); ?> کاربر)
                    </h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>شناسه</th>
                                <th>نام کاربر</th>
                                <th>شماره موبایل فعلی</th>
                                <th>شماره تبدیل شده</th>
                                <th>تغییرات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscribers_with_persian as $subscriber): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subscriber['id']); ?></td>
                                    <td><?php echo htmlspecialchars($subscriber['full_name'] ?: 'نام ثبت نشده'); ?></td>
                                    <td class="mobile-original"><?php echo htmlspecialchars($subscriber['mobile']); ?></td>
                                    <td class="mobile-converted"><?php echo htmlspecialchars($subscriber['converted_mobile']); ?></td>
                                    <td>
                                        <?php
                                        $changes = [];
                                        $original = $subscriber['mobile'];
                                        $converted = $subscriber['converted_mobile'];
                                        
                                        for ($i = 0; $i < strlen($original); $i++) {
                                            if (isset($converted[$i]) && $original[$i] !== $converted[$i]) {
                                                $changes[] = $original[$i] . ' → ' . $converted[$i];
                                            }
                                        }
                                        echo implode(', ', array_unique($changes));
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="update-section">
                    <h3 style="color: #ffb300; margin-bottom: 16px;">انجام تبدیل</h3>
                    <p style="margin-bottom: 20px; color: #e3f2fd;">
                        با کلیک بر دکمه زیر، تمام اعداد فارسی در شماره‌های موبایل به اعداد انگلیسی تبدیل خواهند شد.
                    </p>
                    <form method="POST" onsubmit="return confirmUpdate()">
                        <button type="submit" name="update_mobiles" class="btn-update">
                            🔄 تبدیل شماره‌های موبایل (<?php echo count($subscribers_with_persian); ?> کاربر)
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        function confirmUpdate() {
            return confirm(
                'آیا مطمئن هستید که می‌خواهید تمام اعداد فارسی را به انگلیسی تبدیل کنید؟\n\n' +
                'این عملیات قابل بازگشت نیست!\n\n' +
                'قبل از ادامه، مطمئن شوید که از دیتابیس پشتیبان تهیه کرده‌اید.'
            );
        }
        
        // Add loading animation when form is submitted
        document.querySelector('form')?.addEventListener('submit', function(e) {
            if (confirmUpdate()) {
                const button = this.querySelector('button[type="submit"]');
                button.disabled = true;
                button.innerHTML = '⏳ در حال پردازش...';
            } else {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>