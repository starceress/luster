/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Applies each filter panel's open/closed class before first paint, avoiding a
 flash of the wrong panel state. layout.php already renders the fp-* class
 server-side from the lstr_fp cookie, so on a normal page load this script
 has nothing to do — its job is to migrate any older sessionStorage-only
 state (from before the cookie existed) into the cookie for future loads,
 and correct the class in that one edge case. Must load synchronously in
 <head>, before any CSS — moving it into the tail-loaded admin.js bundle
 would reintroduce the flash for that edge case.
*/

(function () {
  var h = document.documentElement;
  var p = window.location.pathname;
  var m = [
    [/\/admin\/(?:listings|affiliates|joined|categories)(?:\/|$)/, 'filter-panel', 'fp-fl'],
    [/\/admin\/members(?:\/|$)/, 'mem-filter-panel', 'fp-mem'],
    [/\/admin\/link-checker(?:\/|$)/, 'lc-filter-panel', 'fp-lc'],
    [/\/admin\/wishlist(?:\/|$)/, 'wl-filter-panel', 'fp-wl'],
    [/\/admin\/kim(?:\/|$)/, 'kim-filter-panel', 'fp-kim'],
    [/\/admin\/adoptions(?:\/|$)/, 'adopt-filter-panel', 'fp-adopt'],
    [/\/admin\/newsfeed(?:\/|$)/, 'upd-filter-panel', 'fp-upd'],
    [/\/admin\/rules(?:\/|$)/, 'rules-filter-panel', 'fp-rules']
  ];

  function cookieMap() {
    var c = document.cookie.match(/(?:^|; )lstr_fp=([^;]*)/);
    if (!c) return {};
    try { return JSON.parse(decodeURIComponent(c[1])); } catch (e) { return {}; }
  }

  function writeCookie(map) {
    document.cookie = 'lstr_fp=' + encodeURIComponent(JSON.stringify(map)) + '; path=/; SameSite=Lax';
  }

  var cm = cookieMap();
  var dirty = false;

  function state(id) {
    var k = 'fp:' + p + ':' + id;
    if (Object.prototype.hasOwnProperty.call(cm, k)) return cm[k];
    try {
      var s = sessionStorage.getItem(k);
      if (s !== null) { cm[k] = s; dirty = true; }
      return s;
    } catch (e) {
      return null;
    }
  }

  for (var i = 0; i < m.length; i++) {
    if (!m[i][0].test(p)) continue;
    var st = state(m[i][1]);
    h.classList.toggle(m[i][2], st !== '0');
  }

  if (dirty) writeCookie(cm);
}());
