<?php
/**
 * Test Persian/Farsi Digit Normalization
 * This file tests the norm_digits function to ensure Persian digits are properly converted
 */

header('Content-Type: text/html; charset=utf-8');

// Persian/Farsi digit normalization function (same as in the system)
function norm_digits($s) {
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

$test_cases = [
    // Persian digits
    ['input' => '۰۹۱۲۳۴۵۶۷۸۹', 'expected' => '09123456789', 'description' => 'Persian digits mobile number'],
    ['input' => '۰۹۱۱۹۲۴۶۳۶۶', 'expected' => '09119246366', 'description' => 'Persian digits - specific user'],
    
    // Arabic digits
    ['input' => '٠٩١٢٣٤٥٦٧٨٩', 'expected' => '09123456789', 'description' => 'Arabic digits mobile number'],
    ['input' => '٠٩١١٩٢٤٦٣٦٦', 'expected' => '09119246366', 'description' => 'Arabic digits - specific user'],
    
    // Mixed Persian and English
    ['input' => '۰۹12۳۴5۶۷۸9', 'expected' => '0912345678', 'description' => 'Mixed Persian and English digits'],
    
    // With spaces (should be removed)
    ['input' => '۰۹۱ ۲۳۴ ۵۶۷ ۸۹', 'expected' => '09123456789', 'description' => 'Persian digits with spaces'],
    ['input' => '091 234 567 89', 'expected' => '09123456789', 'description' => 'English digits with spaces'],
    
    // Regular English digits (should remain unchanged)
    ['input' => '09123456789', 'expected' => '09123456789', 'description' => 'Regular English digits'],
    ['input' => '09119246366', 'expected' => '09119246366', 'description' => 'Regular English digits - specific user'],
    
    // Edge cases
    ['input' => '', 'expected' => '', 'description' => 'Empty string'],
    ['input' => '۰', 'expected' => '0', 'description' => 'Single Persian zero'],
    ['input' => '۹', 'expected' => '9', 'description' => 'Single Persian nine'],
];

// Interactive test if POST data is received
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_input'])) {
    $test_input = $_POST['test_input'];
    $result = norm_digits($test_input);
    $test_result = [
        'input' => $test_input,
        'output' => $result,
        'length' => strlen($result),
        'is_valid_mobile' => preg_match('/^09\d{9}$/', $result) ? 'Yes' : 'No'
    ];
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تست تبدیل اعداد فارسی</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1d23;
            color: #fff;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(34,36,38,0.97);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.25);
        }
        
        h1 {
            color: #ffb300;
            text-align: center;
            margin-bottom: 24px;
        }
        
        .test-section {
            background: #23272a;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .test-case {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .test-case:last-child {
            border-bottom: none;
        }
        
        .test-input {
            font-family: monospace;
            background: #1a1d23;
            padding: 4px 8px;
            border-radius: 4px;
            color: #ffb300;
        }
        
        .test-output {
            font-family: monospace;
            background: #1a1d23;
            padding: 4px 8px;
            border-radius: 4px;
            color: #4caf50;
        }
        
        .test-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .pass {
            background: #4caf50;
            color: white;
        }
        
        .fail {
            background: #f44336;
            color: white;
        }
        
        .interactive-test {
            background: #2c3136;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #ffb300;
            font-weight: bold;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #555;
            border-radius: 6px;
            background: #1a1d23;
            color: #fff;
            font-size: 16px;
            font-family: monospace;
            box-sizing: border-box;
        }
        
        button {
            background: #ffb300;
            color: #181a1b;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #ffd54f;
        }
        
        .result {
            background: #1a1d23;
            border: 1px solid #4caf50;
            border-radius: 6px;
            padding: 16px;
            margin-top: 16px;
        }
        
        .summary {
            text-align: center;
            padding: 16px;
            font-size: 18px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>تست تبدیل اعداد فارسی/عربی به انگلیسی</h1>
        
        <div class="interactive-test">
            <h3 style="color: #ffb300; margin-bottom: 16px;">تست تعاملی</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="test_input">شماره موبایل را با اعداد فارسی یا عربی وارد کنید:</label>
                    <input type="text" id="test_input" name="test_input" 
                           placeholder="مثال: ۰۹۱۲۳۴۵۶۷۸۹ یا ٠٩١٢٣٤٥٦٧٨٩" 
                           value="<?php echo isset($test_input) ? htmlspecialchars($test_input) : ''; ?>">
                </div>
                <button type="submit">تست کن</button>
            </form>
            
            <?php if (isset($test_result)): ?>
                <div class="result">
                    <h4 style="color: #4caf50; margin-bottom: 12px;">نتیجه تست:</h4>
                    <p><strong>ورودی:</strong> <code style="background: #333; padding: 2px 6px; border-radius: 3px;"><?php echo htmlspecialchars($test_result['input']); ?></code></p>
                    <p><strong>خروجی:</strong> <code style="background: #333; padding: 2px 6px; border-radius: 3px;"><?php echo htmlspecialchars($test_result['output']); ?></code></p>
                    <p><strong>طول:</strong> <?php echo $test_result['length']; ?> کاراکتر</p>
                    <p><strong>شماره موبایل معتبر:</strong> <span style="color: <?php echo $test_result['is_valid_mobile'] === 'Yes' ? '#4caf50' : '#f44336'; ?>;"><?php echo $test_result['is_valid_mobile']; ?></span></p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="test-section">
            <h3 style="color: #ffb300; margin-bottom: 16px;">نتایج تست‌های خودکار</h3>
            
            <?php
            $passed = 0;
            $total = count($test_cases);
            
            foreach ($test_cases as $test): 
                $result = norm_digits($test['input']);
                $is_pass = ($result === $test['expected']);
                if ($is_pass) $passed++;
            ?>
                <div class="test-case">
                    <div style="flex: 2;">
                        <strong><?php echo htmlspecialchars($test['description']); ?></strong>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <span class="test-input"><?php echo htmlspecialchars($test['input']); ?></span>
                    </div>
                    <div style="flex: 1; text-align: center;">
                        <span class="test-output"><?php echo htmlspecialchars($result); ?></span>
                    </div>
                    <div style="flex: 0.5; text-align: center;">
                        <span class="test-status <?php echo $is_pass ? 'pass' : 'fail'; ?>">
                            <?php echo $is_pass ? 'موفق' : 'ناموفق'; ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="summary" style="color: <?php echo $passed === $total ? '#4caf50' : '#f44336'; ?>;">
            <?php echo $passed; ?> از <?php echo $total; ?> تست موفقیت آمیز بود
            <?php if ($passed === $total): ?>
                <br>🎉 همه تست‌ها با موفقیت انجام شد!
            <?php else: ?>
                <br>⚠️ برخی تست‌ها ناموفق بودند. لطفاً کد را بررسی کنید.
            <?php endif; ?>
        </div>
        
        <div style="margin-top: 24px; padding: 16px; background: #23272a; border-radius: 8px; font-size: 14px; color: #b0b3b8;">
            <strong>توضیحات:</strong><br>
            • این تست تابع norm_digits() را بررسی می‌کند که در سیستم برای تبدیل اعداد فارسی/عربی به انگلیسی استفاده می‌شود<br>
            • تابع باید اعداد فارسی (۰۱۲...) و عربی (٠١٢...) را به انگلیسی (012...) تبدیل کند<br>
            • همچنین فاصله‌های اضافی را حذف می‌کند<br>
            • این تابع در فایل‌های send_otp.php، verify_otp.php، admin.php و سایر قسمت‌های سیستم استفاده شده است
        </div>
    </div>
    
    <script>
        // Test JavaScript normalization function as well
        function normDigits(str) {
            if (!str) return str;
            
            const persianNumbers = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
            const arabicNumbers = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            const englishNumbers = ['0','1','2','3','4','5','6','7','8','9'];
            
            for (let i = 0; i < 10; i++) {
                str = str.replace(new RegExp(persianNumbers[i], 'g'), englishNumbers[i]);
            }
            
            for (let i = 0; i < 10; i++) {
                str = str.replace(new RegExp(arabicNumbers[i], 'g'), englishNumbers[i]);
            }
            
            str = str.replace(/\s+/g, '');
            
            return str;
        }
        
        // Auto-normalize the input field
        document.getElementById('test_input').addEventListener('input', function(e) {
            const normalized = normDigits(e.target.value);
            if (normalized !== e.target.value) {
                e.target.value = normalized;
            }
        });
        
        console.log('JavaScript normDigits function is active and will auto-normalize input');
    </script>
</body>
</html>