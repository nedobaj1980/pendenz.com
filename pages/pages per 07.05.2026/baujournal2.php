<?php
// pages/baujournal2.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Get protocol ID (same as in designer)
$protocol_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Simple fallback if no ID
if ($protocol_id <= 0) {
    echo '<p style="padding:20px;color:#f00;">Keine Protokoll-ID angegeben.</p>';
    exit;
}

// Use same header as manager for a premium look
require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8" />
    <title>Baujournal2 – Protokoll Vorschau</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root {
            --bg-main: #f8fafc;
            --accent: #1abc9c;
        }
        body {margin:0;font-family:'Inter',sans-serif;background:var(--bg-main);color:#1e293b;}
        .container {display:flex;flex-direction:column;height:100vh;}
        .header {background:var(--accent);color:#fff;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;}
        .header a {color:#fff;text-decoration:none;}
        .iframe-wrapper {flex:1;overflow:hidden;}
        iframe {border:none;width:100%;height:100%;}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <a href="pendenzen_list_pdf.php?id=<?=$protocol_id?>" target="_blank" style="background:#fff;color:#1e293b;padding:6px 12px;border-radius:4px;text-decoration:none;">📄 Endprodukt‑PDF öffnen</a>
        <h1 style="margin:0;font-size:1.2rem;">Baujournal2 – Protokoll Vorschau</h1>
        <a href="protokoll_designer.php?id=<?=$protocol_id?>" target="_blank" style="background:#fff;color:#1e293b;padding:6px 12px;border-radius:4px;text-decoration:none;">🖨️ PDF öffnen</a>
    </div>
    <div class="iframe-wrapper">
        <iframe src="pendenzen_list_pdf.php?id=<?=$protocol_id?>" sandbox="allow-scripts allow-same-origin"></iframe>
    </div>
    <div class="iframe-wrapper">
        <iframe src="protokoll_designer.php?id=<?=$protocol_id?>" sandbox="allow-scripts allow-same-origin"></iframe>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
