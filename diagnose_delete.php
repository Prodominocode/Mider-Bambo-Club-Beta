<?php
/**
 * Minimal diagnostic script for transaction deletion 500 error
 * Tests each component individually to isolate the issue
 */

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "=== Transaction Deletion Diagnostic ===\n\n";

// Test 1: Basic PHP syntax and includes
echo "1. Testing basic includes...\n";
try {
    if (file_exists('db.php')) {
        echo "   ✓ db.php exists\n";
        // Don't include it yet, just check existence
    } else {
        echo "   ✗ db.php missing\n";
        exit;
    }
    
    if (file_exists('delete_transaction.php')) {
        echo "   ✓ delete_transaction.php exists\n";
    } else {
        echo "   ✗ delete_transaction.php missing\n";
        exit;
    }
    
} catch (Exception $e) {
    echo "   ✗ Error in basic checks: " . $e->getMessage() . "\n";
    exit;
}

// Test 2: Database connection
echo "\n2. Testing database connection...\n";
try {
    require_once 'db.php';
    if (isset($pdo)) {
        echo "   ✓ Database connected\n";
        
        // Test basic query
        $stmt = $pdo->query("SELECT 1");
        if ($stmt) {
            echo "   ✓ Database query works\n";
        } else {
            echo "   ✗ Database query failed\n";
        }
    } else {
        echo "   ✗ PDO not initialized\n";
        exit;
    }
} catch (Exception $e) {
    echo "   ✗ Database error: " . $e->getMessage() . "\n";
    exit;
}

// Test 3: Check table structures
echo "\n3. Checking table structures...\n";
try {
    // Check purchases table
    $stmt = $pdo->query("DESCRIBE purchases");
    $purchases_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   Purchases columns: " . implode(', ', $purchases_columns) . "\n";
    
    $has_active = in_array('active', $purchases_columns);
    echo "   Purchases has 'active' column: " . ($has_active ? "YES" : "NO") . "\n";
    
    // Check if there are any purchases
    $stmt = $pdo->query("SELECT COUNT(*) FROM purchases");
    $count = $stmt->fetchColumn();
    echo "   Total purchases: $count\n";
    
} catch (Exception $e) {
    echo "   ✗ Table check error: " . $e->getMessage() . "\n";
}

// Test 4: Try to include delete_transaction.php
echo "\n4. Testing delete_transaction.php inclusion...\n";
try {
    require_once 'delete_transaction.php';
    echo "   ✓ delete_transaction.php included\n";
    
    if (function_exists('checkDeletePermission')) {
        echo "   ✓ checkDeletePermission function available\n";
    } else {
        echo "   ✗ checkDeletePermission function missing\n";
    }
    
    if (function_exists('deleteTransaction')) {
        echo "   ✓ deleteTransaction function available\n";
    } else {
        echo "   ✗ deleteTransaction function missing\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Include error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

// Test 5: Simulate the exact admin.php logic
echo "\n5. Testing admin.php delete logic simulation...\n";
try {
    // Simulate session
    session_start();
    $_SESSION['admin_mobile'] = '09112345678';
    $_SESSION['is_manager'] = true;
    
    // Simulate POST data
    $_POST['transaction_id'] = '1';
    $_POST['transaction_type'] = 'purchase';
    
    $transaction_id = (int)$_POST['transaction_id'];
    $transaction_type = trim($_POST['transaction_type']);
    $admin_mobile = $_SESSION['admin_mobile'];
    $is_manager = !empty($_SESSION['is_manager']);
    
    echo "   Simulated inputs: ID=$transaction_id, Type=$transaction_type, Admin=$admin_mobile\n";
    
    // Test the query that admin.php uses
    $stmt = $pdo->prepare('
        SELECT id, mobile, amount, created_at as date, admin_number
        FROM purchases 
        WHERE id = ? 
        LIMIT 1
    ');
    $stmt->execute([$transaction_id]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction) {
        echo "   ✓ Transaction found: " . print_r($transaction, true) . "\n";
    } else {
        echo "   ⚠ Transaction not found (ID: $transaction_id)\n";
    }
    
} catch (Exception $e) {
    echo "   ✗ Simulation error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

echo "\n=== Diagnostic Complete ===\n";
?>