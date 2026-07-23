<?php
// Gemeinsames Seiten-Layout (Kopf + Fuß)

function page_head(string $title, string $active = ''): void {
    $t = h($title);
    $na = $active === 'analyse' ? ' class="active"' : '';
    $nd = $active === 'admin' ? ' class="active"' : '';
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
  header.top{background:var(--card);border-bottom:1px solid var(--border);padding:14px 24px;
             display:flex;align-items:center;justify-content:space-between}
  header.top .brand{font-weight:800;font-size:18px;color:var(--accent);letter-spacing:-.01em;text-decoration:none}
  header.top nav{display:flex;gap:8px;align-items:center}
  header.top nav a{color:var(--text2);text-decoration:none;font-size:14px;font-weight:600;
             padding:7px 12px;border-radius:999px}
  header.top nav a:hover{color:var(--accent);background:var(--accent-bg)}
  header.top nav a.active{color:var(--accent);background:var(--accent-bg)}
  .wrap{max-width:900px;margin:0 auto;padding:32px 24px 64px}
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
<header class="top">
  <a class="brand" href="/">EmpCo · Greenwashing-Check</a>
  <nav>
    <a href="/"{$na}>Analyse</a>
    <a href="/admin.php"{$nd}>Admin</a>
  </nav>
</header>
<div class="wrap">
HTML;
}

function page_foot(): void {
    echo "</div></body></html>";
}
