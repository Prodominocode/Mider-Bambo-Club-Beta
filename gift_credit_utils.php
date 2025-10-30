<?php
// Gift Credit management functions
// Functions for managing gift credits for subscribers

require_once 'db.php';

/**
 * Check if the admin has permission to manage gift credits
 * Only specific manager numbers can manage gift credits
 *
 * @param string $admin_mobile Admin mobile number
 * @return bool True if admin can manage gift credits
 */
function can_manage_gift_credits($admin_mobile) {
    $allowed_managers = ['09119246366', '09194467966','09119012010'];
    return in_array(norm_digits($admin_mobile), $allowed_managers);
}

/**
 * Normalize digits - use the existing function from admin.php
 * This function is already defined in admin.php, so we don't redefine it here
 */
if (!function_exists('norm_digits')) {
    function norm_digits($s) {
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $s = str_replace($persian, $latin, $s);
        $s = preg_replace('/\s+/', '', $s);
        return $s;
    }
}

/**
 * Calculate credit amount from Toman amount
 * Divides Toman amount by 5000 to get credit
 *
 * @param float $toman_amount Amount in Toman
 * @return float Credit amount
 */
function calculate_gift_credit($toman_amount) {
    if ($toman_amount <= 0) {
        return 0.0;
    }
    return round($toman_amount / 5000, 2);
}

/**
 * Add a new gift credit for a subscriber
 *
 * @param string $admin_mobile Admin mobile number (who is giving the gift)
 * @param string $subscriber_mobile Subscriber mobile number
 * @param float $gift_amount_toman Gift amount in Toman
 * @param string $notes Optional notes about the gift
 * @return array Result with status and message
 */
