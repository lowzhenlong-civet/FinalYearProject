<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();

function formatDateDMY($value)
{
    if (empty($value)) return '—';
    $ts = strtotime((string) $value);
    if ($ts === false) return htmlspecialchars((string) $value);
    return date('d-m-Y', $ts);
}

function formatDateTimeDMY($value)
{
    if (empty($value)) return '—';
    $ts = strtotime((string) $value);
    if ($ts === false) return htmlspecialchars((string) $value);
    return date('d-m-Y H:i', $ts);
}

//handle job posting approve or reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['job_action'], $_POST['job_id'])) {
    $job_id = (int) $_POST['job_id'];
    $action = $_POST['job_action'];
    $reason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';
    // Validate input
    if ($job_id > 0 && in_array($action, ['approve', 'reject'])) {
        // Determine new status
        $new_status = ($action === 'approve') ? 'active' : 'rejected';
        // Update job status
        $stmt = $db->prepare("
            UPDATE job_postings 
            SET job_post_status = :status 
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $new_status,
            ':id' => $job_id
        ]);
        // Handle rejection reason
        if ($action === 'reject' && $reason !== '') {
            $stmt = $db->prepare("
                UPDATE job_postings 
                SET rejection_reason = :reason 
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason' => $reason,
                ':id' => $job_id
            ]);
        }
        // Clear rejection reason if approved
        if ($action === 'approve') {
            $stmt = $db->prepare("
                UPDATE job_postings 
                SET rejection_reason = NULL 
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $job_id
            ]);
        }
        // Redirect after success
        header("Location: /beta-assignment/admin page/adminPage.php?updated=1");
        exit();
    }
}

//handle create discount (holiday promo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_discount'])) {
    $name = trim($_POST['discount_name'] ?? '');
    $type = in_array($_POST['discount_type'] ?? '', ['percent', 'fixed']) ? $_POST['discount_type'] : 'percent';
    $value = max(0, floatval($_POST['discount_value'] ?? 0));
    $minOrder = max(0, floatval($_POST['min_order_amount'] ?? 100));
    if ($name !== '' && $value > 0 && ($type !== 'percent' || $value <= 100)) {
        $stmt = $db->prepare("INSERT INTO discount_events (name, discount_type, discount_value, min_order_amount, is_active) VALUES (:name, :type, :value, :min_order, 1)");
        $stmt->execute([':name' => $name, ':type' => $type, ':value' => $value, ':min_order' => $minOrder]);
        header("Location: /beta-assignment/admin page/adminPage.php?discount_created=1");
        exit();
    }
}

//handle close discount event
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_discount'], $_POST['discount_id'])) {
    $did = (int) $_POST['discount_id'];
    if ($did > 0) {
        $stmt = $db->prepare("UPDATE discount_events SET is_active = 0 WHERE id = :id");
        $stmt->bindParam(':id', $did, PDO::PARAM_INT);
        $stmt->execute();
        header("Location: /beta-assignment/admin page/adminPage.php?discount_closed=1");
        exit();
    }
}

//report 1 : sale and demand analytics
$report = isset($_GET['report']) ? (string) $_GET['report'] : '';
$weekOffset = isset($_GET['week']) ? max(0, (int) $_GET['week']) : 0;
$now = new DateTime('now');
$weekEndRange = clone $now;
$weekEndRange->setTime(23, 59, 59);
$weekEndRange->modify('-' . (7 * $weekOffset) . ' days');
$weekStart = clone $now;
$weekStart->setTime(0, 0, 0);
$weekStart->modify('-' . (7 * ($weekOffset + 1)) . ' days');
$dateStart = $weekStart->format('Y-m-d 00:00:00');
$dateEnd = $weekEndRange->format('Y-m-d 23:59:59');
$weekStartDisplay = clone $weekStart;
$weekEndDisplay = clone $weekEndRange;

