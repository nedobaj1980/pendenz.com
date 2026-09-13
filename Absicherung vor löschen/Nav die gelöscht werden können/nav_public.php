<nav style="background:#007bff; padding:12px 20px; border-radius:6px; display:flex; align-items:center; flex-wrap:wrap;">
    <a href="index.php" style="color:#fff; margin-right:20px; text-decoration:none; font-weight:bold;">Dashboard</a>
    <a href="projekte.php" style="color:#fff; margin-right:20px; text-decoration:none; font-weight:bold;">Projekte</a>
    <a href="pendenzen.php" style="color:#fff; margin-right:20px; text-decoration:none; font-weight:bold;">Pendenzen</a>
    <a href="idee.php" style="color:#fff; margin-right:20px; text-decoration:none; font-weight:bold;">Idee</a>
    <a href="kontakt.php" style="color:#fff; margin-right:20px; text-decoration:none; font-weight:bold;">Kontakt</a>
    
    <?php if(isset($_SESSION['user_id'])): ?>
        <a href="logout.php" style="color:#fff; margin-left:auto; text-decoration:none; font-weight:bold;">Logout</a>
    <?php else: ?>
        <a href="login.php" style="color:#fff; margin-left:auto; text-decoration:none; font-weight:bold;">Login</a>
    <?php endif; ?>
</nav>
