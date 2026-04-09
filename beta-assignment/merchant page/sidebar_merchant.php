<?php
if (!isset($current_page)) $current_page = '';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>
<aside class="app-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>" id="appSidebar">
    <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">‹</button>
    <div class="sidebar-inner">
        <div class="sidebar-brand">
            <a href="/beta-assignment/merchant page/merchant.php">
                <img src="/beta-assignment/uploads/menu/logo.png" alt="Logo" class="sidebar-logo">
                <span>GigFood TARUMT</span>
            </a>
        </div>
        <nav class="sidebar-nav">
            <a href="profile.php" class="<?php echo $current_page === 'profile' ? 'active' : ''; ?>">Profile</a>
            <a href="settings.php" class="<?php echo $current_page === 'settings' ? 'active' : ''; ?>">Settings</a>
        </nav>
        <div class="sidebar-footer">
            <a href="/beta-assignment/logout.php" class="sidebar-link">Logout</a>
        </div>
    </div>
</aside>
<script>
(function() {
    var sb = document.getElementById('appSidebar');
    var btn = document.getElementById('sidebarToggle');
    if (sb && btn) {
        var hidden = localStorage.getItem('sidebar-merchant-hidden') === '1';
        if (hidden) document.body.classList.add('sidebar-collapsed');
        btn.addEventListener('click', function() {
            document.body.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebar-merchant-hidden', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
            btn.textContent = document.body.classList.contains('sidebar-collapsed') ? '›' : '‹';
        });
        if (hidden) btn.textContent = '›';
    }
})();
</script>
