<?php
declare(strict_types=1);
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/auth.php';

// -----------------------------------------------------------------
// 1. Autoâ€‘login fallback (development helper)
// -----------------------------------------------------------------
if (!is_logged_in()) {
    $admin = $mysqli->query("SELECT id, name, rolle, email FROM benutzer WHERE rolle='superadmin' LIMIT 1")->fetch_assoc();
    if ($admin) {
        set_login_session((int)$admin['id'], $admin['name'], $admin['rolle'], $admin['email']);
    }
}

// -----------------------------------------------------------------
// 2. Load template (id) â€“ also preâ€‘fill the form using the designer layout
// -----------------------------------------------------------------
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    die('âš ï¸ Fehlende oder ungÃ¼ltige Vorlageâ€‘ID');
}

$stmt = $mysqli->prepare(
    "SELECT v.*, t.name AS typ_name FROM protokoll_vorlagen v LEFT JOIN protokoll_typen t ON v.typ_id = t.id WHERE v.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$template = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$template) {
    http_response_code(404);
    die('âŒ Vorlage nicht gefunden');
}

// Decode stored JSON â€“ contains layout (blocks) \u0026 possibly existing values
$protocol = [];
if (!empty($template['json_data'])) {
    $protocol = json_decode($template['json_data'], true) ?? [];
}

// -----------------------------------------------------------------
// 3. Helper for safe HTML escaping
// -----------------------------------------------------------------
function esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Mapping placeholder â†’ input name
$placeholderMap = [
    '{TITEL}'   => 'title',
    '{BETREFF}' => 'subject',
    '{PROJEKT}' => 'project',
    '{OBJEKT}' => 'object',
    '{WOHNUNG}' => 'apartment',
    '{RAUM}'   => 'room',
    '{DATUM}'  => 'datetime',
    '{ORT}'    => 'location',
    '{INTRO}'  => 'intro',
    '{CONTENT}'=> 'content',
    '{OUTRO}'  => 'outro'
];

