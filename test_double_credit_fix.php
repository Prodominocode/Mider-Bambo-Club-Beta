<?php
/**
 * Test script to validate double credit transfer fixes
 * Run this script to verify that the fixes prevent double credit issues
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pending_credits_utils.php';

echo "=== Testing Double Credit Transfer Fix ===\n\n";

function test_double_credit_fix($pdo) {
    try {
        // Find a test subscriber
        $stmt = $pdo->prepare("SELECT id, mobile, credit FROM subscribers WHERE verified = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $test_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!test_user) {
            echo "No test user found\n";
            return false;
        }
        
        echo "Test User: ID {$test_user['id']}, Mobile {$test_user['mobile']}\n";
        echo "Initial Credit: {$test_user['credit']} points (" . ($test_user['credit'] * 5000) . " Toman)\n\n";
        
        // Add a test pending credit with old timestamp
        $test_credit_amount = 24.5; // Same as the reported issue
        $stmt = $pdo->prepare("
            INSERT INTO pending_credits (subscriber_id, mobile, purchase_id, credit_amount, created_at, branch_id, sales_center_id, admin_number, active) 
            VALUES (?, ?, NULL, ?, DATE_SUB(NOW(), INTERVAL 50 HOUR), 1, 1, 'TEST_DOUBLE_FIX', 1)
        ");
        $stmt->execute([$test_user['id'], $test_user['mobile'], $test_credit_amount]);
        $pending_id = $pdo->lastInsertId();
        
        echo "Added test pending credit: ID $pending_id, Amount $test_credit_amount points\n\n";
        
        // Process pending credits
        echo "Processing pending credits...\n";
        $result = process_pending_credits($pdo, $test_user['id']);
        
        if ($result['success']) {
            echo "✓ Processing successful\n";
            echo "Transferred count: {$result['transferred_count']}\n";
            echo "Transferred amount: {$result['transferred_amount']} points\n\n";
            
            // Check final credit
            $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
            $stmt->execute([$test_user['id']]);
            $final_credit = (float)$stmt->fetchColumn();
            
            $expected_credit = $test_user['credit'] + $test_credit_amount;
            $actual_increase = $final_credit - $test_user['credit'];
            
            echo "Final Credit: $final_credit points (" . ($final_credit * 5000) . " Toman)\n";
            echo "Expected Increase: $test_credit_amount points\n";
            echo "Actual Increase: $actual_increase points\n\n";
            
            if (abs($actual_increase - $test_credit_amount) < 0.01) {
                echo "✅ SUCCESS: Credit increased by exactly the expected amount\n";
            } else {
                echo "❌ FAILURE: Credit increase mismatch!\n";
                return false;
            }
            
            // Try processing again to test idempotency
            echo "\nTesting idempotency (processing again)...\n";
            $result2 = process_pending_credits($pdo, $test_user['id']);
            
            $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
            $stmt->execute([$test_user['id']]);
            $final_credit_2 = (float)$stmt->fetchColumn();
            
            if ($final_credit_2 == $final_credit) {
                echo "✅ SUCCESS: No additional credit added on second processing\n";
            } else {
                echo "❌ FAILURE: Additional credit added on second processing!\n";
                return false;
            }
            
        } else {
            echo "❌ Processing failed: {$result['error']}\n";
            return false;
        }
        
        // Clean up test data
        $stmt = $pdo->prepare("DELETE FROM pending_credits WHERE id = ?");
        $stmt->execute([$pending_id]);
        echo "\nTest data cleaned up.\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return false;
    }
}

function test_unique_constraint($pdo) {
    echo "\n=== Testing Unique Constraint Fix ===\n\n";
    
    try {
        // Find a test subscriber
        $stmt = $pdo->prepare("SELECT id, mobile FROM subscribers WHERE verified = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $test_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$test_user) {
            echo "No test user found\n";
            return false;
        }
        
        $test_purchase_id = 999999; // Use a fake purchase ID for testing
        
        // Try to add the first pending credit
        echo "Adding first pending credit...\n";
        $result1 = add_pending_credit($pdo, $test_user['id'], $test_user['mobile'], $test_purchase_id, 10.0, 1, 1, 'TEST_UNIQUE');
        
        if ($result1) {
            echo "✓ First pending credit added successfully\n";
        } else {
            echo "❌ Failed to add first pending credit\n";
            return false;
        }
        
        // Try to add a duplicate pending credit (should be prevented by unique constraint)
        echo "Attempting to add duplicate pending credit...\n";
        $result2 = add_pending_credit($pdo, $test_user['id'], $test_user['mobile'], $test_purchase_id, 10.0, 1, 1, 'TEST_UNIQUE_DUP');
        
        if ($result2) {
            echo "✅ SUCCESS: Duplicate handled gracefully (function returned true due to duplicate handling)\n";
        } else {
            echo "❌ FAILURE: Function returned false for duplicate\n";
        }
        
        // Check that only one record exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_credits WHERE purchase_id = ? AND subscriber_id = ?");
        $stmt->execute([$test_purchase_id, $test_user['id']]);
        $count = $stmt->fetchColumn();
        
        if ($count == 1) {
            echo "✅ SUCCESS: Only one pending credit record exists (duplicate prevented)\n";
        } else {
            echo "❌ FAILURE: Found $count records (should be 1)\n";
            return false;
        }
        
        // Clean up
        $stmt = $pdo->prepare("DELETE FROM pending_credits WHERE purchase_id = ?");
        $stmt->execute([$test_purchase_id]);
        echo "Test data cleaned up.\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        return false;
    }
}

// Run tests
echo "Running validation tests for double credit transfer fixes...\n\n";

$test1_passed = test_double_credit_fix($pdo);
$test2_passed = test_unique_constraint($pdo);

echo "\n=== Test Results Summary ===\n";
echo "Double Credit Transfer Fix: " . ($test1_passed ? "✅ PASSED" : "❌ FAILED") . "\n";
echo "Unique Constraint Fix: " . ($test2_passed ? "✅ PASSED" : "❌ FAILED") . "\n";

if ($test1_passed && $test2_passed) {
    echo "\n🎉 All tests passed! The fixes are working correctly.\n";
} else {
    echo "\n⚠️ Some tests failed. Please review the implementation.\n";
}
?>