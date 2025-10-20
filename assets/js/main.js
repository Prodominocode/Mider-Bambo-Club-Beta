document.addEventListener('DOMContentLoaded', function() {
  // Newsletter form submit
  const newsletterForm = document.getElementById('newsletter-form');
  if (newsletterForm) {
    newsletterForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const mobile = document.getElementById('mobile').value.trim();
      if (!mobile) return showError('Please enter your mobile number.');
      fetch('send_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mobile=' + encodeURIComponent(mobile)
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          // Show form; if exists flag true show only code field
          showVerificationForm(mobile, data.exists === true);
        } else {
          showError(data.message || 'Failed to send code.');
        }
      })
      .catch(() => showError('مشکل در شبکه. لطفاً چند لحظه دیگر دوباره تلاش کنید.'));
    });
  }

  // Verification form submit (delegated)
  document.body.addEventListener('submit', function(e) {
    if (e.target && e.target.id === 'verify-form') {
      e.preventDefault();
  const nameField = document.getElementById('full_name');
  const name = nameField ? nameField.value.trim() : '';
  const code = document.getElementById('otp_code').value.trim();
      const mobile = document.getElementById('verify_mobile').value;
  if (!code) return showError('لطفاً کد تایید را وارد کنید.');
      fetch('verify_otp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'mobile=' + encodeURIComponent(mobile) + '&full_name=' + encodeURIComponent(name) + '&otp_code=' + encodeURIComponent(code)
      })
      .then(res => res.json())
      .then(data => {
        if (data.status === 'success') {
          window.location = 'dashboard.php';
        } else {
          showError(data.message || 'Verification failed.');
        }
      })
      .catch(() => showError('مشکل در شبکه. لطفاً چند لحظه دیگر دوباره تلاش کنید.'));
    }
  });

  // Profile box click
  document.body.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('profile-box')) {
      const form = document.getElementById('profile-form');
      if (form) form.classList.toggle('active');
    }
  });

  // Profile update form submit
  document.body.addEventListener('submit', function(e) {
    if (e.target && e.target.id === 'profile-form') {
      e.preventDefault();
      const form = e.target;
      const formData = new FormData(form);
      fetch('update_profile.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: formData
      })
      .then(res => res.text())
      .then(text => {
        try {
          const data = JSON.parse(text);
          if (data.status === 'success') {
            document.getElementById('credit').textContent = 'اعتبار شما: ' + data.credit + ' امتیاز';
            form.classList.remove('active');
          } else {
            alert(data.message || 'بروز خطا در بروزرسانی.');
          }
        } catch (err) {
          console.error('Non-JSON response:', text);
          alert('خطای شبکه یا خوراک نامعتبر از سرور.');
        }
      })
      .catch(() => alert('خطای شبکه.'));
    }
  });
});

function showVerificationForm(mobile, onlyCode = false) {
  const formBox = document.querySelector('.form-box');
  if (!formBox) return;
  if (onlyCode) {
    formBox.innerHTML = `
      <form id="verify-form" class="fade" dir="rtl" lang="fa">
        <div class="form-title">تکمیل عضویت در باشگاه مشتریان</div>
        <input type="hidden" id="verify_mobile" name="mobile" value="${mobile}">
        <input type="text" id="otp_code" name="otp_code" placeholder="کد تاییدیه" required>
        <button type="submit">ورود به باشگاه</button>
        <div id="form-msg"></div>
      </form>
    `;
  } else {
    formBox.innerHTML = `
      <form id="verify-form" class="fade" dir="rtl" lang="fa">
        <div class="form-title">تکمیل عضویت در باشگاه مشتریان</div>
        <input type="hidden" id="verify_mobile" name="mobile" value="${mobile}">
        <input type="text" id="full_name" name="full_name" placeholder="نام و نام خانوادگی" required>
        <input type="text" id="otp_code" name="otp_code" placeholder="کد تاییدیه" required>
        <button type="submit">ورود به باشگاه</button>
        <div id="form-msg"></div>
      </form>
    `;
  }
}

function showError(msg) {
  let msgBox = document.getElementById('form-msg');
  if (!msgBox) {
    msgBox = document.createElement('div');
    msgBox.id = 'form-msg';
    document.querySelector('.form-box').appendChild(msgBox);
  }
  msgBox.className = 'error-msg';
  msgBox.textContent = msg;
}
