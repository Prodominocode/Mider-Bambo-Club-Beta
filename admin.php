<?php
// Combined admin login + panel with DB-backed OTP, ADMIN_ALLOWED check, long-lived session
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';
require_once 'db.php';
require_once 'branch_utils.php';
require_once 'pending_credits_utils.php';
require_once 'vcard_utils.php';
require_once 'gift_credit_utils.php';

// Set time zone to Tehran for all date/time operations
date_default_timezone_set('Asia/Tehran');

function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

function send_kavenegar_sms($receptor, $message){
    $api_key = KAVENEGAR_API_KEY;
    $url = "https://api.kavenegar.com/v1/$api_key/sms/send.json";
    $postfields = http_build_query(['receptor'=>$receptor,'sender'=>KAVENEGAR_SENDER,'message'=>$message]);
    if (function_exists('curl_init')){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3); // 3 second connection timeout
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok'=>false,'error'=>$err];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    } else {
        $opts = ['http'=>['method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded','content'=>$postfields,'timeout'=>5]]; // 5 second timeout
        $context = stream_context_create($opts);
        $resp = @file_get_contents($url,false,$context);
        if ($resp === false) return ['ok'=>false,'error'=>'file_get_contents_failed'];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    }
}

// Helper to check allowed admin numbers
function is_admin_allowed($mobile){
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return false;
    return array_key_exists($m, $allowed);
}

/**
 * Check if current admin is authorized for an action
 * This can be used to implement role-based access control for features
 *
 * @param string $action The action to check permissions for
 * @param array|null $session Optional session data (defaults to $_SESSION)
 * @return bool True if authorized
 */
function is_admin_authorized($action, $session = null) {
    if ($session === null) {
        $session = $_SESSION;
    }
    
    // If not logged in as admin, no access
    if (empty($session['is_admin'])) {
        return false;
    }
    
    // Manager role has access to everything
    if (!empty($session['is_manager'])) {
        return true;
    }
    
    // For seller role, check specific permissions
    switch ($action) {
        // Actions available to all admin roles (manager, seller)
        case 'add_purchase':
        case 'check_member':
        case 'get_today_report':
            return true;
            
        // Actions available only to managers
        case 'use_credit': 
            return false;
            
        // Default: no access for safety
        default:
            return false;
    }
}

// Helper to get admin name from mobile number
function get_admin_name($mobile){
    if (!$mobile) return '';
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return $mobile;
    return isset($allowed[$m]) && isset($allowed[$m]['name']) ? $allowed[$m]['name'] : $m;
}

/**
 * Get admin role (manager or seller)
 *
 * @param string $mobile Admin mobile number
 * @return string Role ('manager', 'seller', or empty string if not found)
 */
function get_admin_role($mobile){
    if (!$mobile) return '';
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return '';
    return isset($allowed[$m]) && isset($allowed[$m]['role']) ? $allowed[$m]['role'] : '';
}

/**
 * Check if admin has manager role
 *
 * @param string $mobile Admin mobile number
 * @return bool True if admin is a manager
 */
function is_admin_manager($mobile){
    return get_admin_role($mobile) === 'manager';
}

/**
 * Mask a mobile number for privacy (middle digits replaced with asterisks)
 * 
 * @param string $mobile The mobile number to mask
 * @return string The masked mobile number
 */
function mask_mobile_for_seller($mobile) {
    if (!$mobile) return '';
    
    $mobile = norm_digits($mobile);
    $len = strlen($mobile);
    
    // If number is shorter than 7 characters, just show first and last digit
    if ($len <= 7) {
        if ($len <= 2) return $mobile; // Too short to mask
        return substr($mobile, 0, 1) . str_repeat('*', $len - 2) . substr($mobile, -1);
    }
    
    // For normal phone numbers: mask middle 5 digits
    return substr($mobile, 0, ($len-7)/2 + 2) . str_repeat('*', 5) . substr($mobile, -($len-7)/2 - 2);
}

/**
 * Process transaction data based on admin role
 * 
 * @param array $transaction The transaction data
 * @param bool $is_manager Whether the current admin is a manager
 * @param int $current_branch_id Current branch ID
 * @param int $current_sales_center_id Current sales center ID
 * @return array Modified transaction data
 */
function process_transaction_for_display($transaction, $is_manager, $current_branch_id, $current_sales_center_id, $current_admin_mobile = '') {
    // Clone transaction to avoid modifying the original
    $result = $transaction;
    
    // Ensure required fields have default values
    $result['branch_id'] = isset($transaction['branch_id']) ? (int)$transaction['branch_id'] : 0;
    $result['sales_center_id'] = isset($transaction['sales_center_id']) ? (int)$transaction['sales_center_id'] : 0;
    
    if (!$is_manager) {
        // Mask phone number for sellers
        if (isset($transaction['mobile'])) {
            $result['mobile'] = mask_mobile_for_seller($transaction['mobile']);
        }
        
        // Determine transaction ownership for sellers
        $transaction_admin = isset($transaction['admin_mobile']) ? $transaction['admin_mobile'] : 
                           (isset($transaction['admin_number']) ? $transaction['admin_number'] : '');
        $is_own_transaction = ($transaction_admin === $current_admin_mobile);
        
        if ($is_own_transaction) {
            // Seller can see the price for their own transactions
            $result['amount_display'] = (string)$transaction['amount'];
            $result['hide_amount'] = false;
        } else {
            // For transactions created by others, show "نامشخص" as price
            $result['amount_display'] = 'نامشخص';
            $result['hide_amount'] = true;
        }
    } else {
        // Managers see everything
        $result['amount_display'] = (string)$transaction['amount'];
        $result['hide_amount'] = false;
    }
    
    return $result;
}

