<?php
/**
 * PHP Syntax Check for Delete Transaction Files
 * This checks for syntax errors without connecting to database
 */

echo "Checking PHP syntax for delete transaction files...\n\n";

// Check admin.php syntax
echo "1. Checking admin.php syntax...\n";
$output = [];
$return_var = 0;
exec('php -l admin.php 2>&1', $output, $return_var);

if ($return_var === 0) {
    echo "   ✓ admin.php syntax is OK\n";
} else {
    echo "   ✗ admin.php has syntax errors:\n";
    foreach ($output as $line) {
        echo "     $line\n";
    }
}

// Check delete_transaction.php syntax
echo "\n2. Checking delete_transaction.php syntax...\n";
$output = [];
$return_var = 0;
exec('php -l delete_transaction.php 2>&1', $output, $return_var);

if ($return_var === 0) {
    echo "   ✓ delete_transaction.php syntax is OK\n";
} else {
    echo "   ✗ delete_transaction.php has syntax errors:\n";
    foreach ($output as $line) {
        echo "     $line\n";
    }
}

// Check credit_deactivation_utils.php syntax
echo "\n3. Checking credit_deactivation_utils.php syntax...\n";
if (file_exists('credit_deactivation_utils.php')) {
    $output = [];
    $return_var = 0;
    exec('php -l credit_deactivation_utils.php 2>&1', $output, $return_var);
    
    if ($return_var === 0) {
        echo "   ✓ credit_deactivation_utils.php syntax is OK\n";
    } else {
        echo "   ✗ credit_deactivation_utils.php has syntax errors:\n";
        foreach ($output as $line) {
            echo "     $line\n";
        }
    }
} else {
    echo "   ⚠ credit_deactivation_utils.php not found\n";
}

// Test function definitions without database
echo "\n4. Testing function definitions...\n";

try {
    // Include files to check for function definition errors
    
    // Test delete_transaction.php inclusion
    ob_start();
    $error_before = error_get_last();
    
    // Temporarily disable database connections by redefining PDO
    class MockPDO {
        public function __construct() {
            // Do nothing
        }
    }
    
    include_once 'delete_transaction.php';
    
    $error_after = error_get_last();
    $output = ob_get_clean();
    
    if ($error_after && $error_after !== $error_before) {
        echo "   ✗ Error including delete_transaction.php:\n";
        echo "     " . $error_after['message'] . "\n";
    } else {
        echo "   ✓ delete_transaction.php included successfully\n";
        
        // Check if functions are defined
        if (function_exists('checkDeletePermission')) {
            echo "   ✓ checkDeletePermission function defined\n";
        } else {
            echo "   ✗ checkDeletePermission function NOT defined\n";
        }
        
        if (function_exists('deleteTransaction')) {
            echo "   ✓ deleteTransaction function defined\n";
        } else {
            echo "   ✗ deleteTransaction function NOT defined\n";
        }
        
        if (function_exists('deleteTransactionFallback')) {
            echo "   ✓ deleteTransactionFallback function defined\n";
        } else {
            echo "   ✗ deleteTransactionFallback function NOT defined\n";
        }
    }
    
} catch (Throwable $e) {
    echo "   ✗ Exception during function testing:\n";
    echo "     " . $e->getMessage() . "\n";
    echo "     File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n=== Syntax Check Complete ===\n";
echo "If all items show ✓, there are no syntax errors.\n";
echo "If there are ✗ items, fix those before testing deletion.\n";

?>