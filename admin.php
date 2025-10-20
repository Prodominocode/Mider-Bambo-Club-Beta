<?php
// Combined admin login + panel with DB-backed OTP, ADMIN_ALLOWED check, long-lived session
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'config.php';
require_once 'db.php';

function norm_digits($s){
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $latin =   ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    $s = str_replace($persian, $latin, $s);
    $s = preg_replace('/\s+/', '', $s);
    return $s;
}

function send_kavenegar_sms($receptor, $message){
    $api_key = KAVENEGAR_API_KEY;
    $url = "https://api.kavenegar.com/v1/$api_key/sms/send.json";
    $postfields = http_build_query(['receptor'=>$receptor,'sender'=>KAVENEGAR_SENDER,'message'=>$message]);
    if (function_exists('curl_init')){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return ['ok'=>false,'error'=>$err];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    } else {
        $opts = ['http'=>['method'=>'POST','header'=>'Content-type: application/x-www-form-urlencoded','content'=>$postfields,'timeout'=>10]];
        $context = stream_context_create($opts);
        $resp = @file_get_contents($url,false,$context);
        if ($resp === false) return ['ok'=>false,'error'=>'file_get_contents_failed'];
        return ['ok'=>true,'response'=>json_decode($resp,true)];
    }
}

// Helper to check allowed admin numbers
function is_admin_allowed($mobile){
    $m = norm_digits($mobile);
    $allowed = @unserialize(ADMIN_ALLOWED);
    if (!is_array($allowed)) return false;
    foreach($allowed as $a){ if (norm_digits($a) === $m) return true; }
    return false;
}

