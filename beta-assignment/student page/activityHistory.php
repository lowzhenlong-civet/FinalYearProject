<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$db = (new Database())->getConnection();

//orders summary for this student
$ordersStmt = $db->prepare("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.ordered_at,
           u.email AS merchant_email,
           m.name AS item_name, oi.quantity
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN menu_items m ON m.id = oi.menu_item_id
    LEFT JOIN users u ON u.id = o.merchant_id
    WHERE o.student_id = :sid
    ORDER BY o.ordered_at DESC, oi.id ASC
");
$ordersStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
$ordersStmt->execute();
$orderRows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$orders = [];
foreach ($orderRows as $row) {
    $id = $row['id'];
    if (!isset($orders[$id])) {
        $orders[$id] = [
            'id' => $id,
            'order_number' => $row['order_number'],
            'status' => $row['status'],
            'total' => $row['total_amount'],
            'ordered_at' => $row['ordered_at'],
            'merchant_email' => $row['merchant_email'] ?? 'Merchant',
            'items' => []
        ];
    }
    $orders[$id]['items'][] = [
        'name' => $row['item_name'],
        'quantity' => $row['quantity'],
    ];
}

//job applications for this student
$jobStmt = $db->prepare("
    SELECT a.applied_at, a.job_application_status, a.status,
           j.title AS job_title, j.wage
    FROM job_applications a
    JOIN job_postings j ON a.job_id = j.id
    WHERE a.student_id = :sid
    ORDER BY a.applied_at DESC
");
$jobStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
$jobStmt->execute();
$jobApplications = $jobStmt->fetchAll(PDO::FETCH_ASSOC);

//build combined application history report rows
$activityHistory = [];

foreach ($orders as $order) {
    $firstItem = $order['items'][0]['name'] ?? 'Food order';
    $itemCount = count($order['items']);
    $detail = $firstItem;
    if ($itemCount > 1) {
        $detail .= " +" . ($itemCount - 1) . " more";
    }

    $activityHistory[] = [
        'date' => $order['ordered_at'],
        'activity_type' => 'Food Order',
        'detail' => $detail,
        'status' => ucfirst($order['status']),
        'amount' => 'RM ' . number_format((float)$order['total'], 2),
    ];
}

foreach ($jobApplications as $app) {
    $statusRaw = $app['job_application_status'] ?? $app['status'] ?? 'pending';
    if ($statusRaw === '' || $statusRaw === null) {
        $statusRaw = 'pending';
    }
    $statusLabel = ucfirst(
        $statusRaw === 'accepted' ? 'Approved' :
        ($statusRaw === 'rejected' ? 'Rejected' : 'Pending')
    );

    $wage = trim((string)($app['wage'] ?? ''));
    if ($wage === '') {
        $amountLabel = '—';
    } elseif (is_numeric($wage)) {
        $amountLabel = 'RM ' . number_format((float)$wage, 2) . '/hr';
    } else {
        $amountLabel = $wage;
    }

    $activityHistory[] = [
        'date' => $app['applied_at'] ?: date('Y-m-d H:i:s'),
        'activity_type' => 'Job Application',
        'detail' => $app['job_title'] ?? 'Job',
        'status' => $statusLabel,
        'amount' => $amountLabel,
    ];
}

usort($activityHistory, function ($a, $b) {
    return strcmp($b['date'], $a['date']);
});
$current_page = 'activity';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Activity History</title>
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
                    Welcome back, <?php echo htmlspecialchars($_SESSION['email'] ?? 'Student'); ?>!
                </h3>
            </div>
        </div>
        <a href="/beta-assignment/logout.php">
            <button class="logout-btn">Logout</button>
        </a>
    </div>

    <div class="tabs">
        <a href="userOrder.php" class="tab" id="nav-orders">Food Orders</a>
        <a href="jobSearch.php" class="tab" id="nav-jobs">Job Search</a>
        <a href="orderHistory.php" class="tab" id="nav-history">Order History</a>
        <a href="myApplications.php" class="tab" id="nav-applications">My Applications</a>
        <a href="activityHistory.php" class="tab active" id="nav-activity">Activity History</a>
    </div>

    <div class="intro-section">
        <h3>Application History Report</h3>
        <p>Personal record of your food orders and job applications, with status and spending.</p>
        <input type="text" class="search-box" id="searchInput" placeholder="Search by date, activity type or detail...">
    </div>

    <div class="category-title">
        <h4>Activity History</h4>
    </div>

    <div style="background:white;border-radius:24px;padding:20px 24px;box-shadow:0 4px 12px rgba(0,0,0,0.04);border:1px solid #e2e8f0;overflow-x:auto;">
        <table id="activityTable" style="width:100%;border-collapse:collapse;min-width:520px;font-size:0.92rem;">
            <thead>
                <tr>
                    <th style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;background:#f8fafc;color:#334155;">Date</th>
                    <th style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;background:#f8fafc;color:#334155;">Activity Type</th>
                    <th style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;background:#f8fafc;color:#334155;">Detail</th>
                    <th style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:left;background:#f8fafc;color:#334155;">Status</th>
                    <th style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:right;background:#f8fafc;color:#334155;">Amount / Wage</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activityHistory)): ?>
                <tr>
                    <td colspan="5" style="padding:12px 8px;color:#94a3b8;text-align:center;">
                        No activity yet. Your food orders and job applications will appear here.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($activityHistory as $row): ?>
                <tr data-search-row="<?php echo htmlspecialchars(
                    strtolower(
                        date('d/m/Y', strtotime($row['date'])) . ' ' .
                        $row['activity_type'] . ' ' .
                        $row['detail'] . ' ' .
                        $row['status'] . ' ' .
                        $row['amount']
                    )
                ); ?>">
                    <td style="border-bottom:1px solid #e2e8f0;padding:10px 8px;">
                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($row['date']))); ?>
                    </td>
                    <td style="border-bottom:1px solid #e2e8f0;padding:10px 8px;">
                        <?php echo htmlspecialchars($row['activity_type']); ?>
                    </td>
                    <td style="border-bottom:1px solid #e2e8f0;padding:10px 8px;">
                        <?php echo htmlspecialchars($row['detail']); ?>
                    </td>
                    <td style="border-bottom:1px solid #e2e8f0;padding:10px 8px;">
                        <?php echo htmlspecialchars($row['status']); ?>
                    </td>
                    <td style="border-bottom:1px solid #e2e8f0;padding:10px 8px;text-align:right;">
                        <?php echo htmlspecialchars($row['amount']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const rows = document.querySelectorAll('#activityTable tbody tr[data-search-row]');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase();
                rows.forEach(row => {
                    const haystack = row.getAttribute('data-search-row') || '';
                    row.style.display = !term || haystack.includes(term) ? '' : 'none';
                });
            });
        }
    </script>
    </div>
</body>
</html>

