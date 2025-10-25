# Multi-Branch Support Implementation

This update adds multi-branch operation capability to the MiderBamboClub system. The implementation allows the system to operate across different domains with branch-specific data separation while maintaining a unified codebase.

## Key Changes

### 1. Branch Configuration

The `config.php` file now includes a branch configuration array:

```php
define('BRANCH_CONFIG', serialize([
    1 => [
        'domain' => 'miderclub.com',
        'name' => 'Mider Branch 1',
        'dual_sales_center' => 1, // This branch has two sales centers
    ],
    2 => [
        'domain' => 'bamboclub.com', 
        'name' => 'Bambo Branch 2',
        'dual_sales_center' => 0, // This branch has one sales center
    ]
]));
```

### 2. Database Changes

New columns have been added to track branch and sales center affiliations:

- `branch_id` in `subscribers` table
- `branch_id` and `sales_center_id` in `purchases` table
- `branch_id` and `sales_center_id` in `credit_usage` table

### 3. Branch Utility Functions

A new file `branch_utils.php` provides utility functions:

- `get_current_branch()` - Determines branch ID based on domain
- `get_branch_info()` - Retrieves branch configuration
- `get_all_branches()` - Lists all available branches
- `has_dual_sales_centers()` - Checks if a branch has dual sales centers

### 4. Admin Interface Updates

- Sales center selection during admin login (for branches with dual sales centers)
- Branch-specific reporting and data filtering
- Branch info displayed in user-facing interfaces

## Testing Multi-Branch Functionality

### Test Procedure

1. **Setup Domain Mapping**:
   - Map `miderclub.com` to the server IP for Branch 1
   - Map `bamboclub.com` to the server IP for Branch 2
   - Or modify your hosts file locally for testing

2. **Registration Test**:
   - Register users on both domains
   - Verify that the `branch_id` is correctly recorded

3. **Admin Login**:
   - Log in as admin on Branch 1 and verify that sales center selection appears
   - Log in as admin on Branch 2 and verify that sales center selection is hidden

4. **Purchase Recording**:
   - Record purchases on both branches
   - Verify that purchases are branch-specific and properly filtered

5. **Credit Usage**:
   - Use credits on both branches
   - Verify that credit usage is branch-specific

## Database Migration

Run the migration script to update your database schema:

```sql
-- Execute this SQL to add branch support
source migrations/add_branch_support.sql
```

## Future Improvements

1. Add branch-specific admin permissions
2. Implement cross-branch reporting for super-admins
3. Add ability to transfer user accounts between branches