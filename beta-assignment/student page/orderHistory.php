<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

//if not logged in OR not student, back to login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$db = (new Database())->getConnection();

//allow student to set a pickup time
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_pickup'], $_POST['order_id'], $_POST['pickup_time'])) {
    $orderId = (int) $_POST['order_id'];
    $pickupRaw = trim((string) $_POST['pickup_time']);

    if ($orderId > 0 && $pickupRaw !== '') {
        $stmt = $db->prepare("
            UPDATE orders
            SET pickup_time = :pt
            WHERE id = :id AND student_id = :sid AND status <> 'completed'
        ");
        $stmt->execute([
            ':pt' => $pickupRaw,
            ':id' => $orderId,
            ':sid' => $student_id,
        ]);
    }

    header("Location: orderHistory.php");
    exit();
}

//fetch orders with their items
$ordersStmt = $db->prepare("
    SELECT o.id, o.order_number, o.status, o.total_amount, o.ordered_at, o.pickup_time,
           COALESCE(o.discount_amount, 0) AS discount_amount,
           u.email AS merchant_email,
           m.name AS item_name, m.image AS item_image, oi.quantity, oi.price_at_time, oi.customization AS item_customization
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    JOIN menu_items m ON m.id = oi.menu_item_id
    LEFT JOIN users u ON u.id = o.merchant_id
    WHERE o.student_id = :sid
    ORDER BY o.ordered_at DESC, oi.id ASC
");
$ordersStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
$ordersStmt->execute();
$rows = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

$orders = [];
foreach ($rows as $row) {
    $id = $row['id'];
    if (!isset($orders[$id])) {
        $orders[$id] = [
            'id' => $id,
            'order_number' => $row['order_number'],
            'status' => $row['status'],
            'total' => $row['total_amount'],
            'discount_amount' => (float)($row['discount_amount'] ?? 0),
            'ordered_at' => $row['ordered_at'],
            'pickup_time' => $row['pickup_time'],
            'merchant_email' => $row['merchant_email'] ?? 'Merchant',
            'items' => []
        ];
    }
    $orders[$id]['items'][] = [
        'name' => $row['item_name'],
        'image' => $row['item_image'] ?? null,
        'quantity' => $row['quantity'],
        'unit_price' => $row['price_at_time'],
        'customization' => $row['item_customization'] ?? null
    ];
}
$current_page = 'history';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Order History</title>
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
        <a href="/beta-assignment/student page/userOrder.php" class="tab" id="nav-orders">Food Orders</a>
        <a href="/beta-assignment/student page/jobSearch.php" class="tab" id="nav-jobs">Job Search</a>
        <a href="/beta-assignment/student page/orderHistory.php" class="tab active" id="nav-history">Order History</a>
        <a href="/beta-assignment/student page/myApplications.php" class="tab" id="nav-applications">My Applications</a>
        <a href="/beta-assignment/student page/activityHistory.php" class="tab" id="nav-activity">Activity History</a>
    </div>

    <div class="intro-section">
        <h3>Order History</h3>
        <p>View all past & current orders</p>
        <input type="text" class="search-box" id="searchInput" placeholder="Search by item or date...">
    </div>

    <div class="category-title">
        <h4>Order History</h4>
    </div>

    <div id="orderHistoryContainer">
        <?php if (empty($orders)): ?>
            <div class="empty-history">📭 No order history found.</div>
        <?php else: ?>
            <?php foreach ($orders as $order): 
                $date = date('d M Y, h:i A', strtotime($order['ordered_at']));
                $statusClass = in_array($order['status'], ['completed', 'ready']) ? $order['status'] : 'pending';

                //pickup slots
                $now = new DateTime('now');
                $today = (clone $now)->setTime(0, 0, 0);
                $tomorrow = (clone $today)->modify('+1 day');
                $pickupSlots = [];
                foreach ([$today, $tomorrow] as $day) {
                    for ($h = 9; $h <= 18; $h++) {
                        $pickupSlots[] = (clone $day)->setTime($h, 0, 0);
                        if ($h < 18) {
                            $pickupSlots[] = (clone $day)->setTime($h, 30, 0);
                        }
                    }
                }
            ?>
            <div class="order-card" data-search="<?php echo htmlspecialchars($date . ' ' . $order['order_number'] . ' ' . $order['merchant_email']); ?>">
                <div class="merchant-bar">
                    <div>
                        <div class="merchant"><?php echo htmlspecialchars($order['merchant_email']); ?></div>
                        <div class="order-id"><?php echo htmlspecialchars($order['order_number']); ?></div>
                    </div>
                    <div class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars(ucfirst($order['status'])); ?></div>
                </div>

                <?php foreach ($order['items'] as $item):
                    $itemImgSrc = !empty($item['image']) ? '/beta-assignment/' . $item['image'] : '/beta-assignment/uploads/menu/placeholder.svg';
                ?>
                <div class="order-item-row" data-search-item="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                    <div class="order-item-thumb">
                        <img src="<?php echo $itemImgSrc; ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" onerror="this.src='/beta-assignment/uploads/menu/placeholder.svg'">
                    </div>
                    <div>
                        <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                        <?php if (!empty($item['customization'])): ?>
                            <div class="order-item-custom"><?php echo htmlspecialchars($item['customization']); ?></div>
                        <?php endif; ?>
                        <div class="item-qty">Qty: <?php echo (int) $item['quantity']; ?></div>
                    </div>
                    <div class="item-price">RM <?php echo number_format($item['unit_price'], 2); ?></div>
                </div>
                <?php endforeach; ?>

                <div class="time-details">
                    <div class="time-item">
                        <span class="time-label">Ordered on</span>
                        <span class="time-value"><?php echo htmlspecialchars($date); ?></span>
                    </div>
                    <div class="time-item">
                        <span class="time-label">Pickup time</span>
                        <?php if (!empty($order['pickup_time'])): ?>
                            <span class="time-value">
                                <?php echo htmlspecialchars(date('d M Y, h:i A', strtotime($order['pickup_time']))); ?>
                            </span>
                        <?php elseif ($order['status'] !== 'completed'): ?>
                            <form method="POST" style="margin-top:4px; display:flex; flex-wrap:wrap; gap:6px; align-items:center;">
                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                <select name="pickup_time" style="padding:4px 8px;border-radius:8px;border:1px solid #cbd5e1;font-size:0.85rem;">
                                    <?php foreach ($pickupSlots as $slot): 
                                        $isToday = $slot->format('Y-m-d') === $today->format('Y-m-d');
                                        $labelPrefix = $isToday ? 'Today, ' : 'Tomorrow, ';
                                        $label = $labelPrefix . $slot->format('g:i A');
                                    ?>
                                    <option value="<?php echo $slot->format('Y-m-d H:i:s'); ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="set_pickup"
                                        style="padding:4px 10px;border-radius:999px;border:none;background:#0f172a;color:white;font-size:0.8rem;cursor:pointer;">
                                    Set
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="time-value">—</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($order['discount_amount']) && $order['discount_amount'] > 0): ?>
                <div class="total-row" style="color:#15803d; font-size:0.9rem;">
                    <span class="total-label">Promo discount</span>
                    <span class="total-amount">− RM <?php echo number_format($order['discount_amount'], 2); ?> saved</span>
                </div>
                <?php endif; ?>
                <div class="total-row">
                    <span class="total-label">Total</span>
                    <span class="total-amount">RM <?php echo number_format($order['total'], 2); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const cards = document.querySelectorAll('.order-card');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase();

                cards.forEach(card => {
                    const dateText = card.getAttribute('data-search')?.toLowerCase() || '';
                    const itemTexts = Array.from(card.querySelectorAll('[data-search-item]')).map(el => el.getAttribute('data-search-item') || '');
                    const haystack = [dateText, ...itemTexts].join(' ');

                    if (!term || haystack.includes(term)) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }
    </script>
    </div>
</body>

</html>