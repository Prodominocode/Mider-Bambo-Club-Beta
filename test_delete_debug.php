<?php
/**
 * Simple test script to debug transaction deletion
 * This helps identify the exact issue causing the 500 error
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "Testing transaction deletion functionality...\n\n";

try {
    // Include required files
    require_once 'db.php';
    
    echo "✓ Database connection successful\n";
    
    // Check if delete_transaction.php exists
    if (file_exists('delete_transaction.php')) {
        echo "✓ delete_transaction.php found\n";
        require_once 'delete_transaction.php';
        
        // Check if functions exist
        if (function_exists('checkDeletePermission')) {
            echo "✓ checkDeletePermission function available\n";
        } else {
            echo "✗ checkDeletePermission function NOT available\n";
        }
        
        if (function_exists('deleteTransaction')) {
            echo "✓ deleteTransaction function available\n";
        } else {
            echo "✗ deleteTransaction function NOT available\n";
        }
    } else {
        echo "✗ delete_transaction.php NOT found\n";
    }
    
    // Check if credit_deactivation_utils.php exists
    if (file_exists('credit_deactivation_utils.php')) {
        echo "✓ credit_deactivation_utils.php found\n";
        require_once 'credit_deactivation_utils.php';
        
        if (function_exists('deactivate_purchase_with_credit_adjustment')) {
            echo "✓ deactivate_purchase_with_credit_adjustment function available\n";
        } else {
            echo "✗ deactivate_purchase_with_credit_adjustment function NOT available\n";
        }
    } else {
        echo "⚠ credit_deactivation_utils.php not found (will use fallback)\n";
    }
    
    // Test database table structure
    try {
        $stmt = $pdo->prepare("DESCRIBE purchases");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('active', $columns)) {
            echo "✓ purchases table has 'active' column\n";
        } else {
            echo "⚠ purchases table missing 'active' column\n";
        }
    } catch (Exception $e) {
        echo "✗ Error checking purchases table: " . $e->getMessage() . "\n";
    }
    
    // Test pending_credits table
    try {
        $stmt = $pdo->prepare("DESCRIBE pending_credits");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('active', $columns)) {
            echo "✓ pending_credits table has 'active' column\n";
        } else {
            echo "⚠ pending_credits table missing 'active' column\n";
        }
    } catch (Exception $e) {
        echo "⚠ pending_credits table might not exist or error: " . $e->getMessage() . "\n";
    }
    
    // Test a mock delete permission check
    $mock_transaction = [
        'id' => 1,
        'mobile' => '09123456789',
        'amount' => 100000,
        'date' => date('Y-m-d H:i:s'),
        'admin_number' => '09112345678',
        'admin_mobile' => '09112345678'
    ];
    
    if (function_exists('checkDeletePermission')) {
        $permission = checkDeletePermission($mock_transaction, '09112345678', true);
        if ($permission['allowed']) {
            echo "✓ Mock permission check passed (manager)\n";
        } else {
            echo "✗ Mock permission check failed: " . $permission['message'] . "\n";
        }
    }
    
    echo "\n=== Test completed ===\n";
    echo "If all items show ✓, the deletion functionality should work.\n";
    echo "If there are ✗ or ⚠ items, those need to be fixed.\n";
    
} catch (Exception $e) {
    echo "✗ Fatal error during testing: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo "This error is likely causing the 500 error.\n";
}
?>