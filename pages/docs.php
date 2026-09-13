<?php
// pages/docs.php — einfacher Docs-Viewer (MD/YAML/TXT), keine externen Libs
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

$docsDir = realpath(__DIR__ . '/../docs');
if (!$docsDir) {
  http_response_code(500);
  echo "Docs-Verzeichnis fehlt: " . e(__DIR__ . '/../docs');
  exit;
}

// Unterstützte Dateien einsammeln
$files = array_values(array_filter(scandir($docsDir), function($f) use ($docsDir){
  $p = $docsDir . DIRECTORY_SEPARATOR . $f;
  return is_file($p) && preg_match('/\.(md|markdown|ya?ml|txt)$/i', $f);
}));
sort($files, SORT_NATURAL|SORT_FLAG_CASE);

// Auswahl
$selected = $_GET['f'] ?? ($files[0] ?? '');
$selected = basename($selected); // sanitize
$path = $selected ? $docsDir . DIRECTORY_SEPARATOR . $selected : null;
if (!$selected || !is_file($path)) {
  $selected = '';
  $path = null;
}

// Minimal-„Markdown“
function mini_md($text){
  // Überschriften
  $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
  $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
  $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
  // Fett **text**
  $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
  // Codeblöcke ``` ```
  $text = preg_replace_callback('/```([\s\S]*?)```/m', function($m){
    return '<pre><code>'.htmlspecialchars($m[1], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</code></pre>';
  }, $text);
  // Inline-Code `code`
  $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
  // Links [txt](url)
  $text = preg_replace('/\[(.+?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);
  // Absätze
  $parts = preg_split("/\R{2,}/", trim($text));
  $parts = array_map(fn($p)=> preg_match('/^<h[1-3]>/', $p) || str_starts_with($p,'<pre>') ? $p : '<p>'.$p.'</p>', $parts);
  return implode("\n", $parts);
}

// Header einbinden (belässt deine bestehende Navi!)
require_once __DIR__ . '/../includes/header.php';
?>
<div class="docs" style="display:grid;grid-template-columns:260px 1fr;gap:16px">
  <aside style="background:#fff;border-radius:12px;padding:12px;box-shadow:0 2px 8px rgba(0,0,0,.05)">
    <h3 style="margin:6px 0 12px">📚 Dokumente</h3>
    <?php if (!$files): ?>
      <div>Keine Dateien in <code>/docs</code>.</div>
    <?php else: foreach ($files as $f): ?>
      <div>
        <a href="?f=<?= e($f) ?>" style="display:block;padding:6px 8px;border-radius:8px;
           <?= $f===$selected ? 'background:#eef6ff;font-weight:600;' : '' ?>">
          <?= e($f) ?>
        </a>
      </div>
    <?php endforeach; endif; ?>
  </aside>

  <section style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.05)">
    <?php if ($path): 
      $raw = file_get_contents($path) ?: '';
      $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      echo '<div style="margin-bottom:8px;color:#666">Pfad: <code>'.e(basename($path)).'</code></div>';
      if (in_array($ext, ['md','markdown'])) {
        echo mini_md($raw);
      } elseif (in_array($ext, ['yml','yaml'])) {
        echo '<pre style="white-space:pre-wrap"><code>'.e($raw).'</code></pre>';
      } else {
        echo '<pre style="white-space:pre-wrap">'.e($raw).'</pre>';
      }
    else: ?>
      <p>Bitte links eine Datei auswählen.</p>
    <?php endif; ?>
  </section>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
