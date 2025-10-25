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

// Get purchase count
try {
  $purchase_count_stmt = $pdo->prepare("SELECT COUNT(*) as purchase_count FROM purchases WHERE subscriber_id=? AND active=1");
  $purchase_count_stmt->execute([$user_id]);
  $purchase_count = $purchase_count_stmt->fetch()['purchase_count'];
} catch (Exception $e) {
  // Fallback if active column doesn't exist
  try {
    $purchase_count_stmt = $pdo->prepare("SELECT COUNT(*) as purchase_count FROM purchases WHERE subscriber_id=?");
    $purchase_count_stmt->execute([$user_id]);
    $purchase_count = $purchase_count_stmt->fetch()['purchase_count'];
  } catch (Exception $e2) {
    $purchase_count = 0;
  }
}

// Get total used credit
try {
  $used_credit_stmt = $pdo->prepare("SELECT SUM(amount) as total_used FROM credit_usage WHERE user_mobile=? AND active=1");
  $used_credit_stmt->execute([$user['mobile']]);
  $used_credit_result = $used_credit_stmt->fetch();
  $total_used_credit = $used_credit_result['total_used'] ? abs($used_credit_result['total_used']) : 0;
} catch (Exception $e) {
  // Fallback if active column doesn't exist
  try {
    $used_credit_stmt = $pdo->prepare("SELECT SUM(amount) as total_used FROM credit_usage WHERE user_mobile=?");
    $used_credit_stmt->execute([$user['mobile']]);
    $used_credit_result = $used_credit_stmt->fetch();
    $total_used_credit = $used_credit_result['total_used'] ? abs($used_credit_result['total_used']) : 0;
  } catch (Exception $e2) {
    $total_used_credit = 0;
  }
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
        
        <!-- Credit display - prominent and centered -->
        <div class="credit" id="credit" style="text-align:center; margin-bottom:20px;">اعتبار شما: <?php echo number_format(intval($user['credit'] * 5000)); ?> تومان</div>
        
        <!-- New data fields in table-style layout -->
        <div style="display:flex; justify-content:center; margin-bottom:20px;">
          <table style="border-collapse: collapse; background:#1a1c1e; border-radius:8px; overflow:hidden; width:280px;">
            <tr>
              <td style="padding:12px 15px; text-align:center; border-left:1px solid #2a2d30; width:50%;">
                <div style="font-size:0.9em; color:#b0b3b8; margin-bottom:5px;">تعداد خریدها</div>
                <div style="font-size:1.1em; font-weight:bold; color:#4caf50;"><?php echo number_format($purchase_count); ?></div>
              </td>
              <td style="padding:12px 15px; text-align:center; width:50%;">
                <div style="font-size:0.9em; color:#b0b3b8; margin-bottom:5px;">اعتبار استفاده شده</div>
                <div style="font-size:1.1em; font-weight:bold; color:#ff5252;"><?php echo number_format($total_used_credit); ?> تومان</div>
              </td>
            </tr>
          </table>
        </div>
        
        <div style="display:flex;flex-direction:column;gap:10px;align-items:center;margin-top:8px;">
          <button id="profile-toggle" class="dashboard-btn" tabindex="0" style="width:220px;text-align:center;background:#ffb300;color:#181a1b;border:none;border-radius:8px;padding:12px;font-size:1rem;font-weight:600;cursor:pointer;transition:background 0.2s;">تکمیل پروفایل</button>
          <button id="purchases-btn" class="dashboard-btn" style="width:220px;text-align:center;background:#ffb300;color:#181a1b;border:none;border-radius:8px;padding:12px;font-size:1rem;font-weight:600;cursor:pointer;transition:background 0.2s;">تراکنش‌ها</button>
        </div>
      </div>

      <!-- Profile edit view (hidden by default) -->
      <div id="profile-view" style="display:none; margin-top:12px;">
        <form id="profile-form" class="profile-form" method="post" style="display:block;">
          <input type="hidden" name="mobile" value="<?php echo htmlspecialchars($user['mobile']); ?>">
          <input type="text" name="full_name" placeholder="نام و نام خانوادگی" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>">
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

      <!-- Transactions view (embedded in dashboard) -->
      <div id="purchases-view" style="display:none; margin-top:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <strong>تراکنش‌های شما</strong>
          <button id="purchases-back" class="small-logout">بازگشت</button>
        </div>
        <div style="background:#0f1112; padding:8px; border-radius:8px; margin-bottom:8px;">
          <div style="display:flex; align-items:center; gap:8px;">
            <div style="display:flex; align-items:center;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4caf50" stroke-width="2" style="margin-left:4px;">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
              <span style="color:#4caf50; font-size:0.9em;">افزایش اعتبار</span>
            </div>
            <div style="display:flex; align-items:center; margin-right:10px;">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ff5252" stroke-width="2" style="margin-left:4px;">
                <line x1="5" y1="12" x2="19" y2="12"></line>
              </svg>
              <span style="color:#ff5252; font-size:0.9em;">استفاده از اعتبار</span>
            </div>
          </div>
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

      // Add hover effects for dashboard buttons
      function addButtonHover(buttonId) {
        var button = document.getElementById(buttonId);
        if (button) {
          button.addEventListener('mouseenter', function() {
            button.style.background = '#ffd54f';
          });
          button.addEventListener('mouseleave', function() {
            button.style.background = '#ffb300';
          });
        }
      }
      addButtonHover('profile-toggle');
      addButtonHover('purchases-btn');

      // view toggles and purchases loading
      var purchasesBtn = document.getElementById('purchases-btn');
      var purchasesList = document.getElementById('purchases-list');
      var purchasesView = document.getElementById('purchases-view');
      var mainView = document.getElementById('main-view');
      var profileView = document.getElementById('profile-view');
      var profileToggle = document.getElementById('profile-toggle');
      var profileBack = document.getElementById('profile-back');
      var purchasesBack = document.getElementById('purchases-back');
      
      // Profile form submission
      var profileForm = document.getElementById('profile-form');
      if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
          e.preventDefault();
          var formData = new FormData(profileForm);
          
          fetch('update_profile.php', {
            method: 'POST',
            body: formData
          })
          .then(function(res) { return res.json(); })
          .then(function(data) {
            if (data.status === 'success') {
              var creditElement = document.getElementById('credit');
              if (creditElement) {
                creditElement.textContent = 'اعتبار شما: ' + Math.floor(data.credit * 5000).toLocaleString() + ' تومان';
              }
              showMain();
              alert('اطلاعات با موفقیت ذخیره شد.');
            } else {
              alert(data.message || 'خطا در بروزرسانی اطلاعات.');
            }
          })
          .catch(function() {
            alert('خطا در برقراری ارتباط با سرور.');
          });
        });
      }

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
          fetch('get_purchases.php', {
            method: 'POST',
            credentials: 'same-origin'
          })
          .then(function(r){ 
            if (!r.ok) {
              throw new Error('Server responded with status: ' + r.status);
            }
            return r.text(); 
          })
          .then(function(t){
            try { 
              var j = JSON.parse(t); 
            } catch(e) { 
              console.error('JSON parse error:', e, 'Response:', t);
              purchasesList.innerText = 'خطا در دریافت اطلاعات: داده‌های نامعتبر'; 
              return; 
            }
            if (j.status !== 'success') { purchasesList.innerText = 'خطا: ' + (j.message || 'نامشخص'); return; }
            if (!j.transactions || !j.transactions.length) {
              purchasesList.innerHTML = `
                <div style="text-align:center;padding:20px;color:#b0b3b8;">
                  <div style="margin-bottom:10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#b0b3b8" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                      <line x1="16" y1="2" x2="16" y2="6"></line>
                      <line x1="8" y1="2" x2="8" y2="6"></line>
                      <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                  </div>
                  <div>تراکنشی یافت نشد.</div>
                  <div style="font-size:0.8em;margin-top:5px;">پس از انجام تراکنش، سوابق در این قسمت نمایش داده خواهد شد.</div>
                </div>
              `;
              return;
            }
            
            // Function to convert Gregorian to Jalali date
            function formatToJalali(dateStr) {
              try {
                var parts = dateStr.split('T')[0].split('-');
                var gy = parseInt(parts[0]);
                var gm = parseInt(parts[1]);
                var gd = parseInt(parts[2]);
                
                // Convert using jalaali.js
                var jalaliDate = Jalaali.toJalali(gy, gm, gd);
                
                // Format with leading zeros
                var jm = jalaliDate.jm < 10 ? '0' + jalaliDate.jm : jalaliDate.jm;
                var jd = jalaliDate.jd < 10 ? '0' + jalaliDate.jd : jalaliDate.jd;
                
                return jalaliDate.jy + '/' + jm + '/' + jd;
              } catch(e) {
                console.error('Date conversion error:', e);
                return dateStr;
              }
            }
            
            // Group transactions by month/year for better organization
            var months = {};
            j.transactions.forEach(function(t) {
              var date = t.date || '';
              var dateObj = new Date(date);
              var monthYear = dateObj.getFullYear() + '-' + (dateObj.getMonth() + 1);
              
              if (!months[monthYear]) {
                var jalaliDate = Jalaali.toJalali(dateObj.getFullYear(), dateObj.getMonth() + 1, 1);
                // Get month name in Persian
                var persianMonths = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                var monthName = persianMonths[jalaliDate.jm - 1] + ' ' + jalaliDate.jy;
                
                months[monthYear] = {
                  title: monthName,
                  transactions: []
                };
              }
              
              months[monthYear].transactions.push(t);
            });
            
            var html = '<div style="list-style:none;padding:0;margin:0">';
            
            // Get month/years and sort (newest first)
            var sortedMonths = Object.keys(months).sort(function(a, b) {
              return b.localeCompare(a);
            });
            
            sortedMonths.forEach(function(monthKey) {
              var monthData = months[monthKey];
              
              // Add month header
              html += '<div style="background:#1a1c1e;padding:5px 10px;border-radius:4px;margin-bottom:5px;font-weight:bold;">' + 
                      monthData.title + '</div>';
              
              // Add transactions for this month
              html += '<ul style="list-style:none;padding:0;margin:0 0 15px 0;background:#0f1112;border-radius:5px;overflow:hidden;">';
              
              monthData.transactions.forEach(function(t){
                var date = t.date || '';
                var dateObj = new Date(date);
                var jalaliDate = formatToJalali(date);
                var amt = parseInt(t.amount || 0);
                var isNegative = amt < 0;
                var transactionType = t.type === 'usage' ? 'استفاده اعتبار' : 'افزایش اعتبار';
                
                // Extract just the day part from the jalali date
                var dayParts = jalaliDate.split('/');
                var dayOnly = dayParts.length === 3 ? dayParts[2] : '';
                
                html += '<li style="padding:12px;border-bottom:1px solid #222;display:flex;justify-content:space-between;align-items:center;' + 
                       (isNegative ? 'background:rgba(255,82,82,0.05);' : 'background:rgba(76,175,80,0.05);') + '">';
                
                // Left side: date and transaction type
                html += '<div style="display:flex;align-items:center;">';
                
                // Day circle
                html += '<div style="width:36px;height:36px;border-radius:50%;background:' + (isNegative ? '#331111' : '#0F2213') + 
                       ';color:' + (isNegative ? '#ff5252' : '#4caf50') + 
                       ';display:flex;align-items:center;justify-content:center;margin-left:10px;font-weight:bold;">' + dayOnly + '</div>';
                
                html += '<div>';
                html += '<div style="font-weight:500;">' + (isNegative ? 'کسر از حساب' : 'افزایش اعتبار') + '</div>';
                html += '<div style="font-size:0.8em;color:#b0b3b8;margin-top:2px;">' + jalaliDate + '</div>';
                html += '</div>';
                html += '</div>';
                
                // Right side: amount with styling based on positive/negative
                if (isNegative) {
                  var absAmt = Math.abs(amt);
                  html += '<strong style="color:#ff5252;display:flex;align-items:center;direction:ltr;">';
                  html += '<span style="margin-left:3px;">-</span>'; 
                  html += absAmt.toLocaleString() + ' تومان';
                  
                  // Add refund indicator if applicable
                  if (t.is_refund) {
                    html += '<span style="font-size:0.8em;margin-right:5px;color:#ff9800;"> (مرجوع)</span>';
                  }
                  
                  html += '</strong>';
                } else {
                  html += '<strong style="color:#4caf50;display:flex;align-items:center;direction:ltr;">';
                  html += '<span style="margin-left:3px;">+</span>';
                  html += Math.floor(amt).toLocaleString() + ' تومان';
                  html += '</strong>';
                }
                
                html += '</li>';
              });
              
              html += '</ul>';
            });
            
            html += '</div>';
            purchasesList.innerHTML = html;
          }).catch(function(error){ 
            console.error('Fetch error:', error);
            purchasesList.innerHTML = `
              <div style="text-align:center;padding:20px;color:#ff5252;">
                <div style="margin-bottom:10px;">
                  <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ff5252" stroke-width="1" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                  </svg>
                </div>
                <div>خطا در برقراری ارتباط با سرور</div>
                <div style="font-size:0.8em;margin-top:5px;">لطفا مجدداً تلاش کنید یا با پشتیبانی تماس بگیرید.</div>
              </div>
            `;
          });
        }
      });
      if (purchasesBack) purchasesBack.addEventListener('click', showMain);
      // initialize state: main view
      showMain();
    });
  </script>
</body>
</html>
