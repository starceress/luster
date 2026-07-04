/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Applies the saved dark/light theme before first paint, avoiding a flash of
 the wrong theme. Must load synchronously in <head>, before any CSS — moving
 it into the tail-loaded admin.js bundle would reintroduce that flash.
*/

(function () {
  var t = localStorage.getItem('lstr_theme');
  if (t === 'dark' || t === 'light') {
    document.documentElement.setAttribute('data-theme', t);
  }
}());
