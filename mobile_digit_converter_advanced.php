<?php
/**
 * Mobile Digit Converter - Complete Standalone Script v2.0
 * 
 * This script scans all records in the subscribers table for Persian/Farsi digits
 * in the mobile field and converts them to standard English digits.
 * 
 * Features:
 * - Comprehensive scanning of all subscriber records
 * - Detailed logging for each record processed
 * - Summary report with statistics
 * - Error handling and rollback capability
 * - Real-time progress updates
 * - Preview mode before actual conversion
 * - No admin panel integration required
 * 
 * Created: October 29, 2025
 */

// Set error reporting and time zone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Tehran');

// Set content type for proper UTF-8 display
header('Content-Type: text/html; charset=utf-8');

// Include required files with error handling
try {
    require_once 'config.php';
    require_once 'db.php';
    
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection not available');
    }
    
    // Test database connection
    $pdo->query("SELECT 1");
    
} catch (Exception $e) {
    die("❌ Database Connection Error: " . $e->getMessage() . "\n");
}

/**
 * Function to normalize digits (convert Persian/Farsi to English)
 */
function norm_digits($s) {
    if (empty($s)) return $s;
    
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($persian, $latin, $s);
}

/**
 * Function to check if string contains Persian/Farsi digits
 */
function has_persian_digits($s) {
    if (empty($s)) return false;
    
    $persian_digits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    foreach ($persian_digits as $digit) {
        if (strpos($s, $digit) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Function to get digit changes for logging
 */
function get_digit_changes($original, $converted) {
    $changes = [];
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    
    for ($i = 0; $i < strlen($original); $i++) {
        if (isset($converted[$i]) && $original[$i] !== $converted[$i]) {
            $key = array_search($original[$i], $persian);
            if ($key !== false) {
                $changes[] = $original[$i] . ' → ' . $latin[$key];
            }
        }
    }
    return array_unique($changes);
}

/**
 * Function to log conversion details
 */
function log_conversion($log_data) {
    $timestamp = date('Y-m-d H:i:s');
    $status_icon = $log_data['status'] === 'success' ? '✅' : '❌';
    
    echo "<div class='log-entry {$log_data['status']}'>";
    echo "<span class='timestamp'>[{$timestamp}]</span> ";
    echo "<span class='status-icon'>{$status_icon}</span> ";
    echo "<span class='record-id'>ID: {$log_data['id']}</span> - ";
    echo "<span class='mobile-info'>";
    
    if ($log_data['status'] === 'success') {
        echo "Mobile: '{$log_data['original']}' → '{$log_data['converted']}'";
        if (!empty($log_data['name'])) {
            echo " (Name: " . htmlspecialchars($log_data['name']) . ")";
        }
        if (!empty($log_data['changes'])) {
            echo " - Changes: " . implode(', ', $log_data['changes']);
        }
        echo " - <strong>CONVERTED SUCCESSFULLY</strong>";
    } else {
        echo "Mobile: '{$log_data['original']}'";
        if (!empty($log_data['name'])) {
            echo " (Name: " . htmlspecialchars($log_data['name']) . ")";
        }
        echo " - <strong>CONVERSION FAILED</strong>";
        if (!empty($log_data['error'])) {
            echo " - Error: " . htmlspecialchars($log_data['error']);
        }
    }
    
    echo "</span>";
    echo "</div>\n";
    
    // Flush output for real-time display
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

/**
 * Function to log scan progress
 */
function log_progress($current, $total) {
    $percentage = round(($current / $total) * 100, 1);
    echo "<div class='progress-entry'>";
    echo "<span class='timestamp'>[" . date('Y-m-d H:i:s') . "]</span> ";
    echo "📊 Progress: {$current}/{$total} records scanned ({$percentage}%)";
    echo "</div>\n";
    
    // Flush output
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

/**
 * Function to log general information
 */
function log_info($message, $icon = 'ℹ️') {
    echo "<div class='log-entry info'>";
    echo "<span class='timestamp'>[" . date('Y-m-d H:i:s') . "]</span> ";
    echo "{$icon} " . htmlspecialchars($message);
    echo "</div>\n";
    
    // Flush output
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

// Initialize statistics
$stats = [
    'total_records' => 0,
    'records_with_persian' => 0,
    'successful_conversions' => 0,
    'failed_conversions' => 0,
    'records_unchanged' => 0,
    'records_processed' => 0
];

$conversion_log = [];
$start_time = microtime(true);

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mobile Digit Converter - Complete Report v2.0</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            min-height: 100vh;
            color: #fff;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: rgba(0,0,0,0.3);
            border-radius: 16px;
            padding: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: bold;
            color: #ffb300;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #e3f2fd;
            opacity: 0.9;
        }
        
        .info-section {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #4caf50;
        }
        
        .warning-section {
            background: rgba(255,152,0,0.2);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #ff9800;
        }
        
        .log-container {
            background: rgba(0,0,0,0.5);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .log-entry {
            padding: 8px 12px;
            margin-bottom: 4px;
            border-radius: 6px;
            font-size: 14px;
            word-wrap: break-word;
        }
        
        .log-entry.success {
            background: rgba(76,175,80,0.2);
            border-left: 3px solid #4caf50;
        }
        
        .log-entry.failed {
            background: rgba(244,67,54,0.2);
            border-left: 3px solid #f44336;
        }
        
        .log-entry.info {
            background: rgba(33,150,243,0.2);
            border-left: 3px solid #2196f3;
        }
        
        .progress-entry {
            padding: 6px 12px;
            margin-bottom: 4px;
            background: rgba(156,39,176,0.2);
            border-radius: 6px;
            border-left: 3px solid #9c27b0;
            font-size: 14px;
        }
        
        .timestamp {
            color: #90a4ae;
            font-weight: bold;
        }
        
        .status-icon {
            font-size: 16px;
        }
        
        .record-id {
            color: #81c784;
            font-weight: bold;
        }
        
        .mobile-info {
            color: #e1f5fe;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.8;
        }
        
        .success-number { color: #4caf50; }
        .error-number { color: #f44336; }
        .info-number { color: #2196f3; }
        .warning-number { color: #ff9800; }
        
        .action-section {
            text-align: center;
            margin: 30px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(45deg, #4caf50, #45a049);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: bold;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px;
        }
        
        .btn:hover {
            background: linear-gradient(45deg, #45a049, #4caf50);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(76,175,80,0.3);
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #f44336, #d32f2f);
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #d32f2f, #f44336);
            box-shadow: 0 4px 12px rgba(244,67,54,0.3);
        }
        
        .processing {
            text-align: center;
            padding: 20px;
            background: rgba(255,193,7,0.2);
            border-radius: 12px;
            border-left: 4px solid #ffc107;
            margin: 20px 0;
        }
        
        .completed {
            text-align: center;
            padding: 20px;
            background: rgba(76,175,80,0.2);
            border-radius: 12px;
            border-left: 4px solid #4caf50;
            margin: 20px 0;
        }
        
        .error-box {
            text-align: center;
            padding: 20px;
            background: rgba(244,67,54,0.2);
            border-radius: 12px;
            border-left: 4px solid #f44336;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">📱 Mobile Digit Converter v2.0</h1>
            <p class="subtitle">Complete Persian/Farsi to English Digit Conversion Report</p>
            <p style="color: #90a4ae; margin-top: 10px;">Started at: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <div class="info-section">
            <h3>ℹ️ Script Information</h3>
            <p><strong>Purpose:</strong> Scan all subscriber records and convert Persian/Farsi digits to English digits in mobile field</p>
            <p><strong>Database:</strong> <?php echo DB_NAME; ?></p>
            <p><strong>Table:</strong> subscribers</p>
            <p><strong>Field:</strong> mobile</p>
        </div>
        
        <?php
        // Check if this is a confirmation request
        $confirm_conversion = isset($_GET['confirm']) && $_GET['confirm'] === 'true';
        
        if (!$confirm_conversion) {
            echo '<div class="warning-section">';
            echo '<h3>⚠️ Preview Mode</h3>';
            echo '<p>This is a preview scan. No data will be modified yet.</p>';
            echo '<p><strong>Important:</strong> Make sure to backup your database before proceeding with actual conversion!</p>';
            echo '</div>';
        } else {
            echo '<div class="error-box">';
            echo '<h3>🔄 CONVERSION MODE - ACTIVE</h3>';
            echo '<p>This will permanently modify your database!</p>';
            echo '</div>';
        }
        ?>
        
        <div class="processing">
            <h3>🔄 Processing Status</h3>
            <p id="status">Initializing scan...</p>
        </div>
        
        <div class="log-container">
            <h3>📋 Processing Log</h3>
            <div id="log-content">
                <?php
                try {
                    // Start scanning
                    log_info("STARTING MOBILE DIGIT CONVERSION SCAN", "🚀");
                    
                    // Get total record count
                    $count_stmt = $pdo->query("SELECT COUNT(*) as total FROM subscribers");
                    $stats['total_records'] = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    log_info("Total records to scan: {$stats['total_records']}", "📊");
                    
                    if ($stats['total_records'] === 0) {
                        log_info("No records found in subscribers table.", "ℹ️");
                    } else {
                        // Process records in batches for better performance
                        $batch_size = 50;
                        $offset = 0;
                        $processed = 0;
                        
                        if ($confirm_conversion) {
                            log_info("Starting database transaction...", "🔄");
                            $pdo->beginTransaction();
                        }
                        
                        while ($offset < $stats['total_records']) {
                            $stmt = $pdo->prepare("SELECT id, mobile, full_name FROM subscribers ORDER BY id LIMIT ? OFFSET ?");
                            $stmt->execute([$batch_size, $offset]);
                            $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($batch as $record) {
                                $processed++;
                                $stats['records_processed']++;
                                
                                // Show progress every 25 records or at the end
                                if ($processed % 25 === 0 || $processed === $stats['total_records']) {
                                    log_progress($processed, $stats['total_records']);
                                }
                                
                                $original_mobile = $record['mobile'] ?? '';
                                
                                if (has_persian_digits($original_mobile)) {
                                    $stats['records_with_persian']++;
                                    $converted_mobile = norm_digits($original_mobile);
                                    $changes = get_digit_changes($original_mobile, $converted_mobile);
                                    
                                    $log_data = [
                                        'id' => $record['id'],
                                        'original' => $original_mobile,
                                        'converted' => $converted_mobile,
                                        'name' => $record['full_name'] ?? '',
                                        'changes' => $changes,
                                        'status' => 'success',
                                        'error' => null
                                    ];
                                    
                                    if ($confirm_conversion) {
                                        // Actually update the database
                                        try {
                                            $update_stmt = $pdo->prepare("UPDATE subscribers SET mobile = ? WHERE id = ?");
                                            $result = $update_stmt->execute([$converted_mobile, $record['id']]);
                                            
                                            if ($result && $update_stmt->rowCount() > 0) {
                                                $stats['successful_conversions']++;
                                                log_conversion($log_data);
                                            } else {
                                                $stats['failed_conversions']++;
                                                $log_data['status'] = 'failed';
                                                $log_data['error'] = 'Database update failed - no rows affected';
                                                log_conversion($log_data);
                                            }
                                        } catch (Exception $e) {
                                            $stats['failed_conversions']++;
                                            $log_data['status'] = 'failed';
                                            $log_data['error'] = $e->getMessage();
                                            log_conversion($log_data);
                                        }
                                    } else {
                                        // Preview mode - just log what would be changed
                                        $stats['successful_conversions']++;
                                        log_conversion($log_data);
                                    }
                                } else {
                                    $stats['records_unchanged']++;
                                    
                                    // Only log first few unchanged records to avoid spam
                                    if ($stats['records_unchanged'] <= 3) {
                                        log_info("ID: {$record['id']} - Mobile: '{$original_mobile}' - No conversion needed", "✓");
                                    } elseif ($stats['records_unchanged'] === 4) {
                                        log_info("... (Additional unchanged records will not be logged individually)", "ℹ️");
                                    }
                                }
                                
                                // Small delay to prevent overwhelming the browser
                                usleep(500); // 0.5ms delay
                            }
                            
                            $offset += $batch_size;
                        }
                        
                        if ($confirm_conversion) {
                            if ($stats['failed_conversions'] === 0) {
                                $pdo->commit();
                                log_info("ALL CHANGES COMMITTED TO DATABASE", "✅");
                            } else {
                                $pdo->rollBack();
                                log_info("ROLLBACK PERFORMED DUE TO ERRORS - No changes made to database", "🔄");
                            }
                        }
                    }
                    
                    $end_time = microtime(true);
                    $execution_time = round($end_time - $start_time, 2);
                    
                    log_info("SCAN COMPLETED - Execution time: {$execution_time} seconds", "🏁");
                    
                } catch (Exception $e) {
                    $stats['failed_conversions']++;
                    log_info("CRITICAL ERROR: " . $e->getMessage(), "❌");
                    
                    if ($confirm_conversion && isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                        log_info("TRANSACTION ROLLED BACK", "🔄");
                    }
                }
                ?>
            </div>
        </div>
        
        <div class="<?php echo ($stats['failed_conversions'] > 0) ? 'error-box' : 'completed'; ?>">
            <h3><?php echo ($stats['failed_conversions'] > 0) ? '⚠️ Process Completed with Errors' : '✅ Process Completed Successfully'; ?></h3>
            <p><?php echo $confirm_conversion ? 'Conversion completed' : 'Preview scan completed'; ?></p>
        </div>
        
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number info-number"><?php echo $stats['total_records']; ?></div>
                <div class="stat-label">Total Records Scanned</div>
            </div>
            <div class="stat-card">
                <div class="stat-number warning-number"><?php echo $stats['records_with_persian']; ?></div>
                <div class="stat-label">Records with Persian Digits</div>
            </div>
            <div class="stat-card">
                <div class="stat-number success-number"><?php echo $stats['successful_conversions']; ?></div>
                <div class="stat-label"><?php echo $confirm_conversion ? 'Successful Conversions' : 'Conversions Available'; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-number error-number"><?php echo $stats['failed_conversions']; ?></div>
                <div class="stat-label">Failed Conversions</div>
            </div>
            <div class="stat-card">
                <div class="stat-number info-number"><?php echo $stats['records_unchanged']; ?></div>
                <div class="stat-label">Records Unchanged</div>
            </div>
            <div class="stat-card">
                <div class="stat-number info-number"><?php echo isset($execution_time) ? $execution_time : 0; ?>s</div>
                <div class="stat-label">Execution Time</div>
            </div>
        </div>
        
        <?php if (!$confirm_conversion && $stats['records_with_persian'] > 0): ?>
        <div class="action-section">
            <h3>🎯 Next Steps</h3>
            <p style="margin-bottom: 20px;">
                Found <?php echo $stats['records_with_persian']; ?> records that need conversion.
                <br><strong>⚠️ IMPORTANT: Backup your database before proceeding!</strong>
            </p>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>?confirm=true" class="btn btn-danger" 
               onclick="return confirm('⚠️ WARNING: This will permanently modify your database!\n\nRecords to be converted: <?php echo $stats['records_with_persian']; ?>\n\nMake sure you have a backup!\n\nProceed with actual conversion?')">
                🔄 Perform Actual Conversion (<?php echo $stats['records_with_persian']; ?> records)
            </a>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn">
                🔄 Run Preview Again
            </a>
        </div>
        <?php elseif ($confirm_conversion): ?>
        <div class="action-section">
            <h3>✅ Conversion Complete</h3>
            <p style="margin-bottom: 20px;">
                <?php if ($stats['successful_conversions'] > 0): ?>
                Successfully converted <?php echo $stats['successful_conversions']; ?> mobile numbers!
                <?php if ($stats['failed_conversions'] > 0): ?>
                <br><span style="color: #ff9800;">⚠️ <?php echo $stats['failed_conversions']; ?> conversions failed.</span>
                <?php endif; ?>
                <?php else: ?>
                No conversions were performed.
                <?php endif; ?>
            </p>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn">
                🔄 Run New Scan
            </a>
        </div>
        <?php else: ?>
        <div class="action-section">
            <h3>✅ No Action Required</h3>
            <p style="margin-bottom: 20px;">
                All mobile numbers are already using English digits!
            </p>
            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn">
                🔄 Run Scan Again
            </a>
        </div>
        <?php endif; ?>
        
        <div class="info-section">
            <h3>📈 Final Summary Report</h3>
            <ul style="list-style: none; padding: 0;">
                <li>📊 <strong>Total Records:</strong> <?php echo $stats['total_records']; ?></li>
                <li>🔍 <strong>Records Processed:</strong> <?php echo $stats['records_processed']; ?></li>
                <li>🔢 <strong>Records with Persian Digits:</strong> <?php echo $stats['records_with_persian']; ?></li>
                <li>✅ <strong>Successful Operations:</strong> <?php echo $stats['successful_conversions']; ?></li>
                <li>❌ <strong>Failed Operations:</strong> <?php echo $stats['failed_conversions']; ?></li>
                <li>📝 <strong>Unchanged Records:</strong> <?php echo $stats['records_unchanged']; ?></li>
                <li>⏱️ <strong>Execution Time:</strong> <?php echo isset($execution_time) ? $execution_time : 0; ?> seconds</li>
                <li>🎯 <strong>Mode:</strong> <?php echo $confirm_conversion ? 'ACTUAL CONVERSION' : 'PREVIEW SCAN'; ?></li>
                <li>📅 <strong>Date:</strong> <?php echo date('Y-m-d H:i:s'); ?></li>
            </ul>
        </div>
        
        <div class="info-section">
            <h3>ℹ️ Script Features</h3>
            <ul style="list-style: none; padding: 0;">
                <li>✅ Comprehensive scanning of all subscriber records</li>
                <li>✅ Detailed logging for each record processed</li>
                <li>✅ Real-time progress updates</li>
                <li>✅ Preview mode before actual conversion</li>
                <li>✅ Error handling and rollback capability</li>
                <li>✅ Batch processing for better performance</li>
                <li>✅ Detailed conversion change tracking</li>
                <li>✅ Complete summary statistics</li>
                <li>✅ No admin panel integration required</li>
            </ul>
        </div>
    </div>

    <script>
        // Update status based on completion
        document.addEventListener('DOMContentLoaded', function() {
            const statusElement = document.getElementById('status');
            <?php if ($stats['total_records'] === 0): ?>
                statusElement.textContent = '✅ Complete - No records found';
                statusElement.style.color = '#4caf50';
            <?php elseif ($confirm_conversion): ?>
                statusElement.textContent = '✅ Conversion completed - <?php echo $stats['successful_conversions']; ?> successful, <?php echo $stats['failed_conversions']; ?> failed';
                statusElement.style.color = '<?php echo $stats['failed_conversions'] > 0 ? "#ff9800" : "#4caf50"; ?>';
            <?php else: ?>
                statusElement.textContent = '✅ Preview scan completed - <?php echo $stats['records_with_persian']; ?> records need conversion';
                statusElement.style.color = '#2196f3';
            <?php endif; ?>
        });
        
        // Auto-scroll log to bottom
        const logContainer = document.querySelector('.log-container');
        if (logContainer) {
            logContainer.scrollTop = logContainer.scrollHeight;
        }
        
        // Add timestamp to page title
        document.title += ' - ' + new Date().toLocaleString();
    </script>
</body>
</html>