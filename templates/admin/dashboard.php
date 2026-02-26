<div style="max-width: 640px;">
  <h2 style="font-size:1.375rem;font-weight:700;color:#111827;margin-bottom:0.5rem;">
    Welcome back, <?= \Luster\Core\View::e($admin_username) ?>
  </h2>
  <p style="color:#6b7280;margin-bottom:2rem;">
    You are signed in to the <?= \Luster\Core\View::e($app_name) ?> admin panel.
    Phase 2 (authentication) is complete and working.
  </p>

  <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));">

    <a href="./admin/fanlistings" style="display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;text-decoration:none;color:#111827;transition:box-shadow 0.15s;"
       onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)'"
       onmouseout="this.style.boxShadow='none'">
      <div style="font-size:1.75rem;margin-bottom:0.5rem;">&#9734;</div>
      <div style="font-weight:600;margin-bottom:0.25rem;">Fanlistings</div>
      <div style="font-size:0.8125rem;color:#6b7280;">Manage your fanlistings</div>
    </a>

    <a href="./admin/members" style="display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;text-decoration:none;color:#111827;transition:box-shadow 0.15s;"
       onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)'"
       onmouseout="this.style.boxShadow='none'">
      <div style="font-size:1.75rem;margin-bottom:0.5rem;">&#9829;</div>
      <div style="font-weight:600;margin-bottom:0.25rem;">Members</div>
      <div style="font-size:0.8125rem;color:#6b7280;">Approve and manage fans</div>
    </a>

    <a href="./admin/affiliates" style="display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;text-decoration:none;color:#111827;transition:box-shadow 0.15s;"
       onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)'"
       onmouseout="this.style.boxShadow='none'">
      <div style="font-size:1.75rem;margin-bottom:0.5rem;">&#9830;</div>
      <div style="font-weight:600;margin-bottom:0.25rem;">Affiliates</div>
      <div style="font-size:0.8125rem;color:#6b7280;">Manage affiliate links</div>
    </a>

    <a href="./admin/settings" style="display:block;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;text-decoration:none;color:#111827;transition:box-shadow 0.15s;"
       onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,.08)'"
       onmouseout="this.style.boxShadow='none'">
      <div style="font-size:1.75rem;margin-bottom:0.5rem;">&#9881;</div>
      <div style="font-weight:600;margin-bottom:0.25rem;">Settings</div>
      <div style="font-size:0.8125rem;color:#6b7280;">Configure the panel</div>
    </a>

  </div>
</div>
