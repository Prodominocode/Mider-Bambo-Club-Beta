// Persian/Farsi digit normalization function for JavaScript
function normDigits(str) {
  if (!str) return str;
  
  const persianNumbers = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const arabicNumbers = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
  const englishNumbers = ['0','1','2','3','4','5','6','7','8','9'];
  
  // Convert Persian digits
  for (let i = 0; i < 10; i++) {
    str = str.replace(new RegExp(persianNumbers[i], 'g'), englishNumbers[i]);
  }
  
  // Convert Arabic digits
  for (let i = 0; i < 10; i++) {
    str = str.replace(new RegExp(arabicNumbers[i], 'g'), englishNumbers[i]);
  }
  
  // Remove any spaces
  str = str.replace(/\s+/g, '');
  
  return str;
}

// Auto-normalize mobile number fields on input
function setupMobileNormalization() {
  // Select all mobile input fields (various IDs used across the system)
  const mobileInputSelectors = [
    '#mobile',           // Main form
    '#mobile_number',    // Mobile inquiry
    '#login_mobile',     // Admin login
    '#sub_mobile',       // Admin add subscriber
    '#inquiry_mobile',   // Admin inquiry
    '#gift_credit_mobile', // Gift credits
    '#vcard_mobile',     // VCard mobile
    'input[name="mobile"]',
    'input[name="mobile_number"]'
  ];
  
  mobileInputSelectors.forEach(selector => {
    const elements = document.querySelectorAll(selector);
    elements.forEach(element => {
      if (element) {
        element.addEventListener('input', function(e) {
          const normalizedValue = normDigits(e.target.value);
          if (normalizedValue !== e.target.value) {
            e.target.value = normalizedValue;
          }
        });
        
        // Also normalize on blur to catch paste events
        element.addEventListener('blur', function(e) {
          e.target.value = normDigits(e.target.value);
        });
      }
    });
  });
  
  // Watch for dynamically added mobile input fields
  const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
      mutation.addedNodes.forEach(function(node) {
        if (node.nodeType === 1) { // Element node
          // Check if the added node contains mobile input fields
          const mobileInputs = node.querySelectorAll ? 
            node.querySelectorAll('input[id*="mobile"], input[name*="mobile"]') : [];
          
          mobileInputs.forEach(input => {
            input.addEventListener('input', function(e) {
              const normalizedValue = normDigits(e.target.value);
              if (normalizedValue !== e.target.value) {
                e.target.value = normalizedValue;
              }
            });
            
            input.addEventListener('blur', function(e) {
              e.target.value = normDigits(e.target.value);
            });
          });
        }
      });
    });
  });
  
  // Start observing
  observer.observe(document.body, { childList: true, subtree: true });
}

document.addEventListener('DOMContentLoaded', function() {
  // Initialize mobile number normalization
  setupMobileNormalization();
  
  // Newsletter form submit
  const newsletterForm = document.getElementById('newsletter-form');
  if (newsletterForm) {
    newsletterForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const mobile = normDigits(document.getElementById('mobile').value.trim());
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
      const mobile = normDigits(document.getElementById('verify_mobile').value);
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

  // Profile box click - Removed as this is now handled in dashboard.php directly

  // Profile update form submit
  document.body.addEventListener('submit', function(e) {
    if (e.target && e.target.id === 'profile-form') {
      e.preventDefault();
      const form = e.target;
      const formData = new FormData(form);
      fetch('update_profile.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.text())
      .then(text => {
        try {
          const data = JSON.parse(text);
          if (data.status === 'success') {
            document.getElementById('credit').textContent = 'اعتبار شما: ' + (data.credit * 5000).toLocaleString() + ' تومان';
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
