<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$merchant_id = $_SESSION['user_id'];

$job_id = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
if ($job_id <= 0) {
    header("Location: jobPosting.php");
    exit();
}

//verify job belongs to merchant and fetch job title
$jobStmt = $db->prepare("SELECT id, title FROM job_postings WHERE id = :id AND merchant_id = :mid");
$jobStmt->bindParam(':id', $job_id, PDO::PARAM_INT);
$jobStmt->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
$jobStmt->execute();
$job = $jobStmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    header("Location: jobPosting.php");
    exit();
}

//handle approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $app_id = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $reject_reason = isset($_POST['rejection_reason']) ? trim((string) $_POST['rejection_reason']) : '';
    if ($app_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        $new_status = $action === 'approve' ? 'accepted' : 'rejected';
        $student_id = null;
        if ($action === 'reject') {
            $getApp = $db->prepare("SELECT student_id FROM job_applications WHERE id = ? AND job_id = ?");
            $getApp->execute([$app_id, $job_id]);
            $appRow = $getApp->fetch(PDO::FETCH_ASSOC);
            if ($appRow)
                $student_id = (int) $appRow['student_id'];
        }
        $up = $db->prepare("UPDATE job_applications SET job_application_status = :status WHERE id = :id AND job_id = :job_id");
        $up->bindValue(':status', $new_status);
        $up->bindParam(':id', $app_id, PDO::PARAM_INT);
        $up->bindParam(':job_id', $job_id, PDO::PARAM_INT);
        $up->execute();

        if ($reject_reason !== '') {
            $db->prepare("UPDATE job_applications SET rejection_reason = ? WHERE id = ? AND job_id = ?")->execute([$reject_reason, $app_id, $job_id]);
        }
        header("Location: jobApplications.php?job_id=" . $job_id . "&updated=1");
        exit();
    }
}

//fetch applications for this job
$appQuery = "SELECT a.id, a.student_id, a.shifts_applied, a.applied_at, a.job_application_status,
             u.email as student_email
             FROM job_applications a
             JOIN users u ON a.student_id = u.id
             WHERE a.job_id = :job_id
             ORDER BY a.applied_at DESC";
$appStmt = $db->prepare($appQuery);
$appStmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$appStmt->execute();
$applications = $appStmt->fetchAll(PDO::FETCH_ASSOC);
$current_page = 'jobs';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · View Applications</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <link rel="stylesheet" href="/beta-assignment/assets/merchant.css">
</head>

<body class="job-applications-page with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
    <?php include __DIR__ . '/sidebar_merchant.php'; ?>
    <div class="main-with-sidebar">
        <div class="small-screen">
            <div class="view-app-header">
                <div>
                    <h2>View Applications</h2>
                    <p class="sub"><?php echo htmlspecialchars($job['title']); ?></p>
                </div>
                <a href="jobPosting.php" class="back-link">← Back to Job Postings</a>
            </div>
            <div class="view-app-body">
                <?php if (isset($_GET['updated'])): ?>
                    <div class="success-msg">✓ Application updated.</div>
                <?php endif; ?>
                <?php if (empty($applications)): ?>
                    <div class="empty-msg">No applications for this job yet.</div>
                <?php else: ?>
                    <?php foreach ($applications as $app):
                        $status = isset($app['job_application_status']) && $app['job_application_status'] !== '' ? $app['job_application_status'] : (isset($app['status']) && $app['status'] !== '' ? $app['status'] : 'pending');
                        $decided = in_array($status, ['accepted', 'rejected'], true);
                        $shifts_display = $app['shifts_applied'] ?? '';
                        if (is_string($shifts_display)) {
                            $dec = @json_decode($shifts_display, true);
                            if (is_array($dec)) {
                                $shifts_display = implode(', ', $dec);
                            }
                        }
                        ?>
                        <div class="app-row">
                            <div class="email"><?php echo htmlspecialchars($app['student_email']); ?></div>
                            <?php if ($shifts_display !== ''): ?>
                                <div class="shifts">Shifts: <?php echo htmlspecialchars($shifts_display); ?></div>
                            <?php endif; ?>
                            <div class="date">Applied
                                <?php echo isset($app['applied_at']) && $app['applied_at'] ? date('M j, Y', strtotime($app['applied_at'])) : '—'; ?>
                            </div>
                            <?php if ($decided): ?>
                                <div class="app-actions">
                                    <span
                                        class="status-badge status-<?php echo $status; ?>"><?php echo $status === 'accepted' ? 'Approved' : 'Rejected'; ?></span>
                                </div>
                            <?php else: ?>
                                <form method="POST" action="" class="app-actions">
                                    <input type="hidden" name="application_id" value="<?php echo (int) $app['id']; ?>">
                                    <div style="margin-bottom: 8px;">
                                        <label style="font-size: 0.9rem; color: #64748b;">Reason for rejection (optional; student
                                            will see in Mailbox):</label>
                                        <textarea name="rejection_reason"
                                            placeholder="e.g. We need someone with weekend availability"
                                            style="width: 100%; min-height: 60px; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; margin-top: 4px;"></textarea>
                                    </div>
                                    <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
                                    <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>

</html>