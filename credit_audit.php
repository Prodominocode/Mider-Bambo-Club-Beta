<?php
// Credit Audit and Analytics Admin Page
header('Content-Type: text/html; charset=utf-8');
session_start();

try {
    require_once 'config.php';
    require_once 'branch_utils.php';
    require_once 'pending_credits_utils.php';
    require_once 'vcard_utils.php';
    require_once 'gift_credit_utils.php';
    
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

function get_admin_info($mobile){
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    return isset($allowed[$m]) ? $allowed[$m] : null;
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

$admin_info = get_admin_info($admin_mobile);
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

// Get credit audit data
function getCreditAuditData($pdo) {
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
        // If columns can't be added, we'll use queries without active filter
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
        
        -- Count and sum of active purchases (no_credit=0)
        COALESCE(p_count.purchase_count, 0) as active_purchase_count,
        COALESCE(p_sum.total_purchase_amount, 0) as total_purchase_amount,
        
        -- Count of active pending credits (not yet transferred)
        COALESCE(pc_count.pending_credit_count, 0) as active_pending_credit_count,
        
        -- Count of transferred pending credits  
        COALESCE(pc_transferred_count.transferred_credit_count, 0) as transferred_pending_credit_count,
        
        -- Total active gift credit amount in Toman
        COALESCE(gc_sum.total_gift_amount_toman, 0) as total_gift_amount_toman,
        
        -- Total active pending credit amount in Toman (not yet transferred)
        COALESCE(pc_sum.total_pending_amount_toman, 0) as total_pending_amount_toman,
        
        -- Total transferred pending credit amount in Toman
        COALESCE(pc_transferred_sum.total_transferred_amount_toman, 0) as total_transferred_amount_toman,
        
        -- C: Used Credit - Sum of credit usage amounts (convert to Toman) where active=1
        COALESCE(cu_sum.total_credit_usage_amount, 0) as used_credit_c_toman,
        
        -- D: Pending Credit - Sum of pending credit amounts (convert to Toman) where active=1 AND transferred=0
        COALESCE(pc_sum.total_pending_amount_toman, 0) as pending_credit_d_toman,
        
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
    
    -- Count active purchases (no_credit=0)
    LEFT JOIN (
        SELECT mobile, COUNT(*) as purchase_count
        FROM purchases 
        $purchases_where
        GROUP BY mobile
    ) p_count ON s.mobile COLLATE utf8mb4_unicode_ci = p_count.mobile COLLATE utf8mb4_unicode_ci
    
    -- Count active pending credits (not transferred)
    LEFT JOIN (
        SELECT mobile, COUNT(*) as pending_credit_count
        FROM pending_credits 
        WHERE active = 1 AND transferred = 0
        GROUP BY mobile
    ) pc_count ON s.mobile COLLATE utf8mb4_unicode_ci = pc_count.mobile COLLATE utf8mb4_unicode_ci
    
    -- Count transferred pending credits
    LEFT JOIN (
        SELECT mobile, COUNT(*) as transferred_credit_count
        FROM pending_credits 
        WHERE active = 1 AND transferred = 1
        GROUP BY mobile
    ) pc_transferred_count ON s.mobile COLLATE utf8mb4_unicode_ci = pc_transferred_count.mobile COLLATE utf8mb4_unicode_ci
    
    -- Sum active gift credit amounts (already in Toman)
    LEFT JOIN (
        SELECT mobile, SUM(gift_amount_toman) as total_gift_amount_toman
        FROM gift_credits 
        WHERE active = 1
        GROUP BY mobile
    ) gc_sum ON s.mobile COLLATE utf8mb4_unicode_ci = gc_sum.mobile COLLATE utf8mb4_unicode_ci
    
    -- Sum active pending credit amounts (convert to Toman, not transferred)
    LEFT JOIN (
        SELECT mobile, SUM(credit_amount * 5000) as total_pending_amount_toman
        FROM pending_credits 
        WHERE active = 1 AND transferred = 0
        GROUP BY mobile
    ) pc_sum ON s.mobile COLLATE utf8mb4_unicode_ci = pc_sum.mobile COLLATE utf8mb4_unicode_ci
    
    -- Sum transferred pending credit amounts (convert to Toman)
    LEFT JOIN (
        SELECT mobile, SUM(credit_amount * 5000) as total_transferred_amount_toman
        FROM pending_credits 
        WHERE active = 1 AND transferred = 1
        GROUP BY mobile
    ) pc_transferred_sum ON s.mobile COLLATE utf8mb4_unicode_ci = pc_transferred_sum.mobile COLLATE utf8mb4_unicode_ci
    
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
    
    ORDER BY s.id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $audit_data = getCreditAuditData($pdo);
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بررسی اعتبارات - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .audit-container {
            background: rgba(34,36,38,0.97);
            border-radius: 16px;
            padding: 24px;
            margin: 20px auto;
            max-width: 95%;
            box-shadow: 0 8px 32px rgba(0,0,0,0.25);
            color: #fff;
        }
        
        .audit-header {
            text-align: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .audit-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffb300;
            margin-bottom: 8px;
        }
        
        .audit-subtitle {
            font-size: 1rem;
            color: #b0b3b8;
        }
        
        .controls {
            margin-bottom: 20px;
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 200px;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            background: #23272a;
            color: #fff;
            font-size: 0.9rem;
        }
        
        .btn-small {
            padding: 8px 16px;
            background: #ffb300;
            color: #181a1b;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-small:hover {
            background: #ffd54f;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 16px;
        }
        
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        
        .audit-table th {
            background: #23272a;
            color: #ffb300;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.1);
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .audit-table th:hover {
            background: #2c3136;
        }
        
        .audit-table td {
            padding: 10px 8px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.02);
        }
        
        .audit-table tr.mismatch {
            background: rgba(244, 67, 54, 0.1);
        }
        
        .audit-table tr.mismatch td {
            background: rgba(244, 67, 54, 0.1);
            border-color: rgba(244, 67, 54, 0.3);
        }
        
        .mismatch-indicator {
            color: #ff5252;
            font-weight: bold;
        }
        
        .match-indicator {
            color: #4caf50;
            font-weight: bold;
        }
        
        .number-cell {
            font-family: 'Courier New', monospace;
            direction: ltr;
            text-align: right;
        }
        
        .stats-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: #23272a;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            flex: 1;
            min-width: 150px;
        }
        
        .stat-number {
            font-size: 1.4rem;
            font-weight: bold;
            color: #ffb300;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: #b0b3b8;
            font-size: 0.9rem;
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
    </style>
</head>
<body>
    <div class="overlay"></div>
    <div class="centered-container">
        <div class="audit-container">
            <div class="audit-header">
                <div class="audit-title">بررسی و تحلیل اعتبارات</div>
                <div class="audit-subtitle">نمایش کامل وضعیت اعتبارات کاربران شامل اعتبار اصلی، اعتبار هدیه و اعتبارات در انتظار</div>
            </div>
            
            <?php
            $total_users = count($audit_data);
            $issues_count = 0;
            $total_recorded_credit = 0;
            $total_calculated_credit = 0;
            $total_purchase_amount = 0;
            $total_gift_credits = 0;
            $total_used_credits = 0;
            $total_pending_credits = 0;
            
            foreach ($audit_data as $row) {
                $total_recorded_credit += $row['recorded_credit_toman'];
                $total_calculated_credit += $row['calculated_credit_toman'];
                $total_purchase_amount += $row['total_purchase_amount'];
                $total_gift_credits += $row['total_gift_amount_toman'];
                $total_used_credits += $row['used_credit_c_toman'];
                $total_pending_credits += $row['pending_credit_d_toman'];
                
                // PRIMARY CHECK ONLY: A (registered) vs D (calculated) mismatch with proper numeric comparison
                $recorded_credit_numeric = (float)$row['recorded_credit_toman'];
                $calculated_credit_numeric = (float)$row['calculated_credit_toman'];
                $tolerance = 1.0; // Increased tolerance to handle rounding differences
                
                if (abs($recorded_credit_numeric - $calculated_credit_numeric) > $tolerance) {
                    $issues_count++;
                }
            }
            ?>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($total_users); ?></div>
                    <div class="stat-label">کل کاربران</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($issues_count); ?></div>
                    <div class="stat-label">موارد نیاز به بررسی</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_recorded_credit); ?></div>
                    <div class="stat-label">کل اعتبار اصلی</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_calculated_credit); ?></div>
                    <div class="stat-label">کل اعتبار محاسبه شده</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_purchase_amount); ?></div>
                    <div class="stat-label">کل خریدها (A)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_gift_credits); ?></div>
                    <div class="stat-label">کل اعتبار هدیه (B)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_used_credits); ?></div>
                    <div class="stat-label">کل مصرف شده (C)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo format_toman($total_pending_credits); ?></div>
                    <div class="stat-label">کل در انتظار (D)</div>
                </div>
            </div>
            
            <?php
            // Detailed verification for user 09119246366
            $verification_mobile = '09119246366';
            $verification_data = null;
            foreach ($audit_data as $row) {
                if ($row['mobile'] === $verification_mobile) {
                    $verification_data = $row;
                    break;
                }
            }
            
            if ($verification_data): ?>
                <div style="background: #1a1d23; border: 2px solid #ffb300; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                    <h3 style="color: #ffb300; text-align: center; margin-bottom: 16px;">تأیید دقت محاسبات برای کاربر <?php echo $verification_mobile; ?></h3>
                    
                    <div style="background: #2c3136; padding: 16px; border-radius: 8px; text-align: center; margin-bottom: 16px;">
                        <div style="color: #ffb300; font-weight: bold; margin-bottom: 8px;">مقایسه اصلی: A (اعتبار ثبت شده) vs D (محاسبه شده از دیتابیس)</div>
                        <div style="display: flex; justify-content: space-around; align-items: center;">
                            <div style="color: #fff; font-family: monospace; font-size: 1.1rem;">
                                A = <?php echo format_toman($verification_data['recorded_credit_toman']); ?>
                            </div>
                            <div style="color: #ffb300; font-size: 1.2rem;">vs</div>
                            <div style="color: #fff; font-family: monospace; font-size: 1.1rem;">
                                D = <?php echo format_toman($verification_data['calculated_credit_toman']); ?>
                            </div>
                        </div>
                        <?php 
                        // Use proper numeric comparison for verification with same tolerance
                        $recorded_numeric = (float)$verification_data['recorded_credit_toman'];
                        $calculated_numeric = (float)$verification_data['calculated_credit_toman'];
                        $tolerance = 1.0; // Same tolerance as main comparison
                        $verification_mismatch = (abs($recorded_numeric - $calculated_numeric) > $tolerance);
                        ?>
                        <div style="color: <?php echo $verification_mismatch ? '#ff5252' : '#4caf50'; ?>; font-weight: bold; font-size: 1.2rem; margin-top: 8px;">
                            <?php echo $verification_mismatch ? '❌ عدم تطابق!' : '✅ تطابق دارد'; ?>
                        </div>
                        <?php if ($verification_mismatch): ?>
                            <div style="color: #ff5252; font-size: 0.9rem; margin-top: 4px;">
                                تفاوت: <?php echo format_toman(abs($recorded_numeric - $calculated_numeric)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 16px;">
                        <div style="background: #23272a; padding: 12px; border-radius: 8px;">
                            <div style="color: #4caf50; font-weight: bold; margin-bottom: 4px;">A - خریدهای فعال (فرمول جدید)</div>
                            <div style="color: #fff; font-family: monospace;"><?php echo format_toman($verification_data['total_purchase_amount'] / 100000 * 5000); ?></div>
                            <div style="color: #b0b3b8; font-size: 0.8rem;">
                                (<?php echo format_toman($verification_data['total_purchase_amount']); ?> ÷ 100,000) × 5,000
                            </div>
                            <div style="color: #b0b3b8; font-size: 0.8rem;">تعداد: <?php echo $verification_data['active_purchase_count']; ?></div>
                        </div>
                        
                        <div style="background: #23272a; padding: 12px; border-radius: 8px;">
                            <div style="color: #4caf50; font-weight: bold; margin-bottom: 4px;">B - اعتبار هدیه فعال</div>
                            <div style="color: #fff; font-family: monospace;"><?php echo format_toman($verification_data['total_gift_amount_toman']); ?></div>
                            <div style="color: #b0b3b8; font-size: 0.8rem;">از جدول gift_credits</div>
                        </div>
                        
                        <div style="background: #23272a; padding: 12px; border-radius: 8px;">
                            <div style="color: #ff5252; font-weight: bold; margin-bottom: 4px;">C - اعتبار مصرف شده</div>
                            <div style="color: #fff; font-family: monospace;"><?php echo format_toman($verification_data['used_credit_c_toman']); ?></div>
                            <div style="color: #b0b3b8; font-size: 0.8rem;">از جدول credit_usage</div>
                        </div>
                        
                        <div style="background: #23272a; padding: 12px; border-radius: 8px;">
                            <div style="color: #ffb300; font-weight: bold; margin-bottom: 4px;">D - اعتبار در انتظار</div>
                            <div style="color: #fff; font-family: monospace;"><?php echo format_toman($verification_data['pending_credit_d_toman']); ?></div>
                            <div style="color: #b0b3b8; font-size: 0.8rem;">تعداد: <?php echo $verification_data['active_pending_credit_count']; ?></div>
                        </div>
                    </div>
                    
                    <div style="background: #2c3136; padding: 16px; border-radius: 8px; text-align: center;">
                        <div style="color: #ffb300; font-weight: bold; margin-bottom: 8px;">فرمول نهایی: A + B - C - D</div>
                        <div style="color: #fff; font-family: monospace; font-size: 1.1rem;">
                            <?php echo format_toman($verification_data['total_purchase_amount'] / 100000 * 5000); ?> + 
                            <?php echo format_toman($verification_data['total_gift_amount_toman']); ?> - 
                            <?php echo format_toman($verification_data['used_credit_c_toman']); ?> - 
                            <?php echo format_toman($verification_data['pending_credit_d_toman']); ?>
                        </div>
                        <div style="color: #4caf50; font-weight: bold; font-size: 1.2rem; margin-top: 8px;">
                            = <?php echo format_toman($verification_data['calculated_credit_toman']); ?>
                        </div>
                        <div style="color: #b0b3b8; font-size: 0.9rem; margin-top: 8px;">
                            اعتبار ثبت شده در سیستم: <?php echo format_toman($verification_data['recorded_credit_toman']); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="controls">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="جستجو بر اساس نام، موبایل یا شناسه...">
                </div>
                <button class="btn-small" onclick="showOnlyIssues()">فقط موارد نیاز به بررسی</button>
                <button class="btn-small" onclick="showAll()">نمایش همه</button>
                <button class="back-btn" onclick="goBack()">بازگشت به پنل ادمین</button>
            </div>
            
            <div class="table-container">
                <table class="audit-table" id="auditTable">
                    <thead>
                        <tr>
                            <th onclick="sortTable(0)">شناسه کاربر</th>
                            <th onclick="sortTable(1)">نام / موبایل</th>
                            <th onclick="sortTable(2)">اعتبار اصلی ثبت شده</th>
                            <th onclick="sortTable(3)">خریدهای فعال</th>
                            <th onclick="sortTable(4)">اعتبار در انتظار (عدد)</th>
                            <th onclick="sortTable(5)">اعتبار منتقل شده (عدد)</th>
                            <th onclick="sortTable(6)">کل مبلغ اعتبار هدیه</th>
                            <th onclick="sortTable(7)">اعتبار مصرف شده (C)</th>
                            <th onclick="sortTable(8)">اعتبار در انتظار (D)</th>
                            <th onclick="sortTable(9)">محاسبه شده از دیتابیس</th>
                            <th onclick="sortTable(10)">وضعیت تطابق</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_data as $row): ?>
                            <?php 
                            // PRIMARY CHECK: Compare registered main credit (A) with calculated credit (D)
                            // Ensure proper numeric comparison by converting to float and using tolerance
                            $recorded_credit_numeric = (float)$row['recorded_credit_toman'];
                            $calculated_credit_numeric = (float)$row['calculated_credit_toman'];
                            $tolerance = 1.0; // Increased tolerance to handle rounding differences
                            $credit_mismatch = (abs($recorded_credit_numeric - $calculated_credit_numeric) > $tolerance);
                            
                            // Only flag as having issues if there's a credit mismatch
                            $has_issues = $credit_mismatch;
                            $issue_description = '';
                            
                            if ($credit_mismatch) {
                                $issue_description = 'عدم تطابق اعتبار اصلی با محاسبه شده';
                            }
                            
                            // Optional: Add secondary checks as warnings but don't mark as issues
                            $secondary_warnings = [];
                            if ($row['active_pending_credit_count'] > 0 && $row['total_pending_amount_toman'] > 10000) {
                                $secondary_warnings[] = 'اعتبار در انتظار زیاد';
                            }
                            if ($row['total_gift_amount_toman'] > $recorded_credit_numeric * 2) {
                                $secondary_warnings[] = 'اعتبار هدیه بیش از حد';
                            }
                            
                            $display_name = !empty($row['full_name']) ? $row['full_name'] : 'نام ثبت نشده';
                            ?>
                            <tr class="<?php echo $has_issues ? 'mismatch' : ''; ?>">
                                <td class="number-cell"><?php echo htmlspecialchars($row['user_id']); ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars($display_name); ?></div>
                                    <div style="font-size: 0.8rem; color: #b0b3b8; margin-top: 2px;">
                                        <?php echo htmlspecialchars($row['mobile']); ?>
                                    </div>
                                </td>
                                <td class="number-cell">
                                    <div style="color: <?php echo $credit_mismatch ? '#ff5252' : '#fff'; ?>;">
                                        <?php echo format_toman($row['recorded_credit_toman']); ?>
                                    </div>
                                    <?php if ($credit_mismatch): ?>
                                        <div style="font-size: 0.7rem; color: #ff5252; margin-top: 2px;">
                                            ⚠️ عدم تطابق
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="number-cell">
                                    <div><?php echo number_format($row['active_purchase_count']); ?> عدد</div>
                                    <div style="font-size: 0.7rem; color: #ffb300; margin-top: 2px;">
                                        <?php echo format_toman($row['total_purchase_amount']); ?>
                                    </div>
                                </td>
                                <td class="number-cell">
                                    <?php echo number_format($row['active_pending_credit_count']); ?>
                                    <?php if ($row['total_pending_amount_toman'] > 0): ?>
                                        <div style="font-size: 0.7rem; color: #ffb300; margin-top: 2px;">
                                            <?php echo format_toman($row['total_pending_amount_toman']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="number-cell">
                                    <?php echo number_format($row['transferred_pending_credit_count']); ?>
                                    <?php if ($row['total_transferred_amount_toman'] > 0): ?>
                                        <div style="font-size: 0.7rem; color: #4caf50; margin-top: 2px;">
                                            <?php echo format_toman($row['total_transferred_amount_toman']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="number-cell"><?php echo format_toman($row['total_gift_amount_toman']); ?></td>
                                <td class="number-cell">
                                    <strong style="color: #ff5252;"><?php echo format_toman($row['used_credit_c_toman']); ?></strong>
                                    <div style="font-size: 0.7rem; color: #b0b3b8; margin-top: 2px;">مصرف شده</div>
                                </td>
                                <td class="number-cell">
                                    <strong style="color: #ffb300;"><?php echo format_toman($row['pending_credit_d_toman']); ?></strong>
                                    <div style="font-size: 0.7rem; color: #b0b3b8; margin-top: 2px;">در انتظار</div>
                                </td>
                                <td class="number-cell">
                                    <strong style="color: <?php echo $credit_mismatch ? '#ff5252' : '#4caf50'; ?>;">
                                        <?php echo format_toman($row['calculated_credit_toman']); ?>
                                    </strong>
                                    <div style="font-size: 0.7rem; color: #b0b3b8; margin-top: 2px;">
                                        A+B-C-D فرمول
                                    </div>
                                    <?php if ($credit_mismatch): ?>
                                        <div style="font-size: 0.7rem; color: #ff5252; margin-top: 2px;">
                                            تفاوت: <?php echo format_toman(abs($recorded_credit_numeric - $calculated_credit_numeric)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($has_issues): ?>
                                        <span class="mismatch-indicator">⚠️ نیاز به بررسی</span>
                                        <div style="font-size: 0.7rem; margin-top: 2px; color: #ff5252;">
                                            <?php echo htmlspecialchars($issue_description); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="match-indicator">✅ عادی</span>
                                        <?php if (!empty($secondary_warnings)): ?>
                                            <div style="font-size: 0.7rem; margin-top: 2px; color: #ffb300;">
                                                💡 <?php echo implode(', ', $secondary_warnings); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const table = document.getElementById('auditTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                // Search in user ID, name, and mobile columns
                for (let j = 0; j < 2; j++) {
                    if (cells[j] && cells[j].textContent.toLowerCase().includes(searchValue)) {
                        found = true;
                        break;
                    }
                }
                
                row.style.display = found ? '' : 'none';
            }
        });
        
        // Show only issues
        function showOnlyIssues() {
            const table = document.getElementById('auditTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                if (row.classList.contains('mismatch')) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        }
        
        // Show all rows
        function showAll() {
            const table = document.getElementById('auditTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = '';
            }
        }
        
        // Simple table sorting
        function sortTable(columnIndex) {
            const table = document.getElementById('auditTable');
            const tbody = table.getElementsByTagName('tbody')[0];
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            rows.sort((a, b) => {
                const aText = a.getElementsByTagName('td')[columnIndex].textContent.trim();
                const bText = b.getElementsByTagName('td')[columnIndex].textContent.trim();
                
                // Try to parse as numbers for numeric columns
                const aNum = parseFloat(aText.replace(/[^\d.-]/g, ''));
                const bNum = parseFloat(bText.replace(/[^\d.-]/g, ''));
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return aNum - bNum;
                } else {
                    return aText.localeCompare(bText, 'fa');
                }
            });
            
            // Re-append sorted rows
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Go back to admin panel
        function goBack() {
            window.location.href = 'admin.php';
        }
    </script>
</body>
</html>