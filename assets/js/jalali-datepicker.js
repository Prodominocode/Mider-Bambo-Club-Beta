(function(){
  'use strict';
  // Lightweight Jalali datepicker using Jalaali conversion utility (jalaali.js must be loaded first)

  function pad(n){ return n.toString().padStart(2,'0'); }

  function daysInJMonth(jy, jm){
    // compute days by converting first day of this month and next month to gregorian and diff
    var g1 = Jalaali.toGregorian(jy, jm, 1);
    var nextJ = {jy: jy + (jm === 12 ? 1 : 0), jm: (jm % 12) + 1, jd: 1};
    var g2 = Jalaali.toGregorian(nextJ.jy, nextJ.jm, 1);
    var d1 = new Date(g1.gy, g1.gm - 1, g1.gd);
    var d2 = new Date(g2.gy, g2.gm - 1, g2.gd);
    var diff = Math.round((d2 - d1) / (1000*60*60*24));
    return diff;
  }

  function createPicker(input, hidden){
    var picker = document.createElement('div');
    picker.className = 'jalali-picker-popup';
    picker.style.position = 'absolute';
    picker.style.zIndex = 9999;
    picker.style.minWidth = '260px';
    picker.style.background = '#27292b';
    picker.style.border = '1px solid rgba(255,255,255,0.06)';
    picker.style.borderRadius = '8px';
    picker.style.padding = '8px';
    picker.style.color = '#fff';
    picker.innerHTML = '<div class="jp-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;"><button type="button" class="jp-prev">‹</button><div class="jp-title"></div><button type="button" class="jp-next">›</button></div><div class="jp-weekdays" style="display:flex;justify-content:space-between;font-size:12px;color:#bbb;margin-bottom:6px;"></div><div class="jp-grid" style="display:flex;flex-wrap:wrap;gap:4px;"></div>';

    var weekdays = ['ش','ی','د','س','چ','پ','ج'];
    var wd = picker.querySelector('.jp-weekdays');
    weekdays.forEach(function(w){ var el = document.createElement('div'); el.style.width='32px'; el.style.textAlign='center'; el.textContent = w; wd.appendChild(el); });

    var title = picker.querySelector('.jp-title');
    var grid = picker.querySelector('.jp-grid');
    var prev = picker.querySelector('.jp-prev');
    var next = picker.querySelector('.jp-next');

    var current = {jy: 1400, jm: 1};

    function render(){
      title.textContent = current.jy + ' / ' + pad(current.jm);
      grid.innerHTML = '';
      var dim = daysInJMonth(current.jy, current.jm);
      // find weekday of 1st
      var gFirst = Jalaali.toGregorian(current.jy, current.jm, 1);
      var dFirst = new Date(gFirst.gy, gFirst.gm-1, gFirst.gd);
      var startWeekday = dFirst.getDay(); // 0 Sun .. 6 Sat (Note: Persian week start differs; we keep this simple)
      // Adjust to make Persian weekdays order match display (we displayed starting with Saturday-like order)
      // We'll render placeholders for alignment (startWeekday) to fill grid
      var total = dim + startWeekday;
      for (var i=0;i<startWeekday;i++){
        var el = document.createElement('div'); el.style.width='32px'; el.style.height='28px'; grid.appendChild(el);
      }
      for (var d=1; d<=dim; d++){
        (function(dd){
          var el = document.createElement('button');
          el.type='button';
          el.textContent = dd;
          el.style.width='32px'; el.style.height='28px';
          el.style.border='none'; el.style.borderRadius='4px';
          el.style.background='transparent'; el.style.color='#fff';
          el.style.cursor='pointer';
          el.addEventListener('click', function(){
            // set input value Jalali
            input.value = current.jy + '/' + pad(current.jm) + '/' + pad(dd);
            // set hidden as Gregorian
            var g = Jalaali.toGregorian(current.jy, current.jm, dd);
            hidden.value = g.gy + '-' + String(g.gm).padStart(2,'0') + '-' + String(g.gd).padStart(2,'0');
            close();
          });
          grid.appendChild(el);
        })(d);
      }
    }

    function openAt(x,y){
      document.body.appendChild(picker);
      picker.style.left = x + 'px'; picker.style.top = y + 'px';
      render();
    }
    function close(){ if (picker.parentNode) picker.parentNode.removeChild(picker); }

    prev.addEventListener('click', function(){ if (current.jm === 1){ current.jm = 12; current.jy -=1; } else current.jm -=1; render(); });
    next.addEventListener('click', function(){ if (current.jm === 12){ current.jm = 1; current.jy +=1; } else current.jm +=1; render(); });

    return {
      open: function(){
        // set current based on input if possible
        var val = input.value.trim();
        if (val){
          var m = val.split('/');
          if (m.length===3){ current.jy = parseInt(m[0],10)||current.jy; current.jm = parseInt(m[1],10)||current.jm; }
        } else {
          // try read hidden
          if (hidden.value){
            var parts = hidden.value.split('-');
            if (parts.length===3){ var j = Jalaali.toJalali(parseInt(parts[0],10), parseInt(parts[1],10), parseInt(parts[2],10)); current.jy=j.jy; current.jm=j.jm; }
          } else {
            var now = new Date();
            var jnow = Jalaali.toJalali(now.getFullYear(), now.getMonth()+1, now.getDate()); current.jy=jnow.jy; current.jm=jnow.jm;
          }
        }
        var rect = input.getBoundingClientRect();
        openAt(rect.left, rect.bottom + window.scrollY + 6);
        // close on outside click
        setTimeout(function(){
          document.addEventListener('click', outside);
        }, 10);
      }
    };

    function outside(e){
      if (!picker.contains(e.target) && e.target !== input){
        if (picker.parentNode) picker.parentNode.removeChild(picker);
        document.removeEventListener('click', outside);
      }
    }
  }

  // Initialize for every input with data-jalali attribute
  document.addEventListener('DOMContentLoaded', function(){
    var input = document.getElementById('jalali-input');
    var hidden = document.getElementById('birthday');
    if (!input || !hidden) return;
    var pickerObj = createPicker(input, hidden);
    input.addEventListener('focus', function(e){ pickerObj.open(); });
    // if hidden has value prefill input
    if (hidden.value){
      var p = hidden.value.split('-');
      if (p.length===3){ var j = Jalaali.toJalali(parseInt(p[0],10), parseInt(p[1],10), parseInt(p[2],10)); input.value = j.jy + '/' + (j.jm.toString().padStart(2,'0')) + '/' + (j.jd.toString().padStart(2,'0')); }
    }
  });
})();
