<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $theme = in_array($_POST['theme'], ['light', 'dark'], true) ? $_POST['theme'] : 'light';
    try {
        $stmt = $db->prepare("UPDATE users SET theme_preference = :t WHERE id = :id");
        $stmt->bindValue(':t', $theme);
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
    } catch (Exception $e) { /* column may not exist */ }
    $_SESSION['theme'] = $theme;
    header("Location: settings.php?saved=1");
    exit();
}

$row = $db->prepare("SELECT theme_preference FROM users WHERE id = :id");
$row->bindParam(':id', $user_id, PDO::PARAM_INT);
$row->execute();
$pref = $row->fetch(PDO::FETCH_ASSOC);
$theme_pref = $pref['theme_preference'] ?? 'light';
if ($theme_pref === 'system') $theme_pref = 'light';
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = $theme_pref === 'dark' ? 'dark' : 'light';
}
$theme = $_SESSION['theme'];
$current_page = 'settings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings · GigFood TARUMT</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <link rel="stylesheet" href="/beta-assignment/assets/merchant.css">
</head>
<body class="with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
    <?php include __DIR__ . '/sidebar_merchant.php'; ?>
    <div class="main-with-sidebar">
        <div class="header">
            <div><h2>Settings</h2><p class="sub">Appearance</p></div>
            <a href="/beta-assignment/logout.php"><button class="logout-btn">Logout</button></a>
        </div>
        <p style="margin-bottom: 16px;"><a href="/beta-assignment/merchant page/merchant.php" class="back-to-main">← Back to Menu</a></p>
        <div class="intro-section">
            <?php if (isset($_GET['saved'])): ?><p class="success-msg">Settings saved.</p><?php endif; ?>
            <h3>Dark mode</h3>
            <form method="POST">
                <label><input type="radio" name="theme" value="light" <?php echo $theme_pref === 'light' ? 'checked' : ''; ?>> Light</label>
                <label><input type="radio" name="theme" value="dark" <?php echo $theme_pref === 'dark' ? 'checked' : ''; ?>> Dark</label>
                <button type="submit" class="btn-update-cart">Save</button>
            </form>
        </div>
    </div>
</body>
</html>
