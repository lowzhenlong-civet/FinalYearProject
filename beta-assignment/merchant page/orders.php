<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$merchant_id = $_SESSION['user_id'];
$db = (new Database())->getConnection();

//mark order as completed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_done'], $_POST['order_id'])) {
    $order_id = (int) $_POST['order_id'];
    if ($order_id > 0) {
        $stmt = $db->prepare("UPDATE orders SET status = 'completed' WHERE id = :id AND merchant_id = :mid");
        $stmt->bindParam(':id', $order_id, PDO::PARAM_INT);
        $stmt->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
        $stmt->execute();
    }
    header("Location: orders.php");
    exit();
}

//merchant email for header
$userStmt = $db->prepare("SELECT email FROM users WHERE id = :id");
$userStmt->bindParam(':id', $merchant_id, PDO::PARAM_INT);
$userStmt->execute();
$merchant_email = $userStmt->fetch(PDO::FETCH_ASSOC)['email'] ?? 'Merchant';

//load all orders for this merchant with items and student email
$ordersStmt = $db->prepare("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.ordered_at, o.student_id,
           u.email AS student_email
    FROM orders o
    LEFT JOIN users u ON u.id = o.student_id
    WHERE o.merchant_id = :mid
    ORDER BY o.ordered_at DESC
");
$ordersStmt->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
$ordersStmt->execute();
$orderRows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$orderIds = array_column($orderRows, 'id');
$ordersWithItems = [];

foreach ($orderRows as $row) {
    $ordersWithItems[$row['id']] = [
        'id' => $row['id'],
        'order_number' => $row['order_number'],
        'status' => $row['status'],
        'total_amount' => $row['total_amount'],
        'ordered_at' => $row['ordered_at'],
        'student_email' => $row['student_email'] ?? 'Student',
        'items' => []
    ];
}

if ($orderIds) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $db->prepare("
        SELECT oi.order_id, oi.quantity, oi.price_at_time, oi.customization, m.name
        FROM order_items oi
        JOIN menu_items m ON m.id = oi.menu_item_id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.order_id, oi.id
    ");
    $itemsStmt->execute($orderIds);
    while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
        $ordersWithItems[$row['order_id']]['items'][] = [
            'name' => $row['name'],
            'quantity' => $row['quantity'],
            'price_at_time' => $row['price_at_time'],
            'customization' => $row['customization'] ?? null
        ];
    }
}

$activeOrders = array_filter($ordersWithItems, fn($o) => $o['status'] !== 'completed');
$completedOrders = array_filter($ordersWithItems, fn($o) => $o['status'] === 'completed');
$current_page = 'orders';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Order Management</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <link rel="stylesheet" href="/beta-assignment/assets/merchant.css">
</head>
<body class="with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
    <?php include __DIR__ . '/sidebar_merchant.php'; ?>
    <div class="main-with-sidebar">
    <div class="header">
        <div style="display:flex;align-items:center;gap:14px;">
            <a href="/beta-assignment/merchant page/merchant.php" style="text-decoration:none;color:inherit;">
                <div class="brand">
                    <img src="/beta-assignment/uploads/menu/logo.png" alt="GigFood logo">
                    <span class="brand-name">GigFood TARUMT Platform</span>
                </div>
            </a>
            <div style="display:flex;flex-direction:column;">
                <h3 style="margin:0;font-size:1.5rem;color:#00008B;">
                    Welcome back, <?php echo htmlspecialchars($merchant_email); ?>!
                </h3>
            </div>
        </div>
        <a href="/beta-assignment/logout.php"><button class="logout-btn">Logout</button></a>
    </div>

    <div class="tabs">
        <a href="merchant.php" class="tab" id="nav-menu">Menu management</a>
        <a href="orders.php" class="tab active" id="nav-orders">Orders</a>
        <a href="jobPosting.php" class="tab" id="nav-jobs">Job posting</a>
    </div>

    <div class="intro-section">
        <div class="order-management-header">
            <h3>Order Management</h3>
            <p>View and manage incoming orders from students.</p>
        </div>

        <div class="order-tabs">
            <button type="button" class="order-tab active" data-panel="active">Active Orders</button>
            <button type="button" class="order-tab" data-panel="completed">Completed Orders</button>
        </div>

        <div id="activePanel" class="order-panel active">
            <?php if (empty($activeOrders)): ?>
                <div class="empty-state">
                    <p>No active orders at the moment</p>
                </div>
            <?php else: ?>
                <?php foreach ($activeOrders as $order): ?>
                <div class="order-card">
                    <div class="order-card-header">
                        <div>
                            <div class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div class="order-card-meta">Student: <strong><?php echo htmlspecialchars($order['student_email']); ?></strong> · <?php echo date('d M Y, h:i A', strtotime($order['ordered_at'])); ?></div>
                        </div>
                        <span class="order-badge <?php echo htmlspecialchars($order['status']); ?>"><?php echo htmlspecialchars(ucfirst($order['status'])); ?></span>
                    </div>
                    <ul class="order-items-list">
                        <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <span>
                                <?php echo htmlspecialchars($item['name']); ?> × <?php echo (int)$item['quantity']; ?>
                                <?php if (!empty($item['customization'])): ?>
                                    <div class="order-item-custom-merchant"><?php echo htmlspecialchars($item['customization']); ?></div>
                                <?php endif; ?>
                            </span>
                            <span>RM <?php echo number_format($item['price_at_time'] * $item['quantity'], 2); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="order-card-footer">
                        <span class="order-total">Total: RM <?php echo number_format($order['total_amount'], 2); ?></span>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                            <button type="submit" name="mark_done" value="1" class="btn-done">Done</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="completedPanel" class="order-panel">
            <?php if (empty($completedOrders)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">✓</div>
                    <p>No completed orders yet</p>
                </div>
            <?php else: ?>
                <?php foreach ($completedOrders as $order): ?>
                <div class="order-card">
                    <div class="order-card-header">
                        <div>
                            <div class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div class="order-card-meta">Student: <strong><?php echo htmlspecialchars($order['student_email']); ?></strong> · <?php echo date('d M Y, h:i A', strtotime($order['ordered_at'])); ?></div>
                        </div>
                        <span class="order-badge completed">Completed</span>
                    </div>
                    <ul class="order-items-list">
                        <?php foreach ($order['items'] as $item): ?>
                        <li>
                            <span>
                                <?php echo htmlspecialchars($item['name']); ?> × <?php echo (int)$item['quantity']; ?>
                                <?php if (!empty($item['customization'])): ?>
                                    <div class="order-item-custom-merchant"><?php echo htmlspecialchars($item['customization']); ?></div>
                                <?php endif; ?>
                            </span>
                            <span>RM <?php echo number_format($item['price_at_time'] * $item['quantity'], 2); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="order-card-footer">
                        <span class="order-total">Total: RM <?php echo number_format($order['total_amount'], 2); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.querySelectorAll('.order-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var panel = this.getAttribute('data-panel');
                document.querySelectorAll('.order-tab').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.order-panel').forEach(function(p) { p.classList.remove('active'); });
                this.classList.add('active');
                var target = document.getElementById(panel + 'Panel');
                if (target) target.classList.add('active');
            });
        });
    </script>
    </div>
</body>
</html>