// Actions: send_otp, verify_otp, logout, add_subscriber
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'send_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        if (!is_admin_allowed($mobile)) { echo json_encode(['status'=>'error','message'=>'not_allowed']); exit; }
        // generate OTP and write to subscribers table (insert or update)
        $otp = str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
        try {
            $ch = $pdo->prepare('SELECT id FROM subscribers WHERE mobile=? LIMIT 1'); $ch->execute([$mobile]); $exists = $ch->fetchColumn();
            if ($exists){ $u = $pdo->prepare('UPDATE subscribers SET otp_code=?, verified=0, created_at=NOW() WHERE mobile=?'); $u->execute([$otp,$mobile]); }
            else { $u = $pdo->prepare('INSERT INTO subscribers (mobile, otp_code, verified, credit, created_at) VALUES (?, ?, 0, 10, NOW())'); $u->execute([$mobile,$otp]); }
        } catch (Throwable $e){ error_log('admin send_otp db: '.$e->getMessage()); }
        // send SMS
        $message = "کد تایید ورود مدیریت: $otp";
        $sms = send_kavenegar_sms($mobile,$message);
        if (!$sms['ok']) { error_log('admin send_otp sms: '.json_encode($sms)); echo json_encode(['status'=>'error','message'=>'sms_failed']); exit; }
        // store mobile in session temporary ui marker
        $_SESSION['admin_otp_ui_mobile'] = $mobile; $_SESSION['admin_otp_ui_expires'] = time()+300;
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'verify_otp'){
        $mobile = isset($_POST['mobile']) ? norm_digits($_POST['mobile']) : '';
        $code = isset($_POST['otp']) ? trim($_POST['otp']) : '';
        if (!$mobile || !$code) { echo json_encode(['status'=>'error','message'=>'missing']); exit; }
        try {
            $stmt = $pdo->prepare('SELECT * FROM subscribers WHERE mobile=? AND otp_code=? LIMIT 1'); $stmt->execute([$mobile,$code]); $row = $stmt->fetch();
            if (!$row) { echo json_encode(['status'=>'error','message'=>'invalid_code']); exit; }
            // clear otp_code
            $u2 = $pdo->prepare('UPDATE subscribers SET otp_code=NULL, verified=1 WHERE id=?'); $u2->execute([$row['id']]);
            if (function_exists('session_regenerate_id')) session_regenerate_id(true);
            $_SESSION['admin_mobile'] = $mobile; $_SESSION['is_admin']=true;
            // persistent cookie (10 years)
            $cookieParams = session_get_cookie_params(); $lifetime = 10*365*24*60*60;
            setcookie(session_name(), session_id(), time()+$lifetime, $cookieParams['path']?:'/', $cookieParams['domain']?:'', $cookieParams['secure']??false, $cookieParams['httponly']??true);
            echo json_encode(['status'=>'success']); exit;
        } catch (Throwable $e){ error_log('admin verify otp: '.$e->getMessage()); echo json_encode(['status'=>'error','message'=>'server_error']); exit; }
    } elseif ($action === 'logout'){
        // logout
        $_SESSION = [];
        if (ini_get('session.use_cookies')){
            $params = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        echo json_encode(['status'=>'success']); exit;
    } elseif ($action === 'add_subscriber'){
        // Admin only
        if (empty($_SESSION['admin_mobile'])) { echo json_encode(['status'=>'error','message'=>'not_logged_in']); exit; }
        $admin = $_SESSION['admin_mobile'];
        $mobile = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
        $amount = isset($_POST['amount']) ? trim($_POST['amount']) : '';
        if (!$mobile) { echo json_encode(['status'=>'error','message'=>'mobile_required']); exit; }
        try {
            // check existing
            $ch = $pdo->prepare('SELECT id FROM subscribers WHERE mobile = ? LIMIT 1'); $ch->execute([$mobile]); $existingId = $ch->fetchColumn();
            if ($existingId){
                if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,created_at) VALUES (?, ?, ?, ?, NOW())'); $ins->execute([$existingId,$mobile,$amount,$admin]);
                    $creditToAdd = (int) round(((float)$amount)/100000.0);
                    if ($creditToAdd>0){ $upd = $pdo->prepare('UPDATE subscribers SET credit = credit + ? WHERE id = ?'); $upd->execute([$creditToAdd,$existingId]); }
                    $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); $sel->execute([$existingId]); $newCredit = (int)$sel->fetchColumn();
                    $pdo->commit();
                    $message = "از خرید شما در پوشاک میدر متشکریم.\nامتیاز کسب‌ شده از خرید در باشگاه مشتریان: $creditToAdd\nmiderclub.ir";
                    $sms = send_kavenegar_sms($mobile,$message);
                    echo json_encode(['status'=>'success','note'=>'existing_user_with_amount','new_credit'=>$newCredit,'sms'=>$sms]); exit;
                }
                echo json_encode(['status'=>'success','note'=>'existing_user','admin_message'=>'Subscriber is already a member.']); exit;
            }
            // create new subscriber
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO subscribers (mobile, verified, credit, created_at, admin_number) VALUES (?, 0, 10, NOW(), ?)'); $stmt->execute([$mobile,$admin]); $id = $pdo->lastInsertId();
            $newCredit = null;
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                $ins = $pdo->prepare('INSERT INTO purchases (subscriber_id,mobile,amount,admin_number,created_at) VALUES (?, ?, ?, ?, NOW())'); $ins->execute([$id,$mobile,$amount,$admin]);
                $creditToAdd = (int) round(((float)$amount)/100000.0);
                if ($creditToAdd>0){ $upd = $pdo->prepare('UPDATE subscribers SET credit = credit + ? WHERE id = ?'); $upd->execute([$creditToAdd,$id]); }
                $sel = $pdo->prepare('SELECT credit FROM subscribers WHERE id = ? LIMIT 1'); $sel->execute([$id]); $newCredit = (int)$sel->fetchColumn();
            }
            $pdo->commit();
            // send SMS
            if ($amount !== '' && preg_match('/^\d+$/',$amount)){
                $message = "به باشگاه مشتریان پوشاک آقایان و بانوان میدر خوش آمدید.\nامتیاز شما از خرید در باشگاه مشتریان : $creditToAdd\nmiderclub.ir";
            } else {
                $message = "به باشگاه مشتریان پوشاک آقایان و بانوان میدر خوش آمدید.\nmiderclub.ir";
            }
            $sms = send_kavenegar_sms($mobile,$message);
            echo json_encode(['status'=>'success','note'=>'new_user','id'=>$id,'new_credit'=>$newCredit,'sms'=>$sms]); exit;
        } catch (Throwable $e){ try{ if ($pdo->inTransaction()) $pdo->rollBack(); }catch(Throwable $_){} echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit; }
    }
}

