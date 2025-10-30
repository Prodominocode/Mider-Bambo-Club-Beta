<?php
/**
 * Mobile Digit Converter - Comprehensive Standalone Script
 * Converts Persian/Farsi digits to English digits in subscribers table
 * 
 * Features:
 * - Scans all records in subscribers table
 * - Converts Persian/Arabic digits to English digits
 * - Detailed logging for each record
 * - Comprehensive summary report
 * - Backup option before conversion
 * - Rollback capability
 * - Dry-run mode for testing
 */

header('Content-Type: text/html; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Tehran');

// Database connection
try {
    $host = 'localhost';
    $db   = 'sasadiir_miderCDB'; 
    $user = 'sasadiir_MiderclUs';      
    $pass = '5TcCpBoXz7W71oi9';

    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Persian/Farsi digit normalization function
function norm_digits($s) {
    if (empty($s)) return $s;
    
    // Persian digits
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    // Arabic-Indic digits
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    // English digits
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    
    // Convert Persian digits
    $s = str_replace($persian, $english, $s);
    // Convert Arabic digits
    $s = str_replace($arabic, $english, $s);
    // Remove extra whitespace
    $s = preg_replace('/\s+/', '', $s);
    
    return $s;
}

// Function to detect Persian/Arabic digits
function has_persian_or_arabic_digits($s) {
    if (empty($s)) return false;
    
    // Check for Persian digits
    if (preg_match('/[۰-۹]/', $s)) return true;
    // Check for Arabic-Indic digits
    if (preg_match('/[٠-٩]/', $s)) return true;
    
    return false;
}

// Validate mobile number format
function is_valid_mobile($mobile) {
    // Iranian mobile number format: 09xxxxxxxxx (11 digits)
    return preg_match('/^09\d{9}$/', $mobile);
}

// Create backup table
function create_backup_table($pdo) {
    $backup_table = 'subscribers_backup_' . date('Y_m_d_H_i_s');
    
    try {
        $sql = "CREATE TABLE `$backup_table` AS SELECT * FROM `subscribers`";
        $pdo->exec($sql);
        return $backup_table;
    } catch (PDOException $e) {
        throw new Exception("Failed to create backup table: " . $e->getMessage());
    }
}

// Log entry structure
class ConversionLog {
    public $id;
    public $original_mobile;
    public $converted_mobile;
    public $full_name;
    public $status; // 'success', 'failed', 'no_change', 'invalid'
    public $message;
    public $timestamp;
    
    public function __construct($id, $original_mobile, $converted_mobile, $full_name, $status, $message = '') {
        $this->id = $id;
        $this->original_mobile = $original_mobile;
        $this->converted_mobile = $converted_mobile;
        $this->full_name = $full_name;
        $this->status = $status;
        $this->message = $message;
        $this->timestamp = date('Y-m-d H:i:s');
    }
}

// Main conversion function
function convert_mobile_digits($pdo, $dry_run = true, $create_backup = true) {
    $results = [
        'total_scanned' => 0,
        'needs_conversion' => 0,
        'successful_conversions' => 0,
        'failed_conversions' => 0,
        'invalid_mobiles' => 0,
        'no_change_needed' => 0,
        'backup_table' => null,
        'logs' => [],
        'errors' => []
    ];
    
    try {
        // Create backup if not dry run and backup requested
        if (!$dry_run && $create_backup) {
            $results['backup_table'] = create_backup_table($pdo);
        }
        
        // Start transaction for non-dry runs
        if (!$dry_run) {
            $pdo->beginTransaction();
        }
        
        // Get all subscribers
        $stmt = $pdo->prepare("SELECT id, mobile, full_name FROM subscribers ORDER BY id");
        $stmt->execute();
        $subscribers = $stmt->fetchAll();
        
        $results['total_scanned'] = count($subscribers);
        
        foreach ($subscribers as $subscriber) {
            $id = $subscriber['id'];
            $original_mobile = $subscriber['mobile'];
            $full_name = $subscriber['full_name'] ?: 'نام ثبت نشده';
            
            // Check if conversion is needed
            if (has_persian_or_arabic_digits($original_mobile)) {
                $results['needs_conversion']++;
                
                // Convert digits
                $converted_mobile = norm_digits($original_mobile);
                
                // Validate converted mobile
                if (!is_valid_mobile($converted_mobile)) {
                    $results['invalid_mobiles']++;
                    $log = new ConversionLog($id, $original_mobile, $converted_mobile, $full_name, 'invalid', 
                        'Converted mobile number format is invalid');
                    $results['logs'][] = $log;
                    continue;
                }
                
                // Update database if not dry run
                if (!$dry_run) {
                    try {
                        $update_stmt = $pdo->prepare("UPDATE subscribers SET mobile = ? WHERE id = ?");
                        $update_result = $update_stmt->execute([$converted_mobile, $id]);
                        
                        if ($update_result) {
                            $results['successful_conversions']++;
                            $log = new ConversionLog($id, $original_mobile, $converted_mobile, $full_name, 'success', 
                                'Mobile number successfully converted');
                        } else {
                            $results['failed_conversions']++;
                            $log = new ConversionLog($id, $original_mobile, $converted_mobile, $full_name, 'failed', 
                                'Database update failed');
                        }
                    } catch (PDOException $e) {
                        $results['failed_conversions']++;
                        $log = new ConversionLog($id, $original_mobile, $converted_mobile, $full_name, 'failed', 
                            'Database error: ' . $e->getMessage());
                    }
                } else {
                    // Dry run - just log what would be converted
                    $results['successful_conversions']++;
                    $log = new ConversionLog($id, $original_mobile, $converted_mobile, $full_name, 'success', 
                        'Would be converted (dry run)');
                }
                
                $results['logs'][] = $log;
                
            } else {
                // No conversion needed
                $results['no_change_needed']++;
                $log = new ConversionLog($id, $original_mobile, $original_mobile, $full_name, 'no_change', 
                    'No Persian/Arabic digits found');
                $results['logs'][] = $log;
            }
        }
        
        // Commit transaction for non-dry runs
        if (!$dry_run) {
            $pdo->commit();
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if (!$dry_run) {
            $pdo->rollBack();
        }
        
        $results['errors'][] = 'Conversion failed: ' . $e->getMessage();
    }
    
    return $results;
}

// Handle form submission
$conversion_results = null;
$operation_mode = 'none';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operation = $_POST['operation'] ?? '';
    $create_backup = isset($_POST['create_backup']);
    
    switch ($operation) {
        case 'dry_run':
            $operation_mode = 'dry_run';
            $conversion_results = convert_mobile_digits($pdo, true, false);
            break;
            
        case 'convert':
            $operation_mode = 'convert';
            $conversion_results = convert_mobile_digits($pdo, false, $create_backup);
            break;
    }
}

// Get current statistics
function get_current_stats($pdo) {
    $stats = [];
    
    // Total subscribers
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM subscribers");
    $stats['total_subscribers'] = $stmt->fetchColumn();
    
    // Subscribers with Persian digits
    $stmt = $pdo->prepare("SELECT id, mobile FROM subscribers");
    $stmt->execute();
    $all_subscribers = $stmt->fetchAll();
    
    $persian_count = 0;
    foreach ($all_subscribers as $subscriber) {
        if (has_persian_or_arabic_digits($subscriber['mobile'])) {
            $persian_count++;
        }
    }
    
    $stats['persian_digits_count'] = $persian_count;
    $stats['clean_mobiles'] = $stats['total_subscribers'] - $persian_count;
    
    return $stats;
}

$current_stats = get_current_stats($pdo);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تبدیل اعداد فارسی شماره موبایل - نسخه کامل</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
            direction: rtl;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255,255,255,0.95);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        
        .title {
            font-size: 2.2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .subtitle {
            font-size: 1.1rem;
            color: #7f8c8d;
        }
        
        .stats-section {
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            border: 2px solid #e9ecef;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .controls-section {
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            margin: 8px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .checkbox-container {
            margin: 16px 0;
        }
        
        .checkbox-container input[type="checkbox"] {
            margin-left: 8px;
        }
        
        .results-section {
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        }
        
        .log-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .log-table th, .log-table td {
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            text-align: center;
        }
        
        .log-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-invalid {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-no-change {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .summary-box {
            background: #e8f5e8;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .error-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .mobile-display {
            font-family: monospace;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        
        .pagination button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 12px;
            margin: 0 2px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .pagination button.active {
            background: #007bff;
        }
        
        .pagination button:hover {
            background: #5a6268;
        }
        
        .pagination button.active:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="title">🔄 تبدیل کامل اعداد فارسی شماره موبایل</div>
            <div class="subtitle">تبدیل اعداد فارسی/عربی به انگلیسی در جدول subscribers</div>
        </div>
        
        <!-- Current Statistics -->
        <div class="stats-section">
            <h3 style="color: #2c3e50; margin-bottom: 16px;">📊 آمار فعلی دیتابیس</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($current_stats['total_subscribers']); ?></div>
                    <div class="stat-label">کل کاربران</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #e74c3c;"><?php echo number_format($current_stats['persian_digits_count']); ?></div>
                    <div class="stat-label">نیاز به تبدیل</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #27ae60;"><?php echo number_format($current_stats['clean_mobiles']); ?></div>
                    <div class="stat-label">اعداد صحیح</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" style="color: #f39c12;">
                        <?php echo $current_stats['total_subscribers'] > 0 ? number_format(($current_stats['persian_digits_count'] / $current_stats['total_subscribers']) * 100, 1) : 0; ?>%
                    </div>
                    <div class="stat-label">درصد نیازمند تبدیل</div>
                </div>
            </div>
        </div>
        
        <!-- Controls -->
        <div class="controls-section">
            <h3 style="color: #2c3e50; margin-bottom: 16px;">⚙️ عملیات تبدیل</h3>
            
            <?php if ($current_stats['persian_digits_count'] == 0): ?>
                <div class="summary-box">
                    <h4 style="color: #155724;">✅ تبریک! همه شماره‌های موبایل دارای اعداد صحیح هستند</h4>
                    <p>هیچ رکوردی نیاز به تبدیل ندارد.</p>
                </div>
            <?php else: ?>
                <div class="warning-box">
                    <h4 style="color: #856404;">⚠️ هشدار مهم</h4>
                    <p><strong><?php echo number_format($current_stats['persian_digits_count']); ?> رکورد</strong> نیاز به تبدیل دارد.</p>
                    <p>لطفاً ابتدا تست dry run انجام دهید تا نتایج را بررسی کنید.</p>
                </div>
                
                <form method="POST">
                    <div class="checkbox-container">
                        <label>
                            <input type="checkbox" name="create_backup" checked>
                            ایجاد backup از جدول قبل از تبدیل (توصیه می‌شود)
                        </label>
                    </div>
                    
                    <button type="submit" name="operation" value="dry_run" class="btn">
                        🔍 تست اجرا (Dry Run)
                    </button>
                    
                    <button type="submit" name="operation" value="convert" class="btn btn-danger" 
                            onclick="return confirm('آیا مطمئن هستید که می‌خواهید تبدیل را انجام دهید؟\n\nاین عمل بازگشت‌پذیر نیست!')">
                        🔄 اجرای واقعی تبدیل
                    </button>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- Results -->
        <?php if ($conversion_results): ?>
            <div class="results-section">
                <h3 style="color: #2c3e50; margin-bottom: 16px;">
                    📋 نتایج <?php echo $operation_mode === 'dry_run' ? 'تست اجرا (Dry Run)' : 'تبدیل واقعی'; ?>
                </h3>
                
                <!-- Summary -->
                <div class="summary-box">
                    <h4 style="color: #155724;">📈 خلاصه نتایج</h4>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?php echo number_format($conversion_results['total_scanned']); ?></div>
                            <div class="stat-label">کل رکورد بررسی شده</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #e74c3c;"><?php echo number_format($conversion_results['needs_conversion']); ?></div>
                            <div class="stat-label">نیاز به تبدیل</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #27ae60;"><?php echo number_format($conversion_results['successful_conversions']); ?></div>
                            <div class="stat-label">تبدیل موفق</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #dc3545;"><?php echo number_format($conversion_results['failed_conversions']); ?></div>
                            <div class="stat-label">تبدیل ناموفق</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #ffc107;"><?php echo number_format($conversion_results['invalid_mobiles']); ?></div>
                            <div class="stat-label">فرمت نامعتبر</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number" style="color: #6c757d;"><?php echo number_format($conversion_results['no_change_needed']); ?></div>
                            <div class="stat-label">بدون تغییر</div>
                        </div>
                    </div>
                    
                    <?php if ($conversion_results['backup_table']): ?>
                        <p style="margin-top: 16px;"><strong>جدول Backup:</strong> <code><?php echo $conversion_results['backup_table']; ?></code></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($conversion_results['errors'])): ?>
                        <div class="error-box" style="margin-top: 16px;">
                            <h4>❌ خطاها:</h4>
                            <?php foreach ($conversion_results['errors'] as $error): ?>
                                <p>• <?php echo htmlspecialchars($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Detailed Log -->
                <?php if (!empty($conversion_results['logs'])): ?>
                    <h4 style="color: #2c3e50; margin: 24px 0 16px 0;">📝 گزارش تفصیلی</h4>
                    
                    <div id="logContainer">
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>شناسه</th>
                                    <th>نام کاربر</th>
                                    <th>شماره اصلی</th>
                                    <th>شماره تبدیل شده</th>
                                    <th>وضعیت</th>
                                    <th>پیام</th>
                                    <th>زمان</th>
                                </tr>
                            </thead>
                            <tbody id="logTableBody">
                                <!-- Will be populated by JavaScript -->
                            </tbody>
                        </table>
                        
                        <div class="pagination" id="pagination">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                    
                    <script>
                        // Log data
                        const logs = <?php echo json_encode($conversion_results['logs']); ?>;
                        const logsPerPage = 50;
                        let currentPage = 1;
                        
                        function getStatusClass(status) {
                            switch(status) {
                                case 'success': return 'status-success';
                                case 'failed': return 'status-failed';
                                case 'invalid': return 'status-invalid';
                                case 'no_change': return 'status-no-change';
                                default: return '';
                            }
                        }
                        
                        function getStatusText(status) {
                            switch(status) {
                                case 'success': return '✅ موفق';
                                case 'failed': return '❌ ناموفق';
                                case 'invalid': return '⚠️ نامعتبر';
                                case 'no_change': return '➖ بدون تغییر';
                                default: return status;
                            }
                        }
                        
                        function displayLogs(page) {
                            const start = (page - 1) * logsPerPage;
                            const end = start + logsPerPage;
                            const pageData = logs.slice(start, end);
                            
                            const tbody = document.getElementById('logTableBody');
                            tbody.innerHTML = '';
                            
                            pageData.forEach(log => {
                                const row = document.createElement('tr');
                                row.className = getStatusClass(log.status);
                                row.innerHTML = `
                                    <td>${log.id}</td>
                                    <td>${log.full_name || 'نام ثبت نشده'}</td>
                                    <td><span class="mobile-display">${log.original_mobile}</span></td>
                                    <td><span class="mobile-display">${log.converted_mobile}</span></td>
                                    <td>${getStatusText(log.status)}</td>
                                    <td>${log.message}</td>
                                    <td>${log.timestamp}</td>
                                `;
                                tbody.appendChild(row);
                            });
                        }
                        
                        function displayPagination() {
                            const totalPages = Math.ceil(logs.length / logsPerPage);
                            const pagination = document.getElementById('pagination');
                            pagination.innerHTML = '';
                            
                            if (totalPages <= 1) return;
                            
                            for (let i = 1; i <= totalPages; i++) {
                                const button = document.createElement('button');
                                button.textContent = i;
                                button.className = i === currentPage ? 'active' : '';
                                button.onclick = () => {
                                    currentPage = i;
                                    displayLogs(currentPage);
                                    displayPagination();
                                };
                                pagination.appendChild(button);
                            }
                        }
                        
                        // Initialize
                        displayLogs(1);
                        displayPagination();
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Documentation -->
        <div class="results-section">
            <h3 style="color: #2c3e50; margin-bottom: 16px;">📚 راهنمای استفاده</h3>
            <div style="background: #f8f9fa; padding: 16px; border-radius: 8px; border: 1px solid #dee2e6;">
                <h4 style="color: #495057;">مراحل انجام تبدیل:</h4>
                <ol style="margin: 12px 0; padding-right: 20px;">
                    <li><strong>بررسی آمار:</strong> ابتدا آمار فعلی دیتابیس را بررسی کنید</li>
                    <li><strong>تست اجرا:</strong> با کلیک روی "تست اجرا" نتایج تبدیل را بدون تغییر دیتابیس مشاهده کنید</li>
                    <li><strong>ایجاد Backup:</strong> حتماً گزینه backup را فعال کنید (پیش‌فرض فعال است)</li>
                    <li><strong>اجرای واقعی:</strong> پس از اطمینان از نتایج، تبدیل واقعی را انجام دهید</li>
                </ol>
                
                <h4 style="color: #495057; margin-top: 20px;">انواع تبدیل:</h4>
                <ul style="margin: 12px 0; padding-right: 20px;">
                    <li><strong>اعداد فارسی:</strong> ۰۱۲۳۴۵۶۷۸۹ ← 0123456789</li>
                    <li><strong>اعداد عربی:</strong> ٠١٢٣٤٥٦٧٨٩ ← 0123456789</li>
                    <li><strong>حذف فاصله:</strong> فاصله‌های اضافی نیز حذف می‌شود</li>
                </ul>
                
                <h4 style="color: #495057; margin-top: 20px;">نکات ایمنی:</h4>
                <ul style="margin: 12px 0; padding-right: 20px;">
                    <li>همیشه قبل از تبدیل واقعی، تست dry run انجام دهید</li>
                    <li>backup جدول قبل از تبدیل ایجاد کنید</li>
                    <li>فقط شماره‌های معتبر ایرانی (11 رقم، شروع با 09) تبدیل می‌شوند</li>
                    <li>در صورت خطا، عملیات rollback می‌شود</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>