<?php
session_start();
// If already logged in, redirect based on role
if (isset($_SESSION['email'])) {
    if ($_SESSION['role'] == 'student') {
        header("Location: /beta-assignment/student page/userOrder.php");
    } elseif ($_SESSION['role'] == 'admin') {
        header("Location: /beta-assignment/admin page/adminPage.php");
    } elseif ($_SESSION['role'] == 'merchant') {
        header("Location: /beta-assignment/merchant page/merchant.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Login</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <style>
        body {
            max-width: 100%;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: radial-gradient(circle at top left, #e0f2fe 0, #eef2ff 35%, #f5f5f5 90%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-shell {
            width: 100%;
            max-width: 1100px;
            padding: 24px;
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            gap: 32px;
            align-items: center;
        }
        @media (max-width: 900px) {
            .login-shell {
                grid-template-columns: minmax(0, 1fr);
                padding: 18px;
            }
        }
        .login-hero {
            color: #0f172a;
        }
        .login-hero h1 {
            font-size: 2.1rem;
            margin-bottom: 12px;
            letter-spacing: 0.03em;
        }
        .login-hero p {
            color: #64748b;
            margin-bottom: 18px;
            font-size: 0.98rem;
        }
        .login-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .login-pill {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .pill-student { background:#e0f2fe; color:#1d4ed8; }
        .pill-merchant { background:#ecfdf3; color:#15803d; }
        .pill-admin { background:#fef3c7; color:#b45309; }
        .login-card {
            max-width: 420px;
            margin-left: auto;
            background: #ffffff;
            padding: 28px 26px 26px;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(15,23,42,0.15);
            border: 1px solid #e2e8f0;
        }
        .login-card h2 {
            margin-bottom: 8px;
            color: #0f172a;
            font-size: 1.5rem;
        }
        .login-card small {
            color: #64748b;
            display: block;
            margin-bottom: 18px;
        }
        .login-card label {
            display:block;
            font-size:0.85rem;
            font-weight:600;
            color:#475569;
            margin-bottom:4px;
        }
        .login-card input {
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            background:#f9fafb;
        }
        .login-card input:focus {
            outline:none;
            border-color:#0f172a;
            background:#ffffff;
        }
        .login-card button {
            background: #0f172a;
            color: white;
            padding: 11px 30px;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            width: 100%;
            font-size: 0.98rem;
            font-weight:600;
            box-shadow:0 10px 25px rgba(15,23,42,0.35);
        }
        .login-card button:hover {
            background: #111827;
        }
        .error {
            color: #b91c1c;
            margin-bottom: 10px;
            font-size:0.9rem;
        }
        .login-footer {
            margin-top:14px;
            font-size:0.88rem;
            color:#64748b;
            text-align:center;
        }
        .login-footer a {
            color:#0f172a;
            font-weight:600;
            text-decoration:none;
        }
        .login-footer a:hover {
            text-decoration:underline;
        }
    </style>
</head>

<body>
    <div style="position:fixed;top:16px;left:24px;z-index:10;">
        <a href="/beta-assignment/login.php" style="text-decoration:none;color:inherit;">
            <div class="brand">
                <img src="/beta-assignment/uploads/menu/logo.png" alt="GigFood logo">
                <span class="brand-name">GigFood</span>
            </div>
        </a>
    </div>
    <div class="login-shell">
        <div class="login-hero">
            <div class="login-pills">
                <span class="login-pill pill-student">Students</span>
                <span class="login-pill pill-merchant">Merchants</span>
                <span class="login-pill pill-admin">Admin</span>
            </div>
            <h1>GigFood TARUMT Platform</h1>
            <p>Order food, discover flexible part-time jobs, and manage stalls across the TARUMT campus in one place.</p>
        </div>

        <div class="login-card">
            <h2>Welcome back</h2>
            <small>Sign in with your TARUMT account</small>

            <?php
            if (isset($_GET['error'])) {
                echo '<div class="error">' . htmlspecialchars($_GET['error']) . '</div>';
            }
            if (isset($_GET['registered'])) {
                echo '<div style="color: #059669; margin-bottom: 10px; font-size:0.9rem;">Registration successful! Please login.</div>';
            }
            ?>

            <form action="authenticate.php" method="POST">
                <label>Email</label>
                <input type="email" name="email" required placeholder="you@student.com">

                <label>Password</label>
                <input type="password" name="password" required placeholder="••••••••">

                <button type="submit">Login</button>
            </form>

            <div class="login-footer">
                <p>No account? <a href="signUp.php">Sign up</a></p>
                <p>Use emails ending with <strong>@student.com</strong>, <strong>@admin.com</strong> or <strong>@merchant.com</strong>.</p>
            </div>
        </div>
    </div>
</body>

</html>