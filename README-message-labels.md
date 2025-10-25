# Branch and Store Message Labels

This document explains how branch and store identification works in the message system.

## Overview

The system now supports customizable message labels for each branch and store (sales center). These labels are used in all outgoing communications, such as SMS messages.

## Configuration

Message labels are defined in `config.php` within the branch configuration:

```php
define('BRANCH_CONFIG', serialize([
    1 => [
        'domain' => 'miderclub.ir',
        'name' => 'میدر - ساری',
        'dual_sales_center' => 0,
        'sales_centers' => [
            1 => 'شعبه مرکزی'
        ],
        'message_label' => 'فروشگاه میدر - شعبه ساری',
        'sales_centers_labels' => [
            1 => 'فروشگاه میدر - شعبه مرکزی ساری'
        ]
    ],
    2 => [
        'domain' => 'bamboclub.ir', 
        'name' => 'بامبو - قائمشهر',
        'dual_sales_center' => 1,
        'sales_centers' => [
            1 => 'خیابان ساری',
            2 => 'خیابان بابل'
        ],
        'message_label' => 'فروشگاه بامبو - قائمشهر',
        'sales_centers_labels' => [
            1 => 'فروشگاه بامبو - شعبه خیابان ساری',
            2 => 'فروشگاه بامبو - شعبه خیابان بابل'
        ]
    ]
]));
```

## Key Configuration Items

- `message_label`: The label used for branch-level communications
- `sales_centers_labels`: Individual labels for each sales center within a branch

## Usage

To use these labels in your code, call the appropriate utility function:

```php
// Get the branch message label
$message_label = get_branch_message_label($branch_id);

// Get a specific sales center label
$store_label = get_sales_center_message_label($sales_center_id, $branch_id);

// Get the most appropriate label (recommended function)
// This automatically determines if branch or sales center label should be used
$label = get_message_label($branch_id, $sales_center_id);
```

## Automatic Usage

The system automatically uses these labels in:

1. User registration and verification messages
2. Admin login OTP messages
3. Purchase confirmation messages
4. Credit usage notifications
5. Welcome messages

## Fallbacks

If a specific label is not found, the system falls back to:
1. Branch label if sales center label is missing
2. Default "فروشگاه میدر" label if both are missing

## Extending

To add labels for new types of messages:

1. Import branch_utils.php: `require_once 'branch_utils.php';`
2. Get the appropriate message label: `$label = get_message_label($branch_id, $sales_center_id);`
3. Include the label in your message: `$message = "Your message text...\n$label\nmiderclub.ir";`