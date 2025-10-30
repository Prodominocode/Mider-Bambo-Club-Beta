<?php
/**
 * Credit Deactivation Tests
 * 
 * Tests for validating the credit adjustment logic when purchases and gift credits are deactivated.
 * These tests demonstrate the before/after scenarios and validate the correct behavior.
 */

require_once 'db.php';
require_once 'credit_deactivation_utils.php';
require_once 'pending_credits_utils.php';
require_once 'gift_credit_utils.php';

/**
 * Test purchase deactivation before pending credit transfer
 */
function test_purchase_deactivation_before_transfer() {
    global $pdo;
    
    echo "=== Test: Purchase Deactivation Before Pending Credit Transfer ===\n";
    
    try {
        $pdo->beginTransaction();
        
        // Setup: Create test subscriber
        $mobile = '09123456789';
        $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, credit, verified) VALUES (?, 10, 1)");
        $stmt->execute([$mobile]);
        $subscriber_id = $pdo->lastInsertId();
        
        // Setup: Create test purchase
        $purchase_amount = 500000; // 500,000 Toman = 5 credit points
        $expected_credit = round($purchase_amount / 100000.0, 1); // 5.0 points
        
        $stmt = $pdo->prepare("INSERT INTO purchases (subscriber_id, mobile, amount, admin_number, active) VALUES (?, ?, ?, '09112345678', 1)");
        $stmt->execute([$subscriber_id, $mobile, $purchase_amount]);
        $purchase_id = $pdo->lastInsertId();
        
        // Setup: Add pending credit for this purchase
        $result = add_pending_credit($pdo, $subscriber_id, $mobile, $purchase_id, $expected_credit);
        
        // Get initial state
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $initial_credit = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_credits WHERE purchase_id = ? AND active = 1 AND transferred = 0");
        $stmt->execute([$purchase_id]);
        $pending_count_before = $stmt->fetchColumn();
        
        echo "Initial subscriber credit: $initial_credit\n";
        echo "Active pending credits for purchase: $pending_count_before\n";
        echo "Expected credit from purchase: $expected_credit\n";
        
        // Test: Deactivate purchase before pending credit is transferred
        $deactivation_result = deactivate_purchase_with_credit_adjustment($pdo, $purchase_id, '09112345678');
        
        // Verify results
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $final_credit = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_credits WHERE purchase_id = ? AND active = 1");
        $stmt->execute([$purchase_id]);
        $pending_count_after = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT active FROM purchases WHERE id = ?");
        $stmt->execute([$purchase_id]);
        $purchase_active = $stmt->fetchColumn();
        
        echo "Final subscriber credit: $final_credit\n";
        echo "Active pending credits after deactivation: $pending_count_after\n";
        echo "Purchase active status: $purchase_active\n";
        echo "Deactivation result: " . ($deactivation_result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Adjustment reason: " . $deactivation_result['details']['adjustment_reason'] . "\n";
        
        // Assertions
        $test_passed = true;
        $test_passed &= ($final_credit == $initial_credit); // Credit should remain unchanged
        $test_passed &= ($pending_count_after == 0); // Pending credit should be deactivated
        $test_passed &= ($purchase_active == 0); // Purchase should be deactivated
        $test_passed &= ($deactivation_result['success'] == true);
        $test_passed &= ($deactivation_result['details']['adjustment_reason'] == 'pending_credit_deactivated');
        
        echo "Test Result: " . ($test_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";
        
        $pdo->rollBack(); // Clean up
        return $test_passed;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Test FAILED with exception: " . $e->getMessage() . "\n\n";
        return false;
    }
}

/**
 * Test purchase deactivation after pending credit transfer
 */
function test_purchase_deactivation_after_transfer() {
    global $pdo;
    
    echo "=== Test: Purchase Deactivation After Pending Credit Transfer ===\n";
    
    try {
        $pdo->beginTransaction();
        
        // Setup: Create test subscriber
        $mobile = '09123456790';
        $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, credit, verified) VALUES (?, 10, 1)");
        $stmt->execute([$mobile]);
        $subscriber_id = $pdo->lastInsertId();
        
        // Setup: Create test purchase (old enough to be transferred)
        $purchase_amount = 300000; // 300,000 Toman = 3 credit points
        $expected_credit = round($purchase_amount / 100000.0, 1); // 3.0 points
        
        $stmt = $pdo->prepare("INSERT INTO purchases (subscriber_id, mobile, amount, admin_number, active, created_at) VALUES (?, ?, ?, '09112345678', 1, DATE_SUB(NOW(), INTERVAL 50 HOUR))");
        $stmt->execute([$subscriber_id, $mobile, $purchase_amount]);
        $purchase_id = $pdo->lastInsertId();
        
        // Setup: Add pending credit and immediately transfer it (simulate cron job)
        $stmt = $pdo->prepare("INSERT INTO pending_credits (subscriber_id, mobile, purchase_id, credit_amount, active, created_at) VALUES (?, ?, ?, ?, 1, DATE_SUB(NOW(), INTERVAL 50 HOUR))");
        $stmt->execute([$subscriber_id, $mobile, $purchase_id, $expected_credit]);
        
        // Simulate credit transfer (what cron job would do)
        $stmt = $pdo->prepare("UPDATE subscribers SET credit = credit + ? WHERE id = ?");
        $stmt->execute([$expected_credit, $subscriber_id]);
        
        $stmt = $pdo->prepare("UPDATE pending_credits SET transferred = 1, transferred_at = NOW() WHERE purchase_id = ?");
        $stmt->execute([$purchase_id]);
        
        // Get initial state
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $initial_credit = $stmt->fetchColumn();
        
        echo "Initial subscriber credit (after transfer): $initial_credit\n";
        echo "Expected credit that was transferred: $expected_credit\n";
        
        // Test: Deactivate purchase after pending credit was transferred
        $deactivation_result = deactivate_purchase_with_credit_adjustment($pdo, $purchase_id, '09112345678');
        
        // Verify results
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $final_credit = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT active FROM purchases WHERE id = ?");
        $stmt->execute([$purchase_id]);
        $purchase_active = $stmt->fetchColumn();
        
        echo "Final subscriber credit: $final_credit\n";
        echo "Credit adjustment: " . $deactivation_result['details']['credit_adjustment'] . "\n";
        echo "Purchase active status: $purchase_active\n";
        echo "Deactivation result: " . ($deactivation_result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Adjustment reason: " . $deactivation_result['details']['adjustment_reason'] . "\n";
        
        // Assertions
        $test_passed = true;
        $expected_final_credit = $initial_credit - $expected_credit; // Should subtract the transferred amount
        $test_passed &= (abs($final_credit - $expected_final_credit) < 0.1); // Allow for floating point precision
        $test_passed &= ($purchase_active == 0); // Purchase should be deactivated
        $test_passed &= ($deactivation_result['success'] == true);
        $test_passed &= ($deactivation_result['details']['adjustment_reason'] == 'pending_credit_already_transferred');
        
        echo "Expected final credit: $expected_final_credit\n";
        echo "Test Result: " . ($test_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";
        
        $pdo->rollBack(); // Clean up
        return $test_passed;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Test FAILED with exception: " . $e->getMessage() . "\n\n";
        return false;
    }
}

/**
 * Test gift credit deactivation
 */
function test_gift_credit_deactivation() {
    global $pdo;
    
    echo "=== Test: Gift Credit Deactivation ===\n";
    
    try {
        $pdo->beginTransaction();
        
        // Setup: Create test subscriber
        $mobile = '09123456791';
        $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, credit, verified) VALUES (?, 20, 1)");
        $stmt->execute([$mobile]);
        $subscriber_id = $pdo->lastInsertId();
        
        // Setup: Add gift credit
        $gift_amount_toman = 250000; // 250,000 Toman
        $gift_credit_amount = $gift_amount_toman / 5000; // 50 credit points
        
        $stmt = $pdo->prepare("INSERT INTO gift_credits (subscriber_id, mobile, gift_amount_toman, credit_amount, admin_number, active) VALUES (?, ?, ?, ?, '09119246366', 1)");
        $stmt->execute([$subscriber_id, $mobile, $gift_amount_toman, $gift_credit_amount]);
        $gift_credit_id = $pdo->lastInsertId();
        
        // Simulate adding gift credit to subscriber balance (what add_gift_credit function does)
        $stmt = $pdo->prepare("UPDATE subscribers SET credit = credit + ? WHERE id = ?");
        $stmt->execute([$gift_credit_amount, $subscriber_id]);
        
        // Get initial state
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $initial_credit = $stmt->fetchColumn();
        
        echo "Initial subscriber credit (with gift): $initial_credit\n";
        echo "Gift credit amount: $gift_credit_amount\n";
        echo "Gift amount in Toman: $gift_amount_toman\n";
        
        // Test: Deactivate gift credit
        $deactivation_result = deactivate_gift_credit_with_adjustment($pdo, $gift_credit_id, '09119246366');
        
        // Verify results
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $final_credit = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT active FROM gift_credits WHERE id = ?");
        $stmt->execute([$gift_credit_id]);
        $gift_active = $stmt->fetchColumn();
        
        echo "Final subscriber credit: $final_credit\n";
        echo "Credit adjustment: " . $deactivation_result['details']['credit_subtracted'] . "\n";
        echo "Gift credit active status: $gift_active\n";
        echo "Deactivation result: " . ($deactivation_result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Assertions
        $test_passed = true;
        $expected_final_credit = $initial_credit - $gift_credit_amount; // Should subtract the gift amount
        $test_passed &= (abs($final_credit - $expected_final_credit) < 0.1); // Allow for floating point precision
        $test_passed &= ($gift_active == 0); // Gift credit should be deactivated
        $test_passed &= ($deactivation_result['success'] == true);
        
        echo "Expected final credit: $expected_final_credit\n";
        echo "Test Result: " . ($test_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";
        
        $pdo->rollBack(); // Clean up
        return $test_passed;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Test FAILED with exception: " . $e->getMessage() . "\n\n";
        return false;
    }
}

/**
 * Test cron job only processes active pending credits
 */
function test_cron_processes_only_active_credits() {
    global $pdo;
    
    echo "=== Test: Cron Job Processes Only Active Pending Credits ===\n";
    
    try {
        $pdo->beginTransaction();
        
        // Setup: Create test subscriber
        $mobile = '09123456792';
        $stmt = $pdo->prepare("INSERT INTO subscribers (mobile, credit, verified) VALUES (?, 15, 1)");
        $stmt->execute([$mobile]);
        $subscriber_id = $pdo->lastInsertId();
        
        // Setup: Create two pending credits - one active, one inactive
        $credit_amount_1 = 5.0; // Active credit
        $credit_amount_2 = 3.0; // Inactive credit
        
        // Insert active pending credit (old enough to be processed)
        $stmt = $pdo->prepare("INSERT INTO pending_credits (subscriber_id, mobile, credit_amount, active, transferred, created_at) VALUES (?, ?, ?, 1, 0, DATE_SUB(NOW(), INTERVAL 50 HOUR))");
        $stmt->execute([$subscriber_id, $mobile, $credit_amount_1]);
        
        // Insert inactive pending credit (also old enough, but should not be processed)
        $stmt = $pdo->prepare("INSERT INTO pending_credits (subscriber_id, mobile, credit_amount, active, transferred, created_at) VALUES (?, ?, ?, 0, 0, DATE_SUB(NOW(), INTERVAL 50 HOUR))");
        $stmt->execute([$subscriber_id, $mobile, $credit_amount_2]);
        
        // Get initial state
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $initial_credit = $stmt->fetchColumn();
        
        echo "Initial subscriber credit: $initial_credit\n";
        echo "Active pending credit: $credit_amount_1\n";
        echo "Inactive pending credit: $credit_amount_2\n";
        
        // Test: Process pending credits (simulating cron job)
        $process_result = process_pending_credits($pdo, $subscriber_id);
        
        // Verify results
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$subscriber_id]);
        $final_credit = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pending_credits WHERE subscriber_id = ? AND transferred = 1");
        $stmt->execute([$subscriber_id]);
        $transferred_count = $stmt->fetchColumn();
        
        echo "Final subscriber credit: $final_credit\n";
        echo "Number of credits transferred: " . $process_result['transferred_count'] . "\n";
        echo "Total amount transferred: " . $process_result['transferred_amount'] . "\n";
        echo "Process result: " . ($process_result['success'] ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Assertions
        $test_passed = true;
        $expected_final_credit = $initial_credit + $credit_amount_1; // Only active credit should be added
        $test_passed &= (abs($final_credit - $expected_final_credit) < 0.1);
        $test_passed &= ($process_result['transferred_count'] == 1); // Only one credit should be transferred
        $test_passed &= (abs($process_result['transferred_amount'] - $credit_amount_1) < 0.1);
        $test_passed &= ($process_result['success'] == true);
        
        echo "Expected final credit: $expected_final_credit\n";
        echo "Test Result: " . ($test_passed ? "PASSED ✓" : "FAILED ✗") . "\n\n";
        
        $pdo->rollBack(); // Clean up
        return $test_passed;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Test FAILED with exception: " . $e->getMessage() . "\n\n";
        return false;
    }
}

/**
 * Run all tests
 */
function run_all_credit_tests() {
    echo "Starting Credit Deactivation Tests...\n\n";
    
    // Ensure active column exists
    global $pdo;
    ensure_pending_credits_active_column($pdo);
    
    $tests = [
        'test_purchase_deactivation_before_transfer',
        'test_purchase_deactivation_after_transfer', 
        'test_gift_credit_deactivation',
        'test_cron_processes_only_active_credits'
    ];
    
    $passed = 0;
    $total = count($tests);
    
    foreach ($tests as $test) {
        if (function_exists($test)) {
            if (call_user_func($test)) {
                $passed++;
            }
        }
    }
    
    echo "=== TEST SUMMARY ===\n";
    echo "Tests passed: $passed/$total\n";
    echo "Overall result: " . ($passed == $total ? "ALL TESTS PASSED ✓" : "SOME TESTS FAILED ✗") . "\n";
    
    return $passed == $total;
}

// Run tests if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    run_all_credit_tests();
}
?>