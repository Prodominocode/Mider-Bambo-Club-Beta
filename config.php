<?php
// Kavenegar API configuration
// Replace with your actual Kavenegar API key

define('KAVENEGAR_API_KEY', '524651466876735449564C3647317575745461764B513D3D');
define('KAVENEGAR_SENDER', '9981802012'); // Example sender, replace as needed

define('SITE_NAME', 'Miderclub');
// Admin allowed phone numbers (normalize to +98 or E.164 as used in sessions)
// Example: ['+989123456789', '+989111223344']
define('ADMIN_ALLOWED', serialize([
	'09119246366','09194467966','09366263863','09333184589','09115809496','09113239550' // example admin number (replace with real ones)
]));

// Debug mode - set to false in production
define('DEBUG_MODE', false);
