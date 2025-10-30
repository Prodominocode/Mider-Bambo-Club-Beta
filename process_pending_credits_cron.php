<?php
/**
 * Cron Job Script for Processing Pending Credits
 * 
 * This script should be run periodically (e.g., every hour) to automatically
 * transfer pending credits that have exceeded the 48-hour waiting period.
 * 
 * Add to crontab:
 * 0 * * * * /usr/bin/php /path/to/process_pending_credits_cron.php
 */

// Suppress output for cron jobs (comment out for debugging)
// ini_set('display_errors', 0);
// error_reporting(0);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pending_credits_utils.php';

// Log function for cron job
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    
    // Log to file
    $log_file = __DIR__ . '/logs/pending_credits_cron.log';
    
    // Create logs directory if it doesn't exist
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    
    // Also log to PHP error log
    error_log("Pending Credits Cron: $message");
}

try {
    // Add process lock to prevent concurrent execution
    $lock_file = __DIR__ . '/logs/pending_credits_cron.lock';
    $lock_handle = fopen($lock_file, 'w');
    
    if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
        log_message("Another instance is already running - skipping this execution");
        fclose($lock_handle);
        exit(0);
    }
    
    log_message("Starting pending credits processing...");
    
    // Check if pending_credits table exists and has active column
    if (!pending_credits_table_exists($pdo)) {
        log_message("WARNING: pending_credits table does not exist. Creating it...");
        if (!ensure_pending_credits_table($pdo)) {
            log_message("ERROR: Failed to create pending_credits table");
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            unlink($lock_file);
            exit(1);
        }
        log_message("Successfully created pending_credits table");
    }
    
    // Ensure active column exists
    if (!ensure_pending_credits_active_column($pdo)) {
        log_message("WARNING: Failed to ensure active column exists in pending_credits table");
    }
    
    // Process all pending credits that are older than 48 hours
    $result = process_pending_credits($pdo);
    
    if ($result['success']) {
        if ($result['transferred_count'] > 0) {
            log_message("Successfully processed {$result['transferred_count']} pending credits");
            log_message("Total amount transferred: {$result['transferred_amount']} points");
            
            // Log details of transferred credits
            foreach ($result['details'] as $detail) {
                log_message("Transferred {$detail['amount']} points to subscriber {$detail['subscriber_id']} (mobile: {$detail['mobile']})");
            }
            
            // Send notification if there are many transfers (possible issue)
            if ($result['transferred_count'] > 100) {
                log_message("WARNING: Large number of credits transferred ({$result['transferred_count']}). This might indicate a processing backlog.");
            }
        } else {
            log_message("No pending credits ready for transfer");
        }
    } else {
        log_message("ERROR: Failed to process pending credits - " . $result['error']);
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        unlink($lock_file);
        exit(1);
    }
    
    // Optional: Clean up old transferred records (older than 30 days)
    try {
        $cleanup_stmt = $pdo->prepare("
            DELETE FROM pending_credits 
            WHERE transferred = 1 AND transferred_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $cleanup_stmt->execute();
        $cleaned_count = $cleanup_stmt->rowCount();
        
        if ($cleaned_count > 0) {
            log_message("Cleaned up $cleaned_count old transferred pending credit records");
        }
    } catch (Exception $e) {
        log_message("WARNING: Failed to clean up old records - " . $e->getMessage());
    }
    
    // Optional: Check for any pending credits that are suspiciously old (more than 7 days) and still active
    try {
        $old_pending_stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM pending_credits 
            WHERE transferred = 0 AND active = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $old_pending_stmt->execute();
        $old_count = $old_pending_stmt->fetchColumn();
        
        if ($old_count > 0) {
            log_message("WARNING: Found $old_count active pending credits older than 7 days. This might indicate a problem.");
        }
    } catch (Exception $e) {
        log_message("WARNING: Failed to check for old pending credits - " . $e->getMessage());
    }
    
    log_message("Pending credits processing completed successfully");
    
    // Release lock
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    unlink($lock_file);
    
} catch (Exception $e) {
    log_message("CRITICAL ERROR: " . $e->getMessage());
    log_message("Stack trace: " . $e->getTraceAsString());
    
    // Release lock on error
    if (isset($lock_handle)) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }
    }
    exit(1);
}

// Exit successfully
exit(0);
?>