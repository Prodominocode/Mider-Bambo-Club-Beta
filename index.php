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
        <div style="margin-top:15px;text-align:center;">
          <a href="vcard_balance.php" style="color:#888;font-size:12px;text-decoration:none;border-bottom:1px dotted #888;">موجودی کارت مجازی را بررسی کنید</a>
        </div>
      </form>
    </div>
  </div>
  <script src="assets/js/main.js"></script>
</body>
</html>
