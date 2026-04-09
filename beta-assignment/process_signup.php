<?php
require_once __DIR__ . '/config/database.php';

$email = trim($_POST['email']);
$password = $_POST['password'];
$confirm_password = $_POST['confirm_password'];

if (empty($email) || empty($password) || empty($confirm_password)) {
    header("Location: signup.php?error=All fields are required");
    exit();
}

if ($password !== $confirm_password) {
    header("Location: signup.php?error=Passwords do not match");
    exit();
}

if (strlen($password) < 6) {
    header("Location: signup.php?error=Password must be at least 6 characters");
    exit();
}

//determine role from email domain
if (str_contains($email, "@student.com")) {
    $role = "student";
} elseif (str_contains($email, "@admin.com")) {
    $role = "admin";
} elseif (str_contains($email, "@merchant.com")) {
    $role = "merchant";
} else {
    header("Location: signup.php?error=Invalid email domain! Use @student.com, @admin.com, or @merchant.com");
    exit();
}

//connect to database
$database = new Database();
$db = $database->getConnection();

//check if email already exists
$checkQuery = "SELECT id FROM users WHERE email = :email";
$checkStmt = $db->prepare($checkQuery);
$checkStmt->bindParam(':email', $email);
$checkStmt->execute();

if ($checkStmt->rowCount() > 0) {
    header("Location: signup.php?error=Email already registered!");
    exit();
}

//hash password and insert user
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$insertQuery = "INSERT INTO users (email, password, role) VALUES (:email, :password, :role)";
$insertStmt = $db->prepare($insertQuery);
$insertStmt->bindParam(':email', $email);
$insertStmt->bindParam(':password', $hashedPassword);
$insertStmt->bindParam(':role', $role);

if ($insertStmt->execute()) {
    header("Location: signup.php?success=1");
} else {
    header("Location: signup.php?error=Registration failed");
}
exit();
?>