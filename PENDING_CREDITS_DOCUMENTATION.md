# Pending Credits Feature Documentation

## Overview

The Pending Credits feature introduces a 48-hour waiting period for newly earned credits from purchases. This ensures proper verification and prevents immediate redemption of potentially fraudulent transactions.

## Key Features

### 1. Two-Tier Credit System
- **Available Credit**: Credits that can be used immediately (existing credits + credits transferred after 48 hours)
- **Pending Credit**: Newly earned credits that must wait 48 hours before becoming available

### 2. Automatic Processing
- Credits are automatically transferred from pending to available after 48 hours
- Background processing ensures seamless user experience
- No manual intervention required

### 3. User Interface Updates
- Dashboard shows both Available Credit and Pending Credit separately
- Admin inquiry interface displays complete credit breakdown
- SMS notifications inform users about pending status

## Technical Implementation

### Database Structure

#### New Table: `pending_credits`
```sql
CREATE TABLE `pending_credits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subscriber_id` INT NOT NULL,
  `mobile` VARCHAR(20) NOT NULL,
  `purchase_id` INT UNSIGNED NULL,
  `credit_amount` DECIMAL(10,1) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transferred` TINYINT(1) NOT NULL DEFAULT 0,
  `transferred_at` DATETIME NULL,
  `branch_id` INT NULL,
  `sales_center_id` INT NULL,
  `admin_number` VARCHAR(20) NULL,
  PRIMARY KEY (`id`),
  -- Foreign key constraints and indexes
);
```

### Key Functions

#### Core Functions (`pending_credits_utils.php`)

1. **`add_pending_credit()`** - Adds new pending credit to the system
2. **`process_pending_credits()`** - Transfers eligible pending credits to available
3. **`get_combined_credits()`** - Returns both available and pending credit information
4. **`get_pending_credits()`** - Retrieves pending credits for a specific user

#### Modified Purchase Flow

**Before:**
```php
// Direct credit addition
UPDATE subscribers SET credit = credit + ? WHERE id = ?
```

**After:**
```php
// Add to pending credits table
add_pending_credit($pdo, $subscriber_id, $mobile, $purchase_id, $credit_amount, ...);
```

### Automated Processing

#### Cron Job Setup
```bash
# Add to crontab to run every hour
0 * * * * /usr/bin/php /path/to/process_pending_credits_cron.php
```

The cron job automatically:
- Processes pending credits older than 48 hours
- Transfers credits to available balance
- Logs all activities
- Cleans up old transferred records

## User Experience

### Customer Dashboard
- **Available Credit**: Shows immediately usable credit
- **Pending Credit**: Shows credits waiting for approval (with countdown)
- **Clear Messaging**: Explains 48-hour waiting period

### Admin Interface
- **Complete Overview**: Shows all credit types for any customer
- **Pending Details**: Lists individual pending credits with timestamps
- **Credit Usage**: Only allows usage of available credits

### SMS Notifications
Updated messages inform customers about:
- Pending credit status
- 48-hour activation period
- Available vs. total credit amounts

## Data Flow

### Purchase Process
1. Customer makes purchase
2. Credit calculated based on amount
3. Credit added to `pending_credits` table
4. SMS sent with pending notification
5. Customer sees pending credit in dashboard

### Credit Activation
1. Cron job runs hourly
2. Identifies pending credits > 48 hours old
3. Transfers credit to main balance
4. Marks as transferred in pending table
5. Customer can now use the credit

### Credit Usage
1. Customer requests credit usage
2. System checks available credit only
3. Deducts from available balance
4. Pending credits remain untouched

## Installation & Migration

### 1. Database Migration
```sql
-- Run the migration script
SOURCE migrations/create_pending_credits_table.sql;
```

### 2. File Updates
- `admin.php` - Modified purchase handling and inquiry
- `dashboard.php` - Updated credit display
- `pending_credits_utils.php` - New utility functions
- `update_profile.php` - Updated to include pending credits

### 3. Cron Job Setup
```bash
# Install cron job for automatic processing
crontab -e
# Add: 0 * * * * /usr/bin/php /path/to/process_pending_credits_cron.php
```

### 4. Testing
```bash
# Run the test script to verify implementation
php test_pending_credits.php
```

## Configuration

### Waiting Period
The 48-hour waiting period is configured in the SQL queries:
```sql
WHERE created_at <= DATE_SUB(NOW(), INTERVAL 48 HOUR)
```

To change the waiting period, update the `INTERVAL` value in:
- `pending_credits_utils.php` (process_pending_credits function)
- `process_pending_credits_cron.php`

### Credit Calculation
Credit calculation remains unchanged:
```php
$creditToAdd = round(((float)$amount)/100000.0, 1);
```

## Monitoring & Maintenance

### Log Files
- `logs/pending_credits_cron.log` - Cron job activities
- PHP error logs - System errors and warnings

### Database Monitoring
```sql
-- Check pending credits status
SELECT 
    COUNT(*) as total_pending,
    SUM(credit_amount) as total_amount,
    MIN(created_at) as oldest_pending
