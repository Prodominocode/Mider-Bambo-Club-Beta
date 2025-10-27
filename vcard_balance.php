<?php
// vcard_balance.php: Public page for checking virtual card balance
header('Content-Type: text/html; charset=utf-8');
require_once 'vcard_utils.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if ($_POST['action'] === 'check_vcard_balance') {
        $vcard_number = isset($_POST['vcard_number']) ? trim($_POST['vcard_number']) : '';
        
        if (empty($vcard_number)) {
            echo json_encode(['success' => false, 'message' => 'شماره کارت الزامی است']);
            exit;
        }
        
        $result = get_vcard_balance_info($vcard_number);
        echo json_encode($result);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>موجودی کارت مجازی - باشگاه مشتریان MIDER</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    .vcard-container {
      max-width: 500px;
      margin: 50px auto;
      padding: 30px;
      background: rgba(0, 0, 0, 0.8);
      border-radius: 12px;
      border: 1px solid rgba(156, 39, 176, 0.3);
      box-shadow: 0 4px 20px rgba(156, 39, 176, 0.2);
      position: relative;
      z-index: 10;
    }
    
    .vcard-title {
      text-align: center;
      font-size: 24px;
      color: #e1bee7;
      margin-bottom: 20px;
      font-weight: bold;
    }
    
    .vcard-subtitle {
      text-align: center;
      font-size: 14px;
      color: #888;
      margin-bottom: 30px;
    }
    
    .form-group {
      margin-bottom: 20px;
    }
    
    .form-group label {
      display: block;
      margin-bottom: 8px;
      color: #e1bee7;
      font-weight: bold;
    }
    
    .form-group input {
      width: 100%;
      padding: 12px 16px;
      border: 2px solid #9c27b0;
      border-radius: 8px;
      background: #0d0d0d;
      color: #fff;
      font-size: 16px;
      font-family: monospace;
      text-align: center;
      letter-spacing: 2px;
      box-sizing: border-box;
    }
    
    .form-group input:focus {
      outline: none;
      border-color: #e1bee7;
      box-shadow: 0 0 10px rgba(156, 39, 176, 0.3);
    }
    
    .btn-vcard {
      width: 100%;
      padding: 12px;
      background: linear-gradient(45deg, #9c27b0, #e1bee7);
      color: white;
      border: none;
      border-radius: 8px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .btn-vcard:hover {
      background: linear-gradient(45deg, #8e24aa, #d1c4e9);
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(156, 39, 176, 0.4);
    }
    
    .btn-vcard:disabled {
      background: #444;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
    }
    
    .message {
      margin: 15px 0;
      padding: 12px;
      border-radius: 6px;
      text-align: center;
      font-weight: bold;
    }
    
    .message.error {
      background: rgba(244, 67, 54, 0.2);
      color: #ff5252;
      border: 1px solid rgba(244, 67, 54, 0.3);
    }
    
    .message.success {
      background: rgba(76, 175, 80, 0.2);
      color: #4caf50;
      border: 1px solid rgba(76, 175, 80, 0.3);
    }
    
    .balance-info {
      background: rgba(156, 39, 176, 0.1);
      border: 1px solid rgba(156, 39, 176, 0.3);
      border-radius: 8px;
      padding: 20px;
      margin-top: 20px;
    }
    
    .balance-card {
      background: linear-gradient(45deg, #9c27b0, #e1bee7);
      color: white;
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      margin-bottom: 20px;
      box-shadow: 0 4px 15px rgba(156, 39, 176, 0.3);
    }
    
    .balance-amount {
      font-size: 28px;
      font-weight: bold;
      margin-bottom: 8px;
    }
    
    .balance-label {
      font-size: 14px;
      opacity: 0.9;
    }
    
    .history-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 15px;
      background: rgba(0, 0, 0, 0.3);
      border-radius: 8px;
      overflow: hidden;
    }
    
    .history-table th {
      background: rgba(156, 39, 176, 0.2);
      color: #e1bee7;
      padding: 12px 8px;
      text-align: center;
      font-weight: bold;
      border-bottom: 1px solid rgba(156, 39, 176, 0.3);
    }
    
    .history-table td {
      padding: 10px 8px;
      text-align: center;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      color: #ccc;
    }
    
    .history-table tr:last-child td {
      border-bottom: none;
    }
    
    .back-link {
      display: block;
      text-align: center;
      margin-top: 20px;
      color: #888;
      text-decoration: none;
      font-size: 14px;
    }
    
    .back-link:hover {
      color: #e1bee7;
    }
    
    .card-info {
      background: rgba(0, 0, 0, 0.3);
      padding: 15px;
      border-radius: 8px;
      margin-bottom: 15px;
    }
    
    .card-info h4 {
      color: #e1bee7;
      margin: 0 0 10px 0;
      font-size: 16px;
    }
    
    .card-detail {
      display: flex;
      justify-content: space-between;
      margin-bottom: 8px;
      color: #ccc;
    }
    
    .card-detail:last-child {
      margin-bottom: 0;
    }
    
    .loading {
      text-align: center;
      color: #e1bee7;
      font-style: italic;
    }
  </style>
</head>
<body>
  <div class="overlay"></div>
  <div class="vcard-container">
    <div class="vcard-title">💳 موجودی کارت مجازی</div>
    <div class="vcard-subtitle">شماره کارت 16 رقمی خود را وارد کنید</div>
    
    <form id="vcard-form">
      <div class="form-group">
        <label for="vcard_number">شماره کارت مجازی</label>
        <input type="text" id="vcard_number" name="vcard_number" placeholder="1234567890123456" maxlength="16" required>
      </div>
      
      <button type="submit" id="check-btn" class="btn-vcard">بررسی موجودی</button>
      
      <div id="message" class="message" style="display:none;"></div>
    </form>
    
    <div id="balance-result" style="display:none;">
      <div class="balance-card">
        <div class="balance-amount" id="balance-amount">0 تومان</div>
        <div class="balance-label">موجودی کارت مجازی</div>
      </div>
      
      <div class="card-info">
        <h4>اطلاعات کارت</h4>
        <div class="card-detail">
          <span>شماره کارت:</span>
          <span id="card-number">-</span>
        </div>
        <div class="card-detail">
          <span>نام صاحب کارت:</span>
          <span id="card-holder">-</span>
        </div>
        <div class="card-detail">
          <span>امتیاز:</span>
          <span id="card-points">-</span>
        </div>
        <div class="card-detail">
          <span>تاریخ ایجاد:</span>
          <span id="card-created">-</span>
        </div>
      </div>
      
      <div id="transaction-history" style="display:none;">
        <h4 style="color:#e1bee7;margin-bottom:10px;">تاریخچه تراکنش‌ها</h4>
        <table class="history-table">
          <thead>
            <tr>
              <th>تاریخ</th>
              <th>ساعت</th>
              <th>نوع</th>
              <th>مبلغ</th>
              <th>امتیاز</th>
            </tr>
          </thead>
          <tbody id="history-tbody">
          </tbody>
        </table>
      </div>
      
      <div id="no-history" style="display:none;">
        <div style="text-align:center;color:#888;margin-top:15px;padding:20px;background:rgba(0,0,0,0.2);border-radius:8px;">
          هیچ تراکنشی برای این کارت ثبت نشده است
        </div>
      </div>
    </div>
    
    <a href="index.php" class="back-link">← بازگشت به صفحه ورود</a>
  </div>

  <script>
    // Format number with dots
    function formatWithDots(num) {
      return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    
    // Format Persian date
    function formatPersianDate(dateString) {
      const date = new Date(dateString);
      return new Intl.DateTimeFormat('fa-IR', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'
      }).format(date);
    }
    
    // Format Persian time
    function formatPersianTime(dateString) {
      const date = new Date(dateString);
      return new Intl.DateTimeFormat('fa-IR', {
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    }
    
    // Show message
    function showMessage(text, type = 'error') {
      const messageEl = document.getElementById('message');
      messageEl.textContent = text;
      messageEl.className = `message ${type}`;
      messageEl.style.display = 'block';
    }
    
    // Hide message
    function hideMessage() {
      document.getElementById('message').style.display = 'none';
    }
    
    // Validate card number input
    document.getElementById('vcard_number').addEventListener('input', function(e) {
      // Only allow digits
      this.value = this.value.replace(/\D/g, '');
      
      // Limit to 16 digits
      if (this.value.length > 16) {
        this.value = this.value.slice(0, 16);
      }
      
      hideMessage();
    });
    
    // Handle form submission
    document.getElementById('vcard-form').addEventListener('submit', function(e) {
      e.preventDefault();
      
      const vcardNumber = document.getElementById('vcard_number').value.trim();
      const checkBtn = document.getElementById('check-btn');
      const balanceResult = document.getElementById('balance-result');
      
      hideMessage();
      balanceResult.style.display = 'none';
      
      // Validate input
      if (!vcardNumber) {
        showMessage('لطفاً شماره کارت را وارد کنید');
        return;
      }
      
      if (vcardNumber.length !== 16) {
        showMessage('شماره کارت باید دقیقاً 16 رقم باشد');
        return;
      }
      
      // Show loading state
      checkBtn.disabled = true;
      checkBtn.textContent = 'در حال بررسی...';
      
      // Send request
      const formData = new FormData();
      formData.append('action', 'check_vcard_balance');
      formData.append('vcard_number', vcardNumber);
      
      fetch('vcard_balance.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          displayBalanceInfo(data);
        } else {
          showMessage(data.message || 'کارت مجازی یافت نشد');
        }
      })
      .catch(error => {
        console.error('Error:', error);
        showMessage('خطا در اتصال به سرور. لطفاً مجدداً تلاش کنید.');
      })
      .finally(() => {
        checkBtn.disabled = false;
        checkBtn.textContent = 'بررسی موجودی';
      });
    });
    
    // Display balance information
    function displayBalanceInfo(data) {
      const balanceResult = document.getElementById('balance-result');
      const balanceAmount = document.getElementById('balance-amount');
      const cardNumber = document.getElementById('card-number');
      const cardHolder = document.getElementById('card-holder');
      const cardPoints = document.getElementById('card-points');
      const cardCreated = document.getElementById('card-created');
      const transactionHistory = document.getElementById('transaction-history');
      const noHistory = document.getElementById('no-history');
      const historyTbody = document.getElementById('history-tbody');
      
      // Display balance
      balanceAmount.textContent = formatWithDots(data.credit_toman) + ' تومان';
      
      // Display card info
      cardNumber.textContent = data.user.vcard_number;
      cardHolder.textContent = data.user.full_name || 'نامشخص';
      cardPoints.textContent = data.credit + ' امتیاز';
      cardCreated.textContent = formatPersianDate(data.user.created_at);
      
      // Display transaction history
      if (data.history && data.history.length > 0) {
        let historyHtml = '';
        data.history.forEach(transaction => {
          const isRefund = transaction.is_refund == 1;
          const amountDisplay = isRefund ? '-' + formatWithDots(transaction.amount) : formatWithDots(transaction.amount);
          const typeDisplay = isRefund ? 'برگشت اعتبار' : 'استفاده از اعتبار';
          
          historyHtml += `
            <tr>
              <td>${formatPersianDate(transaction.datetime)}</td>
              <td>${formatPersianTime(transaction.datetime)}</td>
              <td>${typeDisplay}</td>
              <td>${amountDisplay} تومان</td>
              <td>${transaction.credit_value} امتیاز</td>
            </tr>
          `;
        });
        
        historyTbody.innerHTML = historyHtml;
        transactionHistory.style.display = 'block';
        noHistory.style.display = 'none';
      } else {
        transactionHistory.style.display = 'none';
        noHistory.style.display = 'block';
      }
      
      balanceResult.style.display = 'block';
      showMessage('اطلاعات کارت با موفقیت بارگذاری شد', 'success');
    }
  </script>
</body>
</html>