<?php
// Branch utility functions

/**
 * Get current branch ID based on domain name
 * 
 * @return int Branch ID (defaults to 1 if not found)
 */
function get_current_branch() {
    $current_domain = $_SERVER['HTTP_HOST'] ?? '';
    $branches = @unserialize(BRANCH_CONFIG);
    
    if (!is_array($branches)) {
        return 1; // Default to branch 1 if config is invalid
    }
    
    foreach ($branches as $branch_id => $branch_data) {
        if (isset($branch_data['domain']) && $branch_data['domain'] === $current_domain) {
            return $branch_id;
        }
    }
    
    // Default to branch 1 if domain not found
    return 1;
}

/**
 * Get branch information by ID
 * 
 * @param int $branch_id Branch ID
 * @return array|null Branch information or null if not found
 */
function get_branch_info($branch_id) {
    $branches = @unserialize(BRANCH_CONFIG);
    
    if (!is_array($branches) || !isset($branches[$branch_id])) {
        return null;
    }
    
    return $branches[$branch_id];
}

/**
 * Get all branches
 * 
 * @return array All branch information
 */
function get_all_branches() {
    $branches = @unserialize(BRANCH_CONFIG);
    
    if (!is_array($branches)) {
        // Return at least the default branch if config is invalid
        return [
            1 => [
                'domain' => $_SERVER['HTTP_HOST'] ?? 'miderclub.com',
                'name' => 'Default Branch',
                'dual_sales_center' => 0,
            ]
        ];
    }
    
    return $branches;
}

/**
 * Check if current branch has dual sales centers
 * 
 * @param int|null $branch_id Optional branch ID (uses current branch if null)
 * @return bool True if branch has dual sales centers
 */
function has_dual_sales_centers($branch_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    $branch_info = get_branch_info($branch_id);
    
    if ($branch_info === null) {
        return false;
    }
    
    return !empty($branch_info['dual_sales_center']);
}

/**
 * Get sales centers for a branch
 * 
 * @param int|null $branch_id Optional branch ID (uses current branch if null)
 * @return array Sales centers as array of ID => name pairs
 */
function get_branch_sales_centers($branch_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    $branch_info = get_branch_info($branch_id);
    
    if ($branch_info === null || !isset($branch_info['sales_centers'])) {
        // Return at least one default sales center
        return [1 => 'شعبه مرکزی'];
    }
    
    return $branch_info['sales_centers'];
}

/**
 * Get branch message label for use in communications
 * 
 * @param int|null $branch_id Optional branch ID (uses current branch if null)
 * @return string Message label for the branch
 */
function get_branch_message_label($branch_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    $branch_info = get_branch_info($branch_id);
    
    if ($branch_info === null || !isset($branch_info['message_label'])) {
        // Return a default label if not found
        return 'فروشگاه میدر';
    }
    
    return $branch_info['message_label'];
}

/**
 * Get sales center message label for a specific branch and sales center
 * 
 * @param int $sales_center_id The sales center ID
 * @param int|null $branch_id Optional branch ID (uses current branch if null)
 * @return string Message label for the sales center
 */
function get_sales_center_message_label($sales_center_id, $branch_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    $branch_info = get_branch_info($branch_id);
    
    if ($branch_info === null || !isset($branch_info['sales_centers_labels']) || !isset($branch_info['sales_centers_labels'][$sales_center_id])) {
        // If sales center label is not found, try to use branch label
        if (isset($branch_info['message_label'])) {
            return $branch_info['message_label'];
        }
        
        // Fall back to default label if everything else fails
        return 'فروشگاه میدر';
    }
    
    return $branch_info['sales_centers_labels'][$sales_center_id];
}

/**
 * Get the appropriate message label based on branch and sales center
 * This is the main function to use when generating messages
 * 
 * @param int|null $branch_id Branch ID (uses current branch if null)
 * @param int|null $sales_center_id Sales center ID (uses branch label if null or not applicable)
 * @return string The appropriate message label for the context
 */
function get_message_label($branch_id = null, $sales_center_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    // If no sales center ID or branch doesn't have dual sales centers, return branch label
    if ($sales_center_id === null || !has_dual_sales_centers($branch_id)) {
        return get_branch_message_label($branch_id);
    }
    
    // Otherwise return sales center specific label
    return get_sales_center_message_label($sales_center_id, $branch_id);
}

/**
 * Get the domain for a specific branch
 * 
 * @param int|null $branch_id Branch ID (uses current branch if null)
 * @return string Domain for the branch, properly formatted for use in messages
 */
function get_branch_domain($branch_id = null) {
    if ($branch_id === null) {
        $branch_id = get_current_branch();
    }
    
    // Make sure we have a numeric branch ID
    $branch_id = intval($branch_id);
    if ($branch_id <= 0) {
        $branch_id = 1; // Default to branch 1 if invalid ID
    }
    
    $branches = @unserialize(BRANCH_CONFIG);
    
    // Direct access for performance
    if (is_array($branches) && isset($branches[$branch_id]) && 
        isset($branches[$branch_id]['domain']) && 
        !empty($branches[$branch_id]['domain'])) {
        
        $domain = trim($branches[$branch_id]['domain']);
    } else {
        // Fallback to default domain
        $domain = 'miderclub.ir';
    }
    
    // Make sure the domain has http/https prefix
    if (!preg_match('/^https?:\/\//i', $domain)) {
        $domain = 'www.' . $domain;
    }
    
    return $domain;
}