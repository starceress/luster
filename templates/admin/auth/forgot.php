<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password &mdash; <?= \Luster\Core\View::e($app_name) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500&family=DM+Mono:wght@400&display=swap" rel="stylesheet">
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
      <span class="brand-sub login-brand-sub">Password Reset</span>
    </div>

    <?php if ($success !== '') : ?>
      <div class="alert alert-success" role="alert"><?= \Luster\Core\View::e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== '') : ?>
      <div class="alert alert-error" role="alert"><?= \Luster\Core\View::e($error) ?></div>
    <?php endif; ?>

    <?php if ($success === '') : ?>
    <p style="font-size:.875rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.5">
      Enter your username or email address and we&rsquo;ll send you a link to reset your password.
    </p>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" for="identifier">Username or Email</label>
        <input type="text" id="identifier" name="identifier" class="form-input"
               autocomplete="username email" autofocus required>
      </div>

      <button type="submit" class="btn btn-primary login-submit">Send Reset Link</button>
    </form>
    <?php endif; ?>

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
    requestAnimationFrame(draw);
  }
  window.addEventListener('resize',init); init(); draw();
})();
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('lstr_theme', next);
}
</script>
</body>
</html>
