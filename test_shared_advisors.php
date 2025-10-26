<?php
// Test script to verify shared advisor management functionality
require_once 'db.php';
require_once 'advisor_utils.php';

echo "=== Shared Advisor Management Test ===\n\n";

// Test admin numbers (you may need to adjust these based on your system)
$admin1 = '09119246366';
$admin2 = '09194467966';

echo "Testing with Admin 1: $admin1\n";
echo "Testing with Admin 2: $admin2\n\n";

try {
    // Test 1: Check if both admins can see all advisors
    echo "1. Testing advisor visibility:\n";
    
    $advisors_admin1 = get_all_advisors_shared($admin1);
    $advisors_admin2 = get_all_advisors_shared($admin2);
    
    echo "   Admin 1 sees " . count($advisors_admin1) . " advisors\n";
    echo "   Admin 2 sees " . count($advisors_admin2) . " advisors\n";
    
    if (count($advisors_admin1) === count($advisors_admin2)) {
        echo "   ✓ Both admins see the same number of advisors (shared list)\n";
    } else {
        echo "   ✗ Admins see different numbers of advisors (not shared)\n";
    }
    
    echo "\n2. Advisor details:\n";
    foreach ($advisors_admin1 as $advisor) {
        echo "   ID: {$advisor['id']}, Name: {$advisor['full_name']}, Created by: {$advisor['manager_name']} ({$advisor['manager_mobile']})\n";
    }
    
    echo "\n3. Testing permissions:\n";
    echo "   Admin 1 can manage advisors: " . (can_manage_advisors($admin1) ? "YES" : "NO") . "\n";
    echo "   Admin 2 can manage advisors: " . (can_manage_advisors($admin2) ? "YES" : "NO") . "\n";
    
    // Test database query directly
    echo "\n4. Direct database query (all active advisors):\n";
    $stmt = $pdo->prepare("
        SELECT id, full_name, manager_mobile, created_at 
        FROM advisors 
        WHERE active = 1 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $all_advisors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "   Total active advisors in database: " . count($all_advisors) . "\n";
    foreach ($all_advisors as $advisor) {
        echo "   ID: {$advisor['id']}, Name: {$advisor['full_name']}, Manager: {$advisor['manager_mobile']}, Created: {$advisor['created_at']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test Complete ===\n";
?>