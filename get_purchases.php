<?php
// Start session first before any output
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once 'db.php';
require_once 'config.php';
require_once 'branch_utils.php';

// Debug session info (remove in production)
// error_log("Session data: " . print_r($_SESSION, true));

if (empty($_SESSION['user_id'])) { 
    echo json_encode(['status'=>'error','message'=>'not_logged_in']); 
    exit; 
}

$uid = (int) $_SESSION['user_id'];

// Get mobile from session, falling back to multiple possible session variables
$mobile = '';
if (!empty($_SESSION['mobile'])) {
    $mobile = $_SESSION['mobile'];
} elseif (!empty($_SESSION['user_mobile'])) {
    $mobile = $_SESSION['user_mobile'];
}

// If we still don't have a mobile number, get it from the subscribers table
if (empty($mobile) && $uid > 0) {
    try {
        $stmt = $pdo->prepare("SELECT mobile FROM subscribers WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $userMobile = $stmt->fetchColumn();
        if ($userMobile) {
            $mobile = $userMobile;
            // Update session for future use
            $_SESSION['mobile'] = $mobile;
        }
    } catch (Exception $e) {
        // Silently continue - we'll try to get transactions without mobile
    }
}

try {
    // Get current branch ID
    $branch_id = get_current_branch();
    
    // Get combined transaction history (purchases and credit usage)
    $transactions = [];
    
    // 1. Get purchases (positive credits) - filter by branch and active status
    $stmt = $pdo->prepare('
        SELECT amount, created_at as date, "purchase" as type, branch_id, sales_center_id 
        FROM purchases 
        WHERE (subscriber_id = ? OR mobile = ?) AND branch_id = ? AND active = 1
    ');
    $stmt->execute([$uid, $mobile, $branch_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $transactions[] = [
            'amount' => (int)$row['amount'],
            'date' => $row['date'],
            'type' => 'purchase',
            'is_refund' => false,
            'branch_id' => (int)$row['branch_id'],
            'sales_center_id' => (int)$row['sales_center_id']
        ];
    }
    
    // 2. Get credit usage (negative credits) if mobile is available
    if ($mobile) {
        $stmt = $pdo->prepare('
            SELECT amount, datetime as date, is_refund, "usage" as type, branch_id, sales_center_id
            FROM credit_usage 
            WHERE user_mobile = ? AND branch_id = ? AND active = 1
        ');
        $stmt->execute([$mobile, $branch_id]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transactions[] = [
                'amount' => -(int)$row['amount'], // Negative amount
                'date' => $row['date'],
                'type' => 'usage',
                'is_refund' => (bool)$row['is_refund'],
                'branch_id' => (int)$row['branch_id'],
                'sales_center_id' => (int)$row['sales_center_id']
            ];
        }
    }
    
    // 3. Sort combined records by date (newest first)
    usort($transactions, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    echo json_encode(['status'=>'success','transactions'=>$transactions]);
} catch (Throwable $e) {
    // Log the error for debugging
    error_log("Error in get_purchases.php: " . $e->getMessage());
    
    // Return a more user-friendly error message
    echo json_encode([
        'status' => 'error', 
        'message' => 'خطا در دریافت تراکنش‌ها. لطفاً مجدداً تلاش کنید.',
        'debug' => DEBUG_MODE ? $e->getMessage() : null
    ]);
}




















