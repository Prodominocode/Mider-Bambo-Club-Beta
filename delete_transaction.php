<?php
/**
 * Transaction deletion functionality
 * This file contains functions for handling controlled deletion of transactions
 */

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
function deleteTransaction($transaction_id, $transaction_type, $pdo) {
    try {
        $pdo->beginTransaction();
        
        if ($transaction_type === 'purchase') {
            // First, get the purchase details before deletion
            $stmt = $pdo->prepare('SELECT subscriber_id, mobile, amount FROM purchases WHERE id = ? AND active = 1 LIMIT 1');
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
            $stmt = $pdo->prepare('UPDATE purchases SET active = 0 WHERE id = ?');
            $stmt->execute([$transaction_id]);
        } else {
            // For credit usage, just perform soft delete (no credit adjustment needed)
            $stmt = $pdo->prepare('UPDATE credit_usage SET active = 0 WHERE id = ?');
            $stmt->execute([$transaction_id]);
        }
        
        $success = $stmt->rowCount() > 0;
        
        if ($success) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        
        return $success;
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
 * Notify all managers about a transaction deletion event
 * 
 * @param array $transaction The transaction data
 * @param string $admin_mobile The mobile number of the admin user
 * @param string $reason Reason for notification (e.g., 'time_expired')
 * @return void
 */
function notifyManagers($transaction, $admin_mobile, $reason) {
    // Log the event
    $admin_name = get_admin_name($admin_mobile);
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