<?php
// Gemeinsames Seiten-Layout (Kopf + Fuß)

function page_head(string $title, string $active = '', bool $bare = false): void {
    $GLOBALS['__empco_bare'] = $bare;
    $t = h($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --accent:#0049EC; --accent-dark:#263FCC; --accent-bg:#E8EFFD; --accent-border:#BACEFA;
    --bg:#F7F9FC; --card:#FFFFFF;
    --text:#0F172A; --text2:#475569; --text3:#94A3B8;
    --border:#E2E8F0; --border2:#CBD5E1;
    --green:#12A150; --green-bg:#F4FDF7; --green-border:#BCF1CE;
    --amber:#D97706; --amber-bg:#FFFBEB; --amber-border:#FDE68A;
    --red:#E90C3C; --red-bg:#FDECEF; --red-border:#F8C2CD;
    --purple:#8E3FD4; --sky:#40C5EF; --grass:#1ED05C; --cool-grey:#B6C5CD;
    --radius-sm:6px; --radius:8px; --radius-lg:12px;
    --shadow-sm:0 1px 2px rgba(15,23,42,.05);
    --shadow:0 1px 4px rgba(15,23,42,.08),0 0 0 1px rgba(15,23,42,.04);
    --font:'Manrope',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:var(--font);font-feature-settings:'tnum';
       background:var(--bg);color:var(--text);line-height:1.5;-webkit-font-smoothing:antialiased}
  .app-shell{display:flex;min-height:100vh}
  .sidebar{width:220px;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100;
        background:var(--card);border-right:1px solid var(--border);
        display:flex;flex-direction:column;overflow-y:auto}
  .sidebar-logo{padding:0 20px;display:flex;align-items:center;gap:10px;
        border-bottom:1px solid var(--border);height:64px;flex-shrink:0}
  .brand-icon-sm{width:32px;height:32px;background:var(--accent);border-radius:8px;
        display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
  .sidebar-brand{font-size:15px;font-weight:800;color:var(--accent);letter-spacing:-.01em}
  .sidebar-nav{flex:1;padding:8px;display:flex;flex-direction:column}
  .nav-section-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
        color:var(--text3);padding:12px 10px 4px}
  .nav-item{display:flex;align-items:center;gap:9px;width:100%;padding:8px 10px;
        border:none;border-left:2px solid transparent;border-radius:var(--radius);background:none;
        cursor:pointer;text-align:left;color:var(--text2);margin-bottom:1px;text-decoration:none;
        transition:background .12s,color .12s;font-family:inherit;font-size:13px;font-weight:500}
  .nav-item svg{flex-shrink:0;opacity:.55}
  .nav-item:hover{background:#F1F5F9;color:var(--text)}
  .nav-item:hover svg{opacity:1}
  .nav-item.active{background:var(--accent-bg);color:var(--accent);font-weight:600;border-left:2px solid var(--accent)}
  .nav-item.active svg{opacity:1}
  .sidebar-footer{padding:14px 20px;border-top:1px solid var(--border);font-size:11px;
        color:var(--text3);display:flex;align-items:center;justify-content:space-between}
  .sidebar-footer a{color:var(--text3);font-size:11px;text-decoration:none;transition:color .12s}
  .sidebar-footer a:hover{color:var(--red)}
  .main-content{margin-left:220px;flex:1;min-width:0;background:var(--bg)}
  .wrap{max-width:900px;margin:0 auto;padding:32px 24px 64px}
  .wrap-narrow{max-width:440px;padding-top:56px}
  @media(max-width:720px){
    .sidebar{position:static;width:100%;bottom:auto;flex-direction:column}
    .main-content{margin-left:0}
  }
  h1{font-size:26px;font-weight:800;letter-spacing:-.02em;margin:0 0 8px}
  h2{font-weight:700;letter-spacing:-.01em;font-size:20px}
  .sub{color:var(--text2);margin:0 0 28px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);
        padding:24px;margin-bottom:20px;box-shadow:var(--shadow)}
  label{display:block;font-weight:600;margin:16px 0 6px;font-size:14px}
  input[type=text],input[type=password],input[type=url],textarea,select{width:100%;padding:11px 13px;
        border:1px solid var(--border2);border-radius:var(--radius);font-size:15px;font-family:inherit;
        background:#fff;color:var(--text)}
  input:focus,textarea:focus,select:focus{outline:none;border-color:var(--accent);
        box-shadow:0 0 0 3px var(--accent-bg)}
  textarea{min-height:120px;resize:vertical}
  .hint{color:var(--text2);font-size:13px;margin-top:4px}
  .row{display:flex;gap:16px;flex-wrap:wrap}
  .row > div{flex:1;min-width:200px}
  button,.btn{display:inline-block;background:var(--accent);color:#fff;border:none;
        border-radius:999px;padding:12px 26px;font-size:15px;font-weight:700;cursor:pointer;
        text-decoration:none;margin-top:20px;font-family:inherit;transition:background .15s,transform .1s}
  button:hover,.btn:hover{background:var(--accent-dark)}
  button:active,.btn:active{transform:translateY(1px)}
  .btn-ghost{background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-border)}
  .btn-sm{padding:7px 14px;font-size:13px;margin-top:0}
  .alert{padding:12px 16px;border-radius:var(--radius);margin-bottom:20px;font-size:14px}
  .alert.ok{background:var(--green-bg);color:var(--green);border:1px solid var(--green-border)}
  .alert.err{background:var(--red-bg);color:var(--red);border:1px solid var(--red-border)}
  .alert.info{background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-border)}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;color:var(--text2);font-weight:600;font-size:12px;text-transform:uppercase;
     letter-spacing:.03em;padding:8px 10px;border-bottom:1px solid var(--border)}
  td{padding:10px;border-bottom:1px solid var(--border);vertical-align:top}
  .tag{display:inline-block;background:var(--accent-bg);color:var(--accent);border:1px solid var(--accent-border);
       border-radius:999px;padding:2px 10px;font-size:12px;font-weight:600}
  .badge{display:inline-flex;align-items:center;gap:5px;border-radius:999px;padding:3px 10px;
         font-size:12px;font-weight:600}
  .badge.info{background:var(--accent-bg);color:var(--accent)}
  .badge.warn{background:var(--amber-bg);color:var(--amber)}
  .badge.violation{background:var(--red-bg);color:var(--red)}
  .badge.ok{background:var(--green-bg);color:var(--green)}
  .badge.skipped{background:#F1F5F9;color:var(--text3)}
  .check-status{display:flex;gap:6px;flex-wrap:wrap}
  .count-pill{display:inline-block;background:var(--accent-bg);color:var(--accent);
        border-radius:999px;padding:4px 12px;font-size:13px;font-weight:700}
  code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
  .progress-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
  .progress-pct{font-size:26px;font-weight:800;color:var(--accent);font-variant-numeric:tabular-nums}
  .progress-bar-bg{height:8px;background:var(--accent-bg);border-radius:999px;overflow:hidden}
  .progress-bar-fill{height:100%;background:var(--accent);width:0;border-radius:999px;transition:width .3s}
  .spinner{display:inline-block;width:15px;height:15px;border:2px solid var(--accent-border);
        border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;vertical-align:-2px}
  @keyframes spin{to{transform:rotate(360deg)}}
  /* Findings */
  .sev-chip{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;padding:3px 10px;border-radius:999px}
  .sev-chip .dot{width:8px;height:8px;border-radius:50%}
  .sev-chip.violation{background:var(--red-bg);color:var(--red)} .sev-chip.violation .dot{background:var(--red)}
  .sev-chip.warn{background:var(--amber-bg);color:var(--amber)} .sev-chip.warn .dot{background:var(--amber)}
  .sev-chip.info{background:var(--accent-bg);color:var(--accent)} .sev-chip.info .dot{background:var(--accent)}
  .finding{background:var(--card);border:1px solid var(--border);border-left:4px solid var(--border2);
        border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);padding:16px 20px;margin-bottom:12px;transition:opacity .15s}
  .finding.violation{border-left-color:var(--red)}
  .finding.warn{border-left-color:var(--amber)}
  .finding.info{border-left-color:var(--accent)}
  .finding.resolved{opacity:.5}
  .finding-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .sev-name{font-weight:700;font-size:13px}
  .finding.violation .sev-name{color:var(--red)}
  .finding.warn .sev-name{color:var(--amber)}
  .finding.info .sev-name{color:var(--accent)}
  .finding-cat{font-size:12px;font-weight:600;color:var(--text2);background:var(--bg);
        border:1px solid var(--border);padding:2px 9px;border-radius:6px}
  .finding-page{font-size:12px;font-weight:500;color:var(--accent);background:var(--accent-bg);
        border:1px solid var(--accent-border);padding:2px 9px;border-radius:6px;text-decoration:none;
        max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle}
  .finding-page:hover{background:var(--accent-border)}
  .finding-meta{margin-left:auto;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
        font-size:11px;color:var(--text3);text-align:right}
  .finding-status{font-size:12px;font-weight:700;color:var(--green)}
  .finding-quote{margin:12px 0 8px;font-size:15px;line-height:1.55;color:var(--text);
        padding-left:14px;border-left:2px solid var(--border2)}
  .finding-assess{color:var(--text2);font-size:13.5px;line-height:1.5}
  .finding-actions{display:flex;gap:8px;margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}
  .btn-soft{display:inline-flex;align-items:center;gap:6px;background:#F1F5F9;color:var(--text2);
        border:1px solid var(--border);border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;
        cursor:pointer;margin:0;font-family:inherit;text-decoration:none;transition:.12s}
  .btn-soft:hover{background:#E7EDF4;color:var(--text)}
  .btn-soft.ok:hover{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
  .btn-download{display:inline-flex;align-items:center;gap:8px;margin:0}
  /* Ergebnis-Übersicht (Donut) */
  .summary-card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);
        box-shadow:var(--shadow);padding:20px 24px;margin-bottom:20px;display:flex;flex-direction:column;gap:14px}
  .summary-main{display:flex;align-items:center;gap:28px;flex-wrap:wrap}
  .summary-donut{flex-shrink:0;position:relative;width:120px;height:120px}
  .summary-donut svg{display:block;transform:rotate(0)}
  .donut-center{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  .donut-num{font-size:30px;font-weight:800;line-height:1;color:var(--text);font-variant-numeric:tabular-nums}
  .donut-lbl{font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}
  .summary-legend{flex:1;min-width:240px;display:flex;flex-direction:column;gap:9px}
  .legend-row{display:flex;align-items:center;gap:10px;font-size:14px;flex-wrap:wrap}
  .legend-dot{width:11px;height:11px;border-radius:3px;flex-shrink:0}
  .legend-name{font-weight:700;min-width:60px}
  .legend-count{font-weight:700;font-variant-numeric:tabular-nums;min-width:20px;text-align:right}
  .legend-share{color:var(--text3);font-size:12px;font-variant-numeric:tabular-nums;min-width:44px}
  .legend-desc{color:var(--text2);font-size:12.5px}
  .summary-side{display:flex;flex-direction:column;align-items:flex-end;gap:10px;flex-shrink:0}
  .summary-status{font-size:12px;color:var(--text2)}
  .summary-status b{color:var(--text);font-variant-numeric:tabular-nums}
  .summary-foot{border-top:1px solid var(--border);padding-top:12px;font-size:12px;color:var(--text2);
        display:flex;gap:8px 18px;flex-wrap:wrap;align-items:center}
  @media(max-width:640px){.summary-side{align-items:flex-start}}
  details.rule{background:var(--card);border:1px solid var(--border);border-radius:var(--radius-lg);
        box-shadow:var(--shadow-sm);margin-bottom:10px;overflow:hidden;
        transition:box-shadow .15s,border-color .15s}
  details.rule:hover{box-shadow:var(--shadow);border-color:var(--border2)}
  details.rule>summary{list-style:none;cursor:pointer;user-select:none;display:flex;align-items:center;
        gap:14px;padding:16px 20px}
  details.rule>summary::-webkit-details-marker{display:none}
  details.rule>summary:hover .sum-id{color:var(--accent)}
  details.rule[open]>summary{border-bottom:1px solid var(--border)}
  details.rule .rule-body{padding:14px 20px 20px}
  details.rule .rule-body label{margin-top:12px}
  .sum-id{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;font-size:13px;
        flex-shrink:0;transition:color .12s}
  .sum-desc{color:var(--text2);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;
        white-space:nowrap;font-size:13px}
  .sum-chevron{margin-left:auto;flex-shrink:0;color:var(--text3);transition:transform .2s}
  details.rule[open]>summary .sum-chevron{transform:rotate(180deg)}
  .form-actions{display:flex;gap:8px;align-items:center;margin-top:16px}
  .form-actions button{margin-top:0}
</style>
</head>
<body>
HTML;
    if ($bare) {
        echo "\n<div class=\"wrap wrap-narrow\">\n";
        return;
    }
    $na = $active === 'analyse' ? ' active' : '';
    $nr = $active === 'rules' ? ' active' : '';
    $ng = $active === 'agents' ? ' active' : '';
    echo <<<HTML
<div class="app-shell">
<aside class="sidebar">
  <div class="sidebar-logo">
    <span class="brand-icon-sm"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/></svg></span>
    <span class="sidebar-brand">EmpCo</span>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-section-label">Analyse</div>
    <a class="nav-item{$na}" href="/">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      Neue Analyse
    </a>
    <div class="nav-section-label">Verwaltung</div>
    <a class="nav-item{$nr}" href="/admin.php?section=rules">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="8" y1="18" x2="14" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
      Regeln
    </a>
    <a class="nav-item{$ng}" href="/admin.php?section=agents">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
      KI-Redakteure
    </a>
  </nav>
  <div class="sidebar-footer">
    <span>EmpCo · v0.1</span>
    <a href="/?logout=1">Abmelden</a>
  </div>
</aside>
<div class="main-content"><div class="wrap">
HTML;
}

function page_foot(): void {
    if (!empty($GLOBALS['__empco_bare'])) {
        echo "</div></body></html>";
    } else {
        echo "</div></div></div></body></html>";
    }
}
