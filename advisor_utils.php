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
    $allowed_managers = ['09119246366', '09194467966'];
    return in_array(norm_digits($admin_mobile), $allowed_managers);
}

/**
 * Get all advisors for a specific manager
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
 * Add a new advisor
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
 * Remove an advisor
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
 * Update an advisor
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
?>