<?php
// Credit Update Tool - Standalone script to update subscriber credits based on calculated values
header('Content-Type: text/html; charset=utf-8');
session_start();

try {
    require_once 'config.php';
    require_once 'db.php';
    
    // Check if db.php outputted an error
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

function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

// Helper to check allowed admin numbers
function is_admin_allowed($mobile){
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return false;
    return array_key_exists($m, $allowed);
}

function get_admin_role($mobile){
    if (!$mobile) return '';
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return '';
    return isset($allowed[$m]) && isset($allowed[$m]['role']) ? $allowed[$m]['role'] : '';
}

// Check session and admin access
$is_admin = !empty($_SESSION['admin_mobile']);
if (!$is_admin) {
    header('Location: admin.php');
    exit;
}

$admin_mobile = $_SESSION['admin_mobile'];
if (!is_admin_allowed($admin_mobile)) {
    header('Location: admin.php');
    exit;
}

$admin_role = get_admin_role($admin_mobile);
$is_manager = ($admin_role === 'manager');

if (!$is_manager) {
    header('Location: admin.php');
    exit;
}

// Function to format numbers as Toman
function format_toman($amount) {
    return number_format($amount, 0, '.', ',') . ' تومان';
}

// Get credit audit data (same logic as credit_audit.php)
function getCreditUpdateData($pdo) {
    // First, ensure the active columns exist
    try {
        // Check and add active column to purchases if not exists
        $stmt = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'active'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE purchases ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
        }
        
        // Check and add active column to credit_usage if not exists
        $stmt = $pdo->query("SHOW COLUMNS FROM credit_usage LIKE 'active'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE credit_usage ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (Exception $e) {
        error_log("Warning: Could not add active columns: " . $e->getMessage());
    }
    
    // Check if active columns exist for conditional queries
    $stmt = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'active'");
    $purchases_has_active = ($stmt->rowCount() > 0);
    
    $stmt = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'no_credit'");
    $purchases_has_no_credit = ($stmt->rowCount() > 0);
    
    $stmt = $pdo->query("SHOW COLUMNS FROM credit_usage LIKE 'active'");
    $credit_usage_has_active = ($stmt->rowCount() > 0);
    
    $purchases_where = "";
    if ($purchases_has_active && $purchases_has_no_credit) {
        $purchases_where = "WHERE active = 1 AND no_credit = 0";
    } else if ($purchases_has_active) {
        $purchases_where = "WHERE active = 1";
    } else if ($purchases_has_no_credit) {
        $purchases_where = "WHERE no_credit = 0";
    }
    
    $credit_usage_where = $credit_usage_has_active ? "WHERE active = 1" : "";
    
    $sql = "
    SELECT 
        s.id as user_id,
        s.full_name,
        s.mobile,
        s.credit as recorded_credit,
        
        -- Calculated credit from database
        -- A: (sum of amount from purchases where active=1 AND no_credit=0) / 100000 * 5000
        -- B: sum of credit_amount * 5000 from gift_credits where active=1  
        -- C: sum of credit_value * 5000 from credit_usage where active=1
        -- D: sum of credit_amount * 5000 from pending_credits where active=1 AND transferred=0
        -- Formula: A + B - C - D
        (
            COALESCE((p_sum.total_purchase_amount / 100000 * 5000), 0) + 
            COALESCE(gc_credit_sum.total_gift_credit_amount, 0) - 
            COALESCE(cu_sum.total_credit_usage_amount, 0) - 
            COALESCE(pc_sum.total_pending_amount_toman, 0)
        ) as calculated_credit_toman,
         
        -- Recorded credit in Toman for comparison (main credit only)
        (s.credit * 5000) as recorded_credit_toman
        
    FROM subscribers s
    
    -- A: Sum of purchase amounts where active=1 AND no_credit=0
    LEFT JOIN (
        SELECT mobile, SUM(amount) as total_purchase_amount
        FROM purchases 
        $purchases_where
        GROUP BY mobile
    ) p_sum ON s.mobile COLLATE utf8mb4_unicode_ci = p_sum.mobile COLLATE utf8mb4_unicode_ci
    
    -- B: Sum of gift credit amounts (convert to Toman) where active=1
    LEFT JOIN (
        SELECT mobile, SUM(credit_amount * 5000) as total_gift_credit_amount
        FROM gift_credits 
        WHERE active = 1
        GROUP BY mobile
    ) gc_credit_sum ON s.mobile COLLATE utf8mb4_unicode_ci = gc_credit_sum.mobile COLLATE utf8mb4_unicode_ci
    
    -- C: Sum of credit usage amounts (convert to Toman) where active=1
    LEFT JOIN (
        SELECT user_mobile as mobile, SUM(credit_value * 5000) as total_credit_usage_amount
        FROM credit_usage 
        $credit_usage_where
        GROUP BY user_mobile
    ) cu_sum ON s.mobile COLLATE utf8mb4_unicode_ci = cu_sum.mobile COLLATE utf8mb4_unicode_ci
    
    -- D: Sum of pending credit amounts (convert to Toman) where active=1 AND transferred=0
    LEFT JOIN (
        SELECT mobile, SUM(credit_amount * 5000) as total_pending_amount_toman
        FROM pending_credits 
        WHERE active = 1 AND transferred = 0
        GROUP BY mobile
    ) pc_sum ON s.mobile COLLATE utf8mb4_unicode_ci = pc_sum.mobile COLLATE utf8mb4_unicode_ci
    
    ORDER BY s.id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle update request
$update_result = null;
if ($_POST['action'] === 'update_credits' && $_POST['confirm'] === 'yes') {
    try {
        $pdo->beginTransaction();
        
        $all_data = getCreditUpdateData($pdo);
        $updated_count = 0;
        $update_log = [];
        
        foreach ($all_data as $row) {
            $recorded_credit_numeric = (float)$row['recorded_credit_toman'];
            $calculated_credit_numeric = (float)$row['calculated_credit_toman'];
            $tolerance = 1.0;
            
            // Check all conditions:
            // 1. D != A (calculated != recorded)
            // 2. D >= 0 (not negative)
            // 3. Mobile doesn't start with 111
            $credit_mismatch = (abs($recorded_credit_numeric - $calculated_credit_numeric) > $tolerance);
            $is_positive = ($calculated_credit_numeric >= 0);
            $not_virtual_card = !str_starts_with($row['mobile'], '111');
            
            if ($credit_mismatch && $is_positive && $not_virtual_card) {
                // Convert calculated credit back to credit units (divide by 5000)
                $new_credit = $calculated_credit_numeric / 5000;
                
                // Update the subscriber's credit
                $stmt = $pdo->prepare("UPDATE subscribers SET credit = ? WHERE mobile = ?");
                $stmt->execute([$new_credit, $row['mobile']]);
                
                if ($stmt->rowCount() > 0) {
                    $updated_count++;
                    $update_log[] = [
                        'mobile' => $row['mobile'],
                        'old_credit' => $row['recorded_credit'],
                        'new_credit' => $new_credit,
                        'old_toman' => $recorded_credit_numeric,
                        'new_toman' => $calculated_credit_numeric
                    ];
                }
            }
        }
        
        $pdo->commit();
        $update_result = [
            'success' => true,
            'count' => $updated_count,
            'log' => $update_log
        ];
        
    } catch (Exception $e) {
        $pdo->rollback();
        $update_result = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Get data for display
try {
    $audit_data = getCreditUpdateData($pdo);
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

// Filter data for eligible updates
$eligible_updates = [];
foreach ($audit_data as $row) {
    $recorded_credit_numeric = (float)$row['recorded_credit_toman'];
    $calculated_credit_numeric = (float)$row['calculated_credit_toman'];
    $tolerance = 1.0;
    
    // Check all conditions
    $credit_mismatch = (abs($recorded_credit_numeric - $calculated_credit_numeric) > $tolerance);
    $is_positive = ($calculated_credit_numeric >= 0);
    $not_virtual_card = !str_starts_with($row['mobile'], '111');
    
    if ($credit_mismatch && $is_positive && $not_virtual_card) {
        $eligible_updates[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بروزرسانی اعتبارات - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .update-container {
            background: rgba(34,36,38,0.97);
            border-radius: 16px;
            padding: 24px;
            margin: 20px auto;
            max-width: 95%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.25);
            color: #fff;
        }
        
        .update-header {
            text-align: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .update-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffb300;
            margin-bottom: 8px;
        }
        
        .update-subtitle {
            font-size: 1rem;
            color: #b0b3b8;
        }
        
        .conditions-box {
            background: #1a1d23;
            border: 2px solid #ffb300;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .conditions-title {
            color: #ffb300;
            font-weight: bold;
            margin-bottom: 12px;
        }
        
        .condition-item {
            color: #fff;
            margin-bottom: 8px;
            padding-right: 16px;
        }
        
        .stats-box {
            background: #23272a;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #ffb300;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: #b0b3b8;
            font-size: 1rem;
        }
        
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        
        .update-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        .update-table th {
            background: #23272a;
            color: #ffb300;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .update-table td {
            padding: 10px 8px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.02);
        }
        
        .number-cell {
            font-family: 'Courier New', monospace;
            direction: ltr;
            text-align: right;
        }
        
        .update-form {
            background: #2c3136;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .btn-update {
            background: #ff5722;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-update:hover {
            background: #e64a19;
        }
        
        .btn-update:disabled {
            background: #666;
            cursor: not-allowed;
        }
        
        .back-btn {
            background: transparent;
            color: #ff8a65;
            border: 1px solid rgba(255,138,101,0.3);
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.9rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .back-btn:hover {
            background: rgba(255,138,101,0.1);
        }
        
        .result-box {
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .result-success {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid #4caf50;
            color: #4caf50;
        }
        
        .result-error {
            background: rgba(244, 67, 54, 0.1);
            border: 1px solid #f44336;
            color: #f44336;
        }
        
        .difference-positive {
            color: #4caf50;
        }
        
        .difference-negative {
            color: #ff5252;
        }
    </style>
</head>
<body>
    <div class="overlay"></div>
    <div class="centered-container">
        <div class="update-container">
            <div class="update-header">
                <div class="update-title">بروزرسانی اعتبارات بر اساس محاسبات دیتابیس</div>
                <div class="update-subtitle">این ابزار اعتبارات ثبت شده کاربران را بر اساس محاسبات دقیق از دیتابیس بروزرسانی می‌کند</div>
            </div>
            
            <?php if ($update_result): ?>
                <?php if ($update_result['success']): ?>
                    <div class="result-box result-success">
                        <h3>✅ بروزرسانی با موفقیت انجام شد</h3>
                        <p><?php echo $update_result['count']; ?> کاربر بروزرسانی شد.</p>
                        <?php if (!empty($update_result['log'])): ?>
                            <details>
                                <summary>جزئیات تغییرات</summary>
                                <ul>
                                    <?php foreach ($update_result['log'] as $log): ?>
                                        <li>
                                            <?php echo htmlspecialchars($log['mobile']); ?>: 
                                            <?php echo $log['old_credit']; ?> → <?php echo $log['new_credit']; ?> 
                                            (<?php echo format_toman($log['old_toman']); ?> → <?php echo format_toman($log['new_toman']); ?>)
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="result-box result-error">
                        <h3>❌ خطا در بروزرسانی</h3>
                        <p><?php echo htmlspecialchars($update_result['error']); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="conditions-box">
                <div class="conditions-title">شرایط بروزرسانی:</div>
                <div class="condition-item">✓ اعتبار محاسبه شده (D) با اعتبار ثبت شده (A) متفاوت باشد</div>
                <div class="condition-item">✓ اعتبار محاسبه شده منفی نباشد</div>
                <div class="condition-item">✓ شماره موبایل با 111 شروع نشود (کارت‌های مجازی حذف)</div>
            </div>
            
            <div class="stats-box">
                <div class="stat-number"><?php echo count($eligible_updates); ?></div>
                <div class="stat-label">کاربر واجد شرایط بروزرسانی</div>
            </div>
            
            <?php if (count($eligible_updates) > 0): ?>
                <div class="table-container">
                    <h3 style="color: #ffb300; margin-bottom: 16px;">کاربران واجد شرایط:</h3>
                    <table class="update-table">
                        <thead>
                            <tr>
                                <th>شماره موبایل</th>
                                <th>نام</th>
                                <th>اعتبار فعلی (A)</th>
                                <th>اعتبار محاسبه شده (D)</th>
                                <th>تفاوت</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eligible_updates as $row): ?>
                                <?php 
                                $recorded_toman = (float)$row['recorded_credit_toman'];
                                $calculated_toman = (float)$row['calculated_credit_toman'];
                                $difference = $calculated_toman - $recorded_toman;
                                $display_name = !empty($row['full_name']) ? $row['full_name'] : 'نام ثبت نشده';
                                ?>
                                <tr>
                                    <td class="number-cell"><?php echo htmlspecialchars($row['mobile']); ?></td>
                                    <td><?php echo htmlspecialchars($display_name); ?></td>
                                    <td class="number-cell"><?php echo format_toman($recorded_toman); ?></td>
                                    <td class="number-cell"><?php echo format_toman($calculated_toman); ?></td>
                                    <td class="number-cell">
                                        <span class="<?php echo $difference >= 0 ? 'difference-positive' : 'difference-negative'; ?>">
                                            <?php echo format_toman(abs($difference)); ?>
                                            <?php echo $difference >= 0 ? '↑' : '↓'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="update-form">
                    <h3 style="color: #ff5722; margin-bottom: 16px;">⚠️ هشدار</h3>
                    <p style="margin-bottom: 16px; color: #b0b3b8;">
                        این عملیات اعتبارات <?php echo count($eligible_updates); ?> کاربر را به‌طور دائمی تغییر خواهد داد.
                        لطفاً قبل از ادامه از صحت اطلاعات اطمینان حاصل کنید.
                    </p>
                    
                    <form method="POST" onsubmit="return confirm('آیا از بروزرسانی اعتبارات اطمینان دارید؟ این عملیات قابل بازگشت نیست.');">
                        <input type="hidden" name="action" value="update_credits">
                        <input type="hidden" name="confirm" value="yes">
                        <button type="submit" class="btn-update">
                            🔄 بروزرسانی اعتبارات (<?php echo count($eligible_updates); ?> کاربر)
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: #4caf50; padding: 20px;">
                    <h3>✅ همه اعتبارات به‌روز هستند</h3>
                    <p>هیچ کاربری نیاز به بروزرسانی اعتبار ندارد.</p>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center;">
                <button class="back-btn" onclick="goBack()">بازگشت به پنل ادمین</button>
            </div>
        </div>
    </div>

    <script>
        function goBack() {
            window.location.href = 'admin.php';
        }
    </script>
</body>
</html>