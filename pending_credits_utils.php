<?php
/**
 * Pending Credits Utility Functions
 * Handles the 48-hour pending credit system
 */

/**
 * Add pending credit for a purchase
 * 
 * @param PDO $pdo Database connection
 * @param int $subscriber_id Subscriber ID
 * @param string $mobile Mobile number
 * @param int $purchase_id Purchase ID
 * @param float $credit_amount Credit amount in points
 * @param int $branch_id Branch ID
 * @param int $sales_center_id Sales center ID
 * @param string $admin_number Admin number
 * @return bool Success status
 */
function add_pending_credit($pdo, $subscriber_id, $mobile, $purchase_id, $credit_amount, $branch_id = null, $sales_center_id = null, $admin_number = null) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO pending_credits (subscriber_id, mobile, purchase_id, credit_amount, branch_id, sales_center_id, admin_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$subscriber_id, $mobile, $purchase_id, $credit_amount, $branch_id, $sales_center_id, $admin_number]);
        return true;
    } catch (Exception $e) {
        error_log("Error adding pending credit: " . $e->getMessage());
        return false;
    }
}

/**
 * Process and transfer pending credits that have exceeded 48 hours
 * 
 * @param PDO $pdo Database connection
 * @param int $subscriber_id Optional: process only for specific subscriber
 * @return array Results with transferred credits count and details
 */
