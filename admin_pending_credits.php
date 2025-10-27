<?php
/**
 * Administrative Script for Manual Pending Credits Management
 * 
 * This script provides manual controls for pending credits management.
 * Use with caution - always backup database before manual operations.
 */

require_once 'db.php';
require_once 'pending_credits_utils.php';

// Command line interface
if (php_sapi_name() === 'cli') {
    $action = $argv[1] ?? '';
    $param = $argv[2] ?? '';
    
    switch ($action) {
        case 'process':
            if ($param) {
                echo "Processing pending credits for subscriber ID: $param\n";
                $result = process_pending_credits($pdo, (int)$param);
            } else {
                echo "Processing all pending credits...\n";
                $result = process_pending_credits($pdo);
            }
            
            if ($result['success']) {
                echo "Success! Transferred {$result['transferred_count']} credits\n";
                echo "Total amount: {$result['transferred_amount']} points\n";
            } else {
                echo "Error: {$result['error']}\n";
            }
            break;
            
        case 'status':
            echo "Pending Credits Status Report\n";
            echo "============================\n";
            
            // Total pending
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(credit_amount), 0) as total
                FROM pending_credits WHERE transferred = 0
            ");
            $stmt->execute();
            $pending = $stmt->fetch();
            echo "Pending Credits: {$pending['count']} ({$pending['total']} points)\n";
            
            // Ready to transfer
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(credit_amount), 0) as total
                FROM pending_credits 
                WHERE transferred = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR)
            ");
            $stmt->execute();
            $ready = $stmt->fetch();
            echo "Ready to Transfer: {$ready['count']} ({$ready['total']} points)\n";
            
            // Transferred today
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(credit_amount), 0) as total
                FROM pending_credits 
                WHERE transferred = 1 AND DATE(transferred_at) = CURDATE()
            ");
            $stmt->execute();
            $today = $stmt->fetch();
            echo "Transferred Today: {$today['count']} ({$today['total']} points)\n";
            break;
            
        case 'list':
            $limit = $param ? (int)$param : 10;
            echo "Recent Pending Credits (Limit: $limit)\n";
            echo "=====================================\n";
            
            $stmt = $pdo->prepare("
                SELECT pc.id, pc.subscriber_id, pc.mobile, pc.credit_amount, pc.created_at, pc.transferred,
                       s.full_name
                FROM pending_credits pc
                LEFT JOIN subscribers s ON pc.subscriber_id = s.id
                ORDER BY pc.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            while ($row = $stmt->fetch()) {
                $status = $row['transferred'] ? 'Transferred' : 'Pending';
                echo "ID: {$row['id']}, User: {$row['full_name']} ({$row['mobile']}), ";
                echo "Amount: {$row['credit_amount']} points, ";
                echo "Date: {$row['created_at']}, Status: $status\n";
            }
            break;
            
        case 'help':
        default:
            echo "Pending Credits Management Tool\n";
            echo "===============================\n";
            echo "Usage: php admin_pending_credits.php <action> [parameter]\n\n";
            echo "Actions:\n";
            echo "  status           - Show pending credits status\n";
            echo "  list [limit]     - List recent pending credits (default: 10)\n";
            echo "  process [user_id] - Process pending credits (all or specific user)\n";
            echo "  help             - Show this help message\n\n";
            echo "Examples:\n";
            echo "  php admin_pending_credits.php status\n";
            echo "  php admin_pending_credits.php list 20\n";
            echo "  php admin_pending_credits.php process\n";
            echo "  php admin_pending_credits.php process 123\n";
            break;
    }
    exit;
}

