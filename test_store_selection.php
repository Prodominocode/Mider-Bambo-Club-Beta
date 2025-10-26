<?php
// Test script to verify single vs multiple store behavior
require_once 'branch_utils.php';

echo "=== Store Selection Test ===\n\n";

$branch_id = isset($_SESSION['branch_id']) ? $_SESSION['branch_id'] : get_current_branch();
$has_multiple_stores = has_dual_sales_centers($branch_id);

echo "Current Branch ID: $branch_id\n";
echo "Has Multiple Stores: " . ($has_multiple_stores ? "YES" : "NO") . "\n\n";

if ($has_multiple_stores) {
    $sales_centers = get_branch_sales_centers($branch_id);
    echo "Available Sales Centers:\n";
    foreach ($sales_centers as $sc_id => $sc_name) {
        echo "  ID: $sc_id, Name: $sc_name\n";
    }
} else {
    echo "Single store branch - will automatically use sales_center_id = 1\n";
}

echo "\n=== Test Complete ===\n";
?>