function add_gift_credit($admin_mobile, $subscriber_mobile, $gift_amount_toman, $notes = '') {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت اعتبار هدیه نیستید'];
    }
    
    // Validate input
    if (empty($subscriber_mobile)) {
        return ['status' => 'error', 'message' => 'شماره موبایل الزامی است'];
    }
    
    if ($gift_amount_toman <= 0) {
        return ['status' => 'error', 'message' => 'مبلغ هدیه باید بیشتر از صفر باشد'];
    }
    
    $subscriber_mobile = norm_digits($subscriber_mobile);
    
    try {
        $pdo->beginTransaction();
        
        // Check if subscriber exists, if not create one
        $stmt = $pdo->prepare("SELECT id, full_name, credit FROM subscribers WHERE mobile = ?");
        $stmt->execute([$subscriber_mobile]);
        $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $subscriber_id = null;
        $current_credit = 0;
        
        if ($subscriber) {
            $subscriber_id = $subscriber['id'];
            $current_credit = $subscriber['credit'];
        } else {
            // Create new subscriber if they don't exist
            $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, admin_number, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$subscriber_mobile, norm_digits($admin_mobile)]);
            $subscriber_id = $pdo->lastInsertId();
            $current_credit = 10; // Default starting credit
        }
        
        // Calculate credit amount
        $credit_amount = calculate_gift_credit($gift_amount_toman);
        
        // Insert gift credit record
        $stmt = $pdo->prepare("
            INSERT INTO gift_credits (subscriber_id, mobile, gift_amount_toman, credit_amount, admin_number, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $subscriber_id,
            $subscriber_mobile,
            $gift_amount_toman,
            $credit_amount,
            norm_digits($admin_mobile),
            $notes ?: null
        ]);
        
        if (!$result) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'خطا در ثبت اعتبار هدیه'];
        }
        
        // Update subscriber's total credit
        $new_credit = $current_credit + $credit_amount;
        $stmt = $pdo->prepare("UPDATE subscribers SET credit = ? WHERE id = ?");
        $update_result = $stmt->execute([$new_credit, $subscriber_id]);
        
        if (!$update_result) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'خطا در بروزرسانی اعتبار کاربر'];
        }
        
        $pdo->commit();
        
        return [
            'status' => 'success', 
            'message' => 'اعتبار هدیه با موفقیت اضافه شد',
            'gift_id' => $pdo->lastInsertId(),
            'credit_amount' => $credit_amount,
            'new_total_credit' => $new_credit
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error adding gift credit: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Get all gift credits with optional filtering
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @param string|null $subscriber_mobile Optional: filter by subscriber mobile
 * @param bool $active_only Whether to show only active gift credits
 * @param int $limit Limit number of results
 * @param int $offset Offset for pagination
 * @return array List of gift credits
 */
function get_gift_credits($admin_mobile, $subscriber_mobile = null, $active_only = true, $limit = 100, $offset = 0) {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return [];
    }
    
    try {
        $sql = "
            SELECT gc.*, s.full_name as subscriber_name
            FROM gift_credits gc
            LEFT JOIN subscribers s ON gc.subscriber_id = s.id
            WHERE 1=1
        ";
        $params = [];
        
        if ($active_only) {
            $sql .= " AND gc.active = 1";
        }
        
        if ($subscriber_mobile) {
            $sql .= " AND gc.mobile = ?";
            $params[] = norm_digits($subscriber_mobile);
        }
        
        $sql .= " ORDER BY gc.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $gift_credits = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $gift_credits[] = [
                'id' => (int)$row['id'],
                'subscriber_id' => $row['subscriber_id'] ? (int)$row['subscriber_id'] : null,
                'mobile' => $row['mobile'],
                'subscriber_name' => $row['subscriber_name'] ?: 'نام نامشخص',
                'gift_amount_toman' => (float)$row['gift_amount_toman'],
                'credit_amount' => (float)$row['credit_amount'],
                'admin_number' => $row['admin_number'],
                'notes' => $row['notes'],
                'active' => (bool)$row['active'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        return $gift_credits;
        
    } catch (Exception $e) {
        error_log("Error getting gift credits: " . $e->getMessage());
        return [];
    }
}

/**
 * Get gift credits for a specific subscriber
 *
 * @param string $subscriber_mobile Subscriber mobile number
 * @param bool $active_only Whether to show only active gift credits
 * @return array List of gift credits for the subscriber
 */
function get_gift_credits_for_subscriber($subscriber_mobile, $active_only = true) {
    global $pdo;
    
    if (empty($subscriber_mobile)) {
        return [];
    }
    
    $subscriber_mobile = norm_digits($subscriber_mobile);
    
    try {
        $sql = "
            SELECT gc.*, s.full_name as subscriber_name
            FROM gift_credits gc
            LEFT JOIN subscribers s ON gc.subscriber_id = s.id
            WHERE gc.mobile = ?
        ";
        $params = [$subscriber_mobile];
        
        if ($active_only) {
            $sql .= " AND gc.active = 1";
        }
        
        $sql .= " ORDER BY gc.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $gift_credits = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $gift_credits[] = [
                'id' => (int)$row['id'],
                'gift_amount_toman' => (float)$row['gift_amount_toman'],
                'credit_amount' => (float)$row['credit_amount'],
                'admin_number' => $row['admin_number'],
                'notes' => $row['notes'],
                'active' => (bool)$row['active'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        return $gift_credits;
        
    } catch (Exception $e) {
        error_log("Error getting gift credits for subscriber: " . $e->getMessage());
        return [];
    }
}

/**
 * Disable/remove a gift credit (soft delete)
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @param int $gift_credit_id Gift credit ID to disable
 * @param bool $refund_credit Whether to refund the credit from subscriber's account
 * @return array Result with status and message
 */
function disable_gift_credit($admin_mobile, $gift_credit_id, $refund_credit = false) {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت اعتبار هدیه نیستید'];
    }
    
    if (!is_numeric($gift_credit_id) || $gift_credit_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه اعتبار هدیه معتبر نیست'];
    }
    
    try {
        $pdo->beginTransaction();
        
        // Get gift credit details
        $stmt = $pdo->prepare("
            SELECT gc.*, s.credit as subscriber_credit
            FROM gift_credits gc
            LEFT JOIN subscribers s ON gc.subscriber_id = s.id
            WHERE gc.id = ? AND gc.active = 1
        ");
        $stmt->execute([$gift_credit_id]);
        $gift_credit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$gift_credit) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'اعتبار هدیه یافت نشد یا قبلاً غیرفعال شده است'];
        }
        
        // Disable the gift credit
        $stmt = $pdo->prepare("
            UPDATE gift_credits 
            SET active = 0, updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$gift_credit_id]);
        
        if (!$result) {
            $pdo->rollBack();
            return ['status' => 'error', 'message' => 'خطا در غیرفعال کردن اعتبار هدیه'];
        }
        
        // Refund credit if requested and subscriber exists
        if ($refund_credit && $gift_credit['subscriber_id']) {
            $new_credit = $gift_credit['subscriber_credit'] - $gift_credit['credit_amount'];
            $new_credit = max(0, $new_credit); // Don't go below 0
            
            $stmt = $pdo->prepare("UPDATE subscribers SET credit = ? WHERE id = ?");
            $refund_result = $stmt->execute([$new_credit, $gift_credit['subscriber_id']]);
            
            if (!$refund_result) {
                $pdo->rollBack();
                return ['status' => 'error', 'message' => 'خطا در بازگشت اعتبار به حساب کاربر'];
            }
        }
        
        $pdo->commit();
        
        $message = 'اعتبار هدیه با موفقیت غیرفعال شد';
        if ($refund_credit && $gift_credit['subscriber_id']) {
            $message .= ' و اعتبار از حساب کاربر کسر شد';
        }
        
        return ['status' => 'success', 'message' => $message];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error disabling gift credit: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Get total gift credits for a subscriber
 *
 * @param string $subscriber_mobile Subscriber mobile number
 * @param bool $active_only Whether to count only active gift credits
 * @return float Total gift credit amount
 */
