<?php
/**
 * Transaction deletion functionality
 * This file contains functions for handling controlled deletion of transactions
 */

require_once 'credit_deactivation_utils.php';

/**
 * Check if a user has permission to delete a transaction
 * 
 * @param array $transaction The transaction data
 * @param string $admin_mobile The mobile number of the admin user
 * @param bool $is_manager Whether the admin has manager role
 * @return array ['allowed' => bool, 'message' => string]
 */
function checkDeletePermission($transaction, $admin_mobile, $is_manager) {
    // Managers can delete any transaction without time restrictions
    if ($is_manager) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // For sellers: check ownership
    $transaction_admin = isset($transaction['admin_mobile']) ? $transaction['admin_mobile'] : $transaction['admin_number'];
    $is_owner = ($transaction_admin === $admin_mobile);
    
    if (!$is_owner) {
        return [
            'allowed' => false, 
            'message' => 'شما فقط مجاز به حذف تراکنش‌های ثبت شده توسط خودتان هستید'
        ];
    }
    
    // For sellers: check time restriction (6 hours)
    $transaction_time = strtotime($transaction['date']);
    $current_time = time();
    $hours_passed = ($current_time - $transaction_time) / 3600;
    
    if ($hours_passed > 6) {
        // Log this attempt
        notifyManagers($transaction, $admin_mobile, 'time_expired');
        
        return [
            'allowed' => false, 
            'message' => 'بیش از ۶ ساعت از ایجاد این تراکنش گذشته است. مدیران مطلع شدند.'
        ];
    }
    
    return ['allowed' => true, 'message' => ''];
}

/**
 * Perform soft delete on a transaction
 * 
 * @param int $transaction_id The ID of the transaction
 * @param string $transaction_type 'purchase' or 'credit'
 * @param PDO $pdo Database connection
 * @return bool True if deletion was successful
 */