// If not POST or after actions, render page
$is_admin = !empty($_SESSION['admin_mobile']);
$admin_mobile = $is_admin ? $_SESSION['admin_mobile'] : '';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>پنل مدیریت</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    body{font-family:Tahoma,Arial;background:#111;color:#fff}
    .centered-container{max-width:720px;margin:40px auto}
    .box{background:rgba(0,0,0,0.6);padding:20px;border-radius:10px}
    .btn{padding:8px 12px;border-radius:6px;border:0;cursor:pointer}
    .btn-primary{background:#4caf50;color:#fff}
    .btn-ghost{background:transparent;color:#cfcfcf;border:1px solid rgba(255,255,255,0.04)}
    .small{font-size:14px}
    input[type=text]{padding:8px;border-radius:6px;border:1px solid #333;background:#0d0d0d;color:#fff;width:100%}
    .msg{margin-top:8px}
  </style>
</head>
<body>
  <div class="centered-container">
    <div class="box">
<?php if (!$is_admin): ?>
      <h2>ورود مدیریت</h2>
      <div id="login-area">
        <div id="login-form">
          <label>شماره موبایل</label>
          <input type="text" id="login_mobile" placeholder="شماره موبایل">
          <div style="margin-top:8px"><button id="send_otp" class="btn btn-primary">ارسال کد</button></div>
          <div id="login_msg" class="msg"></div>
        </div>
        <div id="otp-form" style="display:none;margin-top:12px">
          <label>کد تایید</label>
          <input type="text" id="otp_code" placeholder="کد ۵ رقمی">
          <div style="margin-top:8px"><button id="verify_otp" class="btn btn-primary">تایید و ورود</button></div>
          <div id="otp_msg" class="msg"></div>
        </div>
      </div>
<?php else: ?>
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <h2>پنل مدیریت</h2>
          <div class="small">ادمین: <?php echo htmlspecialchars($admin_mobile); ?></div>
        </div>
        <div>
          <button id="logout" class="btn btn-ghost">خروج</button>
        </div>
      </div>
      <hr>
      <div>
        <h3>افزودن مشترک / ثبت خرید</h3>
        <div>
          <label>موبایل (الزامی)</label>
          <input type="text" id="sub_mobile">
        </div>
        <div style="margin-top:8px">
          <label>مبلغ خرید (تومان، اختیاری)</label>
          <input type="text" id="sub_amount" placeholder="مثال: 250.000" inputmode="numeric">
        </div>
        <div style="margin-top:12px;display:flex;gap:8px">
          <button id="add_submit" class="btn btn-primary">ارسال</button>
        </div>
        <div id="add_msg" class="msg"></div>
      </div>
<?php endif; ?>
    </div>
  </div>
  <script>
    function postJSON(body){ return fetch('admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)}).then(r=>r.json()); }
    <?php if (!$is_admin): ?>
    document.getElementById('send_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); var msg = document.getElementById('login_msg'); msg.textContent='';
      if (!m) { msg.textContent='لطفاً شماره موبایل را وارد کنید.'; return; }
      postJSON({action:'send_otp',mobile:m}).then(json=>{ if (json.status==='success'){ document.getElementById('login-form').style.display='none'; document.getElementById('otp-form').style.display='block'; } else { msg.textContent = json.message || 'خطا'; } }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    document.getElementById('verify_otp').addEventListener('click', function(){
      var m = document.getElementById('login_mobile').value.trim(); var code = document.getElementById('otp_code').value.trim(); var msg = document.getElementById('otp_msg'); msg.textContent='';
      if (!m || !code) { msg.textContent='لطفاً شماره و کد را وارد کنید.'; return; }
      postJSON({action:'verify_otp',mobile:m,otp:code}).then(json=>{ if (json.status==='success'){ location.reload(); } else { msg.textContent = json.message || 'کد نامعتبر'; } }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    <?php else: ?>
    document.getElementById('logout').addEventListener('click', function(){ postJSON({action:'logout'}).then(()=>location.reload()); });
    document.getElementById('add_submit').addEventListener('click', function(){
      var mobile = document.getElementById('sub_mobile').value.trim(); var amount = document.getElementById('sub_amount').value.trim(); var msg = document.getElementById('add_msg'); msg.textContent='';
      if (!mobile) { msg.textContent='لطفاً موبایل را وارد کنید.'; return; }
      postJSON({action:'add_subscriber',mobile:mobile,amount:amount}).then(json=>{
        if (json.status==='success'){
          if (json.note==='existing_user') msg.textContent = json.admin_message || 'Subscriber is already a member.';
          else msg.textContent = 'عملیات با موفقیت انجام شد.';
          document.getElementById('sub_mobile').value=''; document.getElementById('sub_amount').value='';
        } else {
          msg.textContent = json.message || 'خطا';
        }
      }).catch(()=>{ msg.textContent='خطا در اتصال'; });
    });
    <?php endif; ?>
  </script>
</body>
</html>
<?php
session_start();
// Simple admin page to add multiple users
if (empty($_SESSION['admin_mobile'])) {
  header('Location: admin_login.php');
  exit;
}
$admin_mobile = $_SESSION['admin_mobile'];
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>افزودن چند کاربر - مدیریت</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .admin-box{ max-width:720px; margin:40px auto; padding:20px; background:rgba(34,36,38,0.96); border-radius:12px; color:#fff }
    .row{ display:flex; gap:8px; margin-bottom:8px }
    .row input{ flex:1 }
    .small{ width:48px }
    .controls{ display:flex; gap:8px; margin-top:12px }
    .msg{ margin-top:12px }
    .btn-back{ padding:6px 10px; font-size:14px; background:transparent; border:0; color:#cfcfcf; cursor:pointer; border-radius:6px }
    .btn-back:hover{ background:rgba(255,255,255,0.02) }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="centered-container">
    <div class="admin-box" id="menu-box">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="text-align:right; margin:0">داشبورد مدیریت</h2>
        <div style="color:#cfcfcf; font-size:14px">کاربر ادمین: <?php echo htmlspecialchars($admin_mobile); ?> — <a href="admin_logout.php" style="color:#ff8a65">خروج</a></div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <button id="btn-add">افزودن مشترک جدید</button>
      </div>
    </div>

    <div class="admin-box" id="add-box" style="display:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="text-align:right; margin:0">افزودن مشترک جدید</h2>
  <div><button id="back-from-add" type="button" class="btn-back">بازگشت</button></div>
      </div>
      <form id="form-add" method="post" style="display:flex;flex-direction:column;gap:8px">
        <input type="text" id="add_mobile" name="mobile" placeholder="موبایل (الزامی)" required>
        <!-- Optional initial purchase amount (visible formatted and hidden raw) -->
        <input type="text" id="add_amount_fmt" placeholder="مبلغ خرید اولیه (تومان)" inputmode="numeric">
        <input type="hidden" id="add_amount" name="amount">
        <div style="display:flex;gap:8px;align-items:center">
          <button id="add_save" type="submit" style="background:#4caf50;">ذخیره و ارسال پیام</button>
          <div id="add_result" class="msg" style="margin:0"></div>
        </div>
      </form>
    </div>

    <div class="admin-box" id="purchase-box" style="display:none;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="text-align:right; margin:0">ثبت خرید</h2>
  <div><button id="back-from-purchase" type="button" class="btn-back">بازگشت</button></div>
      </div>
      <form id="form-purchase" method="post" style="display:flex;flex-direction:column;gap:8px">
        <input type="text" id="purchase_mobile" name="mobile" placeholder="موبایل مشتری" required>
        <!-- Visible formatted amount (with dots) and hidden raw numeric amount submitted -->
        <input type="text" id="purchase_amount_fmt" placeholder="مبلغ خرید" inputmode="numeric" pattern="[0-9\u06F0-\u06F9,\.\s]+" required>
        <input type="hidden" id="purchase_amount" name="amount">
        <div style="display:flex;gap:8px;align-items:center">
          <button id="purchase_save" type="submit" style="background:#4caf50;">ثبت خرید</button>
          <div id="purchase_result" class="msg" style="margin:0"></div>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      const menuBox = document.getElementById('menu-box');
      const addBox = document.getElementById('add-box');
      const purchaseBox = document.getElementById('purchase-box');
  const btnAdd = document.getElementById('btn-add');
  if (btnAdd) btnAdd.addEventListener('click', ()=>{ menuBox.style.display='none'; addBox.style.display='block'; });
  const btnPurchase = document.getElementById('btn-purchase');
  if (btnPurchase) btnPurchase.addEventListener('click', ()=>{ menuBox.style.display='none'; purchaseBox.style.display='block'; });
  const backFromAdd = document.getElementById('back-from-add');
  if (backFromAdd) backFromAdd.addEventListener('click', ()=>{ addBox.style.display='none'; menuBox.style.display='block'; });
  const backFromPurchase = document.getElementById('back-from-purchase');
  if (backFromPurchase) backFromPurchase.addEventListener('click', ()=>{ purchaseBox.style.display='none'; menuBox.style.display='block'; });

      // Add subscriber
      document.getElementById('form-add').addEventListener('submit', function(e){
        e.preventDefault();
        const name = document.getElementById('add_full_name').value.trim();
        const mobile = document.getElementById('add_mobile').value.trim();
        const amount = document.getElementById('add_amount').value.trim();
        const btn = document.getElementById('add_save'); const result = document.getElementById('add_result');
        if (!mobile) { result.innerHTML = '<div style="color:#ff5252">لطفاً موبایل را وارد کنید.</div>'; return; }
        btn.disabled = true; btn.textContent='در حال ارسال...'; result.innerHTML='';
        const form = new FormData(); form.append('full_name', name); form.append('mobile', mobile); if (amount) form.append('amount', amount);
        fetch('admin_add_subscriber.php', { method:'POST', body: form })
        .then(r=>r.json()).then(json=>{
          btn.disabled=false; btn.textContent='ذخیره و ارسال پیام';
          if (json.status==='success') { result.innerHTML='<div style="color:#4caf50">کاربر با موفقیت ثبت شد.</div>'; document.getElementById('form-add').reset(); document.getElementById('add_mobile').focus(); }
          else if (json.message==='not_logged_in') window.location='admin_login.php';
          else result.innerHTML='<div style="color:#ff5252">خطا: '+(json.message||'خطا')+'</div>';
        }).catch(()=>{ btn.disabled=false; btn.textContent='ذخیره و ارسال پیام'; result.innerHTML='<div style="color:#ff5252">خطا در اتصال به سرور</div>'; });
      });

      // Record purchase
      // formatting helper: keep visible formatted field and hidden raw numeric value
      const amtFmtInput = document.getElementById('purchase_amount_fmt');
      const amtRawInput = document.getElementById('purchase_amount');
      function formatWithDots(s){
        if (!s) return '';
        // remove non-digits (including persian digits)
        s = s.replace(/[\u06F0-\u06F9]/g, function(d){ return String('0123456789').charAt(d.charCodeAt(0)-0x06F0); });
        s = s.replace(/[^0-9]/g,'');
        if (s === '') return '';
        return s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
      }
      if (amtFmtInput) {
        amtFmtInput.addEventListener('input', function(){
          var raw = this.value.replace(/[\u06F0-\u06F9]/g, function(d){ return String('0123456789').charAt(d.charCodeAt(0)-0x06F0); }).replace(/[^0-9]/g,'');
          amtRawInput.value = raw;
          var formatted = formatWithDots(raw);
          this.value = formatted;
        });
        // also format on blur/paste
        amtFmtInput.addEventListener('blur', function(){ this.value = formatWithDots(amtRawInput.value); });
      }

      // Add-form amount formatting (initial purchase amount when creating user)
      const addAmtFmt = document.getElementById('add_amount_fmt');
      const addAmtRaw = document.getElementById('add_amount');
      if (addAmtFmt) {
        addAmtFmt.addEventListener('input', function(){
          var raw = this.value.replace(/[\u06F0-\u06F9]/g, function(d){ return String('0123456789').charAt(d.charCodeAt(0)-0x06F0); }).replace(/[^0-9]/g,'');
          addAmtRaw.value = raw;
          var formatted = formatWithDots(raw);
          this.value = formatted;
        });
        addAmtFmt.addEventListener('blur', function(){ this.value = formatWithDots(addAmtRaw.value); });
      }

      document.getElementById('form-purchase').addEventListener('submit', function(e){
        e.preventDefault();
        const mobile = document.getElementById('purchase_mobile').value.trim();
        const amount = document.getElementById('purchase_amount').value.trim();
        const btn = document.getElementById('purchase_save'); const result = document.getElementById('purchase_result');
  if (!mobile || !amount || !/^[0-9]+$/.test(amount)) { result.innerHTML = '<div style="color:#ff5252">لطفاً موبایل و مبلغ صحیح را وارد کنید.</div>'; return; }
        btn.disabled=true; btn.textContent='در حال ارسال...'; result.innerHTML='';
        const form = new FormData(); form.append('mobile', mobile); form.append('amount', amount);
        fetch('admin_record_purchase.php', { method:'POST', body: form })
        .then(r=>r.json()).then(json=>{
          btn.disabled=false; btn.textContent='ثبت خرید';
          if (json.status==='success') { result.innerHTML='<div style="color:#4caf50">خرید ثبت شد.</div>'; document.getElementById('form-purchase').reset(); document.getElementById('purchase_amount_fmt').value=''; document.getElementById('purchase_amount').value=''; document.getElementById('purchase_mobile').focus(); }
          else if (json.message==='not_logged_in') window.location='admin_login.php';
          else if (json.message==='not_member') result.innerHTML='<div style="color:#ff5252">مشتری عضو نیست.</div>';
          else result.innerHTML='<div style="color:#ff5252">خطا: '+(json.message||'خطا')+'</div>';
        }).catch(()=>{ btn.disabled=false; btn.textContent='ثبت خرید'; result.innerHTML='<div style="color:#ff5252">خطا در اتصال به سرور</div>'; });
      });
    })();
  </script>
</body>
</html>
