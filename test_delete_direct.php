<?php
/**
 * Direct test of delete_transaction action
 * This bypasses the frontend and tests the backend directly
 */

// Start session to simulate admin login
session_start();

// Simulate an admin being logged in
$_SESSION['admin_mobile'] = '09112345678';
$_SESSION['is_manager'] = true;

// Simulate POST data
$_POST['action'] = 'delete_transaction';
$_POST['transaction_id'] = '1'; // You might need to change this to a real transaction ID
$_POST['transaction_type'] = 'purchase';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Testing delete_transaction action directly...\n\n";

try {
    // Include the admin.php file to execute the delete_transaction logic
    // We need to capture the output to see what's happening
    ob_start();
    
    // Set up the action variable
    $action = $_POST['action'];
    
    // Include required files that admin.php would normally include
    require_once 'db.php';
    
    // Add some debug output
    echo "Database connection: " . (isset($pdo) ? "OK" : "FAILED") . "\n";
    
    if (!file_exists('delete_transaction.php')) {
        echo "ERROR: delete_transaction.php file not found\n";
        exit;
    }
    
    echo "delete_transaction.php file: EXISTS\n";
    
    // Test if we can include it without errors
    require_once 'delete_transaction.php';
    echo "delete_transaction.php included: OK\n";
    
    // Check if functions exist
    echo "checkDeletePermission function: " . (function_exists('checkDeletePermission') ? "EXISTS" : "NOT FOUND") . "\n";
    echo "deleteTransaction function: " . (function_exists('deleteTransaction') ? "EXISTS" : "NOT FOUND") . "\n";
    
    // Test the admin.php logic directly
    $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
    $transaction_type = isset($_POST['transaction_type']) ? trim($_POST['transaction_type']) : '';
    
    echo "Transaction ID: $transaction_id\n";
    echo "Transaction Type: $transaction_type\n";
    
    // Validate inputs
    if (!$transaction_id) {
        echo "ERROR: Invalid transaction ID\n";
        exit;
    }
    
    if ($transaction_type !== 'purchase' && $transaction_type !== 'credit') {
        echo "ERROR: Invalid transaction type\n";
        exit;
    }
    
    // Get admin info
    $admin_mobile = $_SESSION['admin_mobile'];
    $is_manager = !empty($_SESSION['is_manager']);
    
    echo "Admin mobile: $admin_mobile\n";
    echo "Is manager: " . ($is_manager ? "YES" : "NO") . "\n";
    
    // Test the database query
    if ($transaction_type === 'purchase') {
        $stmt = $pdo->prepare('
            SELECT id, mobile, amount, created_at as date, admin_number, 
                   admin_number as admin_mobile, active 
            FROM purchases 
            WHERE id = ? 
            LIMIT 1
        ');
    } else {
        $stmt = $pdo->prepare('
            SELECT id, user_mobile as mobile, amount, datetime as date, 
                   admin_mobile, admin_mobile as admin_number, active 
            FROM credit_usage 
            WHERE id = ? 
            LIMIT 1
        ');
    }
    
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        echo "ERROR: Transaction not found (ID: $transaction_id)\n";
        exit;
    }
    
    echo "Transaction found: " . print_r($transaction, true) . "\n";
    
    // Check if transaction is already inactive
    if (isset($transaction['active']) && $transaction['active'] == 0) {
        echo "ERROR: Transaction already deleted\n";
        exit;
    }
    
    // Test permission check
    if (function_exists('checkDeletePermission')) {
        $permission = checkDeletePermission($transaction, $admin_mobile, $is_manager);
        echo "Permission check: " . ($permission['allowed'] ? "ALLOWED" : "DENIED") . "\n";
        if (!$permission['allowed']) {
            echo "Permission message: " . $permission['message'] . "\n";
            exit;
        }
    }
    
    // Test the deletion
    if (function_exists('deleteTransaction')) {
        echo "Attempting deletion...\n";
        $deleted = deleteTransaction($transaction_id, $transaction_type, $pdo, $admin_mobile);
        echo "Deletion result: " . ($deleted ? "SUCCESS" : "FAILED") . "\n";
    }
    
    echo "\n=== Test completed successfully ===\n";
    
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();
echo $output;
?>