//common arrays for different reports
$topItems = [];
$demandByDay = [];

//report 2: job fulfillment report
$jobFulfillmentRows = [];

//report 3: congestion report
$congestionSlots = [10, 11, 12, 13, 14]; // 10AM–2PM
$congestionData = [];
$congestionMerchants = [];
$congestionDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : $now->format('Y-m-d');

//report 4: student employment impact report
$impactSummary = [
    'accepted_applications' => 0,
    'unique_students' => 0,
    'total_hourly_wages' => 0.0,
    'estimated_hours' => 0,
];
$impactByMerchant = [];

if ($report === 'sales' || $report === '') {
    try {
        $topStmt = $db->prepare("
            SELECT m.name AS item_name, u.email AS merchant_name, SUM(oi.quantity) AS total_qty, SUM(oi.quantity * oi.price_at_time) AS revenue
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN menu_items m ON m.id = oi.menu_item_id
            JOIN users u ON u.id = m.merchant_id
            WHERE o.ordered_at >= :start AND o.ordered_at <= :end
            GROUP BY oi.menu_item_id
            ORDER BY total_qty DESC
            LIMIT 50
        ");
        $topStmt->execute([':start' => $dateStart, ':end' => $dateEnd]);
        $topItems = $topStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $topItems = [];
    }
    try {
        $dayStmt = $db->prepare("
            SELECT DATE(o.ordered_at) AS order_date, DAYNAME(o.ordered_at) AS day_name, COUNT(*) AS order_count, COALESCE(SUM(o.total_amount), 0) AS revenue
            FROM orders o
            WHERE o.ordered_at >= :start AND o.ordered_at <= :end
            GROUP BY DATE(o.ordered_at)
            ORDER BY order_date
        ");
        $dayStmt->execute([':start' => $dateStart, ':end' => $dateEnd]);
        $demandByDay = $dayStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $demandByDay = [];
    }
} elseif ($report === 'fulfillment') {
    try {
        $jobStmt = $db->query("
            SELECT 
                u.email AS merchant_name,
                j.type AS job_category,
                COUNT(DISTINCT j.id) AS vacancies_posted,
                COUNT(DISTINCT a.id) AS applicants,
                SUM(
                    CASE 
                        WHEN COALESCE(a.job_application_status, a.status, 'pending') = 'accepted' 
                        THEN 1 ELSE 0 
                    END
                ) AS students_hired
            FROM job_postings j
            JOIN users u ON u.id = j.merchant_id
            LEFT JOIN job_applications a ON a.job_id = j.id
            GROUP BY u.email, j.type
            ORDER BY u.email, j.type
        ");
        $jobFulfillmentRows = $jobStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $jobFulfillmentRows = [];
    }
} elseif ($report === 'congestion') {
    try {
        $cStmt = $db->prepare("
            SELECT 
                u.email AS merchant_name,
                HOUR(COALESCE(o.pickup_time, o.ordered_at)) AS hour_slot,
                COUNT(*) AS order_count
            FROM orders o
            JOIN users u ON u.id = o.merchant_id
            WHERE DATE(COALESCE(o.pickup_time, o.ordered_at)) = :d
            GROUP BY u.email, hour_slot
        ");
        $cStmt->execute([':d' => $congestionDate]);
        $rows = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $merchant = $row['merchant_name'];
            $hour = (int) $row['hour_slot'];
            if (!in_array($merchant, $congestionMerchants, true)) {
                $congestionMerchants[] = $merchant;
            }
            if (!isset($congestionData[$merchant])) {
                $congestionData[$merchant] = [];
            }
            $congestionData[$merchant][$hour] = (int) $row['order_count'];
        }
        sort($congestionMerchants);
    } catch (Exception $e) {
        $congestionData = [];
        $congestionMerchants = [];
    }
} elseif ($report === 'impact') {
    try {
        $summaryStmt = $db->query("
            SELECT
                COUNT(*) AS accepted_count,
                COUNT(DISTINCT a.student_id) AS student_count,
                SUM(
                    CASE 
                        WHEN j.wage REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                        THEN CAST(j.wage AS DECIMAL(10,2))
                        ELSE 0
                    END
                ) AS total_hourly_wage
            FROM job_applications a
            JOIN job_postings j ON a.job_id = j.id
            WHERE COALESCE(a.job_application_status, a.status, 'pending') = 'accepted'
        ");
        $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $impactSummary['accepted_applications'] = (int) ($row['accepted_count'] ?? 0);
            $impactSummary['unique_students'] = (int) ($row['student_count'] ?? 0);
            $impactSummary['total_hourly_wages'] = (float) ($row['total_hourly_wage'] ?? 0);
            $impactSummary['estimated_hours'] = $impactSummary['accepted_applications'] * 4;
        }
    } catch (Exception $e) {
        $impactSummary = [
            'accepted_applications' => 0,
            'unique_students' => 0,
            'total_hourly_wages' => 0.0,
            'estimated_hours' => 0,
        ];
    }

    try {
        $byMerchantStmt = $db->query("
            SELECT 
                um.email AS merchant_name,
                COUNT(*) AS accepted_count,
                COUNT(DISTINCT a.student_id) AS student_count,
                SUM(
                    CASE 
                        WHEN j.wage REGEXP '^[0-9]+(\\\\.[0-9]+)?$'
                        THEN CAST(j.wage AS DECIMAL(10,2))
                        ELSE 0
                    END
                ) AS total_hourly_wage
            FROM job_applications a
            JOIN job_postings j ON a.job_id = j.id
            JOIN users um ON um.id = j.merchant_id
            WHERE COALESCE(a.job_application_status, a.status, 'pending') = 'accepted'
            GROUP BY um.id
            ORDER BY total_hourly_wage DESC
        ");
        $impactByMerchant = $byMerchantStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $impactByMerchant = [];
    }
}

//pending job postings for verification
$pendingJobs = [];
try {
    $pendingJobs = $db->query("
        SELECT j.id, j.title, j.description, j.wage, j.location, j.posted_date, j.job_post_status, j.rejection_reason, u.email as merchant_email
        FROM job_postings j
        JOIN users u ON u.id = j.merchant_id
        WHERE (j.job_post_status = 'pending' OR j.job_post_status IS NULL OR TRIM(COALESCE(j.job_post_status, '')) = '')
        ORDER BY j.posted_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $all = $db->query("
        SELECT j.id, j.title, j.description, j.wage, j.location, j.posted_date, u.email as merchant_email
        FROM job_postings j
        JOIN users u ON u.id = j.merchant_id
        ORDER BY j.posted_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $row) {
        $st = isset($row['job_post_status']) ? trim((string) $row['job_post_status']) : (isset($row['status']) ? trim((string) $row['status']) : '');
        if ($st === '' || $st === 'pending') {
            $row['job_post_status'] = 'pending';
            $pendingJobs[] = $row;
        }
    }
}

//discount events (holiday promos)
$discountEvents = [];
try {
    $discountEvents = $db->query("SELECT id, name, discount_type, discount_value, min_order_amount, is_active, created_at FROM discount_events ORDER BY is_active DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $discountEvents = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood Admin Portal</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <style>
        body {
            background: radial-gradient(circle at top left, #e0f2fe 0, #f1f5f9 40%, #e5e7eb 100%);
            padding: 32px 16px;
            min-height: 100vh;
        }

        .page-layout {
            max-width: 1200px;
            margin: 0 auto;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
        }

        .admin-header h1 {
            margin: 0;
            font-size: 1.9rem;
            color: #0f172a;
            letter-spacing: 0.02em;
        }

        .admin-header .sub {
            color: #64748b;
            margin-top: 4px;
            font-size: 0.95rem;
        }

        .logout-btn {
            background: #0f172a;
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: 999px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.25);
        }

        .logout-btn:hover {
            background: #1e293b;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            position: relative;
        }

        .stat-card .label {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .stat-card .value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card .sub {
            color: #94a3b8;
            font-size: 0.85rem;
            margin-top: 6px;
        }

        .stat-card .icon {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 1.5rem;
            opacity: 0.6;
        }

        .recent-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 900px) {
            .recent-grid {
                grid-template-columns: 1fr;
            }
        }

        .recent-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .recent-card h3 {
            margin: 0 0 4px 0;
            font-size: 1.15rem;
            color: #0f172a;
        }

        .recent-card .sub {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .recent-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .recent-row:last-child {
            border-bottom: none;
        }

        .recent-row .left {
            font-weight: 500;
            color: #0f172a;
        }

        .recent-row .right {
            color: #64748b;
            font-size: 0.9rem;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-completed {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-ready {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .badge-accepted {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-rejected {
            background: #fee2e2;
            color: #b91c1c;
        }

        .pending-section {
            background: white;
            border-radius: 16px;
            padding: 24px;
            margin-top: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .pending-section h3 {
            margin: 0 0 4px 0;
            font-size: 1.15rem;
            color: #0f172a;
        }

        .pending-section .sub {
            color: #64748b;
            font-size: 0.9rem;
            margin-bottom: 16px;
        }

        .pending-job {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .pending-job .title {
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .pending-job .meta {
            font-size: 0.9rem;
            color: #64748b;
            margin-bottom: 10px;
        }

        .pending-job-actions {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .btn-approve {
            background: #15803d;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
        }

        .btn-reject {
            background: #dc2626;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
        }

        .reject-form {
            margin-top: 10px;
        }

        .reject-form textarea {
            width: 100%;
            min-height: 60px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .reject-form .hint {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 6px;
        }

        .success-msg {
            background: #dcfce7;
            color: #15803d;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .report-nav {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 999px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            border: 1px solid #e2e8f0;
        }

        .report-nav a {
            background: white;
            color: #0f172a;
            padding: 12px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
            border: 1px solid #e2e8f0;
        }

        .report-nav a:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .report-nav a.active {
            background: #0f172a;
            color: white;
            border-color: #0f172a;
        }

        .report-section {
            background: white;
            border-radius: 18px;
            padding: 24px 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
        }

        .report-section h3 {
            margin: 0 0 16px 0;
            font-size: 1.15rem;
            color: #0f172a;
        }

        .week-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 20px;
        }

        .week-nav a,
        .week-nav span {
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
        }

        .week-nav a:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .week-nav span.current {
            background: #0f172a;
            color: white;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .report-table th {
            background: #f8fafc;
            font-weight: 600;
            color: #334155;
        }

        .report-table tr:hover {
            background: #fafafa;
        }

        .report-table .num {
            text-align: right;
        }

        .heatmap-table {
            width: 100%;
            border-collapse: collapse;
        }

        .heatmap-table th,
        .heatmap-table td {
            padding: 10px;
            text-align: center;
            border: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        .heatmap-table th {
            background: #f8fafc;
            color: #334155;
        }

        .heat-low {
            background: #bbf7d0;
        }

        .heat-medium {
            background: #fef9c3;
        }

        .heat-high {
            background: #fecaca;
        }

        .heat-empty {
            background: #ffffff;
        }
    </style>
</head>

<body>
    <div class="page-layout">
        <div class="admin-header">
            <div>
                <a href="/beta-assignment/admin page/adminPage.php" style="text-decoration:none;color:inherit;">
                    <div class="brand">
                        <img src="/beta-assignment/uploads/menu/logo.png" alt="GigFood logo">
                        <span class="brand-name">GigFood Admin</span>
                    </div>
                </a>
                <p class="sub">Platform Overview & Management</p>
            </div>
            <a href="/beta-assignment/logout.php"><button class="logout-btn">Logout</button></a>
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <div class="success-msg">Job posting updated.</div>
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'status'): ?>
            <div style="background:#fef2f2;color:#b91c1c;padding:12px 20px;border-radius:10px;margin-bottom:20px;">
                Error:Could not update job status.
            </div>
        <?php endif; ?>

        <div class="report-nav">
            <a href="adminPage.php?report=sales"
                class="<?php echo ($report === 'sales' || $report === '') ? 'active' : ''; ?>">
                Sale and Demand Analytics</a>
            <a href="adminPage.php?report=fulfillment" class="<?php echo $report === 'fulfillment' ? 'active' : ''; ?>">
                Job Fulfillment Report</a>
            <a href="adminPage.php?report=congestion" class="<?php echo $report === 'congestion' ? 'active' : ''; ?>">
                Canteen Congestion Report</a>
            <a href="adminPage.php?report=impact" class="<?php echo $report === 'impact' ? 'active' : ''; ?>">
                Student Employment Impact</a>
        </div>

        <?php if ($report === 'sales' || $report === ''): ?>
            <div class="report-section">
                <h3>Sale and Demand Analytics Report</h3>
                <p class="sub" style="color:#64748b;margin-bottom:16px;">
                    Which food items students order the most and when demand is highest.</p>
                <div class="week-nav">
                    <span>Week:</span>
                    <?php for ($w = 0; $w <= 7; $w++): ?>
                        <a href="adminPage.php?report=sales&week=<?php echo $w; ?>"
                            class="<?php echo $weekOffset === $w ? 'current' : ''; ?>"><?php echo $w === 0 ? 'This week' : ($w === 1 ? 'Last week' : ($w + 1) . ' weeks ago'); ?></a>
                    <?php endfor; ?>
                </div>
                <p style="color:#64748b;font-size:0.9rem;margin-bottom:16px;">Showing:
                    <?php echo $weekStartDisplay->format('d-m-Y'); ?> – <?php echo $weekEndDisplay->format('d-m-Y'); ?>
                </p>

                <h4 style="margin:20px 0 10px 0;font-size:1rem;">Top food items (by quantity ordered)</h4>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Item name</th>
                            <th>Merchant</th>
                            <th class="num">Quantity</th>
                            <th class="num">Revenue (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topItems)): ?>
                            <tr>
                                <td colspan="5" style="color:#94a3b8;">No orders in this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($topItems as $i => $row): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['merchant_name']); ?></td>
                                    <td class="num"><?php echo (int) $row['total_qty']; ?></td>
                                    <td class="num"><?php echo number_format((float) ($row['revenue'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h4 style="margin:28px 0 10px 0;font-size:1rem;">Demand by day (orders & revenue)</h4>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Day</th>
                            <th class="num">Orders</th>
                            <th class="num">Revenue (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($demandByDay)): ?>
                            <tr>
                                <td colspan="4" style="color:#94a3b8;">No orders in this period.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($demandByDay as $row): ?>
                                <tr>
                                    <td><?php echo formatDateDMY($row['order_date']); ?></td>
                                    <td><?php echo htmlspecialchars($row['day_name']); ?></td>
                                    <td class="num"><?php echo (int) $row['order_count']; ?></td>
                                    <td class="num"><?php echo number_format((float) ($row['revenue'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($report === 'fulfillment'): ?>
            <div class="report-section">
                <h3>Job Fulfillment Report</h3>
                <p class="sub" style="color:#64748b;margin-bottom:16px;">
                    How well part-time positions are being filled across stalls.
                </p>

                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Merchant Name</th>
                            <th>Job Category</th>
                            <th class="num">Vacancies Posted</th>
                            <th class="num">Applicants</th>
                            <th class="num">Students Hired</th>
                            <th class="num">Fulfillment Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jobFulfillmentRows)): ?>
                            <tr>
                                <td colspan="6" style="color:#94a3b8;">No job postings or applications yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($jobFulfillmentRows as $row):
                                $vacancies = (int) ($row['vacancies_posted'] ?? 0);
                                $hired = (int) ($row['students_hired'] ?? 0);
                                $rate = $vacancies > 0 ? min(100, round(($hired / $vacancies) * 100)) : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['merchant_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['job_category'] ?: 'Part-Time'); ?></td>
                                    <td class="num"><?php echo $vacancies; ?></td>
                                    <td class="num"><?php echo (int) ($row['applicants'] ?? 0); ?></td>
                                    <td class="num"><?php echo $hired; ?></td>
                                    <td class="num"><?php echo $rate; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($report === 'congestion'): ?>
            <div class="report-section">
                <h3>Canteen Congestion Report</h3>
                <p class="sub" style="color:#64748b;margin-bottom:16px;">
                    High-level view of where and when pick-up counters are busiest.
                </p>

                <form method="GET" style="margin-bottom:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
                    <input type="hidden" name="report" value="congestion">
                    <label style="font-size:0.9rem;color:#475569;">
                        Date:
                        <input type="date" name="date" value="<?php echo htmlspecialchars($congestionDate); ?>"
                            style="margin-left:6px;padding:6px 10px;border-radius:8px;border:1px solid #cbd5e1;">
                    </label>
                    <button type="submit"
                        style="padding:8px 14px;border-radius:8px;border:none;background:#0f172a;color:white;font-size:0.85rem;cursor:pointer;">
                        Refresh
                    </button>
                </form>

                <p style="color:#64748b;font-size:0.9rem;margin-bottom:10px;">
                    Colour legend: green = light traffic, yellow = more than 3 orders, red = more than 5 orders.
                </p>

                <table class="heatmap-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Canteen Area / Stall</th>
                            <?php foreach ($congestionSlots as $slot): ?>
                                <?php
                                $labelHour = $slot > 12 ? $slot - 12 : $slot;
                                $labelSuffix = $slot >= 12 ? 'PM' : 'AM';
                                ?>
                                <th><?php echo $labelHour; ?>:00 <?php echo $labelSuffix; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($congestionMerchants)): ?>
                            <tr>
                                <td colspan="<?php echo count($congestionSlots) + 1; ?>"
                                    style="padding:14px;color:#94a3b8;text-align:center;">
                                    No orders recorded for this date.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($congestionMerchants as $merchant): ?>
                                <tr>
                                    <td style="text-align:left;font-weight:500;color:#0f172a;">
                                        <?php echo htmlspecialchars($merchant); ?>
                                    </td>
                                    <?php foreach ($congestionSlots as $slot): ?>
                                        <?php
                                        $count = $congestionData[$merchant][$slot] ?? 0;
                                        if ($count === 0) {
                                            $cls = 'heat-empty';
                                        } elseif ($count > 5) {
                                            $cls = 'heat-high';
                                        } elseif ($count > 3) {
                                            $cls = 'heat-medium';
                                        } else {
                                            $cls = 'heat-low';
                                        }
                                        ?>
                                        <td class="<?php echo $cls; ?>">
                                            <?php echo $count > 0 ? (int) $count : ''; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($report === 'impact'): ?>
            <div class="report-section">
                <h3>Student Employment Impact Report</h3>
                <p class="sub" style="color:#64748b;margin-bottom:16px;">
                    Overall social and financial impact of part-time jobs created through this platform.
                </p>

                <div class="stats-grid" style="margin-top:0;margin-bottom:20px;">
                    <div class="stat-card">
                        <div class="label">Students hired</div>
                        <div class="value"><?php echo (int) $impactSummary['unique_students']; ?></div>
                        <div class="sub">Unique students with at least one accepted job application</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Accepted positions</div>
                        <div class="value"><?php echo (int) $impactSummary['accepted_applications']; ?></div>
                        <div class="sub">Total accepted applications across all stalls</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Estimated hours worked</div>
                        <div class="value"><?php echo (int) $impactSummary['estimated_hours']; ?></div>
                        <div class="sub">Assuming ~4 hours per accepted shift</div>
                    </div>
                    <div class="stat-card">
                        <div class="label">Total wages (per hour)</div>
                        <div class="value">RM <?php echo number_format((float) $impactSummary['total_hourly_wages'], 2); ?>
                        </div>
                        <div class="sub">Sum of hourly rates for accepted roles</div>
                    </div>
                </div>

                <h4 style="margin:10px 0 10px 0;font-size:1rem;">Impact by merchant</h4>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Merchant</th>
                            <th class="num">Students hired</th>
                            <th class="num">Accepted positions</th>
                            <th class="num">Estimated hours</th>
                            <th class="num">Total wages (per hour, RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($impactByMerchant)): ?>
                            <tr>
                                <td colspan="5" style="color:#94a3b8;">No accepted job applications yet. Once merchants approve
                                    students, the impact will appear here.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($impactByMerchant as $row):
                                $accepted = (int) ($row['accepted_count'] ?? 0);
                                $hours = $accepted * 4;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['merchant_name']); ?></td>
                                    <td class="num"><?php echo (int) ($row['student_count'] ?? 0); ?></td>
                                    <td class="num"><?php echo $accepted; ?></td>
                                    <td class="num"><?php echo $hours; ?></td>
                                    <td class="num"><?php echo number_format((float) ($row['total_hourly_wage'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="pending-section">
            <h3>Pending Job Postings</h3>
            <p class="sub">Verify merchant job postings. Approve to make them visible to students, or reject with a
                reason (merchant will see the reason).</p>
            <?php if (empty($pendingJobs)): ?>
                <p style="color:#94a3b8;">No pending job postings.</p>
            <?php else: ?>
                <?php foreach ($pendingJobs as $pj): ?>
                    <div class="pending-job" data-job-id="<?php echo (int) $pj['id']; ?>">
                        <div class="title"><?php echo htmlspecialchars($pj['title']); ?></div>
                        <div class="meta"><?php echo htmlspecialchars($pj['merchant_email']); ?> · RM
                            <?php echo htmlspecialchars($pj['wage'] ?? ''); ?> ·
                            <?php echo htmlspecialchars($pj['location'] ?? ''); ?> · Posted
                            <?php echo formatDateDMY($pj['posted_date'] ?? ''); ?>
                        </div>
                        <div class="pending-job-actions">
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"
                                style="display:inline;">
                                <input type="hidden" name="job_id" value="<?php echo (int) $pj['id']; ?>">
                                <input type="hidden" name="job_action" value="approve">
                                <button type="submit" class="btn-approve">Approve</button>
                            </form>
                            <button type="button" class="btn-reject"
                                onclick="document.getElementById('reject-form-<?php echo (int) $pj['id']; ?>').style.display = document.getElementById('reject-form-<?php echo (int) $pj['id']; ?>').style.display === 'block' ? 'none' : 'block';">Reject</button>
                        </div>
                        <div id="reject-form-<?php echo (int) $pj['id']; ?>" class="reject-form" style="display:none;">
                            <p class="hint">Give a reason (merchant will receive this):</p>
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                                <input type="hidden" name="job_id" value="<?php echo (int) $pj['id']; ?>">
                                <input type="hidden" name="job_action" value="reject">
                                <textarea name="rejection_reason"
                                    placeholder="e.g. Job description is unclear; wage below minimum; duplicate posting."></textarea>
                                <button type="submit" class="btn-reject">Reject & Send Reason</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="pending-section" style="margin-top: 32px;">
            <h3>Holiday / Promo discounts</h3>
            <p class="sub">Create a discount for users when they spend over a minimum amount (e.g. RM100). You can close
                or stop the event anytime.</p>
            <?php if (isset($_GET['discount_created'])): ?>
                <p style="color:#15803d; margin-bottom: 12px;">Discount event created. It is now active for students.</p>
            <?php endif; ?>
            <?php if (isset($_GET['discount_closed'])): ?>
                <p style="color:#15803d; margin-bottom: 12px;">Discount event closed. Students will no longer get this
                    discount.</p>
            <?php endif; ?>

            <div
                style="max-width: 480px; margin-bottom: 24px; padding: 16px; background: #f8fafc; border-radius: 12px;">
                <h4 style="margin: 0 0 12px;">Create new discount</h4>
                <form method="POST">
                    <input type="hidden" name="create_discount" value="1">
                    <div style="margin-bottom: 10px;">
                        <label style="display:block; font-weight: 600; margin-bottom: 4px;">Event name</label>
                        <input type="text" name="discount_name" placeholder="e.g. Christmas 2025" required
                            style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="display:block; font-weight: 600; margin-bottom: 4px;">Discount type</label>
                        <select name="discount_type"
                            style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                            <option value="percent">Percentage off</option>
                            <option value="fixed">Fixed amount off (RM)</option>
                        </select>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label style="display:block; font-weight: 600; margin-bottom: 4px;">Discount value</label>
                        <input type="number" name="discount_value" step="0.01" min="0" max="100"
                            placeholder="e.g. 10 for 10%" required
                            style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display:block; font-weight: 600; margin-bottom: 4px;">Minimum order amount
                            (RM)</label>
                        <input type="number" name="min_order_amount" step="0.01" min="0" value="100"
                            style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px;">
                    </div>
                    <button type="submit" class="btn-approve">Create discount event</button>
                </form>
            </div>

            <h4 style="margin: 0 0 12px;">Discount events</h4>
            <?php if (empty($discountEvents)): ?>
                <p style="color:#94a3b8;">No discount events yet.</p>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse; margin-top: 8px;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e2e8f0;">
                            <th style="text-align: left; padding: 10px;">Name</th>
                            <th style="text-align: left; padding: 10px;">Type</th>
                            <th style="text-align: right; padding: 10px;">Value</th>
                            <th style="text-align: right; padding: 10px;">Min order (RM)</th>
                            <th style="text-align: center; padding: 10px;">Status</th>
                            <th style="text-align: left; padding: 10px;">Created</th>
                            <th style="text-align: left; padding: 10px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountEvents as $de): ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px;"><?php echo htmlspecialchars($de['name']); ?></td>
                                <td style="padding: 10px;">
                                    <?php echo $de['discount_type'] === 'percent' ? 'Percentage' : 'Fixed (RM)'; ?>
                                </td>
                                <td style="padding: 10px; text-align: right;">
                                    <?php echo $de['discount_type'] === 'percent' ? number_format((float) $de['discount_value'], 0) . '%' : 'RM ' . number_format((float) $de['discount_value'], 2); ?>
                                </td>
                                <td style="padding: 10px; text-align: right;">
                                    <?php echo number_format((float) $de['min_order_amount'], 2); ?>
                                </td>
                                <td style="padding: 10px; text-align: center;">
                                    <?php if (!empty($de['is_active'])): ?>
                                        <span
                                            style="background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem;">Active</span>
                                    <?php else: ?>
                                        <span
                                            style="background: #f1f5f9; color: #64748b; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem;">Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px;"><?php echo formatDateTimeDMY($de['created_at'] ?? ''); ?></td>
                                <td style="padding: 10px;">
                                    <?php if (!empty($de['is_active'])): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="close_discount" value="1">
                                            <input type="hidden" name="discount_id" value="<?php echo (int) $de['id']; ?>">
                                            <button type="submit" class="btn-reject"
                                                style="padding: 6px 12px; font-size: 0.9rem;">Close event</button>
                                        </form>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>