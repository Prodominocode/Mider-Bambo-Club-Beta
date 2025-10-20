<?php
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id'])) {
  header('Location: index.php'); exit;
}
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM subscribers WHERE id=? AND verified=1");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
if (!$user) {
  session_destroy();
  header('Location: index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>داشبورد - <?php echo htmlspecialchars($user['full_name']); ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <div class="overlay"></div>
  <div class="centered-container">
    <div class="dashboard fade">
      <!-- Main summary view -->
      <div id="main-view">
        <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:10px;">
          <div style="font-size:1.3rem; font-weight:600;">خوش آمدید، <?php echo htmlspecialchars($user['full_name']); ?>!</div>
          <form method="post" action="logout.php" style="display:inline;margin:0">
            <button type="submit" class="small-logout">خروج</button>
          </form>
        </div>
        <div style="color:#b0b3b8; margin-bottom:8px; display:flex; align-items:center; justify-content:center; gap:10px">موبایل: <?php echo htmlspecialchars($user['mobile']); ?></div>
        <div class="credit" id="credit">اعتبار شما: <?php echo (int)$user['credit']; ?> امتیاز</div>
        <div style="display:flex;flex-direction:column;gap:10px;align-items:center;margin-top:8px;">
          <div id="profile-toggle" class="profile-box complete-btn" tabindex="0" style="width:220px;text-align:center;">تکمیل پروفایل</div>
          <button id="purchases-btn" class="small-btn" style="width:220px;text-align:center;">خریدها</button>
        </div>
      </div>

      <!-- Profile edit view (hidden by default) -->
      <div id="profile-view" style="display:none; margin-top:12px;">
        <form id="profile-form" class="profile-form" method="post">
          <input type="hidden" name="mobile" value="<?php echo htmlspecialchars($user['mobile']); ?>">
          <input type="text" name="first_name" placeholder="نام" value="<?php echo htmlspecialchars(explode(' ', $user['full_name'])[0] ?? ''); ?>">
          <input type="text" name="last_name" placeholder="نام خانوادگی" value="<?php $parts = explode(' ', $user['full_name']); echo htmlspecialchars(isset($parts[1])?$parts[1]:''); ?>">
          <input type="email" name="email" placeholder="ایمیل (اختیاری)" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
          <input type="text" name="city" placeholder="شهر محل زندگی" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
          <!-- Jalali date picker (single input) -->
          <div style="margin-top:8px; text-align:right;">
            <label style="color:#b0b3b8; display:block; margin-bottom:6px;">تاریخ تولد</label>
            <input id="jalali-input" type="text" placeholder="<?php echo empty($user['birthday']) ? '' : '۱۴۰۴/۰۷/۲۵'; ?>" style="width:100%; padding:10px; border-radius:8px; border:none; background:#23272a; color:#fff;" readonly>
          </div>
          <input type="hidden" id="birthday" name="birthday" value="<?php echo htmlspecialchars($user['birthday'] ?? ''); ?>">
          <input type="hidden" id="birthday_jalali" name="birthday_jalali" value="<?php echo htmlspecialchars(''); ?>">
          <div style="display:flex; gap:8px; margin-top:10px; justify-content:center;">
            <button type="submit">ذخیره و دریافت امتیاز</button>
            <button type="button" id="profile-back" class="small-logout">بازگشت به داشبورد</button>
          </div>
        </form>
      </div>

      <!-- Purchases view (embedded in dashboard) -->
      <div id="purchases-view" style="display:none; margin-top:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <strong>خریدهای شما</strong>
          <button id="purchases-back" class="small-logout">بازگشت</button>
        </div>
        <div id="purchases-list" style="background:#0f1112; padding:8px; border-radius:8px; max-height:50vh; overflow:auto;">
          در حال بارگذاری...
        </div>
      </div>
    </div>
  <script src="assets/js/main.js"></script>
  <script src="assets/js/jalaali.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css">
  <script src="https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      if (window.jalaliDatepicker && typeof jalaliDatepicker.startWatch === 'function') {
        // autoShow true to open on focus/click like the demo
        // Use persianDigits so the picker shows Persian numerals and the placeholder appears Persian-style
        jalaliDatepicker.startWatch({ selector: 'input[data-jdp]', autoShow: true, autoHide: true, hideAfterChange: true, persianDigits: true });
      }

      var input = document.getElementById('jalali-input');
      var hidden = document.getElementById('birthday');
      if (!input || !hidden) return;

      function toLatinDigits(s){
        var map = {'\u06F0':'0','\u06F1':'1','\u06F2':'2','\u06F3':'3','\u06F4':'4','\u06F5':'5','\u06F6':'6','\u06F7':'7','\u06F8':'8','\u06F9':'9','\u0660':'0','\u0661':'1','\u0662':'2','\u0663':'3','\u0664':'4','\u0665':'5','\u0666':'6','\u0667':'7','\u0668':'8','\u0669':'9'};
        return s.replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function(d){ return map[d] || d; });
      }

      input.setAttribute('data-jdp', '');
      // Prefill input from hidden gregorian birthday if present
      // Only prefill if hidden birthday is a valid YYYY-MM-DD value
      if (hidden.value && /^\d{4}-\d{2}-\d{2}$/.test(hidden.value)) {
        var p = hidden.value.split('-');
        var gy = parseInt(p[0],10), gm = parseInt(p[1],10), gd = parseInt(p[2],10);
        if (!isNaN(gy) && !isNaN(gm) && !isNaN(gd)) {
          var j = Jalaali.toJalali(gy, gm, gd);
          input.value = j.jy + '/' + String(j.jm).padStart(2,'0') + '/' + String(j.jd).padStart(2,'0');
        }
      }

      var hiddenJ = document.getElementById('birthday_jalali');
      input.addEventListener('change', function(){
        var val = input.value.trim();
        if (!val) { hidden.value = ''; hiddenJ.value = ''; return; }
        val = toLatinDigits(val);
        var parts = val.split(/[\/\-\.\s]/);
        if (parts.length >= 3) {
          var jy = parseInt(parts[0],10), jm = parseInt(parts[1],10), jd = parseInt(parts[2],10);
          if (!isNaN(jy) && !isNaN(jm) && !isNaN(jd) && window.Jalaali && typeof Jalaali.toGregorian === 'function'){
            var g = Jalaali.toGregorian(jy, jm, jd);
            hidden.value = g.gy + '-' + String(g.gm).padStart(2,'0') + '-' + String(g.gd).padStart(2,'0');
            // store jalali string as well
            hiddenJ.value = jy + '/' + String(jm).padStart(2,'0') + '/' + String(jd).padStart(2,'0');
          }
        }
      });

      // initialize hidden from input if value exists
      input.dispatchEvent(new Event('change'));

      // view toggles and purchases loading
      var purchasesBtn = document.getElementById('purchases-btn');
      var purchasesList = document.getElementById('purchases-list');
      var purchasesView = document.getElementById('purchases-view');
      var mainView = document.getElementById('main-view');
      var profileView = document.getElementById('profile-view');
      var profileToggle = document.getElementById('profile-toggle');
      var profileBack = document.getElementById('profile-back');
      var purchasesBack = document.getElementById('purchases-back');

      function showMain(){ mainView.style.display='block'; profileView.style.display='none'; purchasesView.style.display='none'; if(profileToggle) profileToggle.textContent = 'تکمیل پروفایل'; }
      function showProfile(){ mainView.style.display='none'; profileView.style.display='block'; purchasesView.style.display='none'; if(profileToggle) profileToggle.textContent = 'بازگشت به داشبورد'; }
      function showPurchases(){ mainView.style.display='none'; profileView.style.display='none'; purchasesView.style.display='block'; if(profileToggle) profileToggle.textContent = 'بازگشت به داشبورد'; }

      if (profileToggle) {
        profileToggle.addEventListener('click', function(){
          if (profileView.style.display === 'block') { showMain(); } else { showProfile(); }
        });
      }
      if (profileBack) profileBack.addEventListener('click', showMain);
      if (purchasesBtn) purchasesBtn.addEventListener('click', function(){
        showPurchases();
        if (purchasesList) {
          purchasesList.innerText = 'در حال بارگذاری...';
          fetch('get_purchases.php').then(function(r){ return r.text(); }).then(function(t){
            try { var j = JSON.parse(t); } catch(e) { purchasesList.innerText = 'خطا در دریافت اطلاعات'; return; }
            if (j.status !== 'success') { purchasesList.innerText = 'خطا: ' + (j.message || 'نامشخص'); return; }
            if (!j.purchases || !j.purchases.length) { purchasesList.innerText = 'خریدی یافت نشد.'; return; }
            var html = '<ul style="list-style:none;padding:0;margin:0">';
            j.purchases.forEach(function(p){
              var date = p.created_at || '';
              var amt = Number(p.amount || 0);
              html += '<li style="padding:8px;border-bottom:1px solid #222;display:flex;justify-content:space-between;align-items:center">';
              html += '<span>' + date + '</span>';
              html += '<strong>' + amt.toLocaleString() + ' تومان</strong>';
              html += '</li>';
            });
            html += '</ul>';
            purchasesList.innerHTML = html;
          }).catch(function(){ purchasesList.innerText = 'خطا در برقراری ارتباط'; });
        }
      });
      if (purchasesBack) purchasesBack.addEventListener('click', showMain);
      // initialize state: main view
      showMain();
    });
  </script>
</body>
</html>
