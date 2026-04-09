<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$user_id = (int) $_SESSION['user_id'];
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if ($current === '' || $new === '' || $confirm === '') {
        $err = 'Please fill all password fields.';
    } elseif (strlen($new) < 6) {
        $err = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $err = 'New password and confirmation do not match.';
    } else {
        $stmt = $db->prepare("SELECT password FROM users WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password'])) {
            $err = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password = :pw WHERE id = :id");
            $up->bindValue(':pw', $hash);
            $up->bindParam(':id', $user_id, PDO::PARAM_INT);
            $up->execute();
            $msg = 'Password updated successfully.';
        }
    }
}

$stmt = $db->prepare("SELECT email FROM users WHERE id = :id");
$stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$email = $user['email'] ?? $_SESSION['email'];
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
$current_page = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile · GigFood TARUMT</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <link rel="stylesheet" href="/beta-assignment/assets/merchant.css">
</head>
<body class="with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
    <?php include __DIR__ . '/sidebar_merchant.php'; ?>
    <div class="main-with-sidebar">
        <div class="header">
            <div><h2>Profile</h2><p class="sub">Manage your account</p></div>
            <a href="/beta-assignment/logout.php"><button class="logout-btn">Logout</button></a>
        </div>
        <p style="margin-bottom: 16px;"><a href="/beta-assignment/merchant page/merchant.php" class="back-to-main">← Back to Menu</a></p>
        <div class="intro-section">
            <p><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
            <hr style="margin: 20px 0;">
            <h3>Change password</h3>
            <?php if ($msg): ?><p class="success-msg"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
            <?php if ($err): ?><p class="error-msg"><?php echo htmlspecialchars($err); ?></p><?php endif; ?>
            <form method="POST" style="max-width: 400px;">
                <input type="hidden" name="change_password" value="1">
                <label>Current password <input type="password" name="current_password" required></label>
                <label>New password <input type="password" name="new_password" minlength="6" required></label>
                <label>Confirm new password <input type="password" name="confirm_password" required></label>
                <button type="submit" class="btn-update-cart">Update password</button>
            </form>
        </div>
    </div>
</body>
</html>