function get_total_gift_credits_for_subscriber($subscriber_mobile, $active_only = true) {
    global $pdo;
    
    if (empty($subscriber_mobile)) {
        return 0.0;
    }
    
    $subscriber_mobile = norm_digits($subscriber_mobile);
    
    try {
        $sql = "SELECT SUM(credit_amount) as total FROM gift_credits WHERE mobile = ?";
        $params = [$subscriber_mobile];
        
        if ($active_only) {
            $sql .= " AND active = 1";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'] ? (float)$result['total'] : 0.0;
        
    } catch (Exception $e) {
        error_log("Error getting total gift credits: " . $e->getMessage());
        return 0.0;
    }
}

/**
 * Update gift credit notes
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @param int $gift_credit_id Gift credit ID
 * @param string $notes New notes
 * @return array Result with status and message
 */
function update_gift_credit_notes($admin_mobile, $gift_credit_id, $notes) {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت اعتبار هدیه نیستید'];
    }
    
    if (!is_numeric($gift_credit_id) || $gift_credit_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه اعتبار هدیه معتبر نیست'];
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE gift_credits 
            SET notes = ?, updated_at = NOW()
            WHERE id = ? AND active = 1
        ");
        
        $result = $stmt->execute([$notes ?: null, $gift_credit_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['status' => 'success', 'message' => 'یادداشت با موفقیت بروزرسانی شد'];
        } else {
            return ['status' => 'error', 'message' => 'اعتبار هدیه یافت نشد یا خطا در بروزرسانی'];
        }
        
    } catch (Exception $e) {
        error_log("Error updating gift credit notes: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Deactivate a gift credit and adjust subscriber balance
 *
 * @param string $admin_mobile Admin mobile number (who is deactivating)
 * @param int $gift_credit_id Gift credit ID to deactivate
 * @param string $deactivation_reason Optional reason for deactivation
 * @return array Result with status and message
 */
function deactivate_gift_credit($admin_mobile, $gift_credit_id, $deactivation_reason = '') {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت اعتبار هدیه نیستید'];
    }
    
    // Validate input
    if (!is_numeric($gift_credit_id) || $gift_credit_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه اعتبار هدیه معتبر نیست'];
    }
    
    require_once 'credit_deactivation_utils.php';
    
    $result = deactivate_gift_credit_with_adjustment($pdo, $gift_credit_id, $admin_mobile);
    
    if ($result['success']) {
        return [
            'status' => 'success', 
            'message' => 'اعتبار هدیه با موفقیت غیرفعال شد و اعتبار کاربر تنظیم گردید',
            'details' => $result['details']
        ];
    } else {
        return ['status' => 'error', 'message' => $result['message']];
    }
}

/**
 * Get gift credit statistics
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @return array Statistics about gift credits
 */
function get_gift_credit_statistics($admin_mobile) {
    global $pdo;
    
    if (!can_manage_gift_credits($admin_mobile)) {
        return [];
    }
    
    try {
        // Total active gift credits
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, SUM(credit_amount) as total_credit, SUM(gift_amount_toman) as total_toman FROM gift_credits WHERE active = 1");
        $stmt->execute();
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Gift credits by admin
        $stmt = $pdo->prepare("
            SELECT admin_number, COUNT(*) as count, SUM(credit_amount) as total_credit 
            FROM gift_credits 
            WHERE active = 1 
            GROUP BY admin_number 
            ORDER BY total_credit DESC
        ");
        $stmt->execute();
        $by_admin = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recent gift credits (last 30 days)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, SUM(credit_amount) as total_credit 
            FROM gift_credits 
            WHERE active = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $recent = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'total_active_count' => (int)$totals['count'],
            'total_credit_amount' => (float)$totals['total_credit'],
            'total_toman_amount' => (float)$totals['total_toman'],
            'recent_30_days' => [
                'count' => (int)$recent['count'],
                'total_credit' => (float)$recent['total_credit']
            ],
            'by_admin' => $by_admin
        ];
        
    } catch (Exception $e) {
        error_log("Error getting gift credit statistics: " . $e->getMessage());
        return [];
    }
}
?>