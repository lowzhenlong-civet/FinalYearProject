<?php
session_start();

//if not logged in, back to login page
if (!isset($_SESSION['email'])) {
    header("Location: /beta-assignment/login.php");
    exit();
}

//role check
function isStudent()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

function isAdmin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isMerchant()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'merchant';
}
?>