function process_pending_credits($pdo, $subscriber_id = null) {
    try {
        $pdo->beginTransaction();
        
        // Find pending credits older than 48 hours
        $where_clause = "WHERE transferred = 0 AND created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR)";
        $params = [];
        
        if ($subscriber_id) {
            $where_clause .= " AND subscriber_id = ?";
            $params[] = $subscriber_id;
        }
        
        $stmt = $pdo->prepare("
            SELECT id, subscriber_id, mobile, credit_amount, created_at 
            FROM pending_credits 
            $where_clause
            ORDER BY created_at ASC
        ");
        $stmt->execute($params);
        $pending_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $transferred_count = 0;
        $transferred_amount = 0;
        $transferred_details = [];
        
        foreach ($pending_credits as $pending) {
            // Transfer credit to main balance
            $update_stmt = $pdo->prepare('UPDATE subscribers SET credit = credit + ? WHERE id = ?');
            $update_stmt->execute([$pending['credit_amount'], $pending['subscriber_id']]);
            
            // Mark as transferred
            $mark_stmt = $pdo->prepare('
                UPDATE pending_credits 
                SET transferred = 1, transferred_at = NOW() 
                WHERE id = ?
            ');
            $mark_stmt->execute([$pending['id']]);
            
            $transferred_count++;
            $transferred_amount += $pending['credit_amount'];
            $transferred_details[] = [
                'id' => $pending['id'],
                'subscriber_id' => $pending['subscriber_id'],
                'mobile' => $pending['mobile'],
                'amount' => $pending['credit_amount'],
                'created_at' => $pending['created_at']
            ];
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'transferred_count' => $transferred_count,
            'transferred_amount' => $transferred_amount,
            'details' => $transferred_details
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error processing pending credits: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get pending credits for a subscriber
 * 
 * @param PDO $pdo Database connection
 * @param int $subscriber_id Subscriber ID
 * @return array Pending credits data
 */
function get_pending_credits($pdo, $subscriber_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT id, credit_amount, created_at, purchase_id, branch_id, sales_center_id
            FROM pending_credits 
            WHERE subscriber_id = ? AND transferred = 0
            ORDER BY created_at DESC
        ');
        $stmt->execute([$subscriber_id]);
        $pending_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_pending = 0;
        foreach ($pending_credits as $credit) {
            $total_pending += $credit['credit_amount'];
        }
        
        return [
            'total_pending' => $total_pending,
            'pending_credits' => $pending_credits
        ];
    } catch (Exception $e) {
        error_log("Error getting pending credits: " . $e->getMessage());
        return [
            'total_pending' => 0,
            'pending_credits' => []
        ];
    }
}

/**
 * Get pending credits by mobile number
 * 
 * @param PDO $pdo Database connection
 * @param string $mobile Mobile number
 * @return array Pending credits data
 */
function get_pending_credits_by_mobile($pdo, $mobile) {
    try {
        $stmt = $pdo->prepare('
            SELECT pc.id, pc.credit_amount, pc.created_at, pc.purchase_id, pc.branch_id, pc.sales_center_id, s.id as subscriber_id
            FROM pending_credits pc
            JOIN subscribers s ON pc.subscriber_id = s.id
            WHERE pc.mobile = ? AND pc.transferred = 0
            ORDER BY pc.created_at DESC
        ');
        $stmt->execute([$mobile]);
        $pending_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_pending = 0;
        foreach ($pending_credits as $credit) {
            $total_pending += $credit['credit_amount'];
        }
        
        return [
            'total_pending' => $total_pending,
            'pending_credits' => $pending_credits
        ];
    } catch (Exception $e) {
        error_log("Error getting pending credits by mobile: " . $e->getMessage());
        return [
            'total_pending' => 0,
            'pending_credits' => []
        ];
    }
}

/**
 * Get combined credit information (available + pending) for a subscriber
 * 
 * @param PDO $pdo Database connection
 * @param int $subscriber_id Subscriber ID
 * @return array Combined credit data
 */
function get_combined_credits($pdo, $subscriber_id) {
    try {
        // Get available credit
        $stmt = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriber_id]);
        $available_credit = (float)$stmt->fetchColumn();
        
        // Get pending credits
        $pending_data = get_pending_credits($pdo, $subscriber_id);
        
        // Process any eligible pending credits first
        process_pending_credits($pdo, $subscriber_id);
        
        // Re-fetch after processing
        $stmt = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriber_id]);
        $available_credit = (float)$stmt->fetchColumn();
        
        $pending_data = get_pending_credits($pdo, $subscriber_id);
        
        return [
            'available_credit' => $available_credit,
            'pending_credit' => $pending_data['total_pending'],
            'available_credit_toman' => (int)($available_credit * 5000),
            'pending_credit_toman' => (int)($pending_data['total_pending'] * 5000),
            'total_credit_toman' => (int)(($available_credit + $pending_data['total_pending']) * 5000),
            'pending_details' => $pending_data['pending_credits']
        ];
    } catch (Exception $e) {
        error_log("Error getting combined credits: " . $e->getMessage());
        return [
            'available_credit' => 0,
            'pending_credit' => 0,
            'available_credit_toman' => 0,
            'pending_credit_toman' => 0,
            'total_credit_toman' => 0,
            'pending_details' => []
        ];
    }
}

/**
 * Get combined credit information by mobile number
 * 
 * @param PDO $pdo Database connection
 * @param string $mobile Mobile number
 * @return array Combined credit data
 */
function get_combined_credits_by_mobile($pdo, $mobile) {
    try {
        // Get subscriber info
        $stmt = $pdo->prepare('SELECT id, credit FROM subscribers WHERE mobile = ?');
        $stmt->execute([$mobile]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subscriber) {
            return [
                'available_credit' => 0,
                'pending_credit' => 0,
                'available_credit_toman' => 0,
                'pending_credit_toman' => 0,
                'total_credit_toman' => 0,
                'pending_details' => []
            ];
        }
        
        // Process any eligible pending credits first
        process_pending_credits($pdo, $subscriber['id']);
        
        // Re-fetch after processing
        $stmt = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriber['id']]);
        $available_credit = (float)$stmt->fetchColumn();
        
        $pending_data = get_pending_credits_by_mobile($pdo, $mobile);
        
        return [
            'available_credit' => $available_credit,
            'pending_credit' => $pending_data['total_pending'],
            'available_credit_toman' => (int)($available_credit * 5000),
            'pending_credit_toman' => (int)($pending_data['total_pending'] * 5000),
            'total_credit_toman' => (int)(($available_credit + $pending_data['total_pending']) * 5000),
            'pending_details' => $pending_data['pending_credits']
        ];
    } catch (Exception $e) {
        error_log("Error getting combined credits by mobile: " . $e->getMessage());
        return [
            'available_credit' => 0,
            'pending_credit' => 0,
            'available_credit_toman' => 0,
            'pending_credit_toman' => 0,
            'total_credit_toman' => 0,
            'pending_details' => []
        ];
    }
}

/**
 * Check if pending_credits table exists
 * 
 * @param PDO $pdo Database connection
 * @return bool Table exists status
 */
function pending_credits_table_exists($pdo) {
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'pending_credits'");
        $stmt->execute();
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create pending_credits table if it doesn't exist
 * 
 * @param PDO $pdo Database connection
 * @return bool Success status
 */
function ensure_pending_credits_table($pdo) {
    if (pending_credits_table_exists($pdo)) {
        return true;
    }
    
    try {
        $sql = file_get_contents(__DIR__ . '/migrations/create_pending_credits_table.sql');
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        error_log("Error creating pending_credits table: " . $e->getMessage());
        return false;
    }
}