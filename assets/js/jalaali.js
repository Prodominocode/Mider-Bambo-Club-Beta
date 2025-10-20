/* Minimal Jalaali (Persian) <-> Gregorian conversion
   Implementation based on public-domain algorithms (small, standalone)
   Exposes two functions:
   - toGregorian(jy, jm, jd) -> {gy, gm, gd}
   - toJalali(gy, gm, gd) -> {jy, jm, jd}
*/
(function(window){
  'use strict';

  function div(a,b){ return Math.floor(a/b); }

  function gregorianToJd(gy, gm, gd){
    var d = div((gy+div(gm-8,6)+100100)*1461,4)+div(153*((gm+9)%12)+2,5)+gd-34840408;
    d = d - div(div(gy+100100+div(gm-8,6),100)*3,4)+752;
    return d;
  }

  function jdToGregorian(jd){
    var j = 4*jd +139361631;
    j = j + div(div(4*jd+183187720,146097)*3,4)*4 - 3908;
    var i = div((j%1461),4)*5 +308;
    var gd = div(i%153,5)+1;
    var gm = (div(i,153)%12)+1;
    var gy = div(j,1461) - 100100 + div(8-gm,6);
    return {gy:gy, gm:gm, gd:gd};
  }

  function jalaliToJd(jy, jm, jd){
    jy = +jy; jm = +jm; jd = +jd;
    var epbase = jy - (jy >= 0 ? 474 : 473);
    var epyear = 474 + (epbase % 2820);
    var mdays = (jm <= 7) ? ((jm-1)*31) : (((jm-1)*30) +6);
    var jdNo = jd + mdays + Math.floor(((epyear * 682) - 110) / 2816) + (epyear -1)*365 + Math.floor(epbase/2820)*1029983 + (1948320-1);
    return jdNo;
  }

  function jdToJalali(jd){
    jd = +jd;
    var depoch = jd - jalaliToJd(475,1,1);
    var cycle = Math.floor(depoch / 1029983);
    var cyear = depoch % 1029983;
    var ycycle;
    if (cyear === 1029982) {
      ycycle = 2820;
    } else {
      var aux1 = Math.floor(cyear/366);
      var aux2 = cyear % 366;
      ycycle = Math.floor((2134*aux1 + 2816*aux2 + 2815) / 1028522) + aux1 + 1;
    }
    var jy = ycycle + (2820 * cycle) + 474;
    if (jy <= 0) jy -= 1;
    var jd1f = jalaliToJd(jy,1,1);
    var dayOfYear = jd - jd1f +1;
    var jm = (dayOfYear <= 186) ? Math.ceil(dayOfYear/31) : Math.ceil((dayOfYear-186)/30)+6;
    var jdDay = jd - jalaliToJd(jy, jm, 1) +1;
    return {jy:jy, jm:jm, jd:jdDay};
  }

  function toGregorian(jy, jm, jd){
    var jdNum = jalaliToJd(jy, jm, jd);
    var g = jdToGregorian(jdNum);
    return {gy: g.gy, gm: g.gm, gd: g.gd};
  }

  function toJalali(gy, gm, gd){
    var jd = gregorianToJd(gy, gm, gd);
    return jdToJalali(jd);
  }

  window.Jalaali = {
    toGregorian: toGregorian,
    toJalali: toJalali
  };

})(window);