FROM pending_credits 
WHERE transferred = 0;

-- Check processing statistics
SELECT 
    DATE(transferred_at) as date,
    COUNT(*) as processed_count,
    SUM(credit_amount) as processed_amount
FROM pending_credits 
WHERE transferred = 1 
    AND transferred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(transferred_at);
```

### Cleanup
```sql
-- Clean up old transferred records (run monthly)
DELETE FROM pending_credits 
WHERE transferred = 1 
    AND transferred_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

## Security Considerations

### Data Integrity
- Foreign key constraints ensure referential integrity
- Transaction-based processing prevents data corruption
- Audit trail maintains complete history

### Fraud Prevention
- 48-hour waiting period allows for transaction verification
- Prevents immediate redemption of potentially fraudulent purchases
- Complete audit trail for all credit movements

### Access Control
- Only available credits can be used for purchases
- Pending credits are read-only for customers
- Admin interface shows complete picture for support

## Troubleshooting

### Common Issues

#### Pending Credits Not Processing
1. Check cron job is running: `crontab -l`
2. Verify log files: `tail -f logs/pending_credits_cron.log`
3. Check database connectivity
4. Verify table structure

#### Incorrect Credit Display
1. Check if `pending_credits_utils.php` is included
2. Verify `get_combined_credits()` function calls
3. Check for JavaScript errors in browser console

#### Migration Issues
1. Verify database permissions
2. Check for existing table conflicts
3. Review migration script syntax

### Recovery Procedures

#### Reprocess Failed Transfers
```php
// Reset failed transfers and reprocess
UPDATE pending_credits SET transferred = 0 WHERE id IN (...);
// Then run: php process_pending_credits_cron.php
```

#### Emergency Credit Transfer
```php
// Manually transfer specific pending credit
$result = process_pending_credits($pdo, $subscriber_id);
```

## Future Enhancements

### Possible Improvements
1. **Variable Waiting Periods**: Different periods based on amount or customer tier
2. **SMS Notifications**: Automatic notification when credits become available
3. **Admin Override**: Manual credit activation for special cases
4. **Analytics Dashboard**: Visual reporting of pending credit metrics
5. **API Integration**: REST API for external system integration

### Performance Optimization
1. **Indexed Queries**: Additional indexes for large-scale operations
2. **Batch Processing**: Process multiple credits in single transaction
3. **Archival System**: Move old records to archive tables

## Support

For technical support or questions about this implementation:
1. Check the test script output: `php test_pending_credits.php`
2. Review log files for error messages
3. Verify database consistency with monitoring queries
4. Contact development team with specific error details

---

**Last Updated**: October 2025  
**Version**: 1.0  
**Author**: GitHub Copilot