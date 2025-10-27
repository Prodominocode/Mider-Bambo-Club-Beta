<?php
// Advisor management functions
// Functions for managing customer advisors

require_once 'db.php';
require_once 'branch_utils.php';

/**
 * Check if the admin has permission to manage advisors
 * Only specific manager numbers can manage advisors
 *
 * @param string $admin_mobile Admin mobile number
 * @return bool True if admin can manage advisors
 */
function can_manage_advisors($admin_mobile) {
    $allowed_managers = ['09119246366', '09194467966','09119012010'];
    return in_array(norm_digits($admin_mobile), $allowed_managers);
}

/**
 * Get all advisors (shared between all managers)
 * This replaces the manager-specific advisor filtering
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @return array List of all advisors
 */
function get_all_advisors_shared($admin_mobile) {
    global $pdo;
    
    if (!can_manage_advisors($admin_mobile)) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, mobile_number, branch_id, sales_centers, manager_mobile, active, created_at, updated_at,
                   CASE WHEN credit IS NOT NULL THEN credit ELSE 0.0 END as credit
            FROM advisors 
            WHERE active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute();
        
        $advisors = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decode sales_centers JSON
            $sales_centers = json_decode($row['sales_centers'], true);
            if (!is_array($sales_centers)) {
                $sales_centers = [];
            }
            
            // Get branch info
            $branch_info = get_branch_info($row['branch_id']);
            $branch_name = $branch_info ? $branch_info['name'] : "شعبه {$row['branch_id']}";
            
            // Get manager name
            $manager_name = get_admin_name($row['manager_mobile']);
            
            // Get sales center names from all branches
            $sales_center_names = [];
            $all_branches = get_all_branches();
            
            foreach ($sales_centers as $sc_info) {
                if (is_array($sc_info) && isset($sc_info['branch_id'], $sc_info['sales_center_id'])) {
                    $b_id = $sc_info['branch_id'];
                    $sc_id = $sc_info['sales_center_id'];
                    
                    if (isset($all_branches[$b_id]['sales_centers'][$sc_id])) {
                        $branch_prefix = $all_branches[$b_id]['name'] . ' - ';
                        $sales_center_names[] = $branch_prefix . $all_branches[$b_id]['sales_centers'][$sc_id];
                    }
                } else {
                    // Handle old format (just sales center IDs)
                    $sc_id = $sc_info;
                    if ($branch_info && isset($branch_info['sales_centers'][$sc_id])) {
                        $sales_center_names[] = $branch_info['sales_centers'][$sc_id];
                    }
                }
            }
            
            $advisors[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'],
                'mobile_number' => $row['mobile_number'],
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $branch_name,
                'sales_centers' => $sales_centers,
                'sales_center_names' => $sales_center_names,
                'manager_mobile' => $row['manager_mobile'],
                'manager_name' => $manager_name,
                'credit' => (float)$row['credit'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        return $advisors;
    } catch (Exception $e) {
        error_log("Error getting all advisors: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all advisors for a specific manager (kept for backward compatibility)
 *
 * @param string $manager_mobile Manager mobile number
 * @return array List of advisors
 */
function get_advisors_for_manager($manager_mobile) {
    global $pdo;
    
    if (!can_manage_advisors($manager_mobile)) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, mobile_number, branch_id, sales_centers, active, created_at, updated_at
            FROM advisors 
            WHERE manager_mobile = ? AND active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([norm_digits($manager_mobile)]);
        
        $advisors = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Decode sales_centers JSON
            $sales_centers = json_decode($row['sales_centers'], true);
            if (!is_array($sales_centers)) {
                $sales_centers = [];
            }
            
            // Get branch info
            $branch_info = get_branch_info($row['branch_id']);
            $branch_name = $branch_info ? $branch_info['name'] : "شعبه {$row['branch_id']}";
            
            // Get sales center names from all branches
            $sales_center_names = [];
            $all_branches = get_all_branches();
            
            foreach ($sales_centers as $sc_info) {
                if (is_array($sc_info) && isset($sc_info['branch_id'], $sc_info['sales_center_id'])) {
                    $b_id = $sc_info['branch_id'];
                    $sc_id = $sc_info['sales_center_id'];
                    
                    if (isset($all_branches[$b_id]['sales_centers'][$sc_id])) {
                        $branch_prefix = $all_branches[$b_id]['name'] . ' - ';
                        $sales_center_names[] = $branch_prefix . $all_branches[$b_id]['sales_centers'][$sc_id];
                    }
                } else {
                    // Handle old format (just sales center IDs)
                    $sc_id = $sc_info;
                    if ($branch_info && isset($branch_info['sales_centers'][$sc_id])) {
                        $sales_center_names[] = $branch_info['sales_centers'][$sc_id];
                    }
                }
            }
            
            $advisors[] = [
                'id' => (int)$row['id'],
                'full_name' => $row['full_name'],
                'mobile_number' => $row['mobile_number'],
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $branch_name,
                'sales_centers' => $sales_centers,
                'sales_center_names' => $sales_center_names,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        return $advisors;
    } catch (Exception $e) {
        error_log("Error getting advisors: " . $e->getMessage());
        return [];
    }
}

/**
 * Add a new advisor (shared - can be managed by any admin)
 *
 * @param string $admin_mobile Admin mobile number (who is adding)
 * @param string $full_name Advisor full name
 * @param string $mobile_number Advisor mobile number (optional)
 * @param array $sales_centers Array of sales center IDs with their branch info
 * @return array Result with status and message
 */
function add_advisor_shared($admin_mobile, $full_name, $mobile_number, $sales_centers) {
    global $pdo;
    
    if (!can_manage_advisors($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    // Validate input
    if (empty($full_name)) {
        return ['status' => 'error', 'message' => 'نام کامل الزامی است'];
    }
    
    if (!is_array($sales_centers) || empty($sales_centers)) {
        return ['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود'];
    }
    
    // Validate sales centers exist in any branch
    $all_branches = get_all_branches();
    $valid_sales_centers = [];
    
    foreach ($sales_centers as $sc_info) {
        $branch_id = $sc_info['branch_id'];
        $sc_id = $sc_info['sales_center_id'];
        
        if (isset($all_branches[$branch_id]['sales_centers'][$sc_id])) {
            $valid_sales_centers[] = $sc_info;
        }
    }
    
    if (empty($valid_sales_centers)) {
        return ['status' => 'error', 'message' => 'مراکز فروش انتخابی معتبر نیست'];
    }
    
    // Use the first branch as the primary branch for the advisor
    $primary_branch_id = $valid_sales_centers[0]['branch_id'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO advisors (full_name, mobile_number, branch_id, sales_centers, manager_mobile, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $full_name,
            $mobile_number ?: null,
            $primary_branch_id,
            json_encode($valid_sales_centers),
            norm_digits($admin_mobile) // Record who created it, but all can manage
        ]);
        
        if ($result) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت اضافه شد', 'id' => $pdo->lastInsertId()];
        } else {
            return ['status' => 'error', 'message' => 'خطا در افزودن مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error adding advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Add a new advisor (original function - kept for backward compatibility)
 *
 * @param string $manager_mobile Manager mobile number
 * @param string $full_name Advisor full name
 * @param string $mobile_number Advisor mobile number (optional)
 * @param array $sales_centers Array of sales center IDs with their branch info
 * @return array Result with status and message
 */
function add_advisor($manager_mobile, $full_name, $mobile_number, $sales_centers) {
    global $pdo;
    
    if (!can_manage_advisors($manager_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    // Validate input
    if (empty($full_name)) {
        return ['status' => 'error', 'message' => 'نام کامل الزامی است'];
    }
    
    if (!is_array($sales_centers) || empty($sales_centers)) {
        return ['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود'];
    }
    
    // Validate sales centers exist in any branch
    $all_branches = get_all_branches();
    $valid_sales_centers = [];
    
    foreach ($sales_centers as $sc_info) {
        $branch_id = $sc_info['branch_id'];
        $sc_id = $sc_info['sales_center_id'];
        
        if (isset($all_branches[$branch_id]['sales_centers'][$sc_id])) {
            $valid_sales_centers[] = $sc_info;
        }
    }
    
    if (empty($valid_sales_centers)) {
        return ['status' => 'error', 'message' => 'مراکز فروش انتخابی معتبر نیست'];
    }
    
    // Use the first branch as the primary branch for the advisor
    $primary_branch_id = $valid_sales_centers[0]['branch_id'];
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO advisors (full_name, mobile_number, branch_id, sales_centers, manager_mobile, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $result = $stmt->execute([
            $full_name,
            $mobile_number ?: null,
            $primary_branch_id,
            json_encode($valid_sales_centers),
            norm_digits($manager_mobile)
        ]);
        
        if ($result) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت اضافه شد', 'id' => $pdo->lastInsertId()];
        } else {
            return ['status' => 'error', 'message' => 'خطا در افزودن مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error adding advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Remove an advisor (shared - any admin can remove any advisor)
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @param int $advisor_id Advisor ID
 * @return array Result with status and message
 */
function remove_advisor_shared($admin_mobile, $advisor_id) {
    global $pdo;
    
    if (!can_manage_advisors($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    if (!is_numeric($advisor_id) || $advisor_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه مشاور معتبر نیست'];
    }
    
    try {
        // Check if advisor exists (any advisor, not just own)
        $checkStmt = $pdo->prepare("
            SELECT id FROM advisors 
            WHERE id = ? AND active = 1
        ");
        $checkStmt->execute([$advisor_id]);
        
        if (!$checkStmt->fetch()) {
            return ['status' => 'error', 'message' => 'مشاور یافت نشد'];
        }
        
        // Soft delete the advisor
        $stmt = $pdo->prepare("
            UPDATE advisors SET active = 0 
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$advisor_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت حذف شد'];
        } else {
            return ['status' => 'error', 'message' => 'خطا در حذف مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error removing advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Remove an advisor (original function - kept for backward compatibility)
 *
 * @param string $manager_mobile Manager mobile number
 * @param int $advisor_id Advisor ID
 * @return array Result with status and message
 */
function remove_advisor($manager_mobile, $advisor_id) {
    global $pdo;
    
    if (!can_manage_advisors($manager_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    if (!is_numeric($advisor_id) || $advisor_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه مشاور معتبر نیست'];
    }
    
    try {
        // First check if advisor exists and belongs to this manager
        $checkStmt = $pdo->prepare("
            SELECT id FROM advisors 
            WHERE id = ? AND manager_mobile = ? AND active = 1
        ");
        $checkStmt->execute([$advisor_id, norm_digits($manager_mobile)]);
        
        if (!$checkStmt->fetch()) {
            return ['status' => 'error', 'message' => 'مشاور یافت نشد یا متعلق به شما نیست'];
        }
        
        // Soft delete the advisor
        $stmt = $pdo->prepare("
            UPDATE advisors 
            SET active = 0, updated_at = NOW()
            WHERE id = ? AND manager_mobile = ?
        ");
        
        $result = $stmt->execute([$advisor_id, norm_digits($manager_mobile)]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت حذف شد'];
        } else {
            return ['status' => 'error', 'message' => 'خطا در حذف مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error removing advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Update an advisor (shared - any admin can update any advisor)
 *
 * @param string $admin_mobile Admin mobile number (for permission check)
 * @param int $advisor_id Advisor ID
 * @param string $full_name Advisor full name
 * @param string $mobile_number Advisor mobile number (optional)
 * @param array $sales_centers Array of sales center IDs with their branch info
 * @return array Result with status and message
 */
function update_advisor_shared($admin_mobile, $advisor_id, $full_name, $mobile_number, $sales_centers) {
    global $pdo;
    
    if (!can_manage_advisors($admin_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    // Validate input
    if (!is_numeric($advisor_id) || $advisor_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه مشاور معتبر نیست'];
    }
    
    if (empty($full_name)) {
        return ['status' => 'error', 'message' => 'نام کامل الزامی است'];
    }
    
    if (!is_array($sales_centers) || empty($sales_centers)) {
        return ['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود'];
    }
    
    // Validate sales centers exist in any branch
    $all_branches = get_all_branches();
    $valid_sales_centers = [];
    
    foreach ($sales_centers as $sc_info) {
        $branch_id = $sc_info['branch_id'];
        $sc_id = $sc_info['sales_center_id'];
        
        if (isset($all_branches[$branch_id]['sales_centers'][$sc_id])) {
            $valid_sales_centers[] = $sc_info;
        }
    }
    
    if (empty($valid_sales_centers)) {
        return ['status' => 'error', 'message' => 'مراکز فروش انتخابی معتبر نیست'];
    }
    
    // Use the first branch as the primary branch for the advisor
    $primary_branch_id = $valid_sales_centers[0]['branch_id'];
    
    try {
        // Check if advisor exists (any advisor, not just own)
        $checkStmt = $pdo->prepare("
            SELECT id FROM advisors 
            WHERE id = ? AND active = 1
        ");
        $checkStmt->execute([$advisor_id]);
        
        if (!$checkStmt->fetch()) {
            return ['status' => 'error', 'message' => 'مشاور یافت نشد'];
        }
        
        $stmt = $pdo->prepare("
            UPDATE advisors 
            SET full_name = ?, mobile_number = ?, branch_id = ?, sales_centers = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $result = $stmt->execute([
            $full_name,
            $mobile_number ?: null,
            $primary_branch_id,
            json_encode($valid_sales_centers),
            $advisor_id
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت بروزرسانی شد'];
        } else {
            return ['status' => 'error', 'message' => 'خطا در بروزرسانی مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error updating advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Update an advisor (original function - kept for backward compatibility)
 *
 * @param string $manager_mobile Manager mobile number
 * @param int $advisor_id Advisor ID
 * @param string $full_name Advisor full name
 * @param string $mobile_number Advisor mobile number (optional)
 * @param array $sales_centers Array of sales center IDs with their branch info
 * @return array Result with status and message
 */
function update_advisor($manager_mobile, $advisor_id, $full_name, $mobile_number, $sales_centers) {
    global $pdo;
    
    if (!can_manage_advisors($manager_mobile)) {
        return ['status' => 'error', 'message' => 'شما مجاز به مدیریت مشاورین نیستید'];
    }
    
    // Validate input
    if (!is_numeric($advisor_id) || $advisor_id < 1) {
        return ['status' => 'error', 'message' => 'شناسه مشاور معتبر نیست'];
    }
    
    if (empty($full_name)) {
        return ['status' => 'error', 'message' => 'نام کامل الزامی است'];
    }
    
    if (!is_array($sales_centers) || empty($sales_centers)) {
        return ['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود'];
    }
    
    // Validate sales centers exist in any branch
    $all_branches = get_all_branches();
    $valid_sales_centers = [];
    
    foreach ($sales_centers as $sc_info) {
        $branch_id = $sc_info['branch_id'];
        $sc_id = $sc_info['sales_center_id'];
        
        if (isset($all_branches[$branch_id]['sales_centers'][$sc_id])) {
            $valid_sales_centers[] = $sc_info;
        }
    }
    
    if (empty($valid_sales_centers)) {
        return ['status' => 'error', 'message' => 'مراکز فروش انتخابی معتبر نیست'];
    }
    
    // Use the first branch as the primary branch for the advisor
    $primary_branch_id = $valid_sales_centers[0]['branch_id'];
    
    try {
        // First check if advisor exists and belongs to this manager
        $checkStmt = $pdo->prepare("
            SELECT id FROM advisors 
            WHERE id = ? AND manager_mobile = ? AND active = 1
        ");
        $checkStmt->execute([$advisor_id, norm_digits($manager_mobile)]);
        
        if (!$checkStmt->fetch()) {
            return ['status' => 'error', 'message' => 'مشاور یافت نشد یا متعلق به شما نیست'];
        }
        
        $stmt = $pdo->prepare("
            UPDATE advisors 
            SET full_name = ?, mobile_number = ?, branch_id = ?, sales_centers = ?, updated_at = NOW()
            WHERE id = ? AND manager_mobile = ?
        ");
        
        $result = $stmt->execute([
            $full_name,
            $mobile_number ?: null,
            $primary_branch_id,
            json_encode($valid_sales_centers),
            $advisor_id,
            norm_digits($manager_mobile)
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['status' => 'success', 'message' => 'مشاور با موفقیت بروزرسانی شد'];
        } else {
            return ['status' => 'error', 'message' => 'خطا در بروزرسانی مشاور'];
        }
    } catch (Exception $e) {
        error_log("Error updating advisor: " . $e->getMessage());
        return ['status' => 'error', 'message' => 'خطا در پایگاه داده'];
    }
}

/**
 * Get all branches for advisor management
 *
 * @return array List of branches with their sales centers
 */
function get_branches_for_advisor_management() {
    $branches = get_all_branches();
    $result = [];
    
    foreach ($branches as $branch_id => $branch_info) {
        $result[] = [
            'id' => $branch_id,
            'name' => $branch_info['name'],
            'sales_centers' => $branch_info['sales_centers']
        ];
    }
    
    return $result;
}

/**
 * Get all sales centers from all branches for advisor management
 *
 * @return array List of all sales centers with branch information
 */
function get_all_sales_centers_for_advisor_management() {
    $branches = get_all_branches();
    $result = [];
    
    foreach ($branches as $branch_id => $branch_info) {
        foreach ($branch_info['sales_centers'] as $sc_id => $sc_name) {
            $result[] = [
                'branch_id' => $branch_id,
                'branch_name' => $branch_info['name'],
                'sales_center_id' => $sc_id,
                'sales_center_name' => $sc_name,
                'display_name' => $branch_info['name'] . ' - ' . $sc_name
            ];
        }
    }
    
    return $result;
}

/**
 * Find the appropriate advisor for a transaction
 * This function determines which advisor should be linked to a purchase/credit transaction
 * based on the branch, sales center, and admin performing the transaction
 *
 * @param int $branch_id Branch ID where transaction occurred
 * @param int $sales_center_id Sales center ID where transaction occurred
 * @param string $admin_mobile Admin mobile who performed the transaction
 * @return int|null Advisor ID if found, null otherwise
 */
function find_advisor_for_transaction($branch_id, $sales_center_id, $admin_mobile) {
    global $pdo;
    
    if (!$branch_id || !$sales_center_id || !$admin_mobile) {
        return null;
    }
    
    try {
        // First, try to find an advisor who matches exactly:
        // 1. The advisor is managed by the admin performing the transaction
        // 2. The advisor has the specific branch_id and sales_center_id in their sales_centers
        $stmt = $pdo->prepare("
            SELECT id, sales_centers 
            FROM advisors 
            WHERE manager_mobile = ? AND active = 1
            ORDER BY created_at DESC
        ");
        $stmt->execute([norm_digits($admin_mobile)]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sales_centers = json_decode($row['sales_centers'], true);
            if (!is_array($sales_centers)) {
                continue;
            }
            
            // Check if this advisor handles the specific branch and sales center
            foreach ($sales_centers as $sc_info) {
                if (is_array($sc_info) && 
                    isset($sc_info['branch_id'], $sc_info['sales_center_id']) &&
                    $sc_info['branch_id'] == $branch_id && 
                    $sc_info['sales_center_id'] == $sales_center_id) {
                    return (int)$row['id'];
                }
            }
        }
        
        // If no exact match found, try to find an advisor who handles the same branch
        // (for cases where sales_center_id might be flexible)
        $stmt->execute([norm_digits($admin_mobile)]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sales_centers = json_decode($row['sales_centers'], true);
            if (!is_array($sales_centers)) {
                continue;
            }
            
            // Check if this advisor handles the same branch (any sales center)
            foreach ($sales_centers as $sc_info) {
                if (is_array($sc_info) && 
                    isset($sc_info['branch_id']) &&
                    $sc_info['branch_id'] == $branch_id) {
                    return (int)$row['id'];
                }
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error finding advisor for transaction: " . $e->getMessage());
        return null;
    }
}

/**
 * Get advisor information for a transaction
 * Returns detailed advisor info including name and sales centers
 *
 * @param int $advisor_id Advisor ID
 * @return array|null Advisor info or null if not found
 */
function get_advisor_info_for_transaction($advisor_id) {
    global $pdo;
    
    if (!$advisor_id) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, mobile_number, branch_id, sales_centers
            FROM advisors 
            WHERE id = ? AND active = 1
        ");
        $stmt->execute([$advisor_id]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        
        $sales_centers = json_decode($row['sales_centers'], true);
        if (!is_array($sales_centers)) {
            $sales_centers = [];
        }
        
        // Get branch info
        $branch_info = get_branch_info($row['branch_id']);
        $branch_name = $branch_info ? $branch_info['name'] : "شعبه {$row['branch_id']}";
        
        // Get sales center names
        $sales_center_names = [];
        $all_branches = get_all_branches();
        
        foreach ($sales_centers as $sc_info) {
            if (is_array($sc_info) && isset($sc_info['branch_id'], $sc_info['sales_center_id'])) {
                $b_id = $sc_info['branch_id'];
                $sc_id = $sc_info['sales_center_id'];
                
                if (isset($all_branches[$b_id]['sales_centers'][$sc_id])) {
                    $branch_prefix = $all_branches[$b_id]['name'] . ' - ';
                    $sales_center_names[] = $branch_prefix . $all_branches[$b_id]['sales_centers'][$sc_id];
                }
            }
        }
        
        return [
            'id' => (int)$row['id'],
            'full_name' => $row['full_name'],
            'mobile_number' => $row['mobile_number'],
            'branch_id' => (int)$row['branch_id'],
            'branch_name' => $branch_name,
            'sales_centers' => $sales_centers,
            'sales_center_names' => $sales_center_names
        ];
    } catch (Exception $e) {
        error_log("Error getting advisor info: " . $e->getMessage());
        return null;
    }
}

/**
 * Validate advisor data integrity for transactions
 * Ensures that advisor assignments are consistent and valid
 *
 * @param int $advisor_id Advisor ID
 * @param int $branch_id Branch ID of transaction
 * @param int $sales_center_id Sales center ID of transaction
 * @param string $admin_mobile Admin mobile performing transaction
 * @return bool True if valid, false otherwise
 */
function validate_advisor_transaction_link($advisor_id, $branch_id, $sales_center_id, $admin_mobile) {
    global $pdo;
    
    if (!$advisor_id || !$branch_id || !$sales_center_id || !$admin_mobile) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT sales_centers, manager_mobile 
            FROM advisors 
            WHERE id = ? AND active = 1
        ");
        $stmt->execute([$advisor_id]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        
        // Check if the admin is the manager of this advisor
        if (norm_digits($row['manager_mobile']) !== norm_digits($admin_mobile)) {
            return false;
        }
        
        // Check if the advisor handles this branch and sales center
        $sales_centers = json_decode($row['sales_centers'], true);
        if (!is_array($sales_centers)) {
            return false;
        }
        
        foreach ($sales_centers as $sc_info) {
            if (is_array($sc_info) && 
                isset($sc_info['branch_id'], $sc_info['sales_center_id']) &&
                $sc_info['branch_id'] == $branch_id && 
                $sc_info['sales_center_id'] == $sales_center_id) {
                return true;
            }
        }
        
        // Also accept if advisor handles the same branch (flexible sales center)
        foreach ($sales_centers as $sc_info) {
            if (is_array($sc_info) && 
                isset($sc_info['branch_id']) &&
                $sc_info['branch_id'] == $branch_id) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error validating advisor transaction link: " . $e->getMessage());
        return false;
    }
}

/**
 * Get all advisors available for a specific branch and sales center
 * Used for purchase form advisor selection
 *
 * @param int $branch_id Branch ID
 * @param int $sales_center_id Sales center ID
 * @return array List of advisors available for the branch and sales center
 */
function get_advisors_for_branch_and_sales_center($branch_id, $sales_center_id) {
    global $pdo;
    
    if (!$branch_id || !$sales_center_id) {
        return [];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, mobile_number, sales_centers, manager_mobile, credit
            FROM advisors 
            WHERE active = 1
            ORDER BY full_name ASC
        ");
        $stmt->execute();
        
        $advisors = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sales_centers = json_decode($row['sales_centers'], true);
            if (!is_array($sales_centers)) {
                continue;
            }
            
            // Check if this advisor handles the specific branch and sales center
            $handles_location = false;
            foreach ($sales_centers as $sc_info) {
                if (is_array($sc_info) && 
                    isset($sc_info['branch_id'], $sc_info['sales_center_id']) &&
                    $sc_info['branch_id'] == $branch_id && 
                    $sc_info['sales_center_id'] == $sales_center_id) {
                    $handles_location = true;
                    break;
                }
            }
            
            if ($handles_location) {
                // Get manager name
                $manager_name = get_admin_name($row['manager_mobile']);
                
                $advisors[] = [
                    'id' => (int)$row['id'],
                    'full_name' => $row['full_name'],
                    'mobile_number' => $row['mobile_number'],
                    'manager_mobile' => $row['manager_mobile'],
                    'manager_name' => $manager_name,
                    'credit' => isset($row['credit']) ? (float)$row['credit'] : 0.0
                ];
            }
        }
        
        return $advisors;
    } catch (Exception $e) {
        error_log("Error getting advisors for branch and sales center: " . $e->getMessage());
        return [];
    }
}

/**
 * Calculate advisor credit based on purchase time
 * Before 15:00: amount / 100,000
 * After 15:00: amount / 150,000
 *
 * @param float $purchase_amount Purchase amount
 * @param string $purchase_time Purchase time (or null to use current time)
 * @return float Calculated credit
 */
function calculate_advisor_credit($purchase_amount, $purchase_time = null) {
    if ($purchase_amount <= 0) {
        return 0.0;
    }
    
    // Use current time if not provided
    if (!$purchase_time) {
        $purchase_time = date('H:i:s');
    }
    
    // Extract hour from time
    $hour = (int)date('H', strtotime($purchase_time));
    
    // Calculate credit based on time
    if ($hour < 15) {
        // Before 15:00: divide by 100,000
        $credit = $purchase_amount / 100000;
    } else {
        // After 15:00: divide by 150,000
        $credit = $purchase_amount / 150000;
    }
    
    // Round to 2 decimal places
    return round($credit, 2);
}

/**
 * Process advisor credit for a purchase
 * Updates advisor credits and sends SMS notifications
 * Note: This function works within an existing transaction and does not start its own
 *
 * @param int $purchase_id Purchase ID
 * @param array $advisor_ids Array of advisor IDs
 * @param float $purchase_amount Purchase amount
 * @param int $branch_id Branch ID
 * @param int $sales_center_id Sales center ID
 * @param string $purchase_time Purchase time (optional)
 * @return array Result with status and details
 */
function process_advisor_credits($purchase_id, $advisor_ids, $purchase_amount, $branch_id, $sales_center_id, $purchase_time = null) {
    global $pdo;
    
    if (empty($advisor_ids) || $purchase_amount <= 0) {
        return ['status' => 'success', 'message' => 'No advisors to process or invalid amount'];
    }
    
    try {
        // Note: We don't start a transaction here - we work within the existing one from admin.php
        
        // Calculate credit for this purchase
        $earned_credit = calculate_advisor_credit($purchase_amount, $purchase_time);
        
        $updated_advisors = [];
        
        foreach ($advisor_ids as $advisor_id) {
            // Get advisor info
            $stmt = $pdo->prepare("SELECT full_name, mobile_number, credit FROM advisors WHERE id = ? AND active = 1");
            $stmt->execute([$advisor_id]);
            $advisor = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$advisor) {
                continue; // Skip if advisor not found
            }
            
            // Update advisor's total credit
            $new_total_credit = $advisor['credit'] + $earned_credit;
            $update_stmt = $pdo->prepare("UPDATE advisors SET credit = ? WHERE id = ?");
            $update_stmt->execute([$new_total_credit, $advisor_id]);
            
            // Update purchase_advisors table with earned credit (if the column exists)
            try {
                $purchase_advisor_stmt = $pdo->prepare("UPDATE purchase_advisors SET earned_credit = ? WHERE purchase_id = ? AND advisor_id = ?");
                $purchase_advisor_stmt->execute([$earned_credit, $purchase_id, $advisor_id]);
            } catch (Exception $e) {
                // Column might not exist yet, continue without error
                error_log("Note: earned_credit column not found in purchase_advisors table: " . $e->getMessage());
            }
            
            $updated_advisors[] = [
                'id' => $advisor_id,
                'name' => $advisor['full_name'],
                'mobile' => $advisor['mobile_number'],
                'earned_credit' => $earned_credit,
                'total_credit' => $new_total_credit
            ];
        }
        
        // Note: We don't commit here - the calling function (admin.php) will commit the transaction
        
        // Send SMS notifications to advisors (after the main transaction is committed)
        foreach ($updated_advisors as $advisor) {
            if ($advisor['mobile']) {
                send_advisor_credit_sms($advisor, $branch_id, $sales_center_id);
            }
        }
        
        return [
            'status' => 'success', 
            'message' => 'Advisor credits processed successfully',
            'advisors' => $updated_advisors,
            'earned_credit' => $earned_credit
        ];
        
    } catch (Exception $e) {
        // Note: We don't rollback here since we're working within an existing transaction
        // The calling function (admin.php) will handle the rollback if needed
        error_log("Error processing advisor credits: " . $e->getMessage());
        throw $e; // Re-throw the exception so the main transaction can handle it
    }
}

/**
 * Send SMS notification to advisor about credit update
 *
 * @param array $advisor Advisor information array
 * @param int $branch_id Branch ID
 * @param int $sales_center_id Sales center ID
 * @return bool Success status
 */
function send_advisor_credit_sms($advisor, $branch_id, $sales_center_id) {
    require_once 'config.php';
    require_once 'branch_utils.php';
    
    try {
        // Get message label including branch and sales center
        $message_label = get_message_label($branch_id, $sales_center_id);
        
        // Format credit amounts (multiply by 5000 for Toman display)
        $earned_credit_toman = number_format($advisor['earned_credit'] );
        $total_credit_toman = number_format($advisor['total_credit'] );
        
        // Construct SMS message
        $message = "{$message_label}\nامتیاز جدید {$earned_credit_toman} \nامتیاز کل : {$total_credit_toman} ";
        
        // Send SMS via Kavenegar
        $api_key = KAVENEGAR_API_KEY;
        $receptor = $advisor['mobile'];
        $url = "https://api.kavenegar.com/v1/$api_key/sms/send.json";
        
        $postfields = http_build_query([
            'receptor' => $receptor,
            'sender' => KAVENEGAR_SENDER,
            'message' => $message
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Advisor credit SMS sent to {$advisor['mobile']}: HTTP {$http_code}, Response: {$response}");
        
        return $http_code == 200;
        
    } catch (Exception $e) {
        error_log("Error sending advisor credit SMS: " . $e->getMessage());
        return false;
    }
}
?>