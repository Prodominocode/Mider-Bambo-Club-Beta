<?php
require_once 'config.php';
require_once 'branch_utils.php';

// Test with branch ID 1
echo "Branch 1 domain: " . get_branch_domain(1) . "\n";

// Test with branch ID 2
echo "Branch 2 domain: " . get_branch_domain(2) . "\n";

// Test with default branch
echo "Default branch domain: " . get_branch_domain() . "\n";

// Test with non-existent branch
echo "Non-existent branch domain: " . get_branch_domain(999) . "\n";