ob_start();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>ðŸ–Šï¸ Vorlage ausfÃ¼llen â€“ <?= esc($template['name'] ?? 'Protokoll') ?></title>
    <style>
        body {font-family: 'Inter',Arial,sans-serif; background:#f5f7fa; color:#1e293b; margin:0; padding:20px;}
        .container {max-width:900px; margin:auto; background:#fff; padding:30px; border-radius:12px; box-shadow:0 4px 12px rgba(0,0,0,.08);}
        h1 {color:#0f172a; margin-bottom:0.5rem;}
        .block {margin-bottom:1.5rem; position:relative;}
        .btn {display:inline-block;margin-top:20px;padding:12px 20px;background:#00a896;color:#fff;border:none;border-radius:6px;cursor:pointer; font-size:1rem;}
        .btn:hover {background:#018c79;}
        label {font-weight:600;color:#334155; display:block; margin-top:10px;}
        input, textarea {border:1px solid #cbd5e1;border-radius:4px;padding:8px 8px 8px 35px;margin-top:4px; width:100%; box-sizing:border-box;}
        .input-wrapper {position:relative;}
        .input-icon {position:absolute; left:10px; top:12px; color:#64748b;}
        textarea {resize:vertical; min-height:80px;}
        .pd-hidden {display:none;}
    </style>
</head>
<body>
<div class="container">
    <h1>ðŸ–Šï¸  Vorlage ausfÃ¼llen â€“ <?= esc($template['name'] ?? 'Protokoll') ?></h1>
    <form method="post" action="protokoll_fill_pdf.php?id=<?= $id ?>">
        <?php
        // Helper: render block with icons
        function renderField(string $name, string $value, string $id = ''): string {
            $icons = ['title'=>'📌', 'subject'=>'📍', 'project'=>'🏗️', 'object'=>'🏠', 'apartment'=>'🚪', 'room'=>'🛋️', 'datetime'=>'📅', 'location'=>'📍', 'intro'=>'💬', 'content'=>'📄', 'outro'=>'📝'];
            $icon = $icons[$name] ?? '✍️';
            $fieldId = $id ?: $name . '_' . uniqid();
            $field = in_array($name, ['intro','content','outro'])
                ? '<textarea id="'.$fieldId.'" name="'.$name.'">'.esc($value).'</textarea>'
                : '<input type="text" id="'.$fieldId.'" name="'.$name.'" value="'.esc($value).'" />';
            return '<div class="input-wrapper"><span class="input-icon">'.$icon.'</span>'.$field.'</div>';
        }
        ?>
        <?php
        // Render designer blocks with absolute positioning and input fields
        if (!empty($protocol['layout']['blocks']) && is_array($protocol['layout']['blocks'])) {
            $sc = 0.75; // conversion factor from px to pt
            foreach ($protocol['layout']['blocks'] as $block) {
                $raw = $block['text'] ?? '';
                // Replace placeholders with input fields (icons included)
                foreach ($placeholderMap as $ph => $name) {
                    if (strpos($raw, $ph) !== false) {
                        $fId = $name . '_' . ($block['id'] ?? uniqid());
                        $raw = str_replace($ph, '<label for="'.$fId.'" class="pd-hidden">'.ucfirst($name).'</label>' . renderField($name, $protocol[$name] ?? '', $fId), $raw);
                    }
                }
                // Build CSS style for absolute positioning
                $style = "position:absolute;"
                    ." left:".($block['x']*$sc ?? 0)."pt;"
                    ." top:".($block['y']*$sc ?? 0)."pt;"
                    ." width:".(($block['w'] ?? 100)*$sc)."pt;"
                    ." height:".(($block['h'] ?? 50)*$sc)."pt;"
                    ." font-size:".(($block['fontSize'] ?? 11)*$sc)."pt;"
                    ." font-weight:".(!empty($block['bold']) ? 'bold' : 'normal').";"
                    ." font-style:".(!empty($block['italic']) ? 'italic' : 'normal').";"
                    ." text-align:".($block['align'] ?? 'left').";"
                    ." color:".($block['color'] ?? '#000').";"
                    ." background-color:".($block['bgColor'] ?? 'transparent').";"
                    ." border:".((($block['borderWidth'] ?? 0)*$sc).'pt '.($block['borderStyle'] ?? 'solid').' '.($block['borderColor'] ?? 'transparent')).";"
                    ." border-radius:".((($block['borderRadius'] ?? 0)*$sc))."pt;"
                    ." padding:".((($block['padding'] ?? 0)*$sc))."pt;"
                    ." overflow:hidden;";
                echo "<div class='block' style='$style'>".$raw."</div>\n";
            }
        } else {
            // Fallback: render each placeholder as a labeled input
            foreach ($placeholderMap as $ph => $name) {
                $fId = 'fallback_' . $name;
                echo "<label for='$fId'>".ucfirst($name)."</label>\n";
                echo renderField($name, $protocol[$name] ?? '', $fId);
            }
        }
        ?>
        <?php
        // Render participants (if any)
        if (!empty($protocol['participants']) && is_array($protocol['participants'])) {
            echo "<h2>Teilnehmer</h2><table><tr><th>Name</th><th>Rolle / Position</th><th>Email</th><th>Telefon</th></tr>";
            foreach ($protocol['participants'] as $idx => $p) {
                $pName = esc($p['name'] ?? '');
                $pRole = esc($p['role'] ?? $p['position'] ?? '');
                $pEmail = esc($p['email'] ?? '');
                $pTel = esc($p['tel'] ?? '');
                echo "<tr>";
                echo "<td><label for='p_name_{$idx}' class='pd-hidden'>Name</label><input type='text' id='p_name_{$idx}' name='participants[{$idx}][name]' value='$pName'></td>";
                echo "<td><label for='p_role_{$idx}' class='pd-hidden'>Rolle</label><input type='text' id='p_role_{$idx}' name='participants[{$idx}][role]' value='$pRole'></td>";
                echo "<td><label for='p_email_{$idx}' class='pd-hidden'>Email</label><input type='text' id='p_email_{$idx}' name='participants[{$idx}][email]' value='$pEmail'></td>";
                echo "<td><label for='p_tel_{$idx}' class='pd-hidden'>Telefon</label><input type='text' id='p_tel_{$idx}' name='participants[{$idx}][tel]' value='$pTel'></td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Render custom sections (if any)
        if (!empty($protocol['custom_sections']) && is_array($protocol['custom_sections'])) {
            echo "<h2>ZusÃ¤tzliche Abschnitte</h2>";
            foreach ($protocol['custom_sections'] as $sIdx => $sec) {
                $secTitle = esc($sec['title'] ?? '');
                $secContent = esc($sec['content'] ?? '');
                echo "<label for='section_title_{$sIdx}'>Titel</label>";
                echo "<input type='text' id='section_title_{$sIdx}' name='custom_sections[{$sIdx}][title]' value='$secTitle'>";
                echo "<label for='section_content_{$sIdx}'>Inhalt</label>";
                echo "<textarea id='section_content_{$sIdx}' name='custom_sections[{$sIdx}][content]'>$secContent</textarea>";
            }
        }
        ?>
        <button type="submit" class="btn">PDFâ€¯generieren</button>
    </form>
</div>
</body>
</html>
<?php
echo ob_get_clean();
?>
