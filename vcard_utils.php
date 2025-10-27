<?php
// Virtual Card (vCard) management functions
// Functions for managing virtual card users

require_once 'db.php';

/**
 * Check if the admin has permission to manage virtual cards
 * Using the same permission structure as advisors for consistency
 *
 * @param string $admin_mobile Admin mobile number
 * @return bool True if admin can manage virtual cards
 */
function can_manage_vcards($admin_mobile) {
    // Same permissions as advisor management
    $allowed_managers = ['09119246366', '09194467966'];
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
 * Generate a unique 11-digit mobile number starting with 111
 * Used for vCard users when mobile number is not provided
 *
 * @return string Generated mobile number
 */
function generate_vcard_mobile() {
    global $pdo;
    
    $max_attempts = 100;
    $attempts = 0;
    
    while ($attempts < $max_attempts) {
        // Generate 8 random digits after 111
        $random_digits = '';
        for ($i = 0; $i < 8; $i++) {
            $random_digits .= rand(0, 9);
        }
        
        $mobile = '111' . $random_digits;
        
        // Check if this mobile number already exists
        $stmt = $pdo->prepare("SELECT id FROM subscribers WHERE mobile = ?");
        $stmt->execute([$mobile]);
        
        if (!$stmt->fetch()) {
            return $mobile;
        }
        
        $attempts++;
    }
    
    throw new Exception('Unable to generate unique mobile number after ' . $max_attempts . ' attempts');
}

/**
 * Validate vCard number format
 *
 * @param string $vcard_number The vCard number to validate
 * @return bool True if valid, false otherwise
 */
function validate_vcard_number($vcard_number) {
    $normalized = norm_digits($vcard_number);
    return preg_match('/^[0-9]{16}$/', $normalized);
}

/**
 * Check if vCard number already exists
 *
 * @param string $vcard_number The vCard number to check
 * @param int|null $exclude_id Exclude this subscriber ID from check (for updates)
 * @return bool True if exists, false otherwise
 */
function vcard_number_exists($vcard_number, $exclude_id = null) {
    global $pdo;
    
    $normalized = norm_digits($vcard_number);
    
    $sql = "SELECT id FROM subscribers WHERE vcard_number = ?";
    $params = [$normalized];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return (bool) $stmt->fetch();
}

/**
 * Create a new virtual card user
 *
 * @param string $vcard_number 16-digit vCard number (required)
 * @param string|null $mobile_number Mobile number (optional, will generate if not provided)
 * @param string|null $full_name Full name (optional)
 * @param int $credit_amount Credit amount in Toman (will be divided by 5000)
 * @return array Result array with success status and message
 */
function create_vcard_user($vcard_number, $mobile_number = null, $full_name = null, $credit_amount = 0) {
    global $pdo;
    
    try {
        // Validate vCard number
        if (!validate_vcard_number($vcard_number)) {
            return ['success' => false, 'message' => 'شماره کارت باید دقیقاً 16 رقم باشد'];
        }
        
        $normalized_vcard = norm_digits($vcard_number);
        
        // Check if vCard number already exists
        if (vcard_number_exists($normalized_vcard)) {
            return ['success' => false, 'message' => 'این شماره کارت قبلاً ثبت شده است'];
        }
        
        // Generate mobile number if not provided
        if (empty($mobile_number)) {
            $mobile_number = generate_vcard_mobile();
        } else {
            $mobile_number = norm_digits($mobile_number);
            
            // Check if mobile number already exists
            $stmt = $pdo->prepare("SELECT id FROM subscribers WHERE mobile = ?");
            $stmt->execute([$mobile_number]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'این شماره موبایل قبلاً ثبت شده است'];
            }
        }
        
        // Calculate credit (divide by 5000)
        $credit = intval($credit_amount / 5000);
        
        // Insert the new vCard user
        $stmt = $pdo->prepare("
            INSERT INTO subscribers (vcard_number, mobile, full_name, credit, verified, created_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        
        $stmt->execute([
            $normalized_vcard,
            $mobile_number,
            $full_name ?: null,
            $credit
        ]);
        
        $user_id = $pdo->lastInsertId();
        
        return [
            'success' => true, 
            'message' => 'کارت مجازی با موفقیت ایجاد شد',
            'user_id' => $user_id,
            'mobile' => $mobile_number,
            'credit' => $credit
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطا در ایجاد کارت مجازی: ' . $e->getMessage()];
    }
}

/**
 * Get all virtual card users
 *
 * @return array List of vCard users
 */
function get_all_vcard_users() {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, vcard_number, mobile, full_name, credit, created_at
            FROM subscribers 
            WHERE vcard_number IS NOT NULL 
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get vCard user by card number
 *
 * @param string $vcard_number 16-digit vCard number
 * @return array|null User data or null if not found
 */
function get_vcard_user_by_number($vcard_number) {
    global $pdo;
    
    $normalized = norm_digits($vcard_number);
    
    if (!validate_vcard_number($normalized)) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, vcard_number, mobile, full_name, credit, created_at
            FROM subscribers 
            WHERE vcard_number = ?
        ");
        $stmt->execute([$normalized]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get credit usage history for a vCard user
 *
 * @param string $mobile_number Mobile number of the vCard user
 * @return array List of credit usage records
 */
function get_vcard_credit_history($mobile_number) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT amount, credit_value, datetime, is_refund, admin_mobile
            FROM credit_usage 
            WHERE user_mobile = ? 
            ORDER BY datetime DESC
        ");
        $stmt->execute([$mobile_number]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update vCard user information
 *
 * @param int $user_id User ID
 * @param string $vcard_number 16-digit vCard number
 * @param string|null $mobile_number Mobile number
 * @param string|null $full_name Full name
 * @param int $credit_amount Credit amount in Toman
 * @return array Result array with success status and message
 */
function update_vcard_user($user_id, $vcard_number, $mobile_number = null, $full_name = null, $credit_amount = 0) {
    global $pdo;
    
    try {
        // Validate vCard number
        if (!validate_vcard_number($vcard_number)) {
            return ['success' => false, 'message' => 'شماره کارت باید دقیقاً 16 رقم باشد'];
        }
        
        $normalized_vcard = norm_digits($vcard_number);
        
        // Check if vCard number already exists (excluding current user)
        if (vcard_number_exists($normalized_vcard, $user_id)) {
            return ['success' => false, 'message' => 'این شماره کارت توسط کاربر دیگری استفاده می‌شود'];
        }
        
        // Validate mobile number if provided
        if (!empty($mobile_number)) {
            $mobile_number = norm_digits($mobile_number);
            
            // Check if mobile number already exists (excluding current user)
            $stmt = $pdo->prepare("SELECT id FROM subscribers WHERE mobile = ? AND id != ?");
            $stmt->execute([$mobile_number, $user_id]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'این شماره موبایل توسط کاربر دیگری استفاده می‌شود'];
            }
        }
        
        // Calculate credit (divide by 5000)
        $credit = intval($credit_amount / 5000);
        
        // Update the vCard user
        $stmt = $pdo->prepare("
            UPDATE subscribers 
            SET vcard_number = ?, mobile = ?, full_name = ?, credit = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $normalized_vcard,
            $mobile_number,
            $full_name ?: null,
            $credit,
            $user_id
        ]);
        
        return [
            'success' => true, 
            'message' => 'اطلاعات کارت مجازی با موفقیت به‌روزرسانی شد',
            'credit' => $credit
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطا در به‌روزرسانی کارت مجازی: ' . $e->getMessage()];
    }
}

/**
 * Deactivate/activate a vCard user (soft delete)
 * This is implemented by setting a special flag in the mobile field or another approach
 * For now, we'll add a comment to the full_name to indicate deactivation
 *
 * @param int $user_id User ID
 * @param bool $active True to activate, false to deactivate
 * @return array Result array with success status and message
 */
function set_vcard_user_active($user_id, $active = true) {
    global $pdo;
    
    try {
        // Get current user info
        $stmt = $pdo->prepare("SELECT full_name FROM subscribers WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد'];
        }
        
        $full_name = $user['full_name'] ?: '';
        
        if ($active) {
            // Remove deactivation marker if present
            $full_name = str_replace(' [غیرفعال]', '', $full_name);
        } else {
            // Add deactivation marker if not present
            if (strpos($full_name, '[غیرفعال]') === false) {
                $full_name .= ' [غیرفعال]';
            }
        }
        
        $stmt = $pdo->prepare("UPDATE subscribers SET full_name = ? WHERE id = ?");
        $stmt->execute([trim($full_name), $user_id]);
        
        $status = $active ? 'فعال' : 'غیرفعال';
        return ['success' => true, 'message' => "کارت مجازی $status شد"];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'خطا در تغییر وضعیت کارت مجازی: ' . $e->getMessage()];
    }
}

/**
 * Get vCard user balance and transaction summary
 *
 * @param string $vcard_number 16-digit vCard number
 * @return array User balance info and transaction history
 */
function get_vcard_balance_info($vcard_number) {
    $user = get_vcard_user_by_number($vcard_number);
    
    if (!$user) {
        return ['success' => false, 'message' => 'کارت مجازی یافت نشد'];
    }
    
    $history = get_vcard_credit_history($user['mobile']);
    
    return [
        'success' => true,
        'user' => $user,
        'credit' => $user['credit'],
        'credit_toman' => $user['credit'] * 5000,
        'history' => $history,
        'total_transactions' => count($history)
    ];
}
?>