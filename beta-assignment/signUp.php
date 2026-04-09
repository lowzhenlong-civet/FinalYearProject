<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Sign Up</title>
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
        .signup-shell {
            width: 100%;
            max-width: 1100px;
            padding: 24px;
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            gap: 32px;
            align-items: center;
        }
        @media (max-width: 900px) {
            .signup-shell {
                grid-template-columns: minmax(0, 1fr);
                padding: 18px;
            }
        }
        .signup-hero {
            color: #0f172a;
        }
        .signup-hero h1 {
            font-size: 2.05rem;
            margin-bottom: 12px;
            letter-spacing: 0.03em;
        }
        .signup-hero p {
            color: #64748b;
            margin-bottom: 18px;
            font-size: 0.98rem;
        }
        .signup-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
        }
        .signup-pill {
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
        .signup-card {
            max-width: 420px;
            margin-left: auto;
            background: #ffffff;
            padding: 28px 26px 26px;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(15,23,42,0.15);
            border: 1px solid #e2e8f0;
        }
        .signup-card h2 {
            margin-bottom: 8px;
            color: #0f172a;
            font-size: 1.5rem;
        }
        .signup-card small {
            color: #64748b;
            display: block;
            margin-bottom: 18px;
        }
        .signup-card label {
            display:block;
            font-size:0.85rem;
            font-weight:600;
            color:#475569;
            margin-bottom:4px;
        }
        .signup-card input {
            width: 100%;
            padding: 11px 12px;
            margin-bottom: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            background:#f9fafb;
        }
        .signup-card input:focus {
            outline:none;
            border-color:#0f172a;
            background:#ffffff;
        }
        .signup-card button {
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
        .signup-card button:hover {
            background: #111827;
        }
        .message {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size:0.9rem;
        }
        .error {
            background: #fee2e2;
            color: #b91c1c;
        }
        .success {
            background: #dcfce7;
            color: #059669;
        }
        .signup-footer {
            margin-top:14px;
            font-size:0.88rem;
            color:#64748b;
            text-align:center;
        }
        .signup-footer a {
            color:#0f172a;
            font-weight:600;
            text-decoration:none;
        }
        .signup-footer a:hover {
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
    <div class="signup-shell">
        <div class="signup-hero">
            <div class="signup-pills">
                <span class="signup-pill pill-student">Students</span>
                <span class="signup-pill pill-merchant">Merchants</span>
                <span class="signup-pill pill-admin">Admin</span>
            </div>
            <h1>Create your GigFood TARUMT account</h1>
            <p>Register as a student, merchant or admin, then start ordering food or posting jobs in the TARUMT campus canteen.</p>
        </div>

        <div class="signup-card">
        <h2>Create account</h2>
        <small>Fill in your details to get started</small>
        
        <?php
        if (isset($_GET['error'])) {
            echo '<div class="message error">' . htmlspecialchars($_GET['error']) . '</div>';
        }
        if (isset($_GET['success'])) {
            echo '<div class="message success">Registration successful! <a href="login.php">Login here</a></div>';
        }
        ?>
        
        <form action="process_signup.php" method="POST">
            <label>Email</label>
            <input type="email" name="email" required placeholder="you@student.com">
            
            <label>Password</label>
            <input type="password" name="password" required placeholder="Create a strong password">
            
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required placeholder="Re-enter your password">
            
            <button type="submit">Sign up</button>
        </form>
        
        <div class="signup-footer">
            <p>Already have an account? <a href="login.php">Login</a></p>
            <p>Use emails ending with <strong>@student.com</strong>, <strong>@admin.com</strong> or <strong>@merchant.com</strong>.</p>
        </div>
    </div>
    </div>
</body>
</html>