<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/header.php';
?>
<main class="container" style="padding:1rem">
  <h1 id="st-heading">SmartTable Demo</h1>

  <!-- Toolbar: mit Labels & Accessibility -->
  <form id="st-toolbar"
        class="toolbar"
        role="search"
        aria-label="Tabellenwerkzeuge"
        style="display:flex;gap:.5rem;align-items:center;margin:.5rem 0;">

    <label for="st-search">Suche</label>
    <input
      id="st-search"
      type="search"
      name="q"
      placeholder="Suche…"
      aria-label="Suche"
      title="Suche"
      style="max-width:260px;padding:.4rem .6rem;">

    <label for="st-pagesize">Zeilen pro Seite</label>
    <select
      id="st-pagesize"
      name="page_size"
      aria-label="Zeilen pro Seite"
      title="Zeilen pro Seite"
      style="padding:.3rem .5rem;">
      <option value="10">10</option>
      <option value="20" selected>20</option>
      <option value="50">50</option>
      <option value="100">100</option>
    </select>
  </form>

  <!-- Tabelle: live-updates für Screenreader -->
  <div id="st-table" class="smarttable" aria-live="polite" aria-describedby="st-heading"></div>

  <!-- Pagination: semantisch als Navigation -->
  <nav id="st-pagination"
       class="pagination"
       aria-label="Tabellenpaginierung"
       style="display:flex;gap:.5rem;align-items:center;margin-top:.75rem;">
    <!-- wird via JS gefüllt -->
  </nav>
</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
