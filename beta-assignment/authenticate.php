<?php
session_start();
require_once __DIR__ . '/config/database.php';

$email = trim($_POST['email']);
$password = $_POST['password'];

if (empty($email) || empty($password)) {
    header("Location: login.php?error=Email and password required");
    exit();
}

//validate email role(student, admin, merchant)
if (str_contains($email, "@student.com")) {
    $role = "student";
    $redirect = "/beta-assignment/student page/userOrder.php";
} elseif (str_contains($email, "@admin.com")) {
    $role = "admin";
    $redirect = "/beta-assignment/admin page/adminPage.php";
} elseif (str_contains($email, "@merchant.com")) {
    $role = "merchant";
    $redirect = "/beta-assignment/merchant page/merchant.php";
} else {
    header("Location: login.php?error=Invalid email domain! Use @student.com, @admin.com, or @merchant.com");
    exit();
}

//connect to database
$database = new Database();
$db = $database->getConnection();


//query user
$query = "SELECT id, email, password, role FROM users WHERE email = :email";
$stmt = $db->prepare($query);
$stmt->bindParam(':email', $email);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (password_verify($password, $user['password'])) {
        //check if role matches domain (security check)
        if ($user['role'] !== $role) {
            header("Location: login.php?error=Account type mismatch");
            exit();
        }
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        // Load theme preference
        try {
            $t = $db->prepare("SELECT theme_preference FROM users WHERE id = ?");
            $t->execute([$user['id']]);
            $tr = $t->fetch(PDO::FETCH_ASSOC);
            $tp = $tr['theme_preference'] ?? 'light';
            $_SESSION['theme'] = ($tp === 'dark') ? 'dark' : 'light';
        } catch (Exception $e) {
            $_SESSION['theme'] = 'light';
        }
        header("Location: $redirect");
        exit();
    }
}

//if authentication failed
header("Location: login.php?error=Invalid email or password!");
exit();
?>