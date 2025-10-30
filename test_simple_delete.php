<?php
/**
 * Simple test to verify the delete transaction logic
 * This simulates the exact POST request that the frontend makes
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
session_start();

// Simulate admin login
$_SESSION['admin_mobile'] = '09112345678';
$_SESSION['is_manager'] = true;

// Simulate POST request
$_POST['action'] = 'delete_transaction';
$_POST['transaction_id'] = '1'; // Change this to an actual transaction ID
$_POST['transaction_type'] = 'purchase';

echo "Simulating delete transaction request...\n";
echo "POST data: " . print_r($_POST, true) . "\n";
echo "Session data: " . print_r($_SESSION, true) . "\n";

// Capture the output from admin.php
ob_start();

// Set up the action variable like admin.php does
$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    // Include only the database connection
    include 'db.php';
    
    // Execute just the delete_transaction logic
    if ($action === 'delete_transaction') {
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { 
            echo json_encode(['status'=>'error','message'=>'not_logged_in']); 
            exit; 
        }
        
        $transaction_id = isset($_POST['transaction_id']) ? (int)$_POST['transaction_id'] : 0;
        $transaction_type = isset($_POST['transaction_type']) ? trim($_POST['transaction_type']) : '';
        
        echo "Processing transaction ID: $transaction_id, Type: $transaction_type\n";
        
        // Validate inputs
        if (!$transaction_id) {
            echo json_encode(['status'=>'error','message'=>'Invalid transaction ID']); 
            exit;
        }
        
        if ($transaction_type !== 'purchase' && $transaction_type !== 'credit') {
            echo json_encode(['status'=>'error','message'=>'Invalid transaction type']); 
            exit;
        }
        
        // Get admin info
        $admin_mobile = $_SESSION['admin_mobile'];
        $is_manager = !empty($_SESSION['is_manager']);
        
        echo "Admin: $admin_mobile, Manager: " . ($is_manager ? "Yes" : "No") . "\n";
        
        // Test if we can connect to database
        if (!isset($pdo)) {
            echo json_encode(['status'=>'error','message'=>'Database not connected']); 
            exit;
        }
        
        echo "Database connected successfully\n";
        
        // For purchase deletion - simple focused logic
        if ($transaction_type === 'purchase') {
            $pdo->beginTransaction();
            
            echo "Started transaction\n";
            
            // 1. Get purchase details
            $stmt = $pdo->prepare('SELECT id, subscriber_id, mobile, amount, admin_number FROM purchases WHERE id = ? LIMIT 1');
            $stmt->execute([$transaction_id]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$purchase) {
                $pdo->rollBack();
                echo json_encode(['status'=>'error','message'=>'Transaction not found']); 
                exit;
            }
            
            echo "Found purchase: " . print_r($purchase, true) . "\n";
            
            // Check permissions
            if (!$is_manager) {
                if ($purchase['admin_number'] !== $admin_mobile) {
                    $pdo->rollBack();
                    echo json_encode(['status'=>'error','message'=>'Access denied']); 
                    exit;
                }
            }
            
            echo "Permission check passed\n";
            
            // The rest of the logic would continue here...
            echo "Would continue with credit adjustments and purchase deactivation\n";
            
            $pdo->rollBack(); // Don't actually make changes in test
            echo json_encode(['status'=>'success','message'=>'Test completed - transaction would be deleted']); 
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

$output = ob_get_clean();
echo "\n=== Output ===\n";
echo $output;
?>