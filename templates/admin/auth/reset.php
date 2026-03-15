<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set New Password &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <link rel="stylesheet" href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/assets/css/admin.css">
  <script>
    (function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();
  </script>
</head>
<body class="login-page">

<canvas id="login-constellation"></canvas>

<div class="login-theme-corner">
  <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
    <span class="theme-toggle-icon">☀️</span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon">🌙</span>
  </button>
</div>

<div class="login-wrapper">
  <div class="login-card">

    <div class="login-brand">
      <div class="brand-gem-wrap login-gem-wrap">
        <div class="brand-gem"></div>
        <div class="brand-gem-glow"></div>
      </div>
      <span class="brand-name login-brand-name"><?= \Luster\Core\View::e($app_name) ?></span>
      <span class="brand-sub login-brand-sub">Set New Password</span>
    </div>

    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= \Luster\Core\View::e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group">
        <label class="form-label" for="new_password">New Password <span>*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="new_password" name="new_password" class="form-input"
                 maxlength="255" required autocomplete="new-password" autofocus>
          <button type="button" class="input-icon-btn" onclick="togglePwd(this)" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <p class="form-hint">Minimum 8 characters.</p>
      </div>

      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" for="new_password_confirm">Confirm New Password <span>*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-input"
                 maxlength="255" required autocomplete="new-password">
          <button type="button" class="input-icon-btn" onclick="togglePwd(this)" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary login-submit">Set New Password</button>
    </form>

  </div>

  <p class="login-footer">
    <a href="<?= rtrim(\Luster\Core\View::e($base_url ?? ''), '/') ?>/admin/login" class="login-forgot">&larr; Back to sign in</a>
  </p>
</div>

<script>
(function () {
  const canvas = document.getElementById('login-constellation');
  const ctx    = canvas.getContext('2d');
  let stars    = [];
  const N      = 80;
  let rafId    = null;
  function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
  function init() {
    resize(); stars = [];
    for (let i = 0; i < N; i++) {
      stars.push({ x: Math.random()*canvas.width, y: Math.random()*canvas.height,
        r: Math.random()*1.5+0.3, dx: (Math.random()-0.5)*0.18, dy: (Math.random()-0.5)*0.18,
        opacity: Math.random()*0.5+0.15, twinkleSpeed: Math.random()*0.012+0.004,
        twinklePhase: Math.random()*Math.PI*2 });
    }
  }
  function draw() {
    ctx.clearRect(0,0,canvas.width,canvas.height);
    const t = Date.now()*0.001;
    stars.forEach(s => {
      s.x+=s.dx; s.y+=s.dy;
      if(s.x<0)s.x=canvas.width; if(s.x>canvas.width)s.x=0;
      if(s.y<0)s.y=canvas.height; if(s.y>canvas.height)s.y=0;
      const tw=s.opacity+Math.sin(t*s.twinkleSpeed*60+s.twinklePhase)*0.18;
      ctx.beginPath(); ctx.arc(s.x,s.y,s.r,0,Math.PI*2);
      ctx.fillStyle=`rgba(196,181,253,${Math.max(0.04,Math.min(0.85,tw))})`; ctx.fill();
    });
    for(let i=0;i<stars.length;i++) for(let j=i+1;j<stars.length;j++){
      const dx=stars[i].x-stars[j].x, dy=stars[i].y-stars[j].y, d=Math.sqrt(dx*dx+dy*dy);
      if(d<90){ctx.beginPath();ctx.moveTo(stars[i].x,stars[i].y);ctx.lineTo(stars[j].x,stars[j].y);
        ctx.strokeStyle=`rgba(196,181,253,${0.09*(1-d/90)})`;ctx.lineWidth=0.5;ctx.stroke();}
    }
    rafId = requestAnimationFrame(draw);
  }
  function start() { if (!rafId) { rafId = requestAnimationFrame(draw); } }
  function stop()  { if (rafId)  { cancelAnimationFrame(rafId); rafId = null; } }
  document.addEventListener('visibilitychange', function() {
    document.hidden ? stop() : start();
  });
  window.addEventListener('resize',init); init(); start();
})();
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('lstr_theme', next);
}
function togglePwd(btn) {
  const inp = btn.previousElementSibling;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.querySelector('.eye-on').style.display  = show ? 'none' : '';
  btn.querySelector('.eye-off').style.display = show ? ''     : 'none';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}
</script>
</body>
</html>