// Actions: send_otp, verify_otp, logout, add_subscriber, check_member, use_credit, get_today_report, manage_advisors
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Advisor management actions
    if ($action === 'get_advisors') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        require_once 'advisor_utils.php';
        $admin_mobile = $_SESSION['admin_mobile'];
        
        if (!can_manage_advisors($admin_mobile)) {
            echo json_encode(['status'=>'error','message'=>'no_permission']); exit;
        }
        
        // Use shared advisor list instead of manager-specific
        $advisors = get_all_advisors_shared($admin_mobile);
        $all_sales_centers = get_all_sales_centers_for_advisor_management();
        
        echo json_encode([
            'status' => 'success',
            'advisors' => $advisors,
            'all_sales_centers' => $all_sales_centers
        ]); exit;
        
    } elseif ($action === 'get_advisors_for_purchase') {
        // Admin only - Get advisors for purchase form
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        require_once 'advisor_utils.php';
        
        $branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
        $sales_center_id = isset($_POST['sales_center_id']) ? (int)$_POST['sales_center_id'] : 0;
        
        if (!$branch_id || !$sales_center_id) {
            echo json_encode(['status'=>'error','message'=>'missing_parameters']); exit;
        }
        
        $advisors = get_advisors_for_branch_and_sales_center($branch_id, $sales_center_id);
        
        echo json_encode([
            'status' => 'success',
            'advisors' => $advisors
        ]); exit;
        
    } elseif ($action === 'add_advisor') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        require_once 'advisor_utils.php';
        $admin_mobile = $_SESSION['admin_mobile'];
        
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $mobile_number = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : '';
        
        // Parse sales_centers from the new URL-encoded format
        $sales_centers = [];
        if (isset($_POST['sales_centers']) && is_array($_POST['sales_centers'])) {
            foreach ($_POST['sales_centers'] as $sc_data) {
                if (is_array($sc_data) && isset($sc_data['branch_id'], $sc_data['sales_center_id'])) {
                    $sales_centers[] = [
                        'branch_id' => (int)$sc_data['branch_id'],
                        'sales_center_id' => (int)$sc_data['sales_center_id']
                    ];
                }
            }
        }
        
        // Debug logging for add_advisor
        error_log('Add advisor - Received sales_centers: ' . print_r($_POST['sales_centers'] ?? 'not set', true));
        error_log('Add advisor - Parsed sales_centers: ' . print_r($sales_centers, true));
        
        // Ensure we have valid sales centers
        if (empty($sales_centers)) {
            echo json_encode(['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود']);
            exit;
        }
        
        $result = add_advisor_shared($admin_mobile, $full_name, $mobile_number, $sales_centers);
        echo json_encode($result); exit;
        
    } elseif ($action === 'remove_advisor') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        require_once 'advisor_utils.php';
        $admin_mobile = $_SESSION['admin_mobile'];
        
        $advisor_id = isset($_POST['advisor_id']) ? (int)$_POST['advisor_id'] : 0;
        
        $result = remove_advisor_shared($admin_mobile, $advisor_id);
        echo json_encode($result); exit;
        
    } elseif ($action === 'update_advisor') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        require_once 'advisor_utils.php';
        $admin_mobile = $_SESSION['admin_mobile'];
        
        $advisor_id = isset($_POST['advisor_id']) ? (int)$_POST['advisor_id'] : 0;
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $mobile_number = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : '';
        
        // Parse sales_centers from the new URL-encoded format
        $sales_centers = [];
        if (isset($_POST['sales_centers']) && is_array($_POST['sales_centers'])) {
            foreach ($_POST['sales_centers'] as $sc_data) {
                if (is_array($sc_data) && isset($sc_data['branch_id'], $sc_data['sales_center_id'])) {
                    $sales_centers[] = [
                        'branch_id' => (int)$sc_data['branch_id'],
                        'sales_center_id' => (int)$sc_data['sales_center_id']
                    ];
                }
            }
        }
        
        // Debug logging for update_advisor
        error_log('Update advisor - Received sales_centers: ' . print_r($_POST['sales_centers'] ?? 'not set', true));
        error_log('Update advisor - Parsed sales_centers: ' . print_r($sales_centers, true));
        
        // Ensure we have valid sales centers
        if (empty($sales_centers)) {
            echo json_encode(['status' => 'error', 'message' => 'حداقل یک مرکز فروش باید انتخاب شود']);
            exit;
        }
        
        $result = update_advisor_shared($admin_mobile, $advisor_id, $full_name, $mobile_number, $sales_centers);
        echo json_encode($result); exit;
    }
    if ($action === 'get_today_report') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        try {
            // Get date parameter or use today as default
            $date = isset($_POST['date']) ? $_POST['date'] : date('Y-m-d');
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $date = date('Y-m-d'); // Fall back to today if invalid format
            }
            
            // Get branch config for branch names
            require_once 'branch_utils.php';
            $branches = get_all_branches();
            
            // Log the timezone and current date for debugging
            error_log('Current timezone: ' . date_default_timezone_get());
            error_log('Today in Tehran: ' . date('Y-m-d H:i:s'));
            error_log('Requested date: ' . $date);
            
            // Set date range for the specified day - full day in Tehran time
            $today_start = $date . ' 00:00:00';
            $today_end = $date . ' 23:59:59';
            
            // Apply role-based access controls
            $admin_mobile = $_SESSION['admin_mobile'];
            $is_manager = is_admin_manager($admin_mobile);
            $current_branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : 1;
            $current_sales_center_id = isset($_SESSION['sales_center_id']) ? $_SESSION['sales_center_id'] : 1;
            
            // Get today's purchases - FROM ALL BRANCHES (for proper seller visibility rules)
            $purchases = [];
            
            // Check if active column exists in purchases table
            $columnExists = false;
            try {
                $checkStmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'active'");
                $checkStmt->execute();
                $columnExists = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                error_log('Error checking for active column: ' . $e->getMessage());
                // Continue without filtering by active
            }
            
            // Prepare the SQL to get all transactions with advisor information from purchase_advisors table
            if ($columnExists) {
                $sql = '
                    SELECT p.id, p.mobile, p.amount, p.created_at, s.full_name, p.admin_number, p.branch_id, p.sales_center_id,
                           GROUP_CONCAT(DISTINCT a.full_name SEPARATOR ", ") as advisor_names
                    FROM purchases p
                    LEFT JOIN subscribers s ON p.subscriber_id = s.id
                    LEFT JOIN purchase_advisors pa ON p.id = pa.purchase_id
                    LEFT JOIN advisors a ON pa.advisor_id = a.id
                    WHERE p.created_at BETWEEN ? AND ? AND p.active = 1
                    GROUP BY p.id
                    ORDER BY p.created_at DESC
                ';
            } else {
                $sql = '
                    SELECT p.id, p.mobile, p.amount, p.created_at, s.full_name, p.admin_number, p.branch_id, p.sales_center_id,
                           GROUP_CONCAT(DISTINCT a.full_name SEPARATOR ", ") as advisor_names
                    FROM purchases p
                    LEFT JOIN subscribers s ON p.subscriber_id = s.id
                    LEFT JOIN purchase_advisors pa ON p.id = pa.purchase_id
                    LEFT JOIN advisors a ON pa.advisor_id = a.id
                    WHERE p.created_at BETWEEN ? AND ?
                    GROUP BY p.id
                    ORDER BY p.created_at DESC
                ';
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$today_start, $today_end]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Get branch name and sales center name
                $branch_id = (int)$row['branch_id'];
                $sales_center_id = (int)$row['sales_center_id'];
                $branch_name = isset($branches[$branch_id]['name']) ? $branches[$branch_id]['name'] : "شعبه $branch_id";
                $sales_center_name = "";
                
                if (isset($branches[$branch_id]['sales_centers'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers'][$sales_center_id];
                } else if (isset($branches[$branch_id]['sales_centers_labels'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers_labels'][$sales_center_id];
                }
                
                $branch_store = $branch_name;
                if ($sales_center_name) {
                    $branch_store .= " / " . $sales_center_name;
                }
                
                $purchases[] = [
                    'id' => (int)$row['id'],
                    'mobile' => $row['mobile'],
                    'full_name' => $row['full_name'],
                    'amount' => (int)$row['amount'],
                    'date' => $row['created_at'],
                    'admin' => get_admin_name($row['admin_number']),
                    'admin_number' => $row['admin_number'],
                    'branch_id' => $branch_id,
                    'sales_center_id' => $sales_center_id,
                    'branch_store' => $branch_store,
                    'advisor_name' => $row['advisor_names'] ?: null,
                    'type' => 'purchase'
                ];
            }
            
            // Get today's credit usages - FROM ALL BRANCHES (for proper seller visibility rules)
            $credits = [];
            
            // Check if active column exists in credit_usage table
            $columnExists = false;
            try {
                $checkStmt = $pdo->prepare("SHOW COLUMNS FROM credit_usage LIKE 'active'");
                $checkStmt->execute();
                $columnExists = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                error_log('Error checking for active column: ' . $e->getMessage());
                // Continue without filtering by active
            }
            
            // Prepare the SQL to get all transactions with advisor information
            if ($columnExists) {
                $sql = '
                    SELECT c.id, c.user_mobile, c.amount, c.datetime, c.is_refund, c.admin_mobile, c.branch_id, c.sales_center_id, c.advisor_id,
                           a.full_name as advisor_name
                    FROM credit_usage c
                    LEFT JOIN advisors a ON c.advisor_id = a.id
                    WHERE c.datetime BETWEEN ? AND ? AND c.active = 1
                    ORDER BY c.datetime DESC
                ';
            } else {
                $sql = '
                    SELECT c.id, c.user_mobile, c.amount, c.datetime, c.is_refund, c.admin_mobile, c.branch_id, c.sales_center_id, c.advisor_id,
                           a.full_name as advisor_name
                    FROM credit_usage c
                    LEFT JOIN advisors a ON c.advisor_id = a.id
                    WHERE c.datetime BETWEEN ? AND ?
                    ORDER BY c.datetime DESC
                ';
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$today_start, $today_end]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Get branch name and sales center name
                $branch_id = (int)$row['branch_id'];
                $sales_center_id = (int)$row['sales_center_id'];
                $branch_name = isset($branches[$branch_id]['name']) ? $branches[$branch_id]['name'] : "شعبه $branch_id";
                $sales_center_name = "";
                
                if (isset($branches[$branch_id]['sales_centers'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers'][$sales_center_id];
                } else if (isset($branches[$branch_id]['sales_centers_labels'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers_labels'][$sales_center_id];
                }
                
                $branch_store = $branch_name;
                if ($sales_center_name) {
                    $branch_store .= " / " . $sales_center_name;
                }
                
                $credits[] = [
                    'id' => (int)$row['id'],
                    'mobile' => $row['user_mobile'],
                    'amount' => (int)$row['amount'],
                    'date' => $row['datetime'],
                    'is_refund' => (bool)$row['is_refund'],
                    'admin' => get_admin_name($row['admin_mobile']),
                    'admin_mobile' => $row['admin_mobile'],
                    'branch_id' => $branch_id,
                    'sales_center_id' => $sales_center_id,
                    'branch_store' => $branch_store,
                    'advisor_id' => $row['advisor_id'] ? (int)$row['advisor_id'] : null,
                    'advisor_name' => $row['advisor_name'] ?: null,
                    'type' => 'credit'
                ];
            }
            
            // Process purchases based on admin role
            $processed_purchases = array();
            foreach ($purchases as $p) {
                $processed_purchases[] = process_transaction_for_display($p, $is_manager, $current_branch_id, $current_sales_center_id, $admin_mobile);
            }
            
            // Process credits based on admin role
            $processed_credits = array();
            foreach ($credits as $c) {
                $processed_credits[] = process_transaction_for_display($c, $is_manager, $current_branch_id, $current_sales_center_id, $admin_mobile);
            }
            
            // Calculate totals (use original amounts for accurate totals)
            $total_purchases = 0;
            foreach ($purchases as $p) {
                $total_purchases += $p['amount'];
            }
            
            $total_credits = 0;
            foreach ($credits as $c) {
                $total_credits += $c['amount'];
            }
            
            // Calculate detailed breakdown by branch and store (for managers only)
            $breakdown = null;
            if ($is_manager) {
                $breakdown = [
                    'branches' => []
                ];
                
                // Group purchases by branch and sales center
                $purchase_breakdown = [];
                foreach ($purchases as $p) {
                    $branch_id = $p['branch_id'];
                    $sales_center_id = $p['sales_center_id'];
                    
                    if (!isset($purchase_breakdown[$branch_id])) {
                        $purchase_breakdown[$branch_id] = [];
                    }
                    if (!isset($purchase_breakdown[$branch_id][$sales_center_id])) {
                        $purchase_breakdown[$branch_id][$sales_center_id] = 0;
                    }
                    $purchase_breakdown[$branch_id][$sales_center_id] += $p['amount'];
                }
                
                // Group credits by branch and sales center
                $credit_breakdown = [];
                foreach ($credits as $c) {
                    $branch_id = $c['branch_id'];
                    $sales_center_id = $c['sales_center_id'];
                    
                    if (!isset($credit_breakdown[$branch_id])) {
                        $credit_breakdown[$branch_id] = [];
                    }
                    if (!isset($credit_breakdown[$branch_id][$sales_center_id])) {
                        $credit_breakdown[$branch_id][$sales_center_id] = 0;
                    }
                    $credit_breakdown[$branch_id][$sales_center_id] += $c['amount'];
                }
                
                // Combine all branch IDs
                $all_branch_ids = array_unique(array_merge(
                    array_keys($purchase_breakdown),
                    array_keys($credit_breakdown)
                ));
                
                foreach ($all_branch_ids as $branch_id) {
                    $branch_name = isset($branches[$branch_id]['name']) ? $branches[$branch_id]['name'] : "شعبه $branch_id";
                    
                    $branch_data = [
                        'id' => $branch_id,
                        'name' => $branch_name,
                        'total_purchases' => 0,
                        'total_credits' => 0,
                        'stores' => []
                    ];
                    
                    // Get all sales center IDs for this branch
                    $all_sales_center_ids = array_unique(array_merge(
                        array_keys($purchase_breakdown[$branch_id] ?? []),
                        array_keys($credit_breakdown[$branch_id] ?? [])
                    ));
                    
                    foreach ($all_sales_center_ids as $sales_center_id) {
                        $sales_center_name = "";
                        if (isset($branches[$branch_id]['sales_centers'][$sales_center_id])) {
                            $sales_center_name = $branches[$branch_id]['sales_centers'][$sales_center_id];
                        } else if (isset($branches[$branch_id]['sales_centers_labels'][$sales_center_id])) {
                            $sales_center_name = $branches[$branch_id]['sales_centers_labels'][$sales_center_id];
                        } else {
                            $sales_center_name = "فروشگاه $sales_center_id";
                        }
                        
                        $store_purchases = $purchase_breakdown[$branch_id][$sales_center_id] ?? 0;
                        $store_credits = $credit_breakdown[$branch_id][$sales_center_id] ?? 0;
                        
                        $branch_data['stores'][] = [
                            'id' => $sales_center_id,
                            'name' => $sales_center_name,
                            'purchases' => $store_purchases,
                            'credits' => $store_credits
                        ];
                        
                        $branch_data['total_purchases'] += $store_purchases;
                        $branch_data['total_credits'] += $store_credits;
                    }
                    
                    $breakdown['branches'][] = $branch_data;
                }
            }
            
            echo json_encode([
                'status' => 'success',
                'purchases' => $processed_purchases,
                'credits' => $processed_credits,
                'total_purchases' => $total_purchases,
                'total_credits' => $total_credits,
                'breakdown' => $breakdown,
                'date' => $date
            ]);
            exit;
        } catch (Throwable $e) {
            // Log detailed error information for debugging
            error_log('get_today_report error: ' . $e->getMessage());
            error_log('Error file: ' . $e->getFile() . ' on line ' . $e->getLine());
            error_log('Trace: ' . $e->getTraceAsString());
            
            // In development environment, return the actual error
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'server_error',
                    'debug' => [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]
                ]); 
            } else {
                // In production, just return generic error
                echo json_encode(['status'=>'error','message'=>'server_error']); 
            }
            exit;
        }
    } elseif ($action === 'use_credit') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $admin = $_SESSION['admin_mobile'];
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        $amount = isset($_POST['amount']) ? (int)norm_digits($_POST['amount']) : 0;
        $is_refund = isset($_POST['is_refund']) && $_POST['is_refund'] === 'true';
        
        // Get branch and sales center
        $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch();
        
        // For credit usage, if we're in a multi-store branch, use sales center ID from the request
        // otherwise, use the default sales center ID
        require_once 'branch_utils.php';
        if (has_dual_sales_centers($branch_id) && isset($_POST['sales_center_id'])) {
            $sales_center_id = (int)$_POST['sales_center_id'];
        } else {
            $sales_center_id = isset($_SESSION['sales_center_id']) ? $_SESSION['sales_center_id'] : 1;
        }
        
        // Find appropriate advisor for this credit usage transaction
        require_once 'advisor_utils.php';
        $advisor_id = find_advisor_for_transaction($branch_id, $sales_center_id, $admin);
        
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        if (!$amount) { echo json_encode(['status'=>'error','message'=>'amount_required']); exit; }
        
        try {
            // Check if member exists and get current credit
            $stmt = $pdo->prepare('SELECT id, credit, vcard_number FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$mobile]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['status'=>'error','message'=>'not_a_member']); exit;
            }
            
            // Check if this is a vCard user
            $is_vcard_user = !empty($member['vcard_number']);
            
            // Calculate credit points to subtract (convert from Toman to credit points)
            $creditToSubtract = round(($amount / 5000), 1);
            
            // Check if member has enough credit
            if ($member['credit'] < $creditToSubtract) {
                echo json_encode(['status'=>'error','message'=>'insufficient_credit']); exit;
            }
            
            // Update credit balance
            $pdo->beginTransaction();
            
            // 1. Update subscriber's credit
            $upd = $pdo->prepare('UPDATE subscribers SET credit = credit - ? WHERE id = ?');
            $upd->execute([$creditToSubtract, $member['id']]);
            
            // 2. Insert record into credit_usage table
            $ins = $pdo->prepare('INSERT INTO credit_usage (amount, credit_value, is_refund, user_mobile, admin_mobile, branch_id, sales_center_id, advisor_id) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$amount, $creditToSubtract, $is_refund ? 1 : 0, $mobile, $admin, $branch_id, $sales_center_id, $advisor_id]);
            
            // 3. Get updated credit
            $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1');
            $sel->execute([$member['id']]);
            $newCredit = (float)$sel->fetchColumn();
            
            $pdo->commit();
            
            // 4. Send SMS based on is_refund flag (skip for vCard users)
            $sms_sent = false;
            if (!$is_vcard_user) {
                $formatted_amount = number_format($amount);
                $formatted_remaining_credit = number_format((int)($newCredit * 5000));
                
                // Get appropriate message label for SMS
                require_once 'branch_utils.php';
                $message_label = get_message_label($branch_id, $sales_center_id);
                
                // Get branch domain for the website URL
                $branch_domain = get_branch_domain($branch_id);
                
                if ($is_refund) {
                    $message = "$message_label
با توجه به مرجوع کردن خریدتان مبلغ $formatted_amount تومان از اعتبار باشگاه مشتریان شما کسر شد. 
اعتبار باقیمانده : $formatted_remaining_credit

$branch_domain";
                } else {
                    $message = "$message_label
شما مبلغ $formatted_amount تومان از اعتبار باشگاه مشتریان خود را در خرید فعلی به عنوان تخفیف نقدی استفاده نمودید.
اعتبار باقیمانده : $formatted_remaining_credit

$branch_domain";
                }
                
                $sms = send_kavenegar_sms($mobile, $message);
                $sms_sent = $sms['ok'];
            }
            
            echo json_encode([
                'status' => 'success',
                'credit' => $newCredit,
                'credit_value' => (int)($newCredit * 5000),
                'sms_sent' => $sms_sent,
                'is_vcard_user' => $is_vcard_user
            ]);
            exit;
        } catch (Throwable $e) {
            try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch(Throwable $_) {}
            error_log('use_credit error: ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); exit;
        }
    } elseif ($action === 'check_member') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        
        // Get branch config for branch names
        require_once 'branch_utils.php';
        $branches = get_all_branches();
        
        try {
            // Check if member exists and get credit, including vCard information
            $stmt = $pdo->prepare('SELECT id, credit, branch_id, vcard_number, full_name FROM subscribers WHERE mobile = ? LIMIT 1');
            $stmt->execute([$mobile]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['status'=>'error','message'=>'not_a_member']); exit;
            }
            
            // Check if this is a vCard user
            $is_vcard_user = !empty($member['vcard_number']);
            
            // Get combined credit information (available + pending)
            $credit_info = get_combined_credits_by_mobile($pdo, $mobile);
            
            // Get ALL purchase history and credit usage - NO LIMITS, from ALL BRANCHES
            $transactions = [];
            
            // 1. Get ALL purchases (positive credits) - FROM ALL BRANCHES
            $stmt = $pdo->prepare('
                SELECT amount, created_at as date, "purchase" as type, branch_id, sales_center_id, admin_number
                FROM purchases 
                WHERE mobile = ? AND active = 1
                ORDER BY created_at DESC
            ');
            $stmt->execute([$mobile]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Get branch name and sales center name
                $branch_id = (int)$row['branch_id'];
                $sales_center_id = (int)$row['sales_center_id'];
                $branch_name = isset($branches[$branch_id]['name']) ? $branches[$branch_id]['name'] : "شعبه $branch_id";
                $sales_center_name = "";
                
                if (isset($branches[$branch_id]['sales_centers'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers'][$sales_center_id];
                } else if (isset($branches[$branch_id]['sales_centers_labels'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers_labels'][$sales_center_id];
                }
                
                $branch_store = $branch_name;
                if ($sales_center_name) {
                    $branch_store .= " / " . $sales_center_name;
                }
                
                $transactions[] = [
                    'amount' => (int)$row['amount'],
                    'date' => $row['date'],
                    'type' => 'purchase',
                    'branch_id' => $branch_id,
                    'sales_center_id' => $sales_center_id,
                    'branch_store' => $branch_store,
                    'admin_number' => $row['admin_number'],
                    'mobile' => $mobile,
                    // For inquiry: always show full prices without restrictions for all admin types
                    'amount_display' => (string)$row['amount'],
                    'hide_amount' => false
                ];
            }
            
            // 2. Get ALL credit usage (negative credits) - FROM ALL BRANCHES  
            $stmt = $pdo->prepare('
                SELECT amount, datetime as date, is_refund, "usage" as type, branch_id, sales_center_id, admin_mobile
                FROM credit_usage 
                WHERE user_mobile = ? AND active = 1
                ORDER BY datetime DESC
            ');
            $stmt->execute([$mobile]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Get branch name and sales center name
                $branch_id = (int)$row['branch_id'];
                $sales_center_id = (int)$row['sales_center_id'];
                $branch_name = isset($branches[$branch_id]['name']) ? $branches[$branch_id]['name'] : "شعبه $branch_id";
                $sales_center_name = "";
                
                if (isset($branches[$branch_id]['sales_centers'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers'][$sales_center_id];
                } else if (isset($branches[$branch_id]['sales_centers_labels'][$sales_center_id])) {
                    $sales_center_name = $branches[$branch_id]['sales_centers_labels'][$sales_center_id];
                }
                
                $branch_store = $branch_name;
                if ($sales_center_name) {
                    $branch_store .= " / " . $sales_center_name;
                }
                
                $transactions[] = [
                    'amount' => -(int)$row['amount'], // Negative amount
                    'date' => $row['date'],
                    'type' => 'usage',
                    'is_refund' => (bool)$row['is_refund'],
                    'branch_id' => $branch_id,
                    'sales_center_id' => $sales_center_id,
                    'branch_store' => $branch_store,
                    'admin_mobile' => $row['admin_mobile'],
                    'mobile' => $mobile,
                    // For inquiry: always show full prices without restrictions for all admin types
                    'amount_display' => (string)$row['amount'],
                    'hide_amount' => false
                ];
            }
            
            // 3. Get ALL gift credits (positive credits) - FROM ALL ADMINS
            $stmt = $pdo->prepare('
                SELECT gift_amount_toman, credit_amount, created_at as date, admin_number, notes, "gift_credit" as type
                FROM gift_credits 
                WHERE mobile = ? AND active = 1
                ORDER BY created_at DESC
            ');
            $stmt->execute([$mobile]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $transactions[] = [
                    'amount' => (int)$row['credit_amount'], // Positive amount (credit added)
                    'date' => $row['date'],
                    'type' => 'gift_credit',
                    'gift_amount_toman' => (float)$row['gift_amount_toman'],
                    'branch_id' => 0, // Gift credits are not branch-specific
                    'sales_center_id' => 0,
                    'branch_store' => 'اعتبار هدیه',
                    'admin_number' => $row['admin_number'],
                    'notes' => $row['notes'],
                    'mobile' => $mobile,
                    'amount_display' => (string)$row['credit_amount'],
                    'hide_amount' => false
                ];
            }
            
            // 4. Sort combined records by date (newest first)
            usort($transactions, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            
            // No limit - return ALL transactions for inquiry
            
            echo json_encode([
                'status' => 'success',
                'credit' => (int)$member['credit'],
                'credit_value' => $credit_info['available_credit_toman'],
                'available_credit' => $credit_info['available_credit'],
                'available_credit_toman' => $credit_info['available_credit_toman'],
                'pending_credit' => $credit_info['pending_credit'],
                'pending_credit_toman' => $credit_info['pending_credit_toman'],
                'total_credit_toman' => $credit_info['total_credit_toman'],
                'pending_details' => $credit_info['pending_details'],
                'transactions' => $transactions,
                'is_vcard_user' => $is_vcard_user,
                'vcard_number' => $member['vcard_number'],
                'full_name' => $member['full_name']
            ]);
            exit;
        } catch (Throwable $e) {
            error_log('check_member error: '.$e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); exit;
        }
    } elseif ($action === 'send_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        if (!is_admin_allowed($mobile)) { echo json_encode(['status'=>'error','message'=>'not_allowed']); exit; }
        // generate OTP and write to subscribers table (insert or update)
        $otp = str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
        try {
            $ch = $pdo->prepare('SELECT id FROM subscribers WHERE mobile=? LIMIT 1'); $ch->execute([$mobile]); $exists = $ch->fetchColumn();
            if ($exists){ $u = $pdo->prepare('UPDATE subscribers SET otp_code=?, verified=0, created_at=NOW() WHERE mobile=?'); $u->execute([$otp,$mobile]); }
            else { $u = $pdo->prepare('INSERT INTO subscribers (mobile, otp_code, verified, credit, created_at) VALUES (?, ?, 0, 10, NOW())'); $u->execute([$mobile,$otp]); }
        } catch (Throwable $e){ error_log('admin send_otp db: '.$e->getMessage()); }
        // Get current branch for the message label
        require_once 'branch_utils.php';
        $branch_id = get_current_branch();
        $message_label = get_branch_message_label($branch_id);
        
        // send SMS
        $branch_domain = get_branch_domain($branch_id);
        $message = "$message_label\nکد تایید ورود مدیریت: $otp\n$branch_domain";
        $sms = send_kavenegar_sms($mobile,$message);
        if (!$sms['ok']) { error_log('admin send_otp sms: '.json_encode($sms)); echo json_encode(['status'=>'error','message'=>'sms_failed']); exit; }
        // store mobile in session temporary ui marker
        $_SESSION['admin_otp_ui_mobile'] = $mobile; $_SESSION['admin_otp_ui_expires'] = time()+300;
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'delete_transaction') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        
        $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
        $transaction_type = isset($_POST['transaction_type']) ? $_POST['transaction_type'] : '';
        
        if (!$transaction_id || ($transaction_type !== 'purchase' && $transaction_type !== 'credit')) {
            echo json_encode(['status'=>'error','message'=>'invalid_request']); exit;
        }
        
        // Get admin info
        $admin_mobile = $_SESSION['admin_mobile'];
        $is_manager = !empty($_SESSION['is_manager']);
        
        try {
            // Get the transaction details first
            if ($transaction_type === 'purchase') {
                $stmt = $pdo->prepare('SELECT id, mobile, amount, created_at as date, admin_number FROM purchases WHERE id = ? LIMIT 1');
            } else {
                $stmt = $pdo->prepare('SELECT id, user_mobile as mobile, amount, datetime as date, admin_mobile FROM credit_usage WHERE id = ? LIMIT 1');
            }
            
            $stmt->execute([$transaction_id]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transaction) {
                echo json_encode(['status'=>'error','message'=>'transaction_not_found']); exit;
            }
            
            // Include delete_transaction.php for helper functions
            require_once 'delete_transaction.php';
            
            // Check if admin has permission to delete this transaction
            $permission = checkDeletePermission($transaction, $admin_mobile, $is_manager);
            
            if (!$permission['allowed']) {
                echo json_encode(['status'=>'error','message'=>$permission['message']]); exit;
            }
            
            // Perform the deletion (soft delete)
            $deleted = deleteTransaction($transaction_id, $transaction_type, $pdo);
            
            if (!$deleted) {
                echo json_encode(['status'=>'error','message'=>'delete_failed']); exit;
            }
            
            echo json_encode(['status'=>'success']); exit;
            
        } catch (Throwable $e) {
            error_log('delete_transaction error: ' . $e->getMessage());
            echo json_encode(['status'=>'error','message'=>'server_error']); exit;
        }
    } elseif ($action === 'verify_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        $code = isset($_POST['otp']) ? trim($_POST['otp']) : '';
        
        if (!$mobile || !$code) { echo json_encode(['status'=>'error','message'=>'missing']); exit; }
        
        // Get current branch
        require_once 'branch_utils.php';
        $branch_id = get_current_branch();
        
        // Use default sales center ID 1 for all admin logins
        // This won't affect transactions which will still use the store selector
        $sales_center_id = 1;
        
        try {
            $stmt = $pdo->prepare('SELECT * FROM subscribers WHERE mobile=? AND otp_code=? LIMIT 1'); $stmt->execute([$mobile,$code]); $row = $stmt->fetch();
            if (!$row) { echo json_encode(['status'=>'error','message'=>'invalid_code']); exit; }
            // clear otp_code
            $u2 = $pdo->prepare('UPDATE subscribers SET otp_code=NULL, verified=1 WHERE id=?'); $u2->execute([$row['id']]);
            if (function_exists('session_regenerate_id')) session_regenerate_id(true);
            
            // Get admin info and role
            $admin_role = get_admin_role($mobile);
            
            $_SESSION['admin_mobile'] = $mobile;
            $_SESSION['admin_name'] = get_admin_name($mobile);
            $_SESSION['admin_role'] = $admin_role;
            $_SESSION['is_admin'] = true;
            $_SESSION['is_manager'] = ($admin_role === 'manager');
            $_SESSION['branch_id'] = $branch_id;
            $_SESSION['sales_center_id'] = $sales_center_id;
            // persistent cookie (10 years)
            $cookieParams = session_get_cookie_params(); $lifetime = 10*365*24*60*60;
            setcookie(session_name(), session_id(), time()+$lifetime, $cookieParams['path']?:'/', $cookieParams['domain']?:'', $cookieParams['secure']??false, $cookieParams['httponly']??true);
            echo json_encode(['status'=>'success']); exit;
        } catch (Throwable $e){ error_log('admin verify otp: '.$e->getMessage()); echo json_encode(['status'=>'error','message'=>'server_error']); exit; }
    } elseif ($action === 'logout'){
        // logout
        $_SESSION = [];
        if (ini_get('session.use_cookies')){
            $params = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'add_subscriber'){
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $admin = $_SESSION['admin_mobile'];
        $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $no_credit = isset($_POST['no_credit']) && $_POST['no_credit'] === 'true' ? 1 : 0;
        
        // Handle selected_advisors array (from FormData with selected_advisors[])
        $selected_advisors = [];
        if (isset($_POST['selected_advisors']) && is_array($_POST['selected_advisors'])) {
            $selected_advisors = array_map('intval', $_POST['selected_advisors']);
        }
        
        // Debug logging
        error_log('Add subscriber - Received data:');
        error_log('selected_advisors: ' . print_r($_POST['selected_advisors'] ?? 'not set', true));
        error_log('parsed selected_advisors: ' . print_r($selected_advisors, true));
        error_log('description: ' . $description);
        error_log('no_credit: ' . $no_credit);
        
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        
        // Get branch and sales center
        require_once 'branch_utils.php';
        $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch();
        
        // Check if sales_center_id was provided in the request, otherwise use the session value
        $sales_center_id = isset($_POST['sales_center_id']) ? (int)$_POST['sales_center_id'] : 
                          (isset($_SESSION['sales_center_id']) ? $_SESSION['sales_center_id'] : 1);
        
        // Find appropriate advisor for this transaction (backward compatibility)
        require_once 'advisor_utils.php';
        $advisor_id = find_advisor_for_transaction($branch_id, $sales_center_id, $admin);
        
        try {
            // check existing (also check if it's a vCard user)
            $ch = $pdo->prepare('SELECT id, vcard_number FROM subscribers WHERE mobile = ? LIMIT 1'); $ch->execute([$mobile]); $existing = $ch->fetch(PDO::FETCH_ASSOC);
            if ($existing){
                $existingId = $existing['id'];
                $is_vcard_user = !empty($existing['vcard_number']);
                if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                    $pdo->beginTransaction();
                    
                    // Check if purchases table has new columns
                    $hasDescriptionColumn = false;
                    $hasNoCreditColumn = false;
                    try {
                        $checkStmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'description'");
                        $checkStmt->execute();
                        $hasDescriptionColumn = $checkStmt->rowCount() > 0;
                        
                        $checkStmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'no_credit'");
                        $checkStmt->execute();
                        $hasNoCreditColumn = $checkStmt->rowCount() > 0;
                    } catch (Exception $e) {
                        // Ignore column check errors for backward compatibility
                    }
                    
                    // Insert purchase record with dynamic column handling
                    if ($hasDescriptionColumn && $hasNoCreditColumn) {
                        $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,branch_id,sales_center_id,advisor_id,description,no_credit,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'); 
                        $ins->execute([$existingId,$mobile,$amount,$admin,$branch_id,$sales_center_id,$advisor_id,$description,$no_credit]);
                    } else {
                        // Fallback for older schema
                        $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,branch_id,sales_center_id,advisor_id,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'); 
                        $ins->execute([$existingId,$mobile,$amount,$admin,$branch_id,$sales_center_id,$advisor_id]);
                    }
                    $purchase_id = $pdo->lastInsertId();
                    
                    // Handle multiple advisor selection
                    if (!empty($selected_advisors)) {
                        error_log('Processing advisor selection. Selected advisors: ' . print_r($selected_advisors, true));
                        $checkPurchaseAdvisorsTable = false;
                        try {
                            $checkStmt = $pdo->prepare("SHOW TABLES LIKE 'purchase_advisors'");
                            $checkStmt->execute();
                            $checkPurchaseAdvisorsTable = $checkStmt->rowCount() > 0;
                            error_log('purchase_advisors table exists: ' . ($checkPurchaseAdvisorsTable ? 'yes' : 'no'));
                        } catch (Exception $e) {
                            error_log('Error checking purchase_advisors table: ' . $e->getMessage());
                        }
                        
                        if ($checkPurchaseAdvisorsTable) {
                            $advisorInsert = $pdo->prepare('INSERT IGNORE INTO purchase_advisors (purchase_id, advisor_id) VALUES (?, ?)');
                            foreach ($selected_advisors as $selected_advisor_id) {
                                if ($selected_advisor_id > 0) {
                                    error_log("Inserting advisor link: purchase_id=$purchase_id, advisor_id=$selected_advisor_id");
                                    $advisorInsert->execute([$purchase_id, $selected_advisor_id]);
                                    error_log("Advisor insert affected rows: " . $advisorInsert->rowCount());
                                }
                            }
                            
                            // Process advisor credits and send SMS notifications
                            require_once 'advisor_utils.php';
                            try {
                                $credit_result = process_advisor_credits($purchase_id, $selected_advisors, (float)$amount, $branch_id, $sales_center_id);
                                error_log("Advisor credit processing result: " . print_r($credit_result, true));
                            } catch (Exception $e) {
                                error_log("Error in advisor credit processing: " . $e->getMessage());
                                // Continue with the main purchase process even if advisor credit processing fails
                            }
                        }
                    } else {
                        error_log('No advisors selected for this purchase');
                    }
                    
                    // Calculate credit (only if no_credit is false)
                    $creditToAdd = $no_credit ? 0 : round(((float)$amount)/100000.0, 1);
                    
                    // Use pending credits system: add to pending_credits table instead of direct credit
                    if ($creditToAdd > 0) {
                        // Ensure pending_credits table exists
                        ensure_pending_credits_table($pdo);
                        
                        // Add to pending credits instead of direct credit
                        add_pending_credit($pdo, $existingId, $mobile, $purchase_id, $creditToAdd, $branch_id, $sales_center_id, $admin);
                        
                        // Get available credit for display (not including pending)
                        $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); 
                        $sel->execute([$existingId]); 
                        $newCredit = (int)$sel->fetchColumn();
                    } else {
                        $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); 
                        $sel->execute([$existingId]); 
                        $newCredit = (int)$sel->fetchColumn();
                    }
                    $pdo->commit();
                    
                    // Get branch and sales center info for SMS and response
                    $branch_info = get_branch_info($branch_id);
                    
                    // Get sales center name if applicable for UI display
                    $sales_center_name = '';
                    if ($branch_info && isset($branch_info['sales_centers'][$sales_center_id])) {
                        $sales_center_name = $branch_info['sales_centers'][$sales_center_id];
                    }
                    
                    // Get appropriate message label for SMS
                    $message_label = get_message_label($branch_id, $sales_center_id);
                    
                    // Get branch domain
                    $branch_domain = get_branch_domain($branch_id);
                    
                    // Update SMS message to reflect pending credit system
                    if ($no_credit) {
                        $creditMessage = "";
                    } else {
                        $creditMessage = "\nامتیاز کسب‌ شده از خرید امروز: " . intval($creditToAdd * 5000) . " تومان (48 ساعت آینده فعال می‌شود)";
                    }
                    
                    $message = "$message_label\nاز خرید شما متشکریم.$creditMessage\nامتیاز قابل استفاده شما در باشگاه مشتریان: " . intval($newCredit * 5000) . " تومان\n$branch_domain";
                    
                    // Only send SMS if not a vCard user
                    $sms_sent = false;
                    if (!$is_vcard_user) {
                        $sms = send_kavenegar_sms($mobile,$message);
                        $sms_sent = $sms['ok'] ?? false;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'note' => 'existing_user_with_amount',
                        'new_credit' => $newCredit,
                        'sms' => ['ok' => $sms_sent],
                        'sales_center_name' => $sales_center_name,
                        'is_vcard_user' => $is_vcard_user
                    ]); exit;
                }
                echo json_encode(['status'=>'success','note'=>'existing_user','admin_message'=>'Subscriber is already a member.']); exit;
            }
            // create new subscriber
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO subscribers (mobile, verified, credit, created_at, admin_number, branch_id) VALUES (?, 0, 0, NOW(), ?, ?)'); $stmt->execute([$mobile,$admin,$branch_id]); $id = $pdo->lastInsertId();
            $newCredit = null;
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                // Check if purchases table has new columns
                $hasDescriptionColumn = false;
                $hasNoCreditColumn = false;
                try {
                    $checkStmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'description'");
                    $checkStmt->execute();
                    $hasDescriptionColumn = $checkStmt->rowCount() > 0;
                    
                    $checkStmt = $pdo->prepare("SHOW COLUMNS FROM purchases LIKE 'no_credit'");
                    $checkStmt->execute();
                    $hasNoCreditColumn = $checkStmt->rowCount() > 0;
                } catch (Exception $e) {
                    // Ignore column check errors for backward compatibility
                }
                
                // Insert purchase record with dynamic column handling
                if ($hasDescriptionColumn && $hasNoCreditColumn) {
                    $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,branch_id,sales_center_id,advisor_id,description,no_credit,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'); 
                    $ins->execute([$id,$mobile,$amount,$admin,$branch_id,$sales_center_id,$advisor_id,$description,$no_credit]);
                } else {
                    // Fallback for older schema
                    $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,branch_id,sales_center_id,advisor_id,created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'); 
                    $ins->execute([$id,$mobile,$amount,$admin,$branch_id,$sales_center_id,$advisor_id]);
                }
                $purchase_id = $pdo->lastInsertId();
                
                // Handle multiple advisor selection
                if (!empty($selected_advisors)) {
                    error_log('Processing advisor selection for new user. Selected advisors: ' . print_r($selected_advisors, true));
                    $checkPurchaseAdvisorsTable = false;
                    try {
                        $checkStmt = $pdo->prepare("SHOW TABLES LIKE 'purchase_advisors'");
                        $checkStmt->execute();
                        $checkPurchaseAdvisorsTable = $checkStmt->rowCount() > 0;
                        error_log('purchase_advisors table exists: ' . ($checkPurchaseAdvisorsTable ? 'yes' : 'no'));
                    } catch (Exception $e) {
                        error_log('Error checking purchase_advisors table: ' . $e->getMessage());
                    }
                    
                    if ($checkPurchaseAdvisorsTable) {
                        $advisorInsert = $pdo->prepare('INSERT IGNORE INTO purchase_advisors (purchase_id, advisor_id) VALUES (?, ?)');
                        foreach ($selected_advisors as $selected_advisor_id) {
                            if ($selected_advisor_id > 0) {
                                error_log("Inserting advisor link for new user: purchase_id=$purchase_id, advisor_id=$selected_advisor_id");
                                $advisorInsert->execute([$purchase_id, $selected_advisor_id]);
                                error_log("Advisor insert affected rows: " . $advisorInsert->rowCount());
                            }
                        }
                        
                        // Process advisor credits and send SMS notifications
                        require_once 'advisor_utils.php';
                        try {
                            $credit_result = process_advisor_credits($purchase_id, $selected_advisors, (float)$amount, $branch_id, $sales_center_id);
                            error_log("Advisor credit processing result for new user: " . print_r($credit_result, true));
                        } catch (Exception $e) {
                            error_log("Error in advisor credit processing for new user: " . $e->getMessage());
                            // Continue with the main purchase process even if advisor credit processing fails
                        }
                    }
                } else {
                    error_log('No advisors selected for this new user purchase');
                }
                
                // Calculate credit (only if no_credit is false)
                $creditToAdd = $no_credit ? 0 : round(((float)$amount)/100000.0, 1);
                
                // Use pending credits system: add to pending_credits table instead of direct credit
                if ($creditToAdd > 0) {
                    // Ensure pending_credits table exists
                    ensure_pending_credits_table($pdo);
                    
                    // Add to pending credits instead of direct credit
                    add_pending_credit($pdo, $id, $mobile, $purchase_id, $creditToAdd, $branch_id, $sales_center_id, $admin);
                    
                    // Get available credit for display (not including pending)
                    $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); 
                    $sel->execute([$id]); 
                    $newCredit = intval($sel->fetchColumn());
                } else {
                    $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); 
                    $sel->execute([$id]); 
                    $newCredit = intval($sel->fetchColumn());
                }
            }
            $pdo->commit();
            
            // Get branch and sales center info for SMS and response
            $branch_info = get_branch_info($branch_id);
            
            // Get sales center name if applicable for UI display
            $sales_center_name = '';
            if ($branch_info && isset($branch_info['sales_centers'][$sales_center_id])) {
                $sales_center_name = $branch_info['sales_centers'][$sales_center_id];
            }
            
            // Get appropriate message label for SMS
            $message_label = get_message_label($branch_id, $sales_center_id);
            
            // Get branch domain
            $branch_domain = get_branch_domain($branch_id);
            
            // send SMS
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                if ($no_credit) {
                    $creditMessage = "";
                } else {
                    $creditMessage = "\nامتیاز شما از خرید امروز: " . ($creditToAdd * 5000) . " تومان (48 ساعت آینده فعال می‌شود)";
                }
                $message = "$message_label\nبه باشگاه مشتریان فروشگاه خوش آمدید.$creditMessage\nامتیاز قابل استفاده شما در باشگاه مشتریان: " . ($newCredit * 5000) . " تومان\n$branch_domain";
            } else {
                $message = "$message_label\nبه باشگاه مشتریان فروشگاه خوش آمدید.\n$branch_domain";
            }
            $sms = send_kavenegar_sms($mobile,$message);
            echo json_encode([
                'status' => 'success',
                'note' => 'new_user',
                'id' => $id,
                'new_credit' => $newCredit,
                'sms' => $sms,
                'sales_center_name' => $sales_center_name
            ]); exit;
        } catch (Throwable $e){ try{ if ($pdo->inTransaction()) $pdo->rollBack(); }catch(Throwable $_){} echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
    }
}

// If not POST or after actions, render page
$is_admin = !empty($_SESSION['admin_mobile']);
$admin_mobile = $is_admin ? $_SESSION['admin_mobile'] : '';
$admin_name = $is_admin ? (isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : get_admin_name($admin_mobile)) : '';
$admin_role = $is_admin ? (isset($_SESSION['admin_role']) ? $_SESSION['admin_role'] : get_admin_role($admin_mobile)) : '';
$is_manager = $is_admin && ($admin_role === 'manager');

// Ensure role is stored in session
if ($is_admin && !isset($_SESSION['admin_role'])) {
    $_SESSION['admin_role'] = $admin_role;
    $_SESSION['is_manager'] = $is_manager;
}

// VCard management actions
if ($action === 'get_vcards') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_vcards($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'no_permission']); exit;
    }
    
    $vcards = get_all_vcard_users();
    
    echo json_encode([
        'status' => 'success',
        'vcards' => $vcards
    ]); exit;
    
} elseif ($action === 'create_vcard') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_vcards($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'no_permission']); exit;
    }
    
    $vcard_number = isset($_POST['vcard_number']) ? trim($_POST['vcard_number']) : '';
    $mobile_number = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : null;
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
    $credit_amount = isset($_POST['credit_amount']) ? (int)$_POST['credit_amount'] : 0;
    
    if (empty($mobile_number)) {
        $mobile_number = null; // Let the function generate it
    }
    
    if (empty($full_name)) {
        $full_name = null;
    }
    
    $result = create_vcard_user($vcard_number, $mobile_number, $full_name, $credit_amount);
    echo json_encode($result); exit;
    
} elseif ($action === 'update_vcard') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_vcards($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'no_permission']); exit;
    }
    
    $vcard_id = isset($_POST['vcard_id']) ? (int)$_POST['vcard_id'] : 0;
    $vcard_number = isset($_POST['vcard_number']) ? trim($_POST['vcard_number']) : '';
    $mobile_number = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : null;
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
    $credit_amount = isset($_POST['credit_amount']) ? (int)$_POST['credit_amount'] : 0;
    
    if (empty($mobile_number)) {
        $mobile_number = null;
    }
    
    if (empty($full_name)) {
        $full_name = null;
    }
    
    if (!$vcard_id) {
        echo json_encode(['success' => false, 'message' => 'شناسه کارت مجازی معتبر نیست']); exit;
    }
    
    $result = update_vcard_user($vcard_id, $vcard_number, $mobile_number, $full_name, $credit_amount);
    echo json_encode($result); exit;
    
} elseif ($action === 'toggle_vcard_status') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_vcards($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'no_permission']); exit;
    }
    
    $vcard_id = isset($_POST['vcard_id']) ? (int)$_POST['vcard_id'] : 0;
    $active = isset($_POST['active']) ? (bool)$_POST['active'] : false;
    
    if (!$vcard_id) {
        echo json_encode(['success' => false, 'message' => 'شناسه کارت مجازی معتبر نیست']); exit;
    }
    
    $result = set_vcard_user_active($vcard_id, $active);
    echo json_encode($result); exit;
    
} elseif ($action === 'check_vcard_balance') {
    // Simple vCard to mobile lookup for admin
    $vcard_number = isset($_POST['vcard_number']) ? trim($_POST['vcard_number']) : '';
    
    if (empty($vcard_number)) {
        echo json_encode(['success' => false, 'message' => 'شماره کارت الزامی است']); exit;
    }
    
    // For admin inquiry, just find the linked mobile number
    if (isset($_SESSION['admin_mobile'])) {
        try {
            if (!isset($pdo) || !$pdo) {
                echo json_encode(['success' => false, 'message' => 'خطا در اتصال به پایگاه داده']); exit;
            }
            
            // Normalize and validate vCard number
            $normalized_vcard = norm_digits($vcard_number);
            if (strlen($normalized_vcard) !== 16) {
                echo json_encode(['success' => false, 'message' => 'شماره کارت باید دقیقاً 16 رقم باشد']); exit;
            }
            
            // Test database connection
            $pdo->query("SELECT 1");
            
            // Find the mobile number linked to this vCard
            $stmt = $pdo->prepare('SELECT mobile, full_name FROM subscribers WHERE vcard_number = ? LIMIT 1');
            $stmt->execute([$normalized_vcard]);
            $vcard_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vcard_user) {
                echo json_encode(['success' => false, 'message' => 'کارت یافت نشد.']); exit;
            }
            
            // Return the linked mobile number for population
            echo json_encode([
                'success' => true,
                'mobile' => $vcard_user['mobile'],
                'full_name' => $vcard_user['full_name'] ?: '',
                'vcard_number' => $normalized_vcard,
                'message' => 'شماره موبایل کارت مجازی یافت شد'
            ]);
            
        } catch (PDOException $e) {
            error_log('Database error in vCard lookup: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطا در اتصال به پایگاه داده']); exit;
        } catch (Exception $e) {
            error_log('vCard lookup error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'خطا در بازیابی اطلاعات کارت']); exit;
        }
    } else {
        // For public inquiry, use basic function
        try {
            $result = get_vcard_balance_info($vcard_number);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'خطا در بازیابی اطلاعات کارت']);
        }
    }
    exit;

} elseif ($action === 'add_gift_credit') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_gift_credits($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'شما مجاز به مدیریت اعتبار هدیه نیستید']); exit;
    }
    
    $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    $result = add_gift_credit($admin_mobile, $mobile, $amount, $notes);
    echo json_encode($result); exit;

} elseif ($action === 'get_gift_credits') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_gift_credits($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'شما مجاز به مدیریت اعتبار هدیه نیستید']); exit;
    }
    
    $search_mobile = isset($_POST['search_mobile']) ? trim($_POST['search_mobile']) : null;
    
    $gift_credits = get_gift_credits($admin_mobile, $search_mobile, true, 100, 0);
    
    echo json_encode([
        'status' => 'success',
        'gift_credits' => $gift_credits
    ]); exit;

} elseif ($action === 'disable_gift_credit') {
    // Admin only
    if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
    
    $admin_mobile = $_SESSION['admin_mobile'];
    
    if (!can_manage_gift_credits($admin_mobile)) {
        echo json_encode(['status'=>'error','message'=>'شما مجاز به مدیریت اعتبار هدیه نیستید']); exit;
    }
    
    $gift_credit_id = isset($_POST['gift_credit_id']) ? (int)$_POST['gift_credit_id'] : 0;
    $refund_credit = isset($_POST['refund_credit']) ? (bool)$_POST['refund_credit'] : false;
    
    if (!$gift_credit_id) {
        echo json_encode(['status' => 'error', 'message' => 'شناسه اعتبار هدیه معتبر نیست']); exit;
    }
    
    $result = disable_gift_credit($admin_mobile, $gift_credit_id, $refund_credit);
    echo json_encode($result); exit;
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>پنل مدیریت</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="assets/js/jalaali.js"></script>
  <style>
    body{font-family:'IRANYekanXVF',IRANYekanX,Tahoma,Arial;background:#181a1b url('assets/images/bg.jpg') no-repeat center center fixed;background-size:cover;color:#fff;position:relative}
    .overlay{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(24,26,27,0.85);backdrop-filter:blur(6px);z-index:1}
    .centered-container{max-width:720px;margin:40px auto;position:relative;z-index:2}
    .box{ width: 100%; background:rgba(34,36,38,0.95);padding:20px;border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.25)}
    .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
    .btn-primary{background:#4caf50;color:#fff}
    .btn-ghost{background:transparent;color:#cfcfcf;border:1px solid rgba(255,255,255,0.04)}
    .small{font-size:14px}
    input[type=text]{padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;width:100%}
    .msg{margin-top:8px}
    /* New styles for large buttons */
    .btn-large{
      display:flex;
      align-items:center;
      background:#4caf50;
      color:#fff;
      padding:20px;
      border:0;
      border-radius:8px;
      font-size:18px;
      cursor:pointer;
      width:100%;
      transition:background 0.2s ease;
    }
    .btn-large:hover{
      background:#43a047;
    }
    .admin-badge {
      display:inline-block;
      margin-right:8px;
      padding:2px 6px;
      border-radius:4px;
      font-size:11px;
      color:#fff;
    }
    .admin-badge.manager {
      background:#4caf50;
    }
    .admin-badge.seller {
      background:#ff9800;
    }
    .btn-icon{
      font-size:24px;
      margin-left:12px;
    }
    .btn-text{
      flex:1;
      text-align:right;
      font-weight:bold;
    }
    
    /* Form section styling */
    .form-section {
      background: rgba(0,0,0,0.2);
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 16px;
      border: 1px solid rgba(255,255,255,0.1);
    }
    
    .form-section:last-of-type {
      margin-bottom: 0;
    }
    
    .form-section h4 {
      margin: 0 0 12px 0;
      color: #fff;
      font-size: 14px;
      font-weight: bold;
    }
    
    .form-input {
      width: 100%;
      padding: 12px;
      border-radius: 6px;
      border: 1px solid #555;
      background: #1a1a1a;
      color: #fff;
      font-size: 14px;
      margin-bottom: 12px;
      box-sizing: border-box;
    }
    
    .form-input:last-child {
      margin-bottom: 0;
    }
    
    .form-input::placeholder {
      color: #888;
    }
    
    .form-checkbox-container {
      display: flex;
      align-items: center;
      margin-bottom: 12px;
    }
    
    .form-checkbox-container:last-child {
      margin-bottom: 0;
    }
    
    .form-checkbox {
      margin-left: 8px;
    }
    
    .form-checkbox-label {
      color: #fff;
      cursor: pointer;
      font-size: 14px;
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="centered-container">
    <div class="box">
<?php if (!$is_admin): ?>
      <h2>ورود مدیریت</h2>
      <div id="login-area">
        <div id="login-form">
          <label>شماره موبایل</label>
          <input type="text" id="login_mobile" placeholder="شماره موبایل">
          <div style="margin-top:8px"><button id="send_otp" class="btn btn-primary">ارسال کد</button></div>
          <div id="login_msg" class="msg"></div>
        </div>
        <div id="otp-form" style="display:none;margin-top:12px">
          <label>کد تایید</label>
          <input type="text" id="otp_code" placeholder="کد ۵ رقمی">
          
          <div style="margin-top:8px"><button id="verify_otp" class="btn btn-primary">تایید و ورود</button></div>
          <div id="otp_msg" class="msg"></div>
        </div>
      </div>
<?php else: ?>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h2>پنل مدیریت</h2>
          <div class="small">
            ادمین: <?php echo htmlspecialchars($admin_name); ?> 
            <span class="admin-badge <?php echo $admin_role; ?>">
              <?php echo $admin_role === 'manager' ? 'مدیر' : 'فروشنده'; ?>
            </span>
          </div>
        </div>
        <div>
          <button id="logout" class="btn btn-ghost">خروج</button>
        </div>
      </div>
      <hr>
      <!-- Main navigation buttons -->
      <div style="display:flex;flex-direction:column;gap:16px;margin:20px 0">
        <button id="btn_purchase" class="btn-large">
          <div class="btn-icon">💰</div>
          <div class="btn-text">ثبت خرید</div>
        </button>
        <button id="btn_inquiry" class="btn-large">
          <div class="btn-icon">🔍</div>
          <div class="btn-text">استعلام</div>
        </button>
        <button id="btn_today_report" class="btn-large">
          <div class="btn-icon">📊</div>
          <div class="btn-text">تراکنش های امروز</div>
        </button>
        <?php 
        // Show advisor management button only for specific managers
        require_once 'advisor_utils.php';
        if (can_manage_advisors($admin_mobile)): 
        ?>
        <button id="btn_advisor_management" class="btn-large">
          <div class="btn-icon">👥</div>
          <div class="btn-text">مشاور مشتری</div>
        </button>
        <?php endif; ?>
        <?php 
        // Show virtual card management button only for specific managers
        if (can_manage_vcards($admin_mobile)): 
        ?>
        <button id="btn_vcard_management" class="btn-large">
          <div class="btn-icon">💳</div>
          <div class="btn-text">کارت های مجازی</div>
        </button>
        <?php endif; ?>
        <?php 
        // Show gift credit management button only for specific managers
        if (can_manage_gift_credits($admin_mobile)): 
        ?>
        <button id="btn_gift_credit_management" class="btn-large">
          <div class="btn-icon">🎁</div>
          <div class="btn-text">اعتبار هدیه</div>
        </button>
        <?php endif; ?>
      </div>
      
      <!-- Purchase/subscription form (initially hidden) -->
      <div id="purchase_form" style="display:none">
        <h3>افزودن مشترک / ثبت خرید</h3>
        
        <!-- Top Section: Mobile and Amount -->
        <div class="form-section">
          <h4>اطلاعات مشتری و خرید</h4>
          <input type="text" id="sub_mobile" class="form-input" placeholder="شماره موبایل (الزامی)">
          <input type="text" id="sub_amount" class="form-input" placeholder="مبلغ خرید (تومان، اختیاری) - مثال: 250.000" inputmode="numeric">
          <input type="hidden" id="sub_amount_raw">
        </div>
        
        <!-- Middle Section: Description and Checkbox -->
        <div class="form-section">
          <h4>جزئیات خرید</h4>
          <input type="text" id="sub_description" class="form-input" placeholder="توضیحات خرید (اختیاری)">
          <div class="form-checkbox-container">
            <input type="checkbox" id="sub_no_credit" class="form-checkbox">
            <label for="sub_no_credit" class="form-checkbox-label">این خرید امتیاز به کاربر نمی‌دهد</label>
          </div>
        </div>
        
        <?php 
        $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch();
        $has_multiple_stores = has_dual_sales_centers($branch_id);
        if ($has_multiple_stores): 
          $sales_centers = get_branch_sales_centers($branch_id);
        ?>
        <!-- Store Selection (only for multiple stores) -->
        <div class="form-section">
          <h4>انتخاب فروشگاه</h4>
          <select id="sub_sales_center" class="form-input">
            <?php foreach ($sales_centers as $sc_id => $sc_name): ?>
              <option value="<?php echo $sc_id; ?>"><?php echo htmlspecialchars($sc_name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        
        <!-- Bottom Section: Advisors Selection -->
        <div class="form-section">
          <h4>انتخاب فروشنده</h4>
          <div id="advisor_selection_container">
            <?php if ($has_multiple_stores): ?>
            <div style="color:#aaa;padding:8px;border:1px dashed #555;border-radius:6px;text-align:center;">
              ابتدا فروشگاه را انتخاب کنید تا فروشندگان نمایش داده شوند
            </div>
            <?php else: ?>
            <div style="color:#aaa;padding:8px;border:1px dashed #555;border-radius:6px;text-align:center;">
              در حال بارگذاری فروشندگان...
            </div>
            <?php endif; ?>
          </div>
        </div>
        
        <div style="margin-top:20px;display:flex;gap:8px">
          <button id="add_submit" class="btn btn-primary">ارسال</button>
          <button id="back_to_menu" class="btn btn-ghost">بازگشت</button>
        </div>
        <div id="add_msg" class="msg"></div>
      </div>
      
      <!-- Inquiry form (initially hidden) -->
      <div id="inquiry_form" style="display:none">
        <h3>استعلام وضعیت مشتری</h3>
        <div>
          <label>شماره موبایل مشتری</label>
          <input type="text" id="inquiry_mobile" placeholder="شماره موبایل">
        </div>
        <div style="margin-top:8px;margin-bottom:12px;">
          <a href="#" id="check_by_vcard" style="color:#4caf50;font-size:12px;text-decoration:underline;cursor:pointer;">بررسی با شماره کارت مجازی</a>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="check_member" class="btn btn-primary">استعلام</button>
          <button id="back_to_menu_inquiry" class="btn btn-ghost">بازگشت</button>
        </div>
        <div id="inquiry_msg" class="msg"></div>
        
        <!-- VCard inquiry form (initially hidden) -->
        <div id="vcard_inquiry_form" style="display:none;margin-top:20px;background:rgba(156, 39, 176, 0.1);padding:15px;border-radius:8px;border:1px solid rgba(156, 39, 176, 0.3);">
          <h4 style="color:#e1bee7;">استعلام با شماره کارت مجازی</h4>
          <div style="margin-bottom:12px;">
            <label>شماره کارت 16 رقمی</label>
            <input type="text" id="vcard_inquiry_number" placeholder="1234567890123456" maxlength="16" style="width:100%;padding:8px;border-radius:6px;border:1px solid #9c27b0;background:#0d0d0d;color:#fff;">
          </div>
          <div style="display:flex;gap:8px">
            <button id="check_vcard_inquiry" class="btn" style="background:#9c27b0;color:white;">استعلام کارت</button>
            <button id="cancel_vcard_inquiry" class="btn btn-ghost">انصراف</button>
          </div>
        </div>
        
        <!-- Member info (initially hidden) -->
        <div id="member_info" style="display:none;margin-top:20px;background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;">
          <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:20px;">
            <div id="member_credit" style="background:#d32f2f;color:white;font-weight:bold;font-size:18px;padding:12px 16px;border-radius:8px;text-align:center;width:100%;box-sizing:border-box;"></div>
            <div id="member_pending_credit" style="background:#ff9800;color:white;font-weight:bold;font-size:16px;padding:10px 16px;border-radius:8px;text-align:center;width:100%;box-sizing:border-box;display:none;"></div>
            <div id="member_total_credit" style="background:#4caf50;color:white;font-weight:bold;font-size:16px;padding:10px 16px;border-radius:8px;text-align:center;width:100%;box-sizing:border-box;display:none;"></div>
          </div>
          <h4 style="margin-bottom:10px;">تاریخچه کامل تراکنش‌ها (تمام شعب و فروشگاه‌ها)</h4>
          <div id="purchase_history">
            <table style="width:100%;border-collapse:collapse;">
              <thead>
                <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                  <th style="text-align:right;padding:8px 4px;">تاریخ</th>
                  <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                  <th style="text-align:center;padding:8px 4px;">شعبه / فروشگاه</th>
                </tr>
              </thead>
              <tbody id="purchases_table_body">
                <!-- Transactions will be inserted here -->
              </tbody>
            </table>
          </div>
          <div id="no_purchases" style="display:none;color:#aaa;padding:10px 0;">هیچ تراکنشی ثبت نشده است.</div>
          
          <!-- Credit Use Section -->
          <div style="margin-top:20px;border-top:1px solid rgba(255,255,255,0.1);padding-top:16px;">
            <h4 style="margin-bottom:10px;">استفاده از امتیاز</h4>
            <div>
              <label>استفاده از امتیاز (تومان)</label>
              <input type="text" id="credit_use_amount" inputmode="numeric" placeholder="مثال: 200.000">
              <input type="hidden" id="credit_use_amount_raw">
            </div>
            <?php 
            $branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch();
            if (has_dual_sales_centers($branch_id)): 
              $sales_centers = get_branch_sales_centers($branch_id);
            ?>
            <div style="margin-top:8px">
              <label>انتخاب فروشگاه</label>
              <select id="credit_sales_center" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;">
                <?php foreach ($sales_centers as $sc_id => $sc_name): ?>
                  <option value="<?php echo $sc_id; ?>"><?php echo htmlspecialchars($sc_name); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
            <div style="margin-top:8px;display:flex;align-items:center;">
              <input type="checkbox" id="credit_refund" style="margin-left:8px;">
              <label for="credit_refund">مرجوعی</label>
            </div>
            <div id="credit_preview" style="margin-top:8px;color:#ffc107;display:none;">امتیاز پس از کسر: <span id="credit_remaining"></span> تومان</div>
            <div style="margin-top:12px;">
              <button id="submit_credit_use" class="btn btn-primary">ثبت</button>
            </div>
            <div id="credit_use_msg" class="msg"></div>
          </div>
        </div>
      </div>
      
      <!-- Today's Transactions Report (initially hidden) -->
      <div id="today_report_form" style="display:none">
        <div style="text-align:center;">
          <h3>گزارش تراکنش های امروز</h3>
        </div>
        
        <!-- Dedicated row for date navigation - centered -->
        <div style="display:flex;justify-content:center;align-items:center;margin:15px 0;padding:10px 0;border-top:1px solid rgba(255,255,255,0.05);border-bottom:1px solid rgba(255,255,255,0.05);">
          <div style="display:flex;align-items:center;gap:12px;">
            <button id="next_day_report" class="btn btn-ghost" style="padding:4px 8px;min-width:40px">▶</button>
            <div id="today_date" style="color:#b0b3b8;min-width:120px;text-align:center;font-weight:bold;"></div>
            <button id="prev_day_report" class="btn btn-ghost" style="padding:4px 8px;min-width:40px">◀</button>
          </div>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="refresh_today_report" class="btn btn-primary" style="display:none;">بروزرسانی</button>
          <button id="go_to_today" class="btn btn-primary">امروز</button>
          <button id="back_to_menu_report" class="btn btn-primary" style="background:#ff5252;padding:10px 20px;font-weight:bold;font-size:16px;">بازگشت</button>
        </div>
        
        <div id="today_report_msg" class="msg"></div>
        
        <div style="margin-top:20px;">
          <h4 style="color:#4caf50;margin-bottom:10px;">خریدهای ثبت شده</h4>
          <div style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;margin-bottom:20px;">
            <div id="today_purchases_container" style="max-height:300px;overflow-y:auto;">
              <table style="width:100%;border-collapse:collapse;" id="today_purchases_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">موبایل</th>
                    <th style="text-align:right;padding:8px 4px;">زمان</th>
                    <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                    <th style="text-align:center;padding:8px 4px;">شعبه / فروشگاه</th>
                    <th style="text-align:center;padding:8px 4px;">مشاور</th>
                    <th style="text-align:center;padding:8px 4px;">ادمین</th>
                    <th style="text-align:center;padding:8px 4px;">عملیات</th>
                  </tr>
                </thead>
                <tbody id="today_purchases_list">
                  <tr><td colspan="7" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          
          <h4 style="color:#ff5252;margin-bottom:10px;">اعتبارهای استفاده شده</h4>
          <div style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;margin-bottom:20px;">
            <div id="today_credits_container" style="max-height:300px;overflow-y:auto;">
              <table style="width:100%;border-collapse:collapse;" id="today_credits_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">موبایل</th>
                    <th style="text-align:right;padding:8px 4px;">زمان</th>
                    <th style="text-align:left;padding:8px 4px;">مبلغ (تومان)</th>
                    <th style="text-align:center;padding:8px 4px;">نوع</th>
                    <th style="text-align:center;padding:8px 4px;">شعبه / فروشگاه</th>
                    <th style="text-align:center;padding:8px 4px;">مشاور</th>
                    <th style="text-align:center;padding:8px 4px;">ادمین</th>
                    <th style="text-align:center;padding:8px 4px;">عملیات</th>
                  </tr>
                </thead>
                <tbody id="today_credits_list">
                  <tr><td colspan="8" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
          
          <!-- Summary section - only visible to managers -->
          <?php if (!empty($_SESSION['is_manager'])): ?>
          <div id="transactions_summary" style="margin-top:30px;background:#1a1c1e;padding:20px;border-radius:8px;">
            <h4 style="margin-bottom:15px;color:#fff;text-align:center;">خلاصه کل (Overall Total)</h4>
            
            <!-- Overall totals -->
            <div style="display:flex;justify-content:space-between;margin-bottom:25px;background:#2a2c2e;padding:15px;border-radius:6px;">
              <div style="flex:1;">
                <div style="font-weight:bold;margin-bottom:5px;">مجموع کل خریدها:</div>
                <div id="manager_total_purchases" style="color:#4caf50;font-size:18px;font-weight:bold;">0 تومان</div>
              </div>
              <div style="flex:1;text-align:left;">
                <div style="font-weight:bold;margin-bottom:5px;">مجموع کل اعتبارهای استفاده شده:</div>
                <div id="manager_total_credits_used" style="color:#ff5252;font-size:18px;font-weight:bold;">0 تومان</div>
              </div>
            </div>
            
            <!-- Detailed breakdown table -->
            <div id="breakdown_container" style="margin-top:20px;">
              <h5 style="margin-bottom:15px;color:#fff;">تفکیک شعب و فروشگاه‌ها</h5>
              <div style="overflow-x:auto;">
                <table id="breakdown_table" style="width:100%;border-collapse:collapse;background:#2a2c2e;border-radius:6px;overflow:hidden;">
                  <thead>
                    <tr style="background:#333;color:#fff;">
                      <th style="padding:12px;text-align:right;border-bottom:1px solid #444;">شعبه / فروشگاه</th>
                      <th style="padding:12px;text-align:center;border-bottom:1px solid #444;color:#4caf50;">خریدها (تومان)</th>
                      <th style="padding:12px;text-align:center;border-bottom:1px solid #444;color:#ff5252;">اعتبارهای استفاده شده (تومان)</th>
                    </tr>
                  </thead>
                  <tbody id="breakdown_table_body">
                    <!-- Breakdown data will be inserted here -->
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      
      <!-- Advisor Management Form (initially hidden) -->
      <?php 
      require_once 'advisor_utils.php';
      if (can_manage_advisors($admin_mobile)): 
      ?>
      <div id="advisor_management_form" style="display:none">
        <div style="text-align:center;">
          <h3>مدیریت مشاور مشتری</h3>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;">
          <button id="add_new_advisor" class="btn btn-primary">افزودن مشاور جدید</button>
          <button id="back_to_menu_advisor" class="btn btn-ghost">بازگشت</button>
        </div>
        
        <div id="advisor_msg" class="msg"></div>
        
        <!-- Add/Edit Advisor Form (initially hidden) -->
        <div id="advisor_form" style="display:none;margin-top:20px;background:rgba(0,0,0,0.3);padding:20px;border-radius:8px;">
          <h4 id="advisor_form_title">افزودن مشاور جدید</h4>
          <form id="advisor_form_element">
            <input type="hidden" id="advisor_id" value="">
            
            <div style="margin-bottom:12px;">
              <label>نام کامل (الزامی)</label>
              <input type="text" id="advisor_full_name" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="نام و نام خانوادگی مشاور">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>شماره موبایل (اختیاری)</label>
              <input type="text" id="advisor_mobile" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="09xxxxxxxxx">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>انتخاب مراکز فروش</label>
              
              <!-- Available sales centers dropdown -->
              <div style="margin-bottom:8px;">
                <select id="available_sales_centers" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;">
                  <option value="">انتخاب مرکز فروش...</option>
                </select>
              </div>
              
              <!-- Selected sales centers list -->
              <div style="background:#1a1c1e;padding:10px;border-radius:6px;border:1px solid #333;min-height:60px;">
                <div style="color:#888;font-size:14px;margin-bottom:8px;">مراکز فروش انتخاب شده:</div>
                <div id="selected_sales_centers" style="display:flex;flex-wrap:wrap;gap:6px;">
                  <div style="color:#888;font-size:13px;" id="no_selection_message">هیچ مرکز فروشی انتخاب نشده است</div>
                </div>
              </div>
            </div>
            
            <div style="display:flex;gap:8px;margin-top:20px;">
              <button type="submit" id="save_advisor" class="btn btn-primary">ذخیره</button>
              <button type="button" id="cancel_advisor_form" class="btn btn-ghost">انصراف</button>
            </div>
          </form>
        </div>
        
        <!-- Advisors List -->
        <div id="advisors_list" style="margin-top:20px;">
          <h4>فهرست مشاورین</h4>
          <div id="advisors_container" style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;">
            <div id="advisors_table_container">
              <table style="width:100%;border-collapse:collapse;" id="advisors_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">نام کامل</th>
                    <th style="text-align:center;padding:8px 4px;">موبایل</th>
                    <th style="text-align:center;padding:8px 4px;">شعبه</th>
                    <th style="text-align:center;padding:8px 4px;">مراکز فروش</th>
                    <th style="text-align:center;padding:8px 4px;">عملیات</th>
                  </tr>
                </thead>
                <tbody id="advisors_table_body">
                  <tr><td colspan="5" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Virtual Card Management Form (initially hidden) -->
      <?php 
      if (can_manage_vcards($admin_mobile)): 
      ?>
      <div id="vcard_management_form" style="display:none">
        <div style="text-align:center;">
          <h3>مدیریت کارت های مجازی</h3>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;">
          <button id="add_new_vcard" class="btn btn-primary">افزودن کارت مجازی جدید</button>
          <button id="back_to_menu_vcard" class="btn btn-ghost">بازگشت</button>
        </div>
        
        <div id="vcard_msg" class="msg"></div>
        
        <!-- Add/Edit VCard Form (initially hidden) -->
        <div id="vcard_form" style="display:none;margin-top:20px;background:rgba(0,0,0,0.3);padding:20px;border-radius:8px;">
          <h4 id="vcard_form_title">افزودن کارت مجازی جدید</h4>
          <form id="vcard_form_element">
            <input type="hidden" id="vcard_id" value="">
            
            <div style="margin-bottom:12px;">
              <label>شماره کارت 16 رقمی (الزامی)</label>
              <input type="text" id="vcard_number" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="1234567890123456" maxlength="16">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>شماره موبایل (اختیاری - در صورت عدم ورود، خودکار تولید می‌شود)</label>
              <input type="text" id="vcard_mobile" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="09xxxxxxxxx">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>نام کامل (اختیاری)</label>
              <input type="text" id="vcard_full_name" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="نام و نام خانوادگی">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>مبلغ اعتبار (تومان)</label>
              <input type="text" id="vcard_credit_amount" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="مثال: 250000" inputmode="numeric">
              <div style="font-size:12px;color:#888;margin-top:4px;">مبلغ مستقیما به اعتبار کاربر اضافه می‌شود</div>
            </div>
            
            <div style="display:flex;gap:8px;margin-top:20px;">
              <button type="submit" id="save_vcard" class="btn btn-primary">ذخیره</button>
              <button type="button" id="cancel_vcard_form" class="btn btn-ghost">انصراف</button>
            </div>
          </form>
        </div>
        
        <!-- VCards List -->
        <div id="vcards_list" style="margin-top:20px;">
          <h4>فهرست کارت های مجازی</h4>
          <div id="vcards_container" style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;">
            <div id="vcards_table_container">
              <table style="width:100%;border-collapse:collapse;" id="vcards_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">شماره کارت</th>
                    <th style="text-align:center;padding:8px 4px;">نام کامل</th>
                    <th style="text-align:center;padding:8px 4px;">موبایل</th>
                    <th style="text-align:center;padding:8px 4px;">اعتبار</th>
                    <th style="text-align:center;padding:8px 4px;">عملیات</th>
                  </tr>
                </thead>
                <tbody id="vcards_table_body">
                  <tr><td colspan="5" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Gift Credit Management Form (initially hidden) -->
      <?php 
      if (can_manage_gift_credits($admin_mobile)): 
      ?>
      <div id="gift_credit_management_form" style="display:none">
        <div style="text-align:center;">
          <h3>مدیریت اعتبار هدیه</h3>
        </div>
        
        <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;">
          <button id="add_new_gift_credit" class="btn btn-primary">افزودن اعتبار هدیه جدید</button>
          <button id="back_to_menu_gift_credit" class="btn btn-ghost">بازگشت</button>
        </div>
        
        <div id="gift_credit_msg" class="msg"></div>
        
        <!-- Add Gift Credit Form (initially hidden) -->
        <div id="gift_credit_form" style="display:none;margin-top:20px;background:rgba(0,0,0,0.3);padding:20px;border-radius:8px;">
          <h4 id="gift_credit_form_title">افزودن اعتبار هدیه جدید</h4>
          <form id="gift_credit_form_element">
            
            <div style="margin-bottom:12px;">
              <label>شماره موبایل مشترک (الزامی)</label>
              <input type="text" id="gift_credit_mobile" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="09xxxxxxxxx">
            </div>
            
            <div style="margin-bottom:12px;">
              <label>مبلغ هدیه (تومان)</label>
              <input type="text" id="gift_credit_amount" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="مثال: 250000" inputmode="numeric">
              <div style="font-size:12px;color:#888;margin-top:4px;">مبلغ مستقیما به اعتبار کاربر اضافه می‌شود</div>
            </div>
            
            <div style="margin-bottom:12px;">
              <label>یادداشت (اختیاری)</label>
              <textarea id="gift_credit_notes" style="width:100%;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;resize:vertical;min-height:60px;" placeholder="توضیحات دلیل اعطای اعتبار هدیه..."></textarea>
            </div>
            
            <div style="display:flex;gap:8px;margin-top:20px;">
              <button type="submit" id="save_gift_credit" class="btn btn-primary">ذخیره</button>
              <button type="button" id="cancel_gift_credit_form" class="btn btn-ghost">انصراف</button>
            </div>
          </form>
        </div>
        
        <!-- Gift Credits List -->
        <div id="gift_credits_list" style="margin-top:20px;">
          <h4>فهرست اعتبارات هدیه</h4>
          
          <!-- Search and Filter -->
          <div style="margin-bottom:15px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="gift_credit_search_mobile" style="flex:1;min-width:200px;padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;" placeholder="جستجو بر اساس شماره موبایل...">
            <button id="search_gift_credits" class="btn btn-primary">جستجو</button>
            <button id="clear_gift_credit_search" class="btn btn-ghost">پاک کردن</button>
          </div>
          
          <div id="gift_credits_container" style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;">
            <div id="gift_credits_table_container">
              <table style="width:100%;border-collapse:collapse;" id="gift_credits_table">
                <thead>
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
                    <th style="text-align:right;padding:8px 4px;">شماره موبایل</th>
                    <th style="text-align:center;padding:8px 4px;">نام مشترک</th>
                    <th style="text-align:center;padding:8px 4px;">مبلغ (تومان)</th>
                    <th style="text-align:center;padding:8px 4px;">اعتبار</th>
                    <th style="text-align:center;padding:8px 4px;">تاریخ</th>
                    <th style="text-align:center;padding:8px 4px;">عملیات</th>
                  </tr>
                </thead>
                <tbody id="gift_credits_table_body">
                  <tr><td colspan="6" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>
<?php endif; ?>
    </div>
  </div>
  <script>
    function postJSON(body){ 
      return fetch('admin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(body)
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok: ' + response.status + ' ' + response.statusText);
        }
        return response.json();
      })
      .catch(error => {
        console.error('Error in postJSON:', error);
        throw error; // Re-throw to be caught by the calling function
      });
    }
    
    // Function to determine if the current user can delete a transaction
    function determineDeleteAccess(transaction) {
      // Manager can delete any transaction
      if (<?php echo json_encode(!empty($_SESSION['is_manager'])); ?>) {
        return true;
      }
      
      // Get current admin mobile and branch
      const currentAdminMobile = <?php echo json_encode($_SESSION['admin_mobile'] ?? ''); ?>;
      const currentBranchId = <?php echo json_encode($_SESSION['branch_id'] ?? 1); ?>;
      
      // Sellers can only delete their own transactions
      const transactionAdmin = transaction.admin_mobile || transaction.admin_number;
      const transactionBranchId = transaction.branch_id || 0;
      
      // Rule 1: Only show delete button for own transactions (not other sellers in same branch)
      if (transactionAdmin !== currentAdminMobile) {
        return false;
      }
      
      // Rule 3: Don't show delete button for transactions from other branches
      if (transactionBranchId !== currentBranchId) {
        return false;
      }
      
      // Check time restriction (6 hours) for own transactions
      const transactionTime = new Date(transaction.date).getTime();
      const currentTime = new Date().getTime();
      const hoursPassed = (currentTime - transactionTime) / (1000 * 60 * 60);
      
      return hoursPassed <= 6;
    }
    
    // Format time function for displaying transaction times
    function formatTime(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      let hours = date.getHours();
      let minutes = date.getMinutes();
      
      // Add leading zeros
      hours = hours < 10 ? '0' + hours : hours;
      minutes = minutes < 10 ? '0' + minutes : minutes;
      
      return hours + ':' + minutes;
    }
    
    // Format date in Gregorian calendar
    function formatGregorianDate(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
      ];
      
      return date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();
    }
    
    // Number formatting function - optimized
    function formatWithDots(s){
      if (s === null || s === undefined) return '';
      
      // Direct conversion to number and floor
      const num = Math.floor(Number(s));
      
      // Early return if not a number
      if (isNaN(num)) return '0';
      
      // Convert to string and format with dots
      return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    // Function to extract raw number (remove formatting) - optimized
    function extractRawNumber(s){
      if (!s) return '';
      // For Persian content, maintain conversion
      if (/[\u06F0-\u06F9]/.test(s)) {
        s = s.replace(/[\u06F0-\u06F9]/g, function(d){ return String('0123456789').charAt(d.charCodeAt(0)-0x06F0); });
      }
      // Remove non-digits - faster approach
      return s.replace(/\D/g,'');
    }

    <?php if (!$is_admin): ?>
    document.getElementById('send_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); var msg = document.getElementById('login_msg'); msg.textContent='';
      if (!m) { msg.textContent='لطفاً شماره موبایل را وارد کنید.'; return; }
      postJSON({action:'send_otp',mobile:m}).then(json=>{ if (json.status==='success'){ document.getElementById('login-form').style.display='none'; document.getElementById('otp-form').style.display='block'; } else { msg.textContent = json.message || 'خطا'; } }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    document.getElementById('verify_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); 
      var code = document.getElementById('otp_code').value.trim(); 
      var msg = document.getElementById('otp_msg'); 
      msg.textContent='';
      
      // Default to sales center ID 1 (will be set properly on server side)
      var salesCenterId = 1;
      
      if (!m || !code) { 
        msg.textContent='لطفاً شماره و کد را وارد کنید.'; 
        return; 
      }
      
      postJSON({
        action: 'verify_otp',
        mobile: m,
        otp: code,
        sales_center_id: salesCenterId
      }).then(json=>{ 
        if (json.status==='success'){ 
          location.reload(); 
        } else { 
          msg.textContent = json.message || 'کد نامعتبر'; 
        } 
      }).catch(()=>{ 
        msg.textContent='خطا در اتصال'; 
      });
    });
    <?php else: ?>
    
    // Set up amount input formatting
    const subAmountInput = document.getElementById('sub_amount');
    const subAmountRawInput = document.getElementById('sub_amount_raw');
    
    if (subAmountInput && subAmountRawInput) {
      subAmountInput.addEventListener('input', function(){
        var raw = extractRawNumber(this.value);
        subAmountRawInput.value = raw;
        var formatted = formatWithDots(raw);
        this.value = formatted;
      });
      
      subAmountInput.addEventListener('blur', function(){
        this.value = formatWithDots(subAmountRawInput.value);
      });
    }
    
    document.getElementById('logout').addEventListener('click', function(){ postJSON({action:'logout'}).then(()=>location.reload()); });
    
    // Main menu buttons
    var purchaseBtn = document.getElementById('btn_purchase');
    if (purchaseBtn) {
      purchaseBtn.addEventListener('click', function(){
      document.getElementById('btn_purchase').parentNode.style.display = 'none';
      document.getElementById('purchase_form').style.display = 'block';
      
      // Check if branch has multiple stores or single store
      var salesCenterSelect = document.getElementById('sub_sales_center');
      var hasMultipleStores = <?php echo json_encode($has_multiple_stores); ?>;
      
      if (hasMultipleStores) {
        // Multiple stores - load advisors if sales center is already selected
        if (salesCenterSelect && salesCenterSelect.value) {
          loadAdvisorsForSalesCenter(salesCenterSelect.value);
        }
      } else {
        // Single store - automatically load advisors for the default sales center (ID: 1)
        loadAdvisorsForSalesCenter(1);
      }
    });
    }
    
    // Add sales center change listener for advisor loading (only for multiple stores)
    var salesCenterSelect = document.getElementById('sub_sales_center');
    var hasMultipleStores = <?php echo json_encode($has_multiple_stores); ?>;
    
    if (salesCenterSelect && hasMultipleStores) {
      salesCenterSelect.addEventListener('change', function() {
        loadAdvisorsForSalesCenter(this.value);
      });
      
      // Load advisors on page load if sales center is already selected
      if (salesCenterSelect.value) {
        loadAdvisorsForSalesCenter(salesCenterSelect.value);
      }
    } else if (!hasMultipleStores) {
      // For single store branches, automatically load advisors when DOM is ready
      document.addEventListener('DOMContentLoaded', function() {
        // Small delay to ensure form is ready
        setTimeout(function() {
          if (document.getElementById('advisor_selection_container')) {
            loadAdvisorsForSalesCenter(1);
          }
        }, 100);
      });
    }
    
    // Function to load advisors for a specific sales center
    function loadAdvisorsForSalesCenter(salesCenterId) {
      const container = document.getElementById('advisor_selection_container');
      if (!container) return;
      
      // Show loading state
      container.innerHTML = '<div style="color:#aaa;padding:8px;text-align:center;">در حال بارگذاری فروشندگان...</div>';
      
      // Get current branch ID from session or default
      const branchId = <?php echo json_encode(isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch()); ?>;
      
      postJSON({
        action: 'get_advisors_for_purchase',
        branch_id: branchId,
        sales_center_id: salesCenterId
      }).then(json => {
        if (json.status === 'success') {
          displayAdvisors(json.advisors);
        } else {
          container.innerHTML = '<div style="color:#ff5252;padding:8px;text-align:center;">خطا در بارگذاری فروشندگان</div>';
        }
      }).catch(error => {
        console.error('Error loading advisors:', error);
        container.innerHTML = '<div style="color:#ff5252;padding:8px;text-align:center;">خطا در اتصال به سرور</div>';
      });
    }
    
    // Function to display advisors in the selection container
    function displayAdvisors(advisors) {
      const container = document.getElementById('advisor_selection_container');
      if (!container) return;
      
      if (advisors.length === 0) {
        container.innerHTML = '<div style="color:#aaa;padding:8px;border:1px dashed #555;border-radius:6px;text-align:center;">هیچ فروشنده‌ای برای این فروشگاه یافت نشد</div>';
        return;
      }
      
      // Calculate items per column for roughly equal distribution
      const itemsPerColumn = Math.ceil(advisors.length / 2);
      const leftColumnAdvisors = advisors.slice(0, itemsPerColumn);
      const rightColumnAdvisors = advisors.slice(itemsPerColumn);
      
      let html = '<div style="border:1px solid #555;border-radius:6px;padding:8px;background:#1a1a1a;">';
      html += '<div style="display:flex;gap:16px;">';
      
      // Left column
      html += '<div style="flex:1;">';
      leftColumnAdvisors.forEach(advisor => {
        html += `
          <label style="display:flex;align-items:center;padding:4px 0;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.1);">
            <input type="checkbox" class="advisor-checkbox" value="${advisor.id}" style="margin-left:8px;">
            <span style="flex:1;">
              ${advisor.full_name}
            </span>
          </label>
        `;
      });
      html += '</div>';
      
      // Right column
      html += '<div style="flex:1;">';
      rightColumnAdvisors.forEach(advisor => {
        html += `
          <label style="display:flex;align-items:center;padding:4px 0;cursor:pointer;border-bottom:1px solid rgba(255,255,255,0.1);">
            <input type="checkbox" class="advisor-checkbox" value="${advisor.id}" style="margin-left:8px;">
            <span style="flex:1;">
              ${advisor.full_name}
            </span>
          </label>
        `;
      });
      html += '</div>';
      
      html += '</div>'; // Close flex container
      html += '</div>'; // Close main container
      html += '<div style="margin-top:4px;font-size:12px;color:#aaa;">می‌توانید یک یا چند فروشنده را انتخاب کنید</div>';
      
      container.innerHTML = html;
    }
    
    var inquiryBtn = document.getElementById('btn_inquiry');
    console.log('Inquiry button found:', !!inquiryBtn);
    if (inquiryBtn) {
      inquiryBtn.addEventListener('click', function(){
        document.getElementById('btn_inquiry').parentNode.style.display = 'none';
        document.getElementById('inquiry_form').style.display = 'block';
      });
    } else {
      console.log('Inquiry button not found');
    }
    
    document.getElementById('btn_today_report').addEventListener('click', function(){
      document.getElementById('btn_today_report').parentNode.style.display = 'none';
      document.getElementById('today_report_form').style.display = 'block';
      
      // Explicitly load today's data when button is clicked with Tehran timezone
      const today = getTehranDate();
      today.setHours(0, 0, 0, 0);
      currentReportDate = today; // Reset to today
      
      // Format date properly for Tehran timezone
      const year = today.getFullYear();
      const month = String(today.getMonth() + 1).padStart(2, '0');
      const day = String(today.getDate()).padStart(2, '0');
      const formattedDate = `${year}-${month}-${day}`;
      
      console.log('Today report button clicked. Tehran date:', formattedDate);
      loadTodayReport(formattedDate);
    });
    
    // Back buttons
    document.getElementById('back_to_menu').addEventListener('click', function(){
      document.getElementById('purchase_form').style.display = 'none';
      document.getElementById('btn_purchase').parentNode.style.display = 'flex';
    });
    
    document.getElementById('back_to_menu_inquiry').addEventListener('click', function(){
      document.getElementById('inquiry_form').style.display = 'none';
      document.getElementById('btn_inquiry').parentNode.style.display = 'flex';
      // Reset the form when going back to menu
      document.getElementById('inquiry_mobile').value = '';
      document.getElementById('inquiry_msg').textContent = '';
      document.getElementById('member_info').style.display = 'none';
    });
    
    document.getElementById('back_to_menu_report').addEventListener('click', function(){
      document.getElementById('today_report_form').style.display = 'none';
      document.getElementById('btn_today_report').parentNode.style.display = 'flex';
    });
    
    // Advisor management button and functions
    <?php if (can_manage_advisors($admin_mobile)): ?>
    
    // Initialize global variables for advisor management
    window.selectedSalesCenters = [];
    window.allSalesCentersData = [];
    
    var advisorBtn = document.getElementById('btn_advisor_management');
    if (advisorBtn) {
      advisorBtn.addEventListener('click', function(){
        document.getElementById('btn_advisor_management').parentNode.style.display = 'none';
        document.getElementById('advisor_management_form').style.display = 'block';
        loadAdvisorsData();
      });
    }
    
    var backToMenuAdvisor = document.getElementById('back_to_menu_advisor');
    if (backToMenuAdvisor) {
      backToMenuAdvisor.addEventListener('click', function(){
        document.getElementById('advisor_management_form').style.display = 'none';
        document.getElementById('btn_advisor_management').parentNode.style.display = 'flex';
        resetAdvisorForm();
      });
    }
    
    var addNewAdvisor = document.getElementById('add_new_advisor');
    if (addNewAdvisor) {
      addNewAdvisor.addEventListener('click', function(){
        resetAdvisorForm();
        document.getElementById('advisor_form_title').textContent = 'افزودن مشاور جدید';
        document.getElementById('advisor_form').style.display = 'block';
      });
    }
    
    var cancelAdvisorForm = document.getElementById('cancel_advisor_form');
    if (cancelAdvisorForm) {
      cancelAdvisorForm.addEventListener('click', function(){
        resetAdvisorForm();
        document.getElementById('advisor_form').style.display = 'none';
      });
    }    // Sales center selection logic
    document.getElementById('available_sales_centers').addEventListener('change', function(){
      const selectedValue = this.value;
      if (selectedValue) {
        const selectedOption = this.options[this.selectedIndex];
        addSalesCenter(selectedValue, selectedOption.dataset.displayName, selectedOption.dataset.branchId, selectedOption.dataset.salesCenterId);
        this.value = ''; // Reset dropdown
      }
    });
    
    function addSalesCenter(value, displayName, branchId, salesCenterId) {
      const selectedContainer = document.getElementById('selected_sales_centers');
      const noSelectionMessage = document.getElementById('no_selection_message');
      const availableSelect = document.getElementById('available_sales_centers');
      
      // Remove the "no selection" message if it exists
      if (noSelectionMessage) {
        noSelectionMessage.remove();
      }
      
      // Check if already selected
      if (window.selectedSalesCenters.find(sc => sc.value === value)) {
        return;
      }
      
      // Add to selected items array
      window.selectedSalesCenters.push({
        value: value,
        displayName: displayName,
        branch_id: parseInt(branchId),
        sales_center_id: parseInt(salesCenterId)
      });
      
      // Create selected item element
      const selectedItem = document.createElement('div');
      selectedItem.style.cssText = 'background:#4caf50;color:white;padding:4px 8px;border-radius:4px;font-size:13px;display:flex;align-items:center;gap:6px;';
      selectedItem.innerHTML = `
        <span>${displayName}</span>
        <button type="button" onclick="removeSalesCenter('${value}')" style="background:rgba(255,255,255,0.2);border:none;color:white;border-radius:50%;width:18px;height:18px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;">×</button>
      `;
      
      selectedContainer.appendChild(selectedItem);
      
      // Remove from available options
      const optionToRemove = availableSelect.querySelector(`option[value="${value}"]`);
      if (optionToRemove) {
        optionToRemove.remove();
      }
      
      console.log('Added sales center:', displayName, 'Current selections:', window.selectedSalesCenters);
    }
    
    function removeSalesCenter(value) {
      const selectedContainer = document.getElementById('selected_sales_centers');
      const availableSelect = document.getElementById('available_sales_centers');
      
      // Find and remove from selected items array
      const itemIndex = window.selectedSalesCenters.findIndex(sc => sc.value === value);
      if (itemIndex > -1) {
        const removedItem = window.selectedSalesCenters.splice(itemIndex, 1)[0];
        
        // Remove the visual element
        const itemElement = selectedContainer.querySelector(`div:has(button[onclick="removeSalesCenter('${value}')"])`);
        if (itemElement) {
          itemElement.remove();
        }
        
        // Add back to available options
        const option = document.createElement('option');
        option.value = value;
        option.textContent = removedItem.displayName;
        option.dataset.branchId = removedItem.branch_id;
        option.dataset.salesCenterId = removedItem.sales_center_id;
        option.dataset.displayName = removedItem.displayName;
        availableSelect.appendChild(option);
        
        // Show "no selection" message if no items selected
        if (window.selectedSalesCenters.length === 0) {
          selectedContainer.innerHTML = '<div style="color:#888;font-size:13px;" id="no_selection_message">هیچ مرکز فروشی انتخاب نشده است</div>';
        }
        
        console.log('Removed sales center:', removedItem.displayName, 'Current selections:', window.selectedSalesCenters);
      }
    }
    
    // Make removeSalesCenter globally accessible
    window.removeSalesCenter = removeSalesCenter;
    
    // Advisor form submission
    document.getElementById('advisor_form_element').addEventListener('submit', function(e){
      e.preventDefault();
      
      const advisorId = document.getElementById('advisor_id').value;
      const fullName = document.getElementById('advisor_full_name').value.trim();
      const mobile = document.getElementById('advisor_mobile').value.trim();
      const msg = document.getElementById('advisor_msg');
      
      // Get selected sales centers from the new structure
      const salesCenters = [];
      
      // Ensure the global variable exists
      if (!window.selectedSalesCenters) {
        window.selectedSalesCenters = [];
      }
      
      if (window.selectedSalesCenters && window.selectedSalesCenters.length > 0) {
        window.selectedSalesCenters.forEach((sc) => {
          const salesCenterObj = {
            branch_id: sc.branch_id,
            sales_center_id: sc.sales_center_id
          };
          salesCenters.push(salesCenterObj);
        });
      }
      
      console.log('Sales centers for submission:', salesCenters);

      msg.textContent = '';

      if (!fullName) {
        msg.textContent = 'نام کامل الزامی است';
        return;
      }

      if (salesCenters.length === 0) {
        msg.textContent = 'حداقل یک مرکز فروش باید انتخاب شود';
        return;
      }
      
      // If we reach here, validation passed - proceed with submission
      console.log('All validations passed. Proceeding with form submission...');
      
      const action = advisorId ? 'update_advisor' : 'add_advisor';
      const data = {
        action: action,
        full_name: fullName,
        mobile_number: mobile
      };
      
      if (advisorId) {
        data.advisor_id = advisorId;
      }
      
      // Add sales centers data properly formatted for URLSearchParams
      salesCenters.forEach((sc, index) => {
        data[`sales_centers[${index}][branch_id]`] = sc.branch_id;
        data[`sales_centers[${index}][sales_center_id]`] = sc.sales_center_id;
      });
      
      msg.textContent = 'در حال پردازش...';
      
      postJSON(data).then(json => {
        if (json.status === 'success') {
          msg.textContent = json.message;
          msg.style.color = '#4caf50';
          resetAdvisorForm();
          document.getElementById('advisor_form').style.display = 'none';
          loadAdvisorsData(); // Reload the list
        } else {
          msg.textContent = json.message || 'خطا در عملیات';
          msg.style.color = '#ff5252';
        }
      }).catch(error => {
        console.error('Advisor operation error:', error);
        msg.textContent = 'خطا در اتصال به سرور';
        msg.style.color = '#ff5252';
      });
    });
    
    // Advisor management functions
    function loadAdvisorsData() {
      const msg = document.getElementById('advisor_msg');
      const tableBody = document.getElementById('advisors_table_body');
      
      msg.textContent = '';
      tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      
      postJSON({action: 'get_advisors'}).then(json => {
        if (json.status === 'success') {
          // Store all sales centers data globally
          window.allSalesCentersData = json.all_sales_centers;
          
          // Populate all sales centers immediately
          populateAllSalesCenters();
          
          // Populate advisors table
          if (json.advisors && json.advisors.length > 0) {
            let advisorsHtml = '';
            json.advisors.forEach(advisor => {
              const salesCenterNames = advisor.sales_center_names.join(', ') || '-';
              advisorsHtml += `
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                  <td style="padding:8px 4px;">${advisor.full_name}</td>
                  <td style="padding:8px 4px;text-align:center;">${advisor.mobile_number || '-'}</td>
                  <td style="padding:8px 4px;text-align:center;">${advisor.branch_name}</td>
                  <td style="padding:8px 4px;text-align:center;">${salesCenterNames}</td>
                  <td style="padding:8px 4px;text-align:center;">
                    <button class="btn-edit-advisor" data-id="${advisor.id}" style="background:#2196f3;color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;margin-left:4px;">ویرایش</button>
                    <button class="btn-delete-advisor" data-id="${advisor.id}" style="background:#ff5252;color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;">حذف</button>
                  </td>
                </tr>
              `;
            });
            tableBody.innerHTML = advisorsHtml;
            
            // Add event listeners for edit and delete buttons
            document.querySelectorAll('.btn-edit-advisor').forEach(btn => {
              btn.addEventListener('click', function() {
                editAdvisor(this.dataset.id, json.advisors);
              });
            });
            
            document.querySelectorAll('.btn-delete-advisor').forEach(btn => {
              btn.addEventListener('click', function() {
                deleteAdvisor(this.dataset.id);
              });
            });
          } else {
            tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#b0b3b8;">هیچ مشاوری ثبت نشده است</td></tr>';
          }
        } else {
          msg.textContent = json.message || 'خطا در دریافت اطلاعات';
          msg.style.color = '#ff5252';
          tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
        }
      }).catch(error => {
        console.error('Error loading advisors:', error);
        msg.textContent = 'خطا در اتصال به سرور';
        msg.style.color = '#ff5252';
        tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#ff5252;">خطا در اتصال به سرور</td></tr>';
      });
    }
    
    function editAdvisor(advisorId, advisors) {
      const advisor = advisors.find(a => a.id == advisorId);
      if (!advisor) return;
      
      // Populate form with advisor data
      document.getElementById('advisor_id').value = advisor.id;
      document.getElementById('advisor_full_name').value = advisor.full_name;
      document.getElementById('advisor_mobile').value = advisor.mobile_number || '';
      
      // Populate all sales centers first
      populateAllSalesCenters();
      
      // Wait a bit for the sales centers to be populated, then select them
      setTimeout(() => {
        advisor.sales_centers.forEach(scInfo => {
          let value, branchId, salesCenterId;
          
          if (typeof scInfo === 'object' && scInfo.branch_id && scInfo.sales_center_id) {
            // New format
            branchId = scInfo.branch_id;
            salesCenterId = scInfo.sales_center_id;
            value = `${branchId}_${salesCenterId}`;
          } else {
            // Old format - try to find it in the current branch
            branchId = advisor.branch_id;
            salesCenterId = scInfo;
            value = `${branchId}_${salesCenterId}`;
          }
          
          // Find the sales center in the available data
          const salesCenterData = window.allSalesCentersData.find(sc => 
            sc.branch_id == branchId && sc.sales_center_id == salesCenterId
          );
          
          if (salesCenterData) {
            addSalesCenter(value, salesCenterData.display_name, branchId, salesCenterId);
          }
        });
      }, 100);
      
      document.getElementById('advisor_form_title').textContent = 'ویرایش مشاور';
      document.getElementById('advisor_form').style.display = 'block';
    }
    
    function deleteAdvisor(advisorId) {
      if (!confirm('آیا از حذف این مشاور اطمینان دارید؟')) {
        return;
      }
      
      const msg = document.getElementById('advisor_msg');
      msg.textContent = 'در حال حذف...';
      msg.style.color = '#ffc107';
      
      postJSON({
        action: 'remove_advisor',
        advisor_id: advisorId
      }).then(json => {
        if (json.status === 'success') {
          msg.textContent = json.message;
          msg.style.color = '#4caf50';
          loadAdvisorsData(); // Reload the list
        } else {
          msg.textContent = json.message || 'خطا در حذف مشاور';
          msg.style.color = '#ff5252';
        }
      }).catch(error => {
        console.error('Error deleting advisor:', error);
        msg.textContent = 'خطا در اتصال به سرور';
        msg.style.color = '#ff5252';
      });
    }
    
    function populateAllSalesCenters() {
      const availableSelect = document.getElementById('available_sales_centers');
      const selectedContainer = document.getElementById('selected_sales_centers');
      const noSelectionMessage = document.getElementById('no_selection_message');
      
      console.log('populateAllSalesCenters called');
      console.log('allSalesCentersData:', window.allSalesCentersData);
      
      if (!window.allSalesCentersData || window.allSalesCentersData.length === 0) {
        console.log('No sales centers data available');
        availableSelect.innerHTML = '<option value="">مرکز فروشی یافت نشد</option>';
        return;
      }
      
      // Clear and populate the dropdown with all sales centers
      availableSelect.innerHTML = '<option value="">انتخاب مرکز فروش...</option>';
      window.allSalesCentersData.forEach(sc => {
        const value = `${sc.branch_id}_${sc.sales_center_id}`;
        console.log('Creating option for:', sc.display_name, 'with value:', value);
        const option = document.createElement('option');
        option.value = value;
        option.textContent = sc.display_name;
        option.dataset.branchId = sc.branch_id;
        option.dataset.salesCenterId = sc.sales_center_id;
        option.dataset.displayName = sc.display_name;
        availableSelect.appendChild(option);
      });
      
      // Clear selected items
      selectedContainer.innerHTML = '<div style="color:#888;font-size:13px;" id="no_selection_message">هیچ مرکز فروشی انتخاب نشده است</div>';
      
      // Store selected items globally for form submission
      window.selectedSalesCenters = [];
      
      console.log('Sales centers dropdown populated');
    }
    
    function resetAdvisorForm() {
      document.getElementById('advisor_id').value = '';
      document.getElementById('advisor_full_name').value = '';
      document.getElementById('advisor_mobile').value = '';
      
      // Reset the new multi-select structure
      populateAllSalesCenters();
      
      document.getElementById('advisor_msg').textContent = '';
    }
    <?php endif; ?>
    
    <?php if (can_manage_vcards($admin_mobile)): ?>
    // VCard management event handlers
    
    // Add error handling to ensure elements exist
    var vcardBtn = document.getElementById('btn_vcard_management');
    if (vcardBtn) {
      vcardBtn.addEventListener('click', function(){
        document.getElementById('btn_vcard_management').parentNode.style.display = 'none';
        document.getElementById('vcard_management_form').style.display = 'block';
        loadVCardsData();
      });
    }
    
    var backToMenuVcard = document.getElementById('back_to_menu_vcard');
    if (backToMenuVcard) {
      backToMenuVcard.addEventListener('click', function(){
        document.getElementById('vcard_management_form').style.display = 'none';
        document.getElementById('btn_vcard_management').parentNode.style.display = 'flex';
        resetVCardForm();
      });
    }
    
    var addNewVcard = document.getElementById('add_new_vcard');
    if (addNewVcard) {
      addNewVcard.addEventListener('click', function(){
        resetVCardForm();
        document.getElementById('vcard_form_title').textContent = 'افزودن کارت مجازی جدید';
        document.getElementById('vcard_form').style.display = 'block';
      });
    }
    
    var cancelVcardForm = document.getElementById('cancel_vcard_form');
    if (cancelVcardForm) {
      cancelVcardForm.addEventListener('click', function(){
        resetVCardForm();
        document.getElementById('vcard_form').style.display = 'none';
      });
    }
    
    // VCard form submission
    var vcardFormElement = document.getElementById('vcard_form_element');
    if (vcardFormElement) {
      vcardFormElement.addEventListener('submit', function(e){
      e.preventDefault();
      
      const vcard_id = document.getElementById('vcard_id').value;
      const vcard_number = document.getElementById('vcard_number').value.trim();
      const vcard_mobile = document.getElementById('vcard_mobile').value.trim();
      const vcard_full_name = document.getElementById('vcard_full_name').value.trim();
      const vcard_credit_amount = extractRawNumber(document.getElementById('vcard_credit_amount').value);
      
      const msg = document.getElementById('vcard_msg');
      msg.textContent = '';
      
      if (!vcard_number) {
        msg.textContent = 'شماره کارت الزامی است';
        msg.style.color = '#ff5252';
        return;
      }
      
      if (vcard_number.length !== 16 || !/^\d+$/.test(vcard_number)) {
        msg.textContent = 'شماره کارت باید دقیقاً 16 رقم باشد';
        msg.style.color = '#ff5252';
        return;
      }
      
      const action = vcard_id ? 'update_vcard' : 'create_vcard';
      const requestData = {
        action: action,
        vcard_number: vcard_number,
        mobile_number: vcard_mobile,
        full_name: vcard_full_name,
        credit_amount: vcard_credit_amount || '0'
      };
      
      if (vcard_id) {
        requestData.vcard_id = vcard_id;
      }
      
      postJSON(requestData).then(json => {
        if (json.status === 'success') {
          msg.textContent = json.message;
          msg.style.color = '#4caf50';
          resetVCardForm();
          document.getElementById('vcard_form').style.display = 'none';
          loadVCardsData();
        } else {
          msg.textContent = json.message || 'خطا در عملیات';
          msg.style.color = '#ff5252';
        }
      }).catch(() => {
          msg.textContent = 'خطا در اتصال به سرور';
          msg.style.color = '#ff5252';
        });
      });
    }
    
    // VCard credit amount formatting
    var vcardCreditAmount = document.getElementById('vcard_credit_amount');
    if (vcardCreditAmount) {
      vcardCreditAmount.addEventListener('input', function(){
        let value = extractRawNumber(this.value);
        if (value) {
          this.value = formatWithDots(value);
        }
      });
    }    // VCard management functions
    function loadVCardsData() {
      const msg = document.getElementById('vcard_msg');
      const tableBody = document.getElementById('vcards_table_body');
      
      msg.textContent = '';
      tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      
      postJSON({action: 'get_vcards'}).then(json => {
        if (json.status === 'success') {
          if (json.vcards && json.vcards.length > 0) {
            let vcardsHtml = '';
            json.vcards.forEach(vcard => {
              const creditToman = (vcard.credit * 5000).toLocaleString();
              vcardsHtml += `
                <tr style="border-bottom:1px solid rgba(255,255,255,0.05);">
                  <td style="padding:8px 4px;font-family:monospace;">${vcard.vcard_number}</td>
                  <td style="padding:8px 4px;text-align:center;">${vcard.full_name || '-'}</td>
                  <td style="padding:8px 4px;text-align:center;">${vcard.mobile}</td>
                  <td style="padding:8px 4px;text-align:center;">${creditToman} تومان (${vcard.credit} امتیاز)</td>
                  <td style="padding:8px 4px;text-align:center;">
                    <button class="btn-edit-vcard" data-id="${vcard.id}" style="background:#2196f3;color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;margin-left:4px;">ویرایش</button>
                    <button class="btn-toggle-vcard" data-id="${vcard.id}" data-active="${vcard.full_name && !vcard.full_name.includes('[غیرفعال]') ? '1' : '0'}" style="background:${vcard.full_name && !vcard.full_name.includes('[غیرفعال]') ? '#ff9800' : '#4caf50'};color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;">${vcard.full_name && !vcard.full_name.includes('[غیرفعال]') ? 'غیرفعال' : 'فعال'}</button>
                  </td>
                </tr>
              `;
            });
            tableBody.innerHTML = vcardsHtml;
            
            // Add event listeners for edit and toggle buttons
            document.querySelectorAll('.btn-edit-vcard').forEach(btn => {
              btn.addEventListener('click', function() {
                editVCard(this.dataset.id, json.vcards);
              });
            });
            
            document.querySelectorAll('.btn-toggle-vcard').forEach(btn => {
              btn.addEventListener('click', function() {
                toggleVCardStatus(this.dataset.id, this.dataset.active === '1');
              });
            });
          } else {
            tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#b0b3b8;">هیچ کارت مجازی ثبت نشده است</td></tr>';
          }
        } else {
          msg.textContent = json.message || 'خطا در دریافت اطلاعات';
          msg.style.color = '#ff5252';
          tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
        }
      }).catch(() => {
        msg.textContent = 'خطا در اتصال به سرور';
        msg.style.color = '#ff5252';
        tableBody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
      });
    }
    
    function editVCard(vcardId, vcards) {
      const vcard = vcards.find(v => v.id == vcardId);
      if (!vcard) return;
      
      document.getElementById('vcard_id').value = vcard.id;
      document.getElementById('vcard_number').value = vcard.vcard_number;
      
      // Make mobile field read-only for editing existing vCards
      const mobileField = document.getElementById('vcard_mobile');
      const mobileLabel = mobileField.previousElementSibling;
      mobileField.value = vcard.mobile;
      mobileField.readOnly = true;
      mobileField.style.backgroundColor = '#333';
      mobileField.style.color = '#aaa';
      mobileField.title = 'شماره موبایل کارت مجازی قابل ویرایش نیست';
      if (mobileLabel) {
        mobileLabel.textContent = 'شماره موبایل (غیرقابل ویرایش)';
      }
      
      document.getElementById('vcard_full_name').value = (vcard.full_name || '').replace(' [غیرفعال]', '');
      document.getElementById('vcard_credit_amount').value = formatWithDots(vcard.credit * 5000);
      
      document.getElementById('vcard_form_title').textContent = 'ویرایش کارت مجازی';
      document.getElementById('vcard_form').style.display = 'block';
    }
    
    function toggleVCardStatus(vcardId, isActive) {
      const msg = document.getElementById('vcard_msg');
      const action = isActive ? 'غیرفعال' : 'فعال';
      
      if (confirm(`آیا از ${action} کردن این کارت مجازی اطمینان دارید؟`)) {
        postJSON({
          action: 'toggle_vcard_status',
          vcard_id: vcardId,
          active: !isActive
        }).then(json => {
          if (json.status === 'success') {
            msg.textContent = json.message;
            msg.style.color = '#4caf50';
            loadVCardsData();
          } else {
            msg.textContent = json.message || 'خطا در تغییر وضعیت';
            msg.style.color = '#ff5252';
          }
        }).catch(() => {
          msg.textContent = 'خطا در اتصال به سرور';
          msg.style.color = '#ff5252';
        });
      }
    }
    
    function resetVCardForm() {
      document.getElementById('vcard_id').value = '';
      document.getElementById('vcard_number').value = '';
      
      // Reset mobile field to be editable for new vCards
      const mobileField = document.getElementById('vcard_mobile');
      const mobileLabel = mobileField.previousElementSibling;
      mobileField.value = '';
      mobileField.readOnly = false;
      mobileField.style.backgroundColor = '#0d0d0d';
      mobileField.style.color = '#fff';
      mobileField.title = '';
      if (mobileLabel) {
        mobileLabel.textContent = 'شماره موبایل (اختیاری - در صورت عدم ورود، خودکار تولید می‌شود)';
      }
      
      document.getElementById('vcard_full_name').value = '';
      document.getElementById('vcard_credit_amount').value = '';
      document.getElementById('vcard_msg').textContent = '';
    }
    <?php endif; ?>
    
    <?php if (can_manage_gift_credits($admin_mobile)): ?>
    // Gift Credit management event handlers
    
    var giftCreditBtn = document.getElementById('btn_gift_credit_management');
    if (giftCreditBtn) {
      giftCreditBtn.addEventListener('click', function(){
        document.getElementById('btn_gift_credit_management').parentNode.style.display = 'none';
        document.getElementById('gift_credit_management_form').style.display = 'block';
        loadGiftCreditsData();
      });
    }
    
    var backToMenuGiftCredit = document.getElementById('back_to_menu_gift_credit');
    if (backToMenuGiftCredit) {
      backToMenuGiftCredit.addEventListener('click', function(){
        document.getElementById('gift_credit_management_form').style.display = 'none';
        document.getElementById('btn_gift_credit_management').parentNode.style.display = 'flex';
        resetGiftCreditForm();
      });
    }
    
    var addNewGiftCredit = document.getElementById('add_new_gift_credit');
    if (addNewGiftCredit) {
      addNewGiftCredit.addEventListener('click', function(){
        resetGiftCreditForm();
        document.getElementById('gift_credit_form_title').textContent = 'افزودن اعتبار هدیه جدید';
        document.getElementById('gift_credit_form').style.display = 'block';
      });
    }
    
    var cancelGiftCreditForm = document.getElementById('cancel_gift_credit_form');
    if (cancelGiftCreditForm) {
      cancelGiftCreditForm.addEventListener('click', function(){
        resetGiftCreditForm();
        document.getElementById('gift_credit_form').style.display = 'none';
      });
    }
    
    // Set up gift credit amount input formatting
    const giftCreditAmountInput = document.getElementById('gift_credit_amount');
    if (giftCreditAmountInput) {
      giftCreditAmountInput.addEventListener('input', function(){
        var raw = extractRawNumber(this.value);
        var formatted = formatWithDots(raw);
        this.value = formatted;
      });
      
      giftCreditAmountInput.addEventListener('blur', function(){
        this.value = formatWithDots(extractRawNumber(this.value));
      });
    }
    
    // Gift Credit form submission
    var giftCreditFormElement = document.getElementById('gift_credit_form_element');
    if (giftCreditFormElement) {
      giftCreditFormElement.addEventListener('submit', function(e){
        e.preventDefault();
        
        const mobile = document.getElementById('gift_credit_mobile').value.trim();
        const amount = extractRawNumber(document.getElementById('gift_credit_amount').value);
        const notes = document.getElementById('gift_credit_notes').value.trim();
        
        const msg = document.getElementById('gift_credit_msg');
        msg.textContent = '';
        
        if (!mobile) {
          msg.textContent = 'شماره موبایل الزامی است';
          msg.style.color = '#ff5252';
          return;
        }
        
        if (!amount || parseInt(amount) <= 0) {
          msg.textContent = 'مبلغ هدیه باید بیشتر از صفر باشد';
          msg.style.color = '#ff5252';
          return;
        }
        
        const requestData = {
          action: 'add_gift_credit',
          mobile: mobile,
          amount: amount,
          notes: notes
        };
        
        postJSON(requestData).then(json => {
          if (json.status === 'success') {
            msg.textContent = json.message;
            msg.style.color = '#4caf50';
            resetGiftCreditForm();
            document.getElementById('gift_credit_form').style.display = 'none';
            loadGiftCreditsData();
          } else {
            msg.textContent = json.message || 'خطا در ثبت اعتبار هدیه';
            msg.style.color = '#ff5252';
          }
        }).catch(() => {
          msg.textContent = 'خطا در اتصال به سرور';
          msg.style.color = '#ff5252';
        });
      });
    }
    
    // Gift Credit search functionality
    var searchGiftCreditsBtn = document.getElementById('search_gift_credits');
    if (searchGiftCreditsBtn) {
      searchGiftCreditsBtn.addEventListener('click', function(){
        const searchMobile = document.getElementById('gift_credit_search_mobile').value.trim();
        loadGiftCreditsData(searchMobile);
      });
    }
    
    var clearGiftCreditSearchBtn = document.getElementById('clear_gift_credit_search');
    if (clearGiftCreditSearchBtn) {
      clearGiftCreditSearchBtn.addEventListener('click', function(){
        document.getElementById('gift_credit_search_mobile').value = '';
        loadGiftCreditsData();
      });
    }
    
    // Add Enter key support for search
    var giftCreditSearchInput = document.getElementById('gift_credit_search_mobile');
    if (giftCreditSearchInput) {
      giftCreditSearchInput.addEventListener('keypress', function(e){
        if (e.key === 'Enter') {
          e.preventDefault();
          document.getElementById('search_gift_credits').click();
        }
      });
    }
    
    // Load gift credits data
    function loadGiftCreditsData(searchMobile = '') {
      const requestData = {
        action: 'get_gift_credits'
      };
      
      if (searchMobile) {
        requestData.search_mobile = searchMobile;
      }
      
      postJSON(requestData).then(json => {
        if (json.status === 'success') {
          displayGiftCreditsTable(json.gift_credits);
        } else {
          document.getElementById('gift_credits_table_body').innerHTML = 
            '<tr><td colspan="6" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری اطلاعات</td></tr>';
        }
      }).catch(() => {
        document.getElementById('gift_credits_table_body').innerHTML = 
          '<tr><td colspan="6" style="text-align:center;padding:15px;color:#ff5252;">خطا در اتصال به سرور</td></tr>';
      });
    }
    
    // Display gift credits table
    function displayGiftCreditsTable(giftCredits) {
      const tbody = document.getElementById('gift_credits_table_body');
      
      if (!giftCredits || giftCredits.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:15px;color:#888;">هیچ اعتبار هدیه ای یافت نشد</td></tr>';
        return;
      }
      
      let html = '';
      giftCredits.forEach(gift => {
        const date = new Date(gift.created_at);
        const formattedDate = date.toLocaleDateString('fa-IR');
        const formattedTime = date.toLocaleTimeString('fa-IR');
        
        html += `
          <tr style="border-bottom:1px solid rgba(255,255,255,0.1)">
            <td style="padding:8px 4px;text-align:right;">${gift.mobile}</td>
            <td style="padding:8px 4px;text-align:center;">${gift.subscriber_name}</td>
            <td style="padding:8px 4px;text-align:center;">${formatWithDots(gift.gift_amount_toman)}</td>
            <td style="padding:8px 4px;text-align:center;">${gift.credit_amount}</td>
            <td style="padding:8px 4px;text-align:center;">${formattedDate}<br><small style="color:#888;">${formattedTime}</small></td>
            <td style="padding:8px 4px;text-align:center;">
              <button onclick="disableGiftCredit(${gift.id})" 
                      class="btn btn-ghost" 
                      style="padding:4px 8px;font-size:12px;color:#ff5252;border-color:#ff5252;"
                      ${!gift.active ? 'disabled' : ''}>
                ${gift.active ? 'غیرفعال' : 'غیرفعال شده'}
              </button>
              ${gift.notes ? `<br><small style="color:#888;" title="${gift.notes}">یادداشت</small>` : ''}
            </td>
          </tr>
        `;
      });
      
      tbody.innerHTML = html;
    }
    
    // Disable gift credit function
    function disableGiftCredit(giftCreditId) {
      const msg = document.getElementById('gift_credit_msg');
      
      if (confirm('آیا از غیرفعال کردن این اعتبار هدیه اطمینان دارید؟\n\nتوجه: اعتبار از حساب کاربر کسر نخواهد شد.')) {
        postJSON({
          action: 'disable_gift_credit',
          gift_credit_id: giftCreditId,
          refund_credit: false
        }).then(json => {
          if (json.status === 'success') {
            msg.textContent = json.message;
            msg.style.color = '#4caf50';
            loadGiftCreditsData();
          } else {
            msg.textContent = json.message || 'خطا در غیرفعال کردن اعتبار هدیه';
            msg.style.color = '#ff5252';
          }
        }).catch(() => {
          msg.textContent = 'خطا در اتصال به سرور';
          msg.style.color = '#ff5252';
        });
      }
    }
    
    function resetGiftCreditForm() {
      document.getElementById('gift_credit_mobile').value = '';
      document.getElementById('gift_credit_amount').value = '';
      document.getElementById('gift_credit_notes').value = '';
      document.getElementById('gift_credit_msg').textContent = '';
    }
    <?php endif; ?>
    
    document.getElementById('refresh_today_report').addEventListener('click', function(){
      loadTodayReport();
    });
    
    // Navigate to previous day
    document.getElementById('prev_day_report').addEventListener('click', function(){
      const prevDate = new Date(currentReportDate);
      prevDate.setDate(prevDate.getDate() - 1);
      loadTodayReport(prevDate.toISOString().split('T')[0]);
    });
    
    // Navigate to next day
    document.getElementById('next_day_report').addEventListener('click', function(){
      const nextDate = new Date(currentReportDate);
      nextDate.setDate(nextDate.getDate() + 1);
      
      // Format both dates to YYYY-MM-DD for comparison
      function formatYMD(date) {
        return date.getFullYear() + '-' + 
               String(date.getMonth() + 1).padStart(2, '0') + '-' + 
               String(date.getDate()).padStart(2, '0');
      }
      
      // Don't allow going beyond today's date
      const today = getTehranDate();
      today.setHours(0, 0, 0, 0);
      
      // Compare dates as strings to avoid timezone issues
      if (formatYMD(nextDate) > formatYMD(today)) {
        nextDate.setTime(today.getTime());
      }
      
      // Format properly for API call
      const year = nextDate.getFullYear();
      const month = String(nextDate.getMonth() + 1).padStart(2, '0');
      const day = String(nextDate.getDate()).padStart(2, '0');
      const formattedDate = `${year}-${month}-${day}`;
      
      loadTodayReport(formattedDate);
    });
    
    // Return to today
    document.getElementById('go_to_today').addEventListener('click', function(){
      const today = getTehranDate();
      today.setHours(0, 0, 0, 0);
      
      // Format date for Tehran timezone (YYYY-MM-DD)
      const year = today.getFullYear();
      const month = String(today.getMonth() + 1).padStart(2, '0');
      const day = String(today.getDate()).padStart(2, '0');
      const formattedDate = `${year}-${month}-${day}`;
      
      console.log('Go to today clicked. Tehran date:', formattedDate);
      loadTodayReport(formattedDate);
    });
    
    // Handle delete transaction buttons using event delegation
    document.addEventListener('click', function(event) {
      // Check if the clicked element is a delete button
      if (event.target.classList.contains('btn-delete')) {
        const transactionId = event.target.getAttribute('data-id');
        const transactionType = event.target.getAttribute('data-type');
        
        if (confirm('آیا از حذف این تراکنش اطمینان دارید؟')) {
          deleteTransaction(transactionId, transactionType);
        }
      }
    });
    
    // Handle member inquiry - optimized
    document.getElementById('check_member').addEventListener('click', function(){
      const mobile = document.getElementById('inquiry_mobile').value.trim();
      const msg = document.getElementById('inquiry_msg');
      const memberInfo = document.getElementById('member_info');
      
      msg.textContent = '';
      memberInfo.style.display = 'none';
      
      if (!mobile) {
        msg.textContent = 'لطفاً شماره موبایل را وارد کنید.';
        return;
      }
      
      // Show loading state
      msg.textContent = 'در حال بررسی...';
      
      // Cache DOM elements to avoid multiple lookups
      const tableBody = document.getElementById('purchases_table_body');
      const purchaseHistory = document.getElementById('purchase_history');
      const noPurchases = document.getElementById('no_purchases');
      const memberCredit = document.getElementById('member_credit');
      
      // Reset credit use section
      document.getElementById('credit_use_amount').value = '';
      document.getElementById('credit_use_amount_raw').value = '';
      document.getElementById('credit_refund').checked = false;
      document.getElementById('credit_preview').style.display = 'none';
      document.getElementById('credit_use_msg').textContent = '';
      
      // Simple date formatter function
      const formatDate = dateString => {
        try {
          const parts = dateString.split('T')[0].split('-');
          const gregorianYear = parseInt(parts[0]);
          const gregorianMonth = parseInt(parts[1]);
          const gregorianDay = parseInt(parts[2]);
          
          // Convert to Jalali using the jalaali.js library
          const jalaliDate = window.toJalali(gregorianYear, gregorianMonth, gregorianDay);
          
          // Format with leading zeros for month and day
          const jMonth = jalaliDate.jm < 10 ? '0' + jalaliDate.jm : jalaliDate.jm;
          const jDay = jalaliDate.jd < 10 ? '0' + jalaliDate.jd : jalaliDate.jd;
          
          // Return in Jalali format: yyyy/mm/dd
          return `${jalaliDate.jy}/${jMonth}/${jDay}`;
        } catch (e) {
          console.error('Date conversion error:', e);
          return dateString;
        }
      };
      
      // Use a documentFragment for better performance when adding multiple rows
      const createTransactionRows = transactions => {
        const fragment = document.createDocumentFragment();
        
        transactions.forEach(transaction => {
          const row = document.createElement('tr');
          row.style.borderBottom = '1px solid rgba(255,255,255,0.05)';
          
          const dateCell = document.createElement('td');
          dateCell.style.padding = '8px 4px';
          dateCell.style.textAlign = 'right';
          dateCell.textContent = formatDate(transaction.date);
          
          const amountCell = document.createElement('td');
          amountCell.style.padding = '8px 4px';
          amountCell.style.textAlign = 'left';
          
          // Check if amount should be hidden (processed by backend)
          if (transaction.hide_amount) {
            // Just show the type indicator instead of actual amount
            amountCell.innerHTML = '<span style="color:#888;">' + transaction.amount_display + '</span>';
          } else {
            // Show the actual amount with proper formatting
            // Style positive and negative amounts differently
            if (transaction.amount < 0) {
              // Negative amount (credit usage)
              amountCell.innerHTML = '<span style="color:#ff5252;">-' + formatWithDots(Math.abs(transaction.amount)) + '</span>';
              
              // Add a red minus icon for negative amounts
              const iconSpan = document.createElement('span');
              iconSpan.textContent = '−';  // Unicode minus sign
              iconSpan.style.color = '#ff5252';
              iconSpan.style.marginLeft = '5px';
              iconSpan.style.fontWeight = 'bold';
              amountCell.appendChild(iconSpan);
              
              // Add refund indicator if applicable
              if (transaction.is_refund) {
                const refundSpan = document.createElement('span');
                refundSpan.textContent = ' (مرجوع)';
                refundSpan.style.fontSize = '0.8em';
                refundSpan.style.color = '#ff9800';
                amountCell.appendChild(refundSpan);
              }
            } else if (transaction.type === 'gift_credit') {
              // Gift credit (positive amount)
              amountCell.innerHTML = '<span style="color:#4caf50;">+' + formatWithDots(transaction.amount) + '</span>';
              
              // Add a gift icon for gift credits
              const iconSpan = document.createElement('span');
              iconSpan.textContent = '🎁';  // Gift emoji
              iconSpan.style.marginLeft = '5px';
              amountCell.appendChild(iconSpan);
              
              // Add gift amount in Toman if available
              if (transaction.gift_amount_toman) {
                const giftSpan = document.createElement('div');
                giftSpan.textContent = '(' + formatWithDots(transaction.gift_amount_toman) + ' تومان)';
                giftSpan.style.fontSize = '0.8em';
                giftSpan.style.color = '#81c784';
                amountCell.appendChild(giftSpan);
              }
              
              // Add notes if available
              if (transaction.notes) {
                const notesSpan = document.createElement('div');
                notesSpan.textContent = transaction.notes;
                notesSpan.style.fontSize = '0.75em';
                notesSpan.style.color = '#aaa';
                notesSpan.style.marginTop = '2px';
                amountCell.appendChild(notesSpan);
              }
            } else {
              // Positive amount (purchase)
              amountCell.textContent = formatWithDots(transaction.amount);
            }
          }
          
          // Add branch/store information
          const branchCell = document.createElement('td');
          branchCell.style.padding = '8px 4px';
          branchCell.style.textAlign = 'center';
          branchCell.style.fontSize = '0.85em';
          branchCell.textContent = transaction.branch_store || '';
          
          row.appendChild(dateCell);
          row.appendChild(amountCell);
          row.appendChild(branchCell);
          fragment.appendChild(row);
        });
        
        return fragment;
      };
      
      // Make AJAX request with proper error handling
      postJSON({action: 'check_member', mobile: mobile})
        .then(json => {
          if (json.status === 'success') {
            // Check if this is a vCard user and update display accordingly
            if (json.is_vcard_user && json.vcard_number) {
              memberCredit.innerHTML = '💳 کارت مجازی: ' + formatWithDots(json.available_credit_toman) + ' تومان';
              memberCredit.style.background = '#9c27b0'; // Purple background for vCard
              memberCredit.innerHTML += '<br><small style="font-size:12px;opacity:0.8;">شماره کارت: ' + json.vcard_number + '</small>';
              if (json.full_name) {
                memberCredit.innerHTML += '<br><small style="font-size:12px;opacity:0.8;">نام: ' + json.full_name + '</small>';
              }
            } else {
              // Regular user display
              memberCredit.textContent = 'اعتبار قابل استفاده: ' + formatWithDots(json.available_credit_toman) + ' تومان';
              memberCredit.style.background = '#d32f2f'; // Red background for regular users
            }
            
            currentCreditValue = json.available_credit_toman; // Store available credit value for calculations
            
            // Show pending credit if exists (not applicable for vCard users)
            const memberPendingCredit = document.getElementById('member_pending_credit');
            const memberTotalCredit = document.getElementById('member_total_credit');
            
            if (json.pending_credit_toman > 0 && !json.is_vcard_user) {
              memberPendingCredit.textContent = 'اعتبار در انتظار: ' + formatWithDots(json.pending_credit_toman) + ' تومان';
              memberPendingCredit.style.display = 'block';
              
              memberTotalCredit.textContent = 'مجموع اعتبار: ' + formatWithDots(json.total_credit_toman) + ' تومان';
              memberTotalCredit.style.display = 'block';
            } else {
              memberPendingCredit.style.display = 'none';
              memberTotalCredit.style.display = 'none';
            }
            
            // Clear previous transaction history
            tableBody.innerHTML = '';
            
            // Handle transaction history (both purchases and credit usage)
            if (json.transactions && json.transactions.length > 0) {
              purchaseHistory.style.display = 'block';
              noPurchases.style.display = 'none';
              
              // Batch DOM updates for better performance
              tableBody.appendChild(createTransactionRows(json.transactions));
            } else {
              purchaseHistory.style.display = 'none';
              noPurchases.style.display = 'block';
            }
            
            // Show the member info section
            memberInfo.style.display = 'block';
            msg.textContent = '';
          } else if (json.message === 'not_a_member') {
            msg.textContent = 'این شماره عضو باشگاه مشتریان نیست.';
          } else {
            msg.textContent = 'خطا: ' + (json.message || 'خطای ناشناخته');
          }
        })
        .catch(error => {
          console.error('Inquiry error:', error);
          msg.textContent = 'خطا در اتصال به سرور';
        });
    });
    
    // VCard inquiry handlers
    document.getElementById('check_by_vcard').addEventListener('click', function(e){
      e.preventDefault(); // Prevent default link behavior
      document.getElementById('vcard_inquiry_form').style.display = 'block';
      document.getElementById('vcard_inquiry_number').focus();
    });
    
    document.getElementById('cancel_vcard_inquiry').addEventListener('click', function(){
      document.getElementById('vcard_inquiry_form').style.display = 'none';
      document.getElementById('vcard_inquiry_number').value = '';
    });
    
    document.getElementById('check_vcard_inquiry').addEventListener('click', function(){
      const vcardNumber = document.getElementById('vcard_inquiry_number').value.trim();
      const msg = document.getElementById('inquiry_msg');
      
      msg.textContent = '';
      
      if (!vcardNumber) {
        msg.textContent = 'لطفاً شماره کارت 16 رقمی را وارد کنید.';
        return;
      }
      
      if (vcardNumber.length !== 16 || !/^\d+$/.test(vcardNumber)) {
        msg.textContent = 'شماره کارت باید دقیقاً 16 رقم باشد.';
        return;
      }
      
      // Show loading state
      msg.textContent = 'در حال جستجو...';
      
      postJSON({action: 'check_vcard_balance', vcard_number: vcardNumber})
        .then(json => {
          if (json.success && json.mobile) {
            // Hide vCard inquiry form
            document.getElementById('vcard_inquiry_form').style.display = 'none';
            
            // Populate the mobile number field
            document.getElementById('inquiry_mobile').value = json.mobile;
            
            // Clear and show instruction message
            msg.innerHTML = '<div style="background:#4caf50;color:white;padding:12px;border-radius:8px;margin:15px 0;text-align:center;">' +
                           '<strong>✓ شماره موبایل کارت مجازی یافت شد</strong><br>' +
                           '<span style="font-size:14px;">با شماره وارد شده در فیلد استعلام بگیرید.</span>' +
                           '</div>';
            
            // Clear vCard number for next use
            document.getElementById('vcard_inquiry_number').value = '';
            
            // Optional: Auto-focus on the inquiry button or mobile field
            document.getElementById('inquiry_mobile').focus();
            
          } else if (json.message === 'کارت یافت نشد.') {
            msg.textContent = 'کارت یافت نشد.';
          } else {
            msg.textContent = json.message || 'خطا در جستجوی کارت مجازی.';
          }
        })
        .catch(error => {
          console.error('VCard lookup error:', error);
          msg.textContent = 'خطا در اتصال به سرور';
        });
    });
    
    document.getElementById('add_submit').addEventListener('click', function(){
      var mobile = document.getElementById('sub_mobile').value.trim(); 
      var amount = document.getElementById('sub_amount_raw').value.trim(); // Use raw value
      var description = document.getElementById('sub_description').value.trim();
      var noCredit = document.getElementById('sub_no_credit').checked;
      var msg = document.getElementById('add_msg'); msg.textContent='';
      
      // Get sales center ID if the select element exists, otherwise use default (1)
      var salesCenterId = 1; // Default value for single store
      var salesCenterSelect = document.getElementById('sub_sales_center');
      if (salesCenterSelect) {
        salesCenterId = salesCenterSelect.value;
      }
      
      // Get selected advisors
      var selectedAdvisors = [];
      var advisorCheckboxes = document.querySelectorAll('.advisor-checkbox:checked');
      advisorCheckboxes.forEach(function(checkbox) {
        selectedAdvisors.push(parseInt(checkbox.value));
      });
      
      if (!mobile) { msg.textContent='لطفاً موبایل را وارد کنید.'; return; }
      
      // Create FormData to properly handle arrays
      var formData = new FormData();
      formData.append('action', 'add_subscriber');
      formData.append('mobile', mobile);
      formData.append('amount', amount);
      formData.append('description', description);
      formData.append('no_credit', noCredit);
      formData.append('sales_center_id', salesCenterId);
      
      // Add selected advisors as array
      selectedAdvisors.forEach(function(advisorId) {
        formData.append('selected_advisors[]', advisorId);
      });
      
      // Debug logging
      console.log('Submitting purchase with selected advisors:', selectedAdvisors);
      
      fetch('admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(json => {
        if (json.status==='success'){
          // Create a success message, including store information if available
          let successMsg = 'عملیات با موفقیت انجام شد.';
          
          // Add store information to success message if available
          if (salesCenterSelect && json.sales_center_name) {
            successMsg += ' در فروشگاه "' + json.sales_center_name + '"';
          }
          
          if (json.note==='existing_user') {
            msg.textContent = json.admin_message || 'Subscriber is already a member.';
          } else {
            msg.textContent = successMsg;
          }
          
          // Clear form fields
          document.getElementById('sub_mobile').value=''; 
          document.getElementById('sub_amount').value='';
          document.getElementById('sub_amount_raw').value='';
          document.getElementById('sub_description').value='';
          document.getElementById('sub_no_credit').checked = false;
          
          // Clear advisor selections
          document.querySelectorAll('.advisor-checkbox').forEach(function(checkbox) {
            checkbox.checked = false;
          });
        } else {
          msg.textContent = json.message || 'خطا';
        }
      })
      .catch(error => {
        console.error('Submit error:', error);
        msg.textContent = 'خطا در اتصال';
      });
    });
    
    // Credit use amount input formatting
    const creditUseInput = document.getElementById('credit_use_amount');
    const creditUseRawInput = document.getElementById('credit_use_amount_raw');
    let currentCreditValue = 0;
    
    if (creditUseInput && creditUseRawInput) {
      creditUseInput.addEventListener('input', function(){
        var raw = extractRawNumber(this.value);
        creditUseRawInput.value = raw;
        var formatted = formatWithDots(raw);
        this.value = formatted;
        
        // Show credit preview if there's a value
        if (raw) {
          calculateAndShowCreditPreview();
        } else {
          document.getElementById('credit_preview').style.display = 'none';
        }
      });
      
      creditUseInput.addEventListener('blur', function(){
        this.value = formatWithDots(creditUseRawInput.value);
      });
    }
    
    // Function to calculate and display credit preview
    function calculateAndShowCreditPreview() {
      const creditRawAmount = parseInt(creditUseRawInput.value) || 0;
      
      if (creditRawAmount > 0) {
        const creditInPoints = Math.round((creditRawAmount / 5000) * 10) / 10; // Round to 1 decimal place
        const remainingCredit = Math.max(0, (currentCreditValue / 5000) - creditInPoints);
        const remainingCreditToman = Math.round(remainingCredit * 5000);
        
        document.getElementById('credit_remaining').textContent = formatWithDots(remainingCreditToman);
        document.getElementById('credit_preview').style.display = 'block';
      } else {
        document.getElementById('credit_preview').style.display = 'none';
      }
    }
    
    // Submit credit use
    document.getElementById('submit_credit_use').addEventListener('click', function() {
      const creditAmount = creditUseRawInput.value.trim();
      const isRefund = document.getElementById('credit_refund').checked;
      const mobile = document.getElementById('inquiry_mobile').value.trim();
      const msg = document.getElementById('credit_use_msg');
      msg.textContent = '';
      
      if (!creditAmount) {
        msg.textContent = 'لطفاً مبلغ را وارد کنید.';
        return;
      }
      
      // Validate that the amount doesn't exceed available credit
      const creditRawAmount = parseInt(creditAmount) || 0;
      const creditInPoints = Math.round((creditRawAmount / 5000) * 10) / 10; // Round to 1 decimal place
      const currentCreditPoints = currentCreditValue / 5000;
      
      if (creditInPoints > currentCreditPoints) {
        msg.textContent = 'مبلغ وارد شده بیشتر از امتیاز موجود است.';
        return;
      }
      
      // Show loading state
      msg.textContent = 'در حال پردازش...';
      
      // Get sales center ID if available (multi-store branch)
      let salesCenterId = 1;
      const salesCenterSelect = document.getElementById('credit_sales_center');
      if (salesCenterSelect) {
        salesCenterId = salesCenterSelect.value;
      }
      
      // Send to server
      postJSON({
        action: 'use_credit',
        mobile: mobile,
        amount: creditRawAmount,
        is_refund: isRefund ? 'true' : 'false',
        sales_center_id: salesCenterId
      }).then(json => {
        if (json.status === 'success') {
          // Success message with SMS status
          if (json.sms_sent) {
            msg.textContent = 'امتیاز با موفقیت استفاده شد و پیامک اطلاع‌رسانی ارسال گردید.';
          } else {
            msg.textContent = 'امتیاز با موفقیت استفاده شد ولی ارسال پیامک با خطا مواجه شد.';
          }
          
          // Update displayed credit with value from server
          document.getElementById('member_credit').textContent = 'اعتبار قابل استفاده: ' + formatWithDots(json.credit_value) + ' تومان';
          currentCreditValue = json.credit_value;
          
          // Reset the form
          document.getElementById('credit_use_amount').value = '';
          document.getElementById('credit_use_amount_raw').value = '';
          document.getElementById('credit_refund').checked = false;
          document.getElementById('credit_preview').style.display = 'none';
        } else {
          msg.textContent = 'خطا: ' + (json.message || 'خطای ناشناخته');
        }
      }).catch(error => {
        console.error('Credit use error:', error);
        msg.textContent = 'خطا در اتصال به سرور';
      });
    });
    
    // Format time function moved to global scope
    
    // Format persian date (YYYY/MM/DD) from datetime string
    function formatPersianDate(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      // Use jalali date if available
      if (window.Jalaali && typeof Jalaali.toJalali === 'function') {
        const jalali = Jalaali.toJalali(date.getFullYear(), date.getMonth() + 1, date.getDate());
        return jalali.jy + '/' + 
               (jalali.jm < 10 ? '0' + jalali.jm : jalali.jm) + '/' + 
               (jalali.jd < 10 ? '0' + jalali.jd : jalali.jd);
      }
      
      // Fallback to Gregorian date
      return date.getFullYear() + '/' +
             (date.getMonth() + 1).toString().padStart(2, '0') + '/' +
             date.getDate().toString().padStart(2, '0');
    }
    
    // Format gregorian date in a readable format (e.g., "21 October 2023")
    function formatGregorianDate(dateStr) {
      const date = new Date(dateStr);
      if (isNaN(date.getTime())) return '';
      
      const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
      ];
      
      return date.getDate() + ' ' + months[date.getMonth()] + ' ' + date.getFullYear();
    }
    
    // Current report date storage - using the browser's timezone
    let currentReportDate = new Date();
    currentReportDate.setHours(0, 0, 0, 0); // Reset time part
    
    // Helper function to get today's date in Tehran timezone
    function getTehranDate() {
      try {
        // Use toLocaleString with explicit timezone to get the current date in Tehran
        // The result is a string, which we parse back to a Date object
        const tehranDateStr = new Date().toLocaleString('en-US', { timeZone: 'Asia/Tehran' });
        const tehranDate = new Date(tehranDateStr);
        console.log('Tehran date:', tehranDate, 'from string:', tehranDateStr);
        
        // Validate the date
        if (isNaN(tehranDate.getTime())) {
          throw new Error('Invalid date created');
        }
        
        return tehranDate;
      } catch (e) {
        console.error('Error in getTehranDate:', e);
        // Fallback to manual offset calculation if toLocaleString with timeZone isn't supported
        console.log('Fallback to manual Tehran time calculation');
        // Tehran is UTC+3:30 (winter) or UTC+4:30 (summer)
        // For simplicity, use constant offset of UTC+3:30
        const tehranOffset = 3.5 * 60; // Tehran is UTC+3:30 (210 minutes)
        const now = new Date();
        const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
        const tehranDate = new Date(utc + (60000 * tehranOffset));
        console.log('Fallback Tehran date:', tehranDate);
        return tehranDate;
      }
    }
    
    // Load transactions report for specific date (default: today)
    function loadTodayReport(dateString) {
      const purchasesList = document.getElementById('today_purchases_list');
      const creditsList = document.getElementById('today_credits_list');
      const totalPurchases = document.getElementById('total_purchases');
      const totalCreditsUsed = document.getElementById('total_credits_used');
      const managerTotalPurchases = document.getElementById('manager_total_purchases');
      const managerTotalCreditsUsed = document.getElementById('manager_total_credits_used');
      const todayDate = document.getElementById('today_date');
      const msg = document.getElementById('today_report_msg');
      
      // If no date provided, use current actual date (today) explicitly with Tehran timezone
      if (!dateString) {
        try {
          const today = getTehranDate();
          today.setHours(0, 0, 0, 0);
          
          // Validate the date
          if (isNaN(today.getTime())) {
            throw new Error('Invalid Tehran date');
          }
          
          dateString = today.toISOString().split('T')[0];
          currentReportDate = today;
          
          // Debug logging for date calculation
          console.log('Today in Tehran:', dateString);
          console.log('Browser local time:', new Date().toISOString().split('T')[0]);
        } catch (e) {
          console.error('Error getting Tehran date:', e);
          // Fallback to browser's local date
          const fallbackDate = new Date();
          fallbackDate.setHours(0, 0, 0, 0);
          dateString = fallbackDate.toISOString().split('T')[0];
          currentReportDate = fallbackDate;
          console.log('Using fallback date:', dateString);
        }
      } else {
        try {
          // Update current report date
          currentReportDate = new Date(dateString);
          
          // Validate the date
          if (isNaN(currentReportDate.getTime())) {
            throw new Error('Invalid date string provided: ' + dateString);
          }
          
          console.log('Using provided date:', dateString);
        } catch (e) {
          console.error('Error parsing provided date:', e);
          // Fallback to today's date
          const fallbackDate = new Date();
          fallbackDate.setHours(0, 0, 0, 0);
          dateString = fallbackDate.toISOString().split('T')[0];
          currentReportDate = fallbackDate;
          console.log('Using fallback date after error:', dateString);
        }
      }
      
      // Reset message
      msg.textContent = '';
      
      // Set loading state
      purchasesList.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      creditsList.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:15px;">در حال بارگذاری...</td></tr>';
      
      // Format the date string properly for API
      function formatDateForAPI(dateObj) {
        const year = dateObj.getFullYear();
        const month = String(dateObj.getMonth() + 1).padStart(2, '0');
        const day = String(dateObj.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
      }
      
      // Use the formatted date from currentReportDate to ensure consistency
      const apiDateString = formatDateForAPI(currentReportDate);
      console.log('Sending API date:', apiDateString, 'Original date string:', dateString);
      
      // Fetch report for specified date with timestamp to prevent caching
      postJSON({
        action: 'get_today_report', 
        date: apiDateString,
        _timestamp: new Date().getTime() // Add timestamp to prevent caching
      })
        .then(function(response) {
          console.log('Today report response:', response);
          if (response.status === 'success') {
            // Update date display (using Gregorian format)
            todayDate.textContent = formatGregorianDate(response.date);
            
            // Update purchases list
            if (response.purchases && response.purchases.length > 0) {
              let purchasesHtml = '';
              response.purchases.forEach(function(purchase) {
                // Determine how to display the amount based on hide_amount flag
                let amountDisplay;
                if (purchase.hide_amount) {
                  amountDisplay = `<span style="color:#888;">${purchase.amount_display}</span>`;
                } else {
                  amountDisplay = `<span style="color:#4caf50;">${formatWithDots(purchase.amount)}</span>`;
                }
                
                // Determine if this user can delete this transaction
                const canDelete = determineDeleteAccess(purchase);
                
                purchasesHtml += `
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.05);" data-id="${purchase.id}" data-type="purchase">
                    <td style="padding:8px 4px;direction:ltr;">${purchase.mobile}</td>
                    <td style="padding:8px 4px;">${formatTime(purchase.date)}</td>
                    <td style="padding:8px 4px;text-align:left;">${amountDisplay}</td>
                    <td style="padding:8px 4px;text-align:center;font-size:0.85em;">${purchase.branch_store || ''}</td>
                    <td style="padding:8px 4px;text-align:center;font-size:0.85em;">${purchase.advisor_name || '-'}</td>
                    <td style="padding:8px 4px;text-align:center;">${purchase.admin || ''}</td>
                    <td style="padding:8px 4px;text-align:center;">
                      ${canDelete ? `<button class="btn-delete" data-id="${purchase.id}" data-type="purchase" style="background:#ff5252;color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;">حذف</button>` : ''}
                    </td>
                  </tr>
                `;
              });
              purchasesList.innerHTML = purchasesHtml;
            } else {
              purchasesList.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:15px;color:#b0b3b8;">امروز خریدی ثبت نشده است</td></tr>';
            }
            
            // Update credits list
            if (response.credits && response.credits.length > 0) {
              let creditsHtml = '';
              response.credits.forEach(function(credit) {
                // Determine how to display the amount based on hide_amount flag
                let amountDisplay;
                if (credit.hide_amount) {
                  amountDisplay = `<span style="color:#888;">${credit.amount_display}</span>`;
                } else {
                  amountDisplay = `<span style="color:#ff5252;">${formatWithDots(credit.amount)}</span>`;
                }
                
                // Determine if this user can delete this transaction
                const canDelete = determineDeleteAccess(credit);
                
                creditsHtml += `
                  <tr style="border-bottom:1px solid rgba(255,255,255,0.05);" data-id="${credit.id}" data-type="credit">
                    <td style="padding:8px 4px;direction:ltr;">${credit.mobile}</td>
                    <td style="padding:8px 4px;">${formatTime(credit.date)}</td>
                    <td style="padding:8px 4px;text-align:left;">${amountDisplay}</td>
                    <td style="padding:8px 4px;text-align:center;">${credit.is_refund ? 
                      '<span style="color:#ff9800;">مرجوعی</span>' : 
                      '<span style="color:#03a9f4;">استفاده</span>'}</td>
                    <td style="padding:8px 4px;text-align:center;font-size:0.85em;">${credit.branch_store || ''}</td>
                    <td style="padding:8px 4px;text-align:center;font-size:0.85em;">${credit.advisor_name || '-'}</td>
                    <td style="padding:8px 4px;text-align:center;">${credit.admin || ''}</td>
                    <td style="padding:8px 4px;text-align:center;">
                      ${canDelete ? `<button class="btn-delete" data-id="${credit.id}" data-type="credit" style="background:#ff5252;color:white;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;font-size:12px;">حذف</button>` : ''}
                    </td>
                  </tr>
                `;
              });
              creditsList.innerHTML = creditsHtml;
            } else {
              creditsList.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:15px;color:#b0b3b8;">امروز اعتباری استفاده نشده است</td></tr>';
            }
            
            // Update totals
            if (totalPurchases) {
              totalPurchases.textContent = formatWithDots(response.total_purchases) + ' تومان';
            }
            if (totalCreditsUsed) {
              totalCreditsUsed.textContent = formatWithDots(response.total_credits) + ' تومان';
            }
            if (managerTotalPurchases) {
              managerTotalPurchases.textContent = formatWithDots(response.total_purchases) + ' تومان';
            }
            if (managerTotalCreditsUsed) {
              managerTotalCreditsUsed.textContent = formatWithDots(response.total_credits) + ' تومان';
            }
            
            // Update breakdown table (for managers only)
            if (response.breakdown && response.breakdown.branches) {
              const breakdownTableBody = document.getElementById('breakdown_table_body');
              if (breakdownTableBody) {
                let breakdownHtml = '';
                
                if (response.breakdown.branches.length === 0) {
                  breakdownHtml = `
                    <tr>
                      <td colspan="3" style="padding:15px;text-align:center;color:#888;">هیچ تراکنشی یافت نشد</td>
                    </tr>
                  `;
                } else {
                  response.breakdown.branches.forEach(function(branch) {
                    // Add branch header row
                    breakdownHtml += `
                      <tr style="background:#3a3c3e;border-bottom:1px solid #444;">
                        <td style="padding:10px;font-weight:bold;color:#fff;">${branch.name}</td>
                        <td style="padding:10px;text-align:center;color:#4caf50;font-weight:bold;">${formatWithDots(branch.total_purchases)}</td>
                        <td style="padding:10px;text-align:center;color:#ff5252;font-weight:bold;">${formatWithDots(branch.total_credits)}</td>
                      </tr>
                    `;
                    
                    // Add store rows if there are multiple stores
                    if (branch.stores && branch.stores.length > 1) {
                      branch.stores.forEach(function(store) {
                        breakdownHtml += `
                          <tr style="background:#2a2c2e;border-bottom:1px solid #444;">
                            <td style="padding:8px 20px;color:#ccc;font-size:0.9em;">├─ ${store.name}</td>
                            <td style="padding:8px;text-align:center;color:#4caf50;">${formatWithDots(store.purchases)}</td>
                            <td style="padding:8px;text-align:center;color:#ff5252;">${formatWithDots(store.credits)}</td>
                          </tr>
                        `;
                      });
                    } else if (branch.stores && branch.stores.length === 1) {
                      // If only one store, show it as a sub-item for clarity
                      const store = branch.stores[0];
                      if (store.name !== branch.name && store.name !== '-') {
                        breakdownHtml += `
                          <tr style="background:#2a2c2e;border-bottom:1px solid #444;">
                            <td style="padding:8px 20px;color:#ccc;font-size:0.9em;">├─ ${store.name}</td>
                            <td style="padding:8px;text-align:center;color:#4caf50;">${formatWithDots(store.purchases)}</td>
                            <td style="padding:8px;text-align:center;color:#ff5252;">${formatWithDots(store.credits)}</td>
                          </tr>
                        `;
                      }
                    }
                  });
                }
                
                breakdownTableBody.innerHTML = breakdownHtml;
              }
            }
          } else {
            let errorMsg = response.message || 'خطا در دریافت اطلاعات';
            msg.textContent = errorMsg;
            console.error('API error response:', response);
            
            // Show more detailed error in the UI if debug info is available
            if (response.debug) {
              console.error('Debug info:', response.debug);
              purchasesList.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری: ${errorMsg}</td></tr>`;
              creditsList.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری: ${errorMsg}</td></tr>`;
            } else {
              purchasesList.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
              creditsList.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:15px;color:#ff5252;">خطا در بارگذاری</td></tr>';
            }
          }
        })
        .catch(function(error) {
          console.error('Error loading today report:', error);
          msg.textContent = 'خطا در اتصال به سرور';
          purchasesList.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:15px;color:#ff5252;">خطا در اتصال به سرور</td></tr>';
          creditsList.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:15px;color:#ff5252;">خطا در اتصال به سرور</td></tr>';
        });
    }
    
    // Function to delete a transaction
    function deleteTransaction(transactionId, transactionType) {
      // Show loading state
      const button = document.querySelector(`.btn-delete[data-id="${transactionId}"][data-type="${transactionType}"]`);
      const originalText = button.textContent;
      button.textContent = '...';
      button.disabled = true;
      
      // API call to delete transaction
      postJSON({
        action: 'delete_transaction',
        transaction_id: transactionId,
        transaction_type: transactionType
      })
        .then(function(response) {
          if (response.status === 'success') {
            // Success - reload the current report to update the list
            showNotification('تراکنش با موفقیت حذف شد', 'success');
            // Reload the current report with the same date
            loadTodayReport(currentReportDate.toISOString().split('T')[0]);
          } else {
            // Error - show message and restore button
            showNotification(response.message || 'خطا در حذف تراکنش', 'error');
            button.textContent = originalText;
            button.disabled = false;
          }
        })
        .catch(function(error) {
          console.error('Error deleting transaction:', error);
          showNotification('خطا در اتصال به سرور', 'error');
          button.textContent = originalText;
          button.disabled = false;
        });
    }
    
    // Helper function to show notifications
    function showNotification(message, type = 'info') {
      const msg = document.getElementById('today_report_msg');
      msg.textContent = message;
      
      // Set color based on type
      if (type === 'success') {
        msg.style.color = '#4caf50';
      } else if (type === 'error') {
        msg.style.color = '#ff5252';
      } else {
        msg.style.color = '#03a9f4';
      }
      
      // Clear message after 5 seconds
      setTimeout(function() {
        msg.textContent = '';
      }, 5000);
    }
    <?php endif; ?>
  </script>
</body>
</html>
