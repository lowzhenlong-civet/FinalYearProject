<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$student_id = $_SESSION['user_id'];

//get student applications
$query = "SELECT a.*, j.title, j.type, j.wage, j.location, 
          u.email as merchant_email,
          (SELECT GROUP_CONCAT(shift_description SEPARATOR '|') FROM job_shifts WHERE job_id = j.id) as all_shifts
          FROM job_applications a
          JOIN job_postings j ON a.job_id = j.id
          JOIN users u ON j.merchant_id = u.id
          WHERE a.student_id = :student_id
          ORDER BY a.applied_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$current_page = 'applications';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · My Applications</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
</head>
<body class="with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
    <?php include __DIR__ . '/sidebar_student.php'; ?>
    <div class="main-with-sidebar">
    <div class="header">
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="/beta-assignment/student page/userOrder.php" style="text-decoration:none;color:inherit;">
                <div class="brand">
                    <img src="/beta-assignment/uploads/menu/logo.png" alt="GigFood logo">
                    <span class="brand-name">GigFood TARUMT Platform</span>
                </div>
            </a>
            <div style="display:flex;flex-direction:column;">
                <h3 style="margin:0;font-size:1.5rem;color:#00008B;">
                    Welcome back, <?php echo htmlspecialchars($_SESSION['email']); ?>!
                </h3>
            </div>
        </div>
        <a href="/beta-assignment/logout.php">
            <button class="logout-btn">Logout</button>
        </a>
    </div>

    <div class="tabs">
        <a href="userOrder.php" class="tab">Food Orders</a>
        <a href="jobSearch.php" class="tab">Job Search</a>
        <a href="orderHistory.php" class="tab">Order History</a>
        <a href="myApplications.php" class="tab active">My Applications</a>
        <a href="activityHistory.php" class="tab" id="nav-activity">Activity History</a>
    </div>

    <div class="intro-section">
        <h3>My Applications</h3>
        <p>Track your job applications</p>
    </div>

    <div class="category-title">
        <h4>My Applications</h4>
    </div>

    <div id="applicationContainer">
        <?php if (empty($applications)): ?>
            <div style="background:white; border-radius:32px; padding:40px; text-align:center; color:#5f779b;">
                 No job applications yet. <a href="jobSearch.php">Browse jobs</a>
            </div>
        <?php else: ?>
            <?php foreach ($applications as $app): 
                $shifts = json_decode($app['shifts_applied'], true) ?? [];
                $all_shifts = explode('|', $app['all_shifts'] ?? '');
                
                $status_class = 'badge-pending';
                $message_class = 'message-pending';
                $status_text = 'Pending Review';
                $status_message = 'Your application is being reviewed by the merchant.';
                
                $raw_status = $app['job_application_status'] ?? $app['status'] ?? '';
                if ($raw_status == 'accepted') {
                    $status_class = 'badge-accepted';
                    $message_class = 'message-accepted';
                    $status_text = 'Accepted';
                    $status_message = 'Congratulations! Your application has been accepted. The merchant will contact you.';
                } elseif ($raw_status == 'rejected') {
                    $status_class = 'badge-rejected';
                    $message_class = 'message-rejected';
                    $status_text = 'Not Selected';
                    $status_message = 'Thank you for your interest. This position has been filled.';
                }
            ?>
            <div class="app-card">
                <div class="job-title-row">
                    <span class="job-title"><?php echo htmlspecialchars($app['title']); ?></span>
                    <span class="app-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                </div>

                <div class="merchant-line">
                    <span class="merchant"><?php echo htmlspecialchars( $app['merchant_email']); ?></span>
                    <span class="applied-date">Applied on <?php echo date('m/d/Y', strtotime($app['applied_at'])); ?></span>
                </div>

                <div class="shifts-section">
                    <div class="shifts-title">Your Selected Shifts:</div>
                    <div class="shift-list">
                        <?php if (!empty($shifts)): ?>
                            <?php foreach ($shifts as $shift): ?>
                                <span class="shift-item"><?php echo htmlspecialchars($shift); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="shift-item">No specific shifts selected</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="status-message <?php echo $message_class; ?>">
                    <span style="flex:1"><?php echo $status_message; ?></span>
                </div>
                
                <?php
                $reject_reason = trim((string)($app['rejection_reason'] ?? ''));
                if ($raw_status === 'rejected' && $reject_reason !== ''):
                ?>
                <div style="margin-top: 10px; padding: 10px; background: #f8fafc; border-radius: 12px;">
                    <strong>Reason from merchant:</strong> <?php echo htmlspecialchars($reject_reason); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    </div>
</body>
</html>