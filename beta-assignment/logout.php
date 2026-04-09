<?php
session_start();

//clear all session data
session_unset();     //remove all session variables
session_destroy();   //destroy the session itself

//clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

//back to login page
header("Location: /beta-assignment/login.php");
exit();
?>