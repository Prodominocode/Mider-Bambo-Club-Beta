<?php
/**
 * Credit Deactivation Management Functions
 * Handles proper credit adjustments when purchases and gift credits are deactivated
 * 
 * Key Requirements:
 * 1. When purchase is deactivated: Check if pending credit was already transferred
 * 2. When gift credit is deactivated: Immediately subtract from subscriber balance
 * 3. Use transactions to prevent race conditions
 * 4. Only process active records
 */

// Only include database if not already included
if (!isset($pdo)) {
    require_once 'db.php';
}
require_once 'pending_credits_utils.php';

/**
 * Safely deactivate a purchase and handle credit adjustments
 * 
 * @param PDO $pdo Database connection
 * @param int $purchase_id Purchase ID to deactivate
 * @param string $admin_mobile Admin performing the action (for logging)
 * @return array Result with status, message, and details
 */
function deactivate_purchase_with_credit_adjustment($pdo, $purchase_id, $admin_mobile = null) {
    try {
        $pdo->beginTransaction();
        
        // 1. Get purchase details and verify it's active
        $stmt = $pdo->prepare('
            SELECT id, subscriber_id, mobile, amount, created_at, active
            FROM purchases 
            WHERE id = ? AND active = 1 
            LIMIT 1
        ');
        $stmt->execute([$purchase_id]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$purchase) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Purchase not found or already deactivated',
                'error_code' => 'PURCHASE_NOT_FOUND'
            ];
        }
        
        // 2. Calculate the earned credit from this purchase
        $earned_credit = round(((float)$purchase['amount']) / 100000.0, 1);
        
        // 3. Check if there's a pending credit for this purchase
        $stmt = $pdo->prepare('
            SELECT id, credit_amount, transferred, transferred_at, active
            FROM pending_credits 
            WHERE purchase_id = ?
            LIMIT 1
        ');
        $stmt->execute([$purchase_id]);
        $pending_credit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $credit_adjustment_needed = 0;
        $adjustment_reason = '';
        
        if ($pending_credit) {
            if ($pending_credit['transferred'] == 1) {
                // Credit was already transferred to main balance, need to subtract it
                $credit_adjustment_needed = -$pending_credit['credit_amount'];
                $adjustment_reason = 'pending_credit_already_transferred';
            } else {
                // Credit not yet transferred, just deactivate the pending credit
                $stmt = $pdo->prepare('
                    UPDATE pending_credits 
                    SET active = 0 
                    WHERE id = ?
                ');
                $stmt->execute([$pending_credit['id']]);
                $adjustment_reason = 'pending_credit_deactivated';
            }
        } else {
            // No pending credit found, check if credit was added directly
            // This handles cases where credits might have been added immediately
            $credit_adjustment_needed = -$earned_credit;
            $adjustment_reason = 'direct_credit_subtraction';
        }
        
        // 4. Find the subscriber to adjust credit for
        $user_id = null;
        if ($purchase['subscriber_id']) {
            $user_id = $purchase['subscriber_id'];
        } else {
            // Find user by mobile if subscriber_id is null
            $stmt = $pdo->prepare('SELECT id FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$purchase['mobile']]);
            $user_id = $stmt->fetchColumn();
        }
        
        // 5. Apply credit adjustment if needed
        $current_credit = 0;
        $new_credit = 0;
        if ($user_id && $credit_adjustment_needed != 0) {
            // Get current credit
            $stmt = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ?');
            $stmt->execute([$user_id]);
            $current_credit = (float)$stmt->fetchColumn();
            
            // Calculate new credit (ensure it doesn't go negative)
            $new_credit = max(0, $current_credit + $credit_adjustment_needed);
            
            // Update subscriber credit
            $stmt = $pdo->prepare('UPDATE subscribers SET credit = ? WHERE id = ?');
            $stmt->execute([$new_credit, $user_id]);
        }
        
        // 6. Deactivate the purchase
        $stmt = $pdo->prepare('UPDATE purchases SET active = 0 WHERE id = ?');
        $stmt->execute([$purchase_id]);
        
        // 7. Log the action for audit trail
        $log_data = [
            'action' => 'purchase_deactivation',
            'purchase_id' => $purchase_id,
            'admin_mobile' => $admin_mobile,
            'subscriber_id' => $user_id,
            'mobile' => $purchase['mobile'],
            'purchase_amount' => $purchase['amount'],
            'earned_credit' => $earned_credit,
            'credit_adjustment' => $credit_adjustment_needed,
            'adjustment_reason' => $adjustment_reason,
            'current_credit' => $current_credit,
            'new_credit' => $new_credit,
            'pending_credit_id' => $pending_credit ? $pending_credit['id'] : null,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        log_credit_action($log_data);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Purchase deactivated successfully',
            'details' => [
                'purchase_id' => $purchase_id,
                'credit_adjustment' => $credit_adjustment_needed,
                'adjustment_reason' => $adjustment_reason,
                'previous_credit' => $current_credit,
                'new_credit' => $new_credit,
                'pending_credit_handled' => $pending_credit ? true : false
            ]
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deactivating purchase: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error during purchase deactivation',
            'error' => $e->getMessage(),
            'error_code' => 'DATABASE_ERROR'
        ];
    }
}

/**
 * Safely deactivate a gift credit and subtract from subscriber balance
 * 
 * @param PDO $pdo Database connection
 * @param int $gift_credit_id Gift credit ID to deactivate
 * @param string $admin_mobile Admin performing the action (for logging)
 * @return array Result with status, message, and details
 */
function deactivate_gift_credit_with_adjustment($pdo, $gift_credit_id, $admin_mobile = null) {
    try {
        $pdo->beginTransaction();
        
        // 1. Get gift credit details and verify it's active
        $stmt = $pdo->prepare('
            SELECT id, subscriber_id, mobile, gift_amount_toman, credit_amount, active, admin_number
            FROM gift_credits 
            WHERE id = ? AND active = 1 
            LIMIT 1
        ');
        $stmt->execute([$gift_credit_id]);
        $gift_credit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gift_credit) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Gift credit not found or already deactivated',
                'error_code' => 'GIFT_CREDIT_NOT_FOUND'
            ];
        }
        
        // 2. Find subscriber and get current credit
        $user_id = null;
        $current_credit = 0;
        
        if ($gift_credit['subscriber_id']) {
            $user_id = $gift_credit['subscriber_id'];
        } else {
            // Find user by mobile if subscriber_id is null
            $stmt = $pdo->prepare('SELECT id FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$gift_credit['mobile']]);
            $user_id = $stmt->fetchColumn();
        }
        
        if (!$user_id) {
            $pdo->rollBack();
            return [
                'success' => false,
                'message' => 'Subscriber not found for gift credit',
                'error_code' => 'SUBSCRIBER_NOT_FOUND'
            ];
        }
        
        // Get current credit
        $stmt = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ?');
        $stmt->execute([$user_id]);
        $current_credit = (float)$stmt->fetchColumn();
        
        // 3. Calculate new credit (subtract gift amount, ensure non-negative)
        $credit_to_subtract = (float)$gift_credit['credit_amount'];
        $new_credit = max(0, $current_credit - $credit_to_subtract);
        
        // 4. Update subscriber credit
        $stmt = $pdo->prepare('UPDATE subscribers SET credit = ? WHERE id = ?');
        $stmt->execute([$new_credit, $user_id]);
        
        // 5. Deactivate the gift credit
        $stmt = $pdo->prepare('UPDATE gift_credits SET active = 0 WHERE id = ?');
        $stmt->execute([$gift_credit_id]);
        
        // 6. Log the action for audit trail
        $log_data = [
            'action' => 'gift_credit_deactivation',
            'gift_credit_id' => $gift_credit_id,
            'admin_mobile' => $admin_mobile,
            'original_admin' => $gift_credit['admin_number'],
            'subscriber_id' => $user_id,
            'mobile' => $gift_credit['mobile'],
            'gift_amount_toman' => $gift_credit['gift_amount_toman'],
            'credit_amount' => $gift_credit['credit_amount'],
            'current_credit' => $current_credit,
            'new_credit' => $new_credit,
            'credit_adjustment' => -$credit_to_subtract,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        log_credit_action($log_data);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'Gift credit deactivated successfully',
            'details' => [
                'gift_credit_id' => $gift_credit_id,
                'credit_subtracted' => $credit_to_subtract,
                'previous_credit' => $current_credit,
                'new_credit' => $new_credit,
                'gift_amount_toman' => $gift_credit['gift_amount_toman']
            ]
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deactivating gift credit: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error during gift credit deactivation',
            'error' => $e->getMessage(),
            'error_code' => 'DATABASE_ERROR'
        ];
    }
}

/**
 * Log credit-related actions for audit trail
 * 
 * @param array $log_data Action data to log
 * @return void
 */
function log_credit_action($log_data) {
    $log_entry = json_encode($log_data, JSON_UNESCAPED_UNICODE) . "\n";
    $log_file = __DIR__ . '/logs/credit_actions.log';
    
    // Create logs directory if it doesn't exist
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    error_log("Credit Action: " . $log_data['action'] . " - " . json_encode($log_data));
}

/**
 * Ensure the active column exists in pending_credits table
 * 
 * @param PDO $pdo Database connection
 * @return bool Success status
 */
function ensure_pending_credits_active_column($pdo) {
    try {
        // Check if active column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM pending_credits LIKE 'active'");
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            // Column doesn't exist, create it
            $sql = file_get_contents(__DIR__ . '/migrations/add_active_column_pending_credits.sql');
            $pdo->exec($sql);
            error_log("Added active column to pending_credits table");
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error ensuring pending_credits active column: " . $e->getMessage());
        return false;
    }
}

/**
 * Get pending credits summary for a subscriber (active only)
 * 
 * @param PDO $pdo Database connection
 * @param int $subscriber_id Subscriber ID
 * @return array Pending credits summary
 */
function get_active_pending_credits_summary($pdo, $subscriber_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                COUNT(*) as pending_count,
                COALESCE(SUM(credit_amount), 0) as total_pending_credit,
                MIN(created_at) as oldest_pending_date
            FROM pending_credits 
            WHERE subscriber_id = ? AND transferred = 0 AND active = 1
        ');
        $stmt->execute([$subscriber_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'pending_count' => (int)$result['pending_count'],
            'total_pending_credit' => (float)$result['total_pending_credit'],
            'oldest_pending_date' => $result['oldest_pending_date']
        ];
    } catch (Exception $e) {
        error_log("Error getting pending credits summary: " . $e->getMessage());
        return [
            'pending_count' => 0,
            'total_pending_credit' => 0,
            'oldest_pending_date' => null
        ];
    }
}
?>