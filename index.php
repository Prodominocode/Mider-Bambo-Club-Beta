<?php
// index.php: Landing page with newsletter form
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>باشگاه مشتریان پوشاک MIDER</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .inquiry-btn {
      background: transparent !important;
      border: 2px solid #fff !important;
      color: #fff !important;
      padding: 12px !important;
      border-radius: 8px !important;
      font-size: 14px !important;
      cursor: pointer !important;
      width: 100% !important;
      transition: all 0.3s ease !important;
    }
    .inquiry-btn:hover {
      background: rgba(255,255,255,0.1) !important;
      border-color: #ffb300 !important;
      color: #ffb300 !important;
      transform: translateY(-2px) !important;
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="centered-container">
    <img src="assets/images/logo.png" alt="Logo" class="logo">
    <div class="subtitle">باشگاه مشتریان - Bambo</div>
    <div class="description">به باشگاه مشتریان پوشاک آقایان و بانوان میدر خوش آمدید.</div>
    <div class="form-box fade">
      <form id="newsletter-form">
        <div class="form-title">ورود / عضویت در باشگاه</div>
        <input type="text" id="mobile" name="mobile" placeholder="شماره موبایل" required>
        <button type="submit">ورود / عضویت</button>
        <div id="form-msg"></div>
        
        <!-- Inquiry buttons -->
        <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">
          <a href="mobile_inquiry.php" style="text-decoration:none;">
            <button type="button" class="inquiry-btn">
              📱 استعلام با شماره موبایل
            </button>
          </a>
          <a href="vcard_balance.php" style="text-decoration:none;">
            <button type="button" class="inquiry-btn">
              💳 استعلام با کارت مجازی
            </button>
          </a>
        </div>
        
        <!-- <div style="margin-top:15px;text-align:center;">
          <a href="vcard_balance.php" style="color:#888;font-size:12px;text-decoration:none;border-bottom:1px dotted #888;">موجودی کارت مجازی را بررسی کنید</a>
        </div> -->
      </form>
    </div>
  </div>
  <script src="assets/js/main.js"></script>
</body>
</html>
