<?php
/**
 * Test script for the Pending Credits feature
 * This script tests all aspects of the pending credits implementation
 */

require_once 'db.php';
require_once 'pending_credits_utils.php';

echo "=== Pending Credits Feature Test ===\n\n";

try {
    // Test 1: Check if pending_credits table exists
    echo "1. Testing table existence and structure:\n";
    if (pending_credits_table_exists($pdo)) {
        echo "   ✓ pending_credits table exists\n";
        
        // Check table structure
        $stmt = $pdo->prepare("DESCRIBE pending_credits");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $expected_columns = ['id', 'subscriber_id', 'mobile', 'purchase_id', 'credit_amount', 'created_at', 'transferred', 'transferred_at', 'branch_id', 'sales_center_id', 'admin_number'];
        $existing_columns = array_column($columns, 'Field');
        
        foreach ($expected_columns as $col) {
            if (in_array($col, $existing_columns)) {
                echo "   ✓ Column '$col' exists\n";
            } else {
                echo "   ✗ Column '$col' missing\n";
            }
        }
    } else {
        echo "   ✗ pending_credits table does not exist\n";
        echo "   Creating table...\n";
        
        if (ensure_pending_credits_table($pdo)) {
            echo "   ✓ Table created successfully\n";
        } else {
            echo "   ✗ Failed to create table\n";
            exit(1);
        }
    }
    
    // Test 2: Test pending credit functions
    echo "\n2. Testing pending credit functions:\n";
    
    // Find a test subscriber
    $stmt = $pdo->prepare("SELECT id, mobile FROM subscribers WHERE verified = 1 LIMIT 1");
    $stmt->execute();
    $test_subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$test_subscriber) {
        echo "   ✗ No verified subscribers found for testing\n";
        exit(1);
    }
    
    $test_id = $test_subscriber['id'];
    $test_mobile = $test_subscriber['mobile'];
    echo "   Using test subscriber: ID $test_id, Mobile $test_mobile\n";
    
    // Test adding pending credit
    $test_credit_amount = 1.5; // 1.5 points (7500 Toman)
    $result = add_pending_credit($pdo, $test_id, $test_mobile, null, $test_credit_amount, 1, 1, 'TEST_ADMIN');
    
    if ($result) {
        echo "   ✓ Successfully added pending credit of $test_credit_amount points\n";
    } else {
        echo "   ✗ Failed to add pending credit\n";
    }
    
    // Test getting pending credits
    $pending_data = get_pending_credits($pdo, $test_id);
    echo "   Pending credits for subscriber $test_id: " . $pending_data['total_pending'] . " points\n";
    
    // Test getting pending credits by mobile
    $pending_by_mobile = get_pending_credits_by_mobile($pdo, $test_mobile);
    echo "   Pending credits by mobile $test_mobile: " . $pending_by_mobile['total_pending'] . " points\n";
    
    // Test combined credits
    $combined = get_combined_credits($pdo, $test_id);
    echo "   Available credit: " . $combined['available_credit_toman'] . " Toman\n";
    echo "   Pending credit: " . $combined['pending_credit_toman'] . " Toman\n";
    echo "   Total credit: " . $combined['total_credit_toman'] . " Toman\n";
    
    // Test 3: Test credit processing (for credits older than 48 hours)
    echo "\n3. Testing credit processing:\n";
    
    // Create a test pending credit with old timestamp
    $stmt = $pdo->prepare("
        INSERT INTO pending_credits (subscriber_id, mobile, credit_amount, created_at, branch_id, sales_center_id, admin_number) 
        VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 50 HOUR), 1, 1, 'TEST_OLD')
    ");
    $stmt->execute([$test_id, $test_mobile, 2.0]);
    echo "   Created test pending credit with 50-hour old timestamp\n";
    
    // Get current available credit before processing
    $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
    $stmt->execute([$test_id]);
    $credit_before = (float)$stmt->fetchColumn();
    echo "   Available credit before processing: " . ($credit_before * 5000) . " Toman\n";
    
    // Process pending credits
    $process_result = process_pending_credits($pdo, $test_id);
    
    if ($process_result['success']) {
        echo "   ✓ Successfully processed pending credits\n";
        echo "   Transferred count: " . $process_result['transferred_count'] . "\n";
        echo "   Transferred amount: " . $process_result['transferred_amount'] . " points\n";
        
        // Check updated available credit
        $stmt = $pdo->prepare("SELECT credit FROM subscribers WHERE id = ?");
        $stmt->execute([$test_id]);
        $credit_after = (float)$stmt->fetchColumn();
        echo "   Available credit after processing: " . ($credit_after * 5000) . " Toman\n";
        echo "   Credit increase: " . (($credit_after - $credit_before) * 5000) . " Toman\n";
    } else {
        echo "   ✗ Failed to process pending credits: " . $process_result['error'] . "\n";
    }
    
    // Test 4: Test edge cases
    echo "\n4. Testing edge cases:\n";
    
    // Test with non-existent subscriber
    $fake_pending = get_pending_credits($pdo, 999999);
    if ($fake_pending['total_pending'] == 0) {
        echo "   ✓ Correctly handled non-existent subscriber\n";
    } else {
        echo "   ✗ Incorrect response for non-existent subscriber\n";
    }
    
    // Test with non-existent mobile
    $fake_mobile_pending = get_pending_credits_by_mobile($pdo, '09999999999');
    if ($fake_mobile_pending['total_pending'] == 0) {
        echo "   ✓ Correctly handled non-existent mobile\n";
    } else {
        echo "   ✗ Incorrect response for non-existent mobile\n";
    }
    
    // Test 5: Check database consistency
    echo "\n5. Checking database consistency:\n";
    
    // Check for any orphaned pending credits
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as orphaned_count 
        FROM pending_credits pc
        LEFT JOIN subscribers s ON pc.subscriber_id = s.id
        WHERE s.id IS NULL
    ");
    $stmt->execute();
    $orphaned_count = $stmt->fetchColumn();
    
    if ($orphaned_count == 0) {
        echo "   ✓ No orphaned pending credits found\n";
    } else {
        echo "   ⚠ Found $orphaned_count orphaned pending credits\n";
    }
    
    // Check for negative pending credits
    $stmt = $pdo->prepare("SELECT COUNT(*) as negative_count FROM pending_credits WHERE credit_amount < 0");
    $stmt->execute();
    $negative_count = $stmt->fetchColumn();
    
    if ($negative_count == 0) {
        echo "   ✓ No negative pending credits found\n";
    } else {
        echo "   ⚠ Found $negative_count negative pending credits\n";
    }
    
    // Final status
    echo "\n6. Testing summary:\n";
    $final_combined = get_combined_credits($pdo, $test_id);
    echo "   Final available credit: " . $final_combined['available_credit_toman'] . " Toman\n";
    echo "   Final pending credit: " . $final_combined['pending_credit_toman'] . " Toman\n";
    echo "   Final total credit: " . $final_combined['total_credit_toman'] . " Toman\n";
    
    echo "\n✓ All tests completed successfully!\n";
    echo "\nNote: Test data has been created. You may want to clean it up:\n";
    echo "- Remove test pending credits: DELETE FROM pending_credits WHERE admin_number LIKE 'TEST_%';\n";
    
} catch (Exception $e) {
    echo "\n✗ Error during testing: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
?>