// Web interface (basic)
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت اعتبارات در انتظار</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .card { background: #f9f9f9; padding: 15px; margin: 10px 0; border-radius: 6px; border-left: 4px solid #007cba; }
        .button { background: #007cba; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .button:hover { background: #005a8b; }
        .button.danger { background: #d32f2f; }
        .button.danger:hover { background: #b71c1c; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
        th { background: #f0f0f0; }
        .status-pending { color: #ff9800; font-weight: bold; }
        .status-transferred { color: #4caf50; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>مدیریت اعتبارات در انتظار</h1>
        
        <?php
        $action = $_GET['action'] ?? '';
        
        if ($action === 'process') {
            $user_id = $_GET['user_id'] ?? null;
            $result = $user_id ? process_pending_credits($pdo, (int)$user_id) : process_pending_credits($pdo);
            
            if ($result['success']) {
                echo "<div class='card' style='border-left-color: #4caf50;'>";
                echo "<h3>پردازش موفق</h3>";
                echo "<p>تعداد اعتبارات منتقل شده: {$result['transferred_count']}</p>";
                echo "<p>مجموع مبلغ: {$result['transferred_amount']} امتیاز</p>";
                echo "</div>";
            } else {
                echo "<div class='card' style='border-left-color: #d32f2f;'>";
                echo "<h3>خطا در پردازش</h3>";
                echo "<p>{$result['error']}</p>";
                echo "</div>";
            }
        }
        ?>
        
        <!-- Status Dashboard -->
        <div class="card">
            <h3>وضعیت کلی</h3>
            <?php
            // Get status information
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN transferred = 0 THEN 1 END) as pending_count,
                    COALESCE(SUM(CASE WHEN transferred = 0 THEN credit_amount END), 0) as pending_amount,
                    COUNT(CASE WHEN transferred = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 END) as ready_count,
                    COALESCE(SUM(CASE WHEN transferred = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN credit_amount END), 0) as ready_amount,
                    COUNT(CASE WHEN transferred = 1 AND DATE(transferred_at) = CURDATE() THEN 1 END) as today_count,
                    COALESCE(SUM(CASE WHEN transferred = 1 AND DATE(transferred_at) = CURDATE() THEN credit_amount END), 0) as today_amount
                FROM pending_credits
            ");
            $stmt->execute();
            $stats = $stmt->fetch();
            ?>
            
            <p><strong>اعتبارات در انتظار:</strong> <?= $stats['pending_count'] ?> مورد (<?= number_format($stats['pending_amount'] * 5000) ?> تومان)</p>
            <p><strong>آماده انتقال:</strong> <?= $stats['ready_count'] ?> مورد (<?= number_format($stats['ready_amount'] * 5000) ?> تومان)</p>
            <p><strong>منتقل شده امروز:</strong> <?= $stats['today_count'] ?> مورد (<?= number_format($stats['today_amount'] * 5000) ?> تومان)</p>
            
            <button class="button" onclick="location.href='?action=process'">پردازش همه اعتبارات آماده</button>
            <button class="button" onclick="location.reload()">بروزرسانی</button>
        </div>
        
        <!-- Recent Pending Credits -->
        <div class="card">
            <h3>اعتبارات در انتظار (20 مورد اخیر)</h3>
            <table>
                <thead>
                    <tr>
                        <th>شناسه</th>
                        <th>نام کاربر</th>
                        <th>موبایل</th>
                        <th>مبلغ (تومان)</th>
                        <th>تاریخ ایجاد</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmt = $pdo->prepare("
                        SELECT pc.id, pc.subscriber_id, pc.mobile, pc.credit_amount, pc.created_at, pc.transferred, pc.transferred_at,
                               s.full_name,
                               CASE WHEN pc.transferred = 0 AND pc.created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END as ready
                        FROM pending_credits pc
                        LEFT JOIN subscribers s ON pc.subscriber_id = s.id
                        ORDER BY pc.created_at DESC
                        LIMIT 20
                    ");
                    $stmt->execute();
                    
                    while ($row = $stmt->fetch()) {
                        $amount_toman = number_format($row['credit_amount'] * 5000);
                        $status = $row['transferred'] ? 'منتقل شده' : ($row['ready'] ? 'آماده انتقال' : 'در انتظار');
                        $status_class = $row['transferred'] ? 'status-transferred' : 'status-pending';
                        
                        echo "<tr>";
                        echo "<td>{$row['id']}</td>";
                        echo "<td>{$row['full_name']}</td>";
                        echo "<td>{$row['mobile']}</td>";
                        echo "<td>{$amount_toman}</td>";
                        echo "<td>{$row['created_at']}</td>";
                        echo "<td class='{$status_class}'>{$status}</td>";
                        echo "<td>";
                        if (!$row['transferred'] && $row['ready']) {
                            echo "<button class='button' onclick=\"location.href='?action=process&user_id={$row['subscriber_id']}'\">پردازش</button>";
                        }
                        echo "</td>";
                        echo "</tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        
        <!-- Tools -->
        <div class="card">
            <h3>ابزارها</h3>
            <p><strong>توجه:</strong> استفاده از این ابزارها با احتیاط و پس از پشتیبان‌گیری از پایگاه داده انجام شود.</p>
            
            <button class="button" onclick="if(confirm('آیا مطمئن هستید؟')) location.href='?action=process'">
                پردازش دستی همه اعتبارات
            </button>
            
            <button class="button danger" onclick="if(confirm('این عمل قابل بازگشت نیست. ادامه؟')) alert('این قابلیت هنوز پیاده‌سازی نشده است.')">
                پاک‌سازی رکوردهای قدیمی
            </button>
        </div>
    </div>
</body>
</html>