function deleteTransaction($transaction_id, $transaction_type, $pdo, $admin_mobile = null) {
    try {
        if ($transaction_type === 'purchase') {
            // Check if the new credit deactivation utilities are available
            if (!file_exists(__DIR__ . '/credit_deactivation_utils.php')) {
                // Fallback to old logic if new utilities are not available
                error_log('credit_deactivation_utils.php not found, using fallback deletion logic');
                return deleteTransactionFallback($transaction_id, $pdo);
            }
            
            require_once __DIR__ . '/credit_deactivation_utils.php';
            
            // Use the new improved purchase deactivation logic
            $result = deactivate_purchase_with_credit_adjustment($pdo, $transaction_id, $admin_mobile);
            return $result['success'];
        } else {
            // For credit usage, just perform soft delete (no credit adjustment needed)
            $pdo->beginTransaction();
            
            // Check if active column exists in credit_usage table
            $column_check = $pdo->query("SHOW COLUMNS FROM credit_usage LIKE 'active'");
            $has_active_column = $column_check->rowCount() > 0;
            
            if ($has_active_column) {
                $stmt = $pdo->prepare('UPDATE credit_usage SET active = 0 WHERE id = ?');
            } else {
                // Add the column if it doesn't exist
                try {
                    $pdo->exec("ALTER TABLE credit_usage ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
                    $stmt = $pdo->prepare('UPDATE credit_usage SET active = 0 WHERE id = ?');
                } catch (Exception $e) {
                    error_log("Could not add active column to credit_usage table: " . $e->getMessage());
                    $pdo->rollBack();
                    return false;
                }
            }
            
            $stmt->execute([$transaction_id]);
            $success = $stmt->rowCount() > 0;
            
            if ($success) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
            
            return $success;
        }
    } catch (Exception $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Exception $rollbackException) {
            // Log rollback failure but don't throw
            error_log('Rollback failed in deleteTransaction: ' . $rollbackException->getMessage());
        }
        
        error_log('Delete transaction error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fallback deletion function for purchases (old logic)
 * Used when the new credit deactivation utilities are not available
 * 
 * @param int $transaction_id Purchase ID
 * @param PDO $pdo Database connection
 * @return bool Success status
 */
function deleteTransactionFallback($transaction_id, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Check if active column exists in purchases table
        $column_check = $pdo->query("SHOW COLUMNS FROM purchases LIKE 'active'");
        $has_active_column = $column_check->rowCount() > 0;
        
        if ($has_active_column) {
            // Get purchase details before deletion (with active column)
            $stmt = $pdo->prepare('SELECT subscriber_id, mobile, amount FROM purchases WHERE id = ? AND active = 1 LIMIT 1');
        } else {
            // Get purchase details before deletion (without active column)
            $stmt = $pdo->prepare('SELECT subscriber_id, mobile, amount FROM purchases WHERE id = ? LIMIT 1');
        }
        
        $stmt->execute([$transaction_id]);
        $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$purchase) {
            $pdo->rollBack();
            return false; // Purchase not found or already deleted
        }
        
        // Calculate the credit points that were earned from this purchase
        $creditToSubtract = round(((float)$purchase['amount'])/100000.0, 1);
        
        if ($creditToSubtract > 0) {
            // Find the user to subtract credit from
            $user_id = null;
            if ($purchase['subscriber_id']) {
                $user_id = $purchase['subscriber_id'];
            } else {
                // If subscriber_id is null, find user by mobile
                $stmt = $pdo->prepare('SELECT id FROM subscribers WHERE mobile = ? LIMIT 1');
                $stmt->execute([$purchase['mobile']]);
                $user_id = $stmt->fetchColumn();
            }
            
            if ($user_id) {
                // Subtract the earned credit from user's total credit
                $stmt = $pdo->prepare('UPDATE subscribers SET credit = GREATEST(0, credit - ?) WHERE id = ?');
                $stmt->execute([$creditToSubtract, $user_id]);
            }
        }
        
        // Perform the soft delete
        if ($has_active_column) {
            $stmt = $pdo->prepare('UPDATE purchases SET active = 0 WHERE id = ?');
        } else {
            // If no active column, we need to add it first, or use a different approach
            // Let's add the column dynamically
            try {
                $pdo->exec("ALTER TABLE purchases ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
                $stmt = $pdo->prepare('UPDATE purchases SET active = 0 WHERE id = ?');
            } catch (Exception $e) {
                // If we can't add the column, we can't do soft delete
                // This is a fallback - you might want to handle this differently
                error_log("Could not add active column to purchases table: " . $e->getMessage());
                $pdo->rollBack();
                return false;
            }
        }
        
        $stmt->execute([$transaction_id]);
        
        $success = $stmt->rowCount() > 0;
        
        if ($success) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        
        return $success;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Fallback delete transaction error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Notify all managers about a transaction deletion event
 * 
 * @param array $transaction The transaction data
 * @param string $admin_mobile The mobile number of the admin user
 * @param string $reason Reason for notification (e.g., 'time_expired')
 * @return void
 */
function notifyManagers($transaction, $admin_mobile, $reason) {
    // Log the event
    $admin_name = function_exists('get_admin_name') ? get_admin_name($admin_mobile) : $admin_mobile;
    $transaction_type = isset($transaction['type']) ? $transaction['type'] : 'purchase';
    $transaction_id = isset($transaction['id']) ? $transaction['id'] : 'unknown';
    $transaction_amount = isset($transaction['amount']) ? $transaction['amount'] : 'unknown';
    $transaction_date = isset($transaction['date']) ? $transaction['date'] : 'unknown';
    
    $log_message = sprintf(
        "Delete attempt blocked: Admin %s (%s) tried to delete %s transaction #%s of amount %s from %s. Reason: %s",
        $admin_name,
        $admin_mobile,
        $transaction_type,
        $transaction_id,
        $transaction_amount,
        $transaction_date,
        $reason
    );
    
    error_log($log_message);
    
    // In a more advanced implementation, you could send SMS or email notifications to managers
    // This would require fetching all manager phone numbers from ADMIN_ALLOWED
}