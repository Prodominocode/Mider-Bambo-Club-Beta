<?php
// Set timezone for the application
date_default_timezone_set('Asia/Tehran');

// Debug mode - set to true for detailed error messages, false for production
define('DEBUG_MODE', true);

// Kavenegar API configuration
// Replace with your actual Kavenegar API key

define('KAVENEGAR_API_KEY', '524651466876735449564C3647317575745461764B513D3D');
define('KAVENEGAR_SENDER', '9981802012'); // Example sender, replace as needed

define('SITE_NAME', 'Miderclub');
// Admin allowed phone numbers, names and roles
// Format: ['phone' => ['name' => 'Admin Name', 'role' => 'manager|seller']]
// Roles: 'manager' (full access), 'seller' (limited access)
define('ADMIN_ALLOWED', serialize([
	// Managers - full access
	'09119246366' => ['name' => 'ادمین نرم افزار', 'role' => 'manager'],
	'09194467966' => ['name' => 'میلاد', 'role' => 'manager'],
	'09119012010' => ['name' => 'بهمن', 'role' => 'manager'],
	'09115809496' => ['name' => 'اسکندری', 'role' => 'manager'],
	'09112288302' => ['name' => 'سینا', 'role' => 'manager'],
	// Sellers - limited access
	'09941187672' => ['name' => 'آرشام', 'role' => 'seller'],
	'09113239550' => ['name' => 'مجتبی', 'role' => 'seller'],
	'09333184589' => ['name' => 'رزاقیان', 'role' => 'seller'],
	'09114424410' => ['name' => 'محمد', 'role' => 'seller'],
	'09204201488' => ['name' => 'تست نرم افزار', 'role' => 'seller'],
	'09013216451' => ['name' => 'روحی', 'role' => 'seller'],
	'09917847923' => ['name' => 'امین', 'role' => 'seller'],
	'09362500124' => ['name' => 'امیر', 'role' => 'seller']
]));

// Branch configuration
// branch_id: Unique identifier for the branch
// domain: Domain name for this branch
// name: Display name for this branch
// dual_sales_center: 0 = single sales center, 1 = dual sales centers
// sales_centers: Array of sales centers with their names and IDs
// message_label: Label to use in messages (SMS, email, etc.)
// sales_centers_labels: Message labels for each sales center
define('BRANCH_CONFIG', serialize([
	1 => [
		'domain' => 'miderclub.ir',
		'name' => 'میدر - ساری',
		'dual_sales_center' => 0,
		'sales_centers' => [
			1 => '-'
		],
		'message_label' => 'فروشگاه میدر - شعبه ساری',
		'sales_centers_labels' => [
			1 => 'فروشگاه میدر - شعبه ساری'
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

// Debug mode - set to false in production
define('DEBUG_MODE', false);
