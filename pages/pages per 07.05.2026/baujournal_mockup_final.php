<?php
// pages/baujournal_mockup_final.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

// Get protocol ID from query string
$protocol_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($protocol_id <= 0) {
    echo '<p style="padding:20px;color:#f00;">Keine Protokoll-ID angegeben.</p>';
    exit;
}

// Header (premium look, same as manager)
require_once __DIR__ . '/../includes/header.php';
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8" />
    <title>Baujournal Mockup Final – Protokoll‑Vorschau</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root {
            --bg-main: #f8fafc;
            --accent: #1abc9c;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--bg-main);
            color: #1e293b;
        }

        .container {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        .header {
            background: var(--accent);
            color: #fff;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            margin: 0;
            font-size: 1.2rem;
        }

        .header a {
            color: #1e293b;
            background: #fff;
            padding: 6px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 600;
        }

        .iframe-wrapper {
            flex: 1;
            overflow: hidden;
        }

        iframe {
            border: none;
            width: 100%;
            height: 100%;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Baujournal Mockup Final – Protokoll‑Vorschau</h1>
            <div>
                <a href="pendenzen_list_pdf.php?id=<?= $protocol_id ?>" target="_blank">📄 Endprodukt‑PDF öffnen</a>
                <a href="protokoll_designer.php?id=<?= $protocol_id ?>" target="_blank" style="margin-left:8px;">🖨️ PDF
                    öffnen</a>
            </div>
        </div>
        <div class="iframe-wrapper">
            <!-- Designer preview (same view used in Baujournal2) -->
            <iframe src="protokoll_designer.php?id=<?= $protocol_id ?>"
                sandbox="allow-scripts allow-same-origin"></iframe>
        </div>
    </div>
    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>

</html>

</html>