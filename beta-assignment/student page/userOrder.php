<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$db = (new Database())->getConnection();

require_once __DIR__ . '/../merchant page/MenuManager.php';
$manager = new MenuManager(); //get all available items

//cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    //add item (with optional quantity and customization)
    if (isset($_POST['add_to_cart'], $_POST['menu_id'])) {
        $menu_id = (int) $_POST['menu_id'];
        $qty = max(1, (int) ($_POST['quantity'] ?? 1));
        $customization = isset($_POST['customization']) && is_array($_POST['customization'])
            ? implode(', ', array_map('trim', $_POST['customization']))
            : '';

        //show unavailable items (sold out)
        $checkAvail = $db->prepare("SELECT available FROM menu_items WHERE id = :mid");
        $checkAvail->bindParam(':mid', $menu_id, PDO::PARAM_INT);
        $checkAvail->execute();
        $avail = $checkAvail->fetch(PDO::FETCH_ASSOC);
        if (!$avail || empty($avail['available'])) {
            header("Location: userOrder.php");
            exit();
        }

        //check if item already in cart (same menu_item_id; customization can differ so we add new row if customizations differ, or merge qty if no customization)
        $check = $db->prepare("SELECT id, quantity, COALESCE(customization,'') AS customization FROM cart_items WHERE student_id = :sid AND menu_item_id = :mid");
        $check->bindParam(':sid', $student_id, PDO::PARAM_INT);
        $check->bindParam(':mid', $menu_id, PDO::PARAM_INT);
        $check->execute();
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing && (string) $existing['customization'] === $customization) {
            $newQty = (int) $existing['quantity'] + $qty;
            $upd = $db->prepare("UPDATE cart_items SET quantity = :q WHERE id = :id");
            $upd->bindParam(':q', $newQty, PDO::PARAM_INT);
            $upd->bindParam(':id', $existing['id'], PDO::PARAM_INT);
            $upd->execute();
        } else {
            $ins = $db->prepare("INSERT INTO cart_items (student_id, menu_item_id, quantity, customization) VALUES (:sid, :mid, :q, :custom)");
            $ins->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $ins->bindParam(':mid', $menu_id, PDO::PARAM_INT);
            $ins->bindParam(':q', $qty, PDO::PARAM_INT);
            $ins->bindValue(':custom', $customization ?: null);
            $ins->execute();
        }

        $redirect = isset($_POST['direct_pay']) && $_POST['direct_pay'] ? 'userOrder.php?pay=1' : 'userOrder.php';
        header("Location: " . $redirect);
        exit();
    }

    //update quantities for all items in cart
    if (isset($_POST['update_cart'], $_POST['quantities']) && is_array($_POST['quantities'])) {
        foreach ($_POST['quantities'] as $mid => $qty) {
            $mid = (int) $mid;
            $qty = (int) $qty;
            if ($mid <= 0)
                continue;

            if ($qty <= 0) {
                $del = $db->prepare("DELETE FROM cart_items WHERE student_id = :sid AND menu_item_id = :mid");
                $del->bindParam(':sid', $student_id, PDO::PARAM_INT);
                $del->bindParam(':mid', $mid, PDO::PARAM_INT);
                $del->execute();
            } else {
                $upd = $db->prepare("UPDATE cart_items SET quantity = :q WHERE student_id = :sid AND menu_item_id = :mid");
                $upd->bindParam(':q', $qty, PDO::PARAM_INT);
                $upd->bindParam(':sid', $student_id, PDO::PARAM_INT);
                $upd->bindParam(':mid', $mid, PDO::PARAM_INT);
                $upd->execute();
            }
        }
        header("Location: userOrder.php");
        exit();
    }

    //remove item
    if (isset($_POST['remove_item'])) {
        $menu_id = (int) $_POST['remove_item'];
        if ($menu_id > 0) {
            $del = $db->prepare("DELETE FROM cart_items WHERE student_id = :sid AND menu_item_id = :mid");
            $del->bindParam(':sid', $student_id, PDO::PARAM_INT);
            $del->bindParam(':mid', $menu_id, PDO::PARAM_INT);
            $del->execute();
        }
        header("Location: userOrder.php");
        exit();
    }

    //checkout: update quantities in cart, create order, clear cart
    if (isset($_POST['checkout'])) {
        if (isset($_POST['quantities']) && is_array($_POST['quantities'])) {
            foreach ($_POST['quantities'] as $mid => $qty) {
                $mid = (int) $mid;
                $qty = (int) $qty;
                if ($mid <= 0)
                    continue;

                if ($qty <= 0) {
                    $del = $db->prepare("DELETE FROM cart_items WHERE student_id = :sid AND menu_item_id = :mid");
                    $del->bindParam(':sid', $student_id, PDO::PARAM_INT);
                    $del->bindParam(':mid', $mid, PDO::PARAM_INT);
                    $del->execute();
                } else {
                    $upd = $db->prepare("UPDATE cart_items SET quantity = :q WHERE student_id = :sid AND menu_item_id = :mid");
                    $upd->bindParam(':q', $qty, PDO::PARAM_INT);
                    $upd->bindParam(':sid', $student_id, PDO::PARAM_INT);
                    $upd->bindParam(':mid', $mid, PDO::PARAM_INT);
                    $upd->execute();
                }
            }
        }

        //reload cart rows with merchant_id (and customization if column exists)
        $cartStmt = $db->prepare("
            SELECT c.menu_item_id, c.quantity, c.customization, m.name, m.price, m.merchant_id
            FROM cart_items c
            JOIN menu_items m ON c.menu_item_id = m.id
            WHERE c.student_id = :sid
        ");
        $cartStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
        $cartStmt->execute();
        $rows = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            //group cart items by merchant_id
            $byMerchant = [];
            foreach ($rows as $r) {
                $mid = (int) $r['merchant_id'];
                if (!isset($byMerchant[$mid]))
                    $byMerchant[$mid] = [];
                $byMerchant[$mid][] = $r;
            }

            // All active discounts for this checkout (apply every qualifying discount)
            $checkoutDiscounts = [];
            $dStmt = $db->query("SELECT id, discount_type, discount_value, min_order_amount FROM discount_events WHERE is_active = 1 ORDER BY id DESC");
            $checkoutDiscounts = $dStmt->fetchAll(PDO::FETCH_ASSOC);

            try {
                $db->beginTransaction();

                foreach ($byMerchant as $merchant_id => $items) {
                    $total = 0;
                    foreach ($items as $r) {
                        $total += $r['price'] * $r['quantity'];
                    }

                    $discountAmount = 0.0;
                    $firstDiscountEventId = null;
                    foreach ($checkoutDiscounts as $d) {
                        if ($total < (float)$d['min_order_amount']) continue;
                        if ($d['discount_type'] === 'percent') {
                            $discountAmount += round($total * (float)$d['discount_value'] / 100, 2);
                        } else {
                            $discountAmount += min((float)$d['discount_value'], $total);
                        }
                        if ($firstDiscountEventId === null) $firstDiscountEventId = (int)$d['id'];
                    }
                    $discountAmount = min(round($discountAmount, 2), $total);
                    $discountEventId = $firstDiscountEventId;
                    $finalTotal = max(0, round($total - $discountAmount, 2));

                    $order_number = 'ORD' . date('YmdHis') . sprintf('%03d', random_int(0, 999));

                    $orderStmt = $db->prepare("
                        INSERT INTO orders (student_id, merchant_id, order_number, status, total_amount, discount_amount, discount_event_id, ordered_at, pickup_time)
                        VALUES (:sid, :mid, :ordno, 'pending', :total, :disc_amt, :disc_id, CURRENT_TIMESTAMP, NULL)
                    ");
                    $orderStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
                    $orderStmt->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
                    $orderStmt->bindParam(':ordno', $order_number);
                    $orderStmt->bindParam(':total', $finalTotal);
                    $orderStmt->bindValue(':disc_amt', $discountAmount);
                    $orderStmt->bindValue(':disc_id', $discountEventId ?: null, $discountEventId ? PDO::PARAM_INT : PDO::PARAM_NULL);
                    $orderStmt->execute();
                    $orderId = (int) $db->lastInsertId();

                    $itemStmt = $db->prepare("
                        INSERT INTO order_items (order_id, menu_item_id, quantity, price_at_time, customization)
                        VALUES (:oid, :mid, :qty, :price, :custom)
                    ");
                    foreach ($items as $r) {
                        $itemStmt->bindParam(':oid', $orderId, PDO::PARAM_INT);
                        $itemStmt->bindParam(':mid', $r['menu_item_id'], PDO::PARAM_INT);
                        $itemStmt->bindParam(':qty', $r['quantity'], PDO::PARAM_INT);
                        $itemStmt->bindParam(':price', $r['price']);
                        $itemStmt->bindValue(':custom', isset($r['customization']) ? $r['customization'] : null);
                        $itemStmt->execute();
                    }
                }

                //clear cart
                $clear = $db->prepare("DELETE FROM cart_items WHERE student_id = :sid");
                $clear->bindParam(':sid', $student_id, PDO::PARAM_INT);
                $clear->execute();

                $db->commit();
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }
        }

        header("Location: orderHistory.php?paid=1");
        exit();
    }
}

//load menus and cart (include unavailable items so they show as sold out)
$menus = $manager->getAllMenusIncludingUnavailable();

// Pending quantity per menu item (from orders not yet completed) — increases estimated prep time
$pendingQtyByItem = [];
$pendingStmt = $db->query("
    SELECT oi.menu_item_id, SUM(oi.quantity) AS pending_qty
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status IN ('pending','processing','ready')
    GROUP BY oi.menu_item_id
");
while ($row = $pendingStmt->fetch(PDO::FETCH_ASSOC)) {
    $pendingQtyByItem[(int) $row['menu_item_id']] = (int) $row['pending_qty'];
}

//fetch cart items for this student
$cartItems = [];
$cartTotal = 0;
$cartCount = 0;

$cartStmt = $db->prepare("
    SELECT c.menu_item_id, c.quantity, c.customization, m.name, m.price, m.image, m.merchant_id
    FROM cart_items c
    JOIN menu_items m ON c.menu_item_id = m.id
    WHERE c.student_id = :sid
");
$cartStmt->bindParam(':sid', $student_id, PDO::PARAM_INT);
$cartStmt->execute();
$cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

$cartSubtotal = 0;
$cartDiscount = 0.0;
foreach ($cartItems as $row) {
    $lineTotal = $row['price'] * $row['quantity'];
    $cartSubtotal += $lineTotal;
    $cartCount += $row['quantity'];
}
$cartTotal = $cartSubtotal;

// Active holiday/promo events (for banner carousel and cart discount)
$eventBanners = [];
$activeDiscount = null;
$discStmt = $db->query("SELECT id, name, discount_type, discount_value, min_order_amount FROM discount_events WHERE is_active = 1 ORDER BY id DESC");
$eventBanners = $discStmt->fetchAll(PDO::FETCH_ASSOC);
$activeDiscount = $eventBanners[0] ?? null;

// Compute cart discount: apply all active discounts per merchant (stack multiple promos)
if (!empty($eventBanners) && $cartSubtotal > 0) {
    $byMerchant = [];
    foreach ($cartItems as $r) {
        $mid = (int) $r['merchant_id'];
        if (!isset($byMerchant[$mid])) $byMerchant[$mid] = 0;
        $byMerchant[$mid] += $r['price'] * $r['quantity'];
    }
    foreach ($byMerchant as $merchantTotal) {
        foreach ($eventBanners as $d) {
            if ($merchantTotal < (float)$d['min_order_amount']) continue;
            if ($d['discount_type'] === 'percent') {
                $cartDiscount += round($merchantTotal * (float)$d['discount_value'] / 100, 2);
            } else {
                $cartDiscount += min((float)$d['discount_value'], $merchantTotal);
            }
        }
    }
    $cartDiscount = min(round($cartDiscount, 2), $cartSubtotal);
}
$cartTotalFinal = max(0, round($cartSubtotal - $cartDiscount, 2));

$current_page = 'orders';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Food Orders</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <style>
        .item-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1001;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }

        .item-modal-overlay.show {
            display: flex;
        }

        .item-modal-box {
            background: #fff;
            border-radius: 20px;
            max-width: 440px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.2);
            position: relative;
        }

        .item-modal-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            border: none;
            background: #f1f5f9;
            border-radius: 50%;
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            color: #64748b;
            z-index: 1;
        }

        .item-modal-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .item-modal-image-wrap {
            height: 200px;
            background: #e2e8f0;
            border-radius: 20px 20px 0 0;
            overflow: hidden;
        }

        .item-modal-image-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .item-modal-body {
            padding: 20px 24px 24px;
        }

        .item-modal-title {
            margin: 0 0 6px;
            font-size: 1.4rem;
            color: #0f172a;
        }

        .item-modal-merchant {
            margin: 0 0 10px;
            font-size: 0.9rem;
            color: #64748b;
        }

        .item-modal-desc {
            margin: 0 0 14px;
            font-size: 0.95rem;
            color: #475569;
            line-height: 1.4;
        }

        .item-modal-price-time {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .item-modal-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0b2f4e;
        }

        .item-modal-time {
            font-size: 0.9rem;
            background: #eef5e9;
            color: #2e6b3c;
            padding: 4px 12px;
            border-radius: 20px;
        }

        .item-modal-qty {
            margin-bottom: 16px;
        }

        .item-modal-qty label,
        .item-modal-custom label {
            display: block;
            font-weight: 600;
            font-size: 0.9rem;
            color: #334155;
            margin-bottom: 8px;
        }

        .item-modal-custom {
            margin-bottom: 20px;
        }

        .item-modal-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 20px;
        }

        .item-custom-opt {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: normal;
            font-size: 0.9rem;
            color: #475569;
            cursor: pointer;
        }

        .item-modal-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-add-to-cart {
            background: #1d3d6d;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
            flex: 1;
            min-width: 120px;
        }

        .btn-add-to-cart:hover {
            background: #234b82;
        }

        .btn-pay-now {
            background: #16a34a;
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.95rem;
            flex: 1;
            min-width: 120px;
        }

        .btn-pay-now:hover {
            background: #15803d;
        }

        .cart-item-custom {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 2px;
        }

        .time-queue {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: normal;
        }

        .event-banner-wrap {
            margin: 0 auto 20px;
            max-width: 900px;
            position: relative;
            overflow: hidden;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .event-banner-track {
            display: flex;
            transition: transform 0.35s ease-out;
        }
        .event-banner-slide {
            min-width: 100%;
            padding: 18px 52px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #fff;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .event-banner-slide.alt { background: linear-gradient(135deg, #0d9488 0%, #0f766e 100%); }
        .event-banner-slide.alt2 { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); }
        .event-banner-title { font-size: 1.15rem; font-weight: 700; margin: 0 0 6px; }
        .event-banner-desc { font-size: 0.9rem; opacity: 0.9; margin: 0; }
        .event-banner-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: none;
            background: rgba(255,255,255,0.2);
            color: #fff;
            border-radius: 50%;
            font-size: 1.25rem;
            cursor: pointer;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .event-banner-arrow:hover { background: rgba(255,255,255,0.35); }
        .event-banner-arrow.prev { left: 10px; }
        .event-banner-arrow.next { right: 10px; }
        .event-banner-dots {
            position: absolute;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 2;
        }
        .event-banner-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            border: none;
            padding: 0;
            cursor: pointer;
            transition: background 0.2s;
        }
        .event-banner-dot.active { background: #fff; }
    </style>
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
        <a href="userOrder.php" class="tab active" id="nav-orders">Food Orders</a>
        <a href="jobSearch.php" class="tab" id="nav-jobs">Job Search</a>
        <a href="orderHistory.php" class="tab" id="nav-history">Order History</a>
        <a href="myApplications.php" class="tab" id="nav-applications">My Applications</a>
        <a href="activityHistory.php" class="tab" id="nav-activity">Activity History</a>
    </div>

    <?php if (!empty($eventBanners)): ?>
    <div class="event-banner-wrap">
        <button type="button" class="event-banner-arrow prev" id="eventBannerPrev" aria-label="Previous event">‹</button>
        <button type="button" class="event-banner-arrow next" id="eventBannerNext" aria-label="Next event">›</button>
        <div class="event-banner-track" id="eventBannerTrack">
            <?php foreach ($eventBanners as $i => $ev):
                $min = number_format((float)$ev['min_order_amount'], 0);
                $val = $ev['discount_type'] === 'percent' ? (int)$ev['discount_value'] . '% off' : 'RM ' . number_format((float)$ev['discount_value'], 2) . ' off';
                $slideClass = 'event-banner-slide' . ($i % 3 === 1 ? ' alt' : ($i % 3 === 2 ? ' alt2' : ''));
            ?>
            <div class="<?php echo $slideClass; ?>">
                <div class="event-banner-title">🎉 <?php echo htmlspecialchars($ev['name']); ?></div>
                <p class="event-banner-desc">Spend over RM <?php echo $min; ?> to get <?php echo $val; ?> at checkout.</p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($eventBanners) > 1): ?>
        <div class="event-banner-dots" id="eventBannerDots">
            <?php foreach ($eventBanners as $i => $ev): ?>
            <button type="button" class="event-banner-dot<?php echo $i === 0 ? ' active' : ''; ?>" data-index="<?php echo $i; ?>" aria-label="Event <?php echo $i + 1; ?>"></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="intro-section">
        <div class="cart-display">
            <h3>Browse Menu</h3>
            <div class="cart-badge">
                Cart <span id="cartCount"><?php echo (int) $cartCount; ?></span>
            </div>
        </div>
        <p>Order your favorite food items from campus merchants</p>
        <input type="text" class="search-box" id="searchInput" placeholder="Search food items, merchants...">
    </div>

    <div class="category-title">
        <h4>Menu Items</h4>
    </div>

    <div id="menuContainer" class="menu-grid">
        <?php if (empty($menus)): ?>
            <div style="padding: 40px; text-align: center; color: #6a7f9b;">No items available at the moment.</div>
        <?php else: ?>
            <?php foreach ($menus as $item):
                $itemImgSrc = !empty($item->image) ? '/beta-assignment/' . $item->image : '/beta-assignment/uploads/menu/placeholder.svg';
                $is_sold_out = !$item->available;
                $basePrep = (int) $item->prepTime;
                $pendingQty = (int) ($pendingQtyByItem[$item->id] ?? 0);
                $estimatedPrep = $basePrep * (1 + $pendingQty);
                ?>
                <div class="menu-card<?php echo $is_sold_out ? ' sold-out' : ''; ?>" data-item-id="<?php echo $item->id; ?>"
                    data-name="<?php echo htmlspecialchars($item->name); ?>"
                    data-price="<?php echo number_format($item->price, 2); ?>" data-prep="<?php echo $estimatedPrep; ?>"
                    data-desc="<?php echo htmlspecialchars($item->description ?? ''); ?>"
                    data-merchant="<?php echo htmlspecialchars($item->merchant_email); ?>"
                    data-image="<?php echo htmlspecialchars($itemImgSrc); ?>">
                    <div class="menu-card-image">
                        <img src="<?php echo $itemImgSrc; ?>" alt="<?php echo htmlspecialchars($item->name); ?>"
                            onerror="this.src='/beta-assignment/uploads/menu/placeholder.svg'">
                    </div>
                    <div class="food-info">
                        <div class="food-title"><?php echo htmlspecialchars($item->name); ?></div>
                        <div class="merchant"><?php echo htmlspecialchars($item->merchant_email); ?></div>
                        <div class="desc"><?php echo htmlspecialchars($item->description ?? ''); ?></div>
                        <div class="price-time">
                            <span class="price">RM <?php echo number_format($item->price, 2); ?></span>
                            <span class="time">Prepare: ~<?php echo $estimatedPrep; ?> min<?php if ($pendingQty > 0): ?> <span
                                        class="time-queue">(<?php echo $pendingQty; ?> in queue)</span><?php endif; ?></span>
                        </div>
                    </div>
                    <div class="status-add">
                        <?php if ($is_sold_out): ?>
                            <span class="sold-out-badge">Sold out</span>
                        <?php else: ?>
                            <span class="availability">Tap to view details & add</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Item detail modal -->
    <div id="itemModal" class="item-modal-overlay">
        <div class="item-modal-box">
            <button type="button" class="item-modal-close" id="itemModalClose" aria-label="Close">×</button>
            <div class="item-modal-image-wrap" id="itemModalImage"></div>
            <div class="item-modal-body">
                <h3 class="item-modal-title" id="itemModalTitle"></h3>
                <p class="item-modal-merchant" id="itemModalMerchant"></p>
                <p class="item-modal-desc" id="itemModalDesc"></p>
                <div class="item-modal-price-time">
                    <span class="item-modal-price" id="itemModalPrice"></span>
                    <span class="item-modal-time" id="itemModalTime"></span>
                </div>
                <form method="POST" id="itemDetailForm">
                    <input type="hidden" name="add_to_cart" value="1">
                    <input type="hidden" name="menu_id" id="itemModalMenuId" value="">
                    <div class="item-modal-qty">
                        <label>Quantity</label>
                        <input type="number" name="quantity" id="itemModalQty" min="1" value="1" class="qty-input">
                    </div>
                    <div class="item-modal-custom">
                        <label>Customization (optional)</label>
                        <div class="item-modal-checkboxes">
                            <label class="item-custom-opt"><input type="checkbox" name="customization[]"
                                    value="No vegetable"> No vegetable</label>
                            <label class="item-custom-opt"><input type="checkbox" name="customization[]"
                                    value="No meat"> No meat</label>
                            <label class="item-custom-opt"><input type="checkbox" name="customization[]"
                                    value="No spicy"> No spicy</label>
                            <label class="item-custom-opt"><input type="checkbox" name="customization[]"
                                    value="Less salt"> Less salt</label>
                            <label class="item-custom-opt"><input type="checkbox" name="customization[]"
                                    value="Extra sauce"> Extra sauce</label>
                        </div>
                    </div>
                    <input type="hidden" name="direct_pay" id="itemModalDirectPay" value="">
                    <div class="item-modal-actions">
                        <button type="submit" class="btn-add-to-cart">Add to cart</button>
                        <button type="button" class="btn-pay-now" id="itemModalPayNow">Pay now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="cartOverlay" class="cart-overlay">
        <div class="cart-section">
            <div class="cart-header">
                <h4>Your Cart</h4>
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="cart-total-label">
                        <?php if ($cartDiscount > 0): ?>
                        Total: <span class="cart-total-amount">RM <?php echo number_format($cartTotalFinal, 2); ?></span>
                        <?php else: ?>
                        Total: <span class="cart-total-amount">RM <?php echo number_format($cartTotal, 2); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="button" id="cartClose" class="cart-close">✖</button>
                </div>
            </div>
            <?php if (!empty($eventBanners)): 
                if (count($eventBanners) === 1):
                    $ev = $eventBanners[0];
                    $min = number_format((float)$ev['min_order_amount'], 0);
                    $val = $ev['discount_type'] === 'percent' ? (int)$ev['discount_value'] . '% off' : 'RM ' . number_format((float)$ev['discount_value'], 2) . ' off';
            ?>
            <div class="cart-promo" style="background:#fef3c7; color:#92400e; padding: 10px 14px; border-radius: 10px; margin: 0 14px 12px; font-size: 0.9rem;">
                🎉 <strong><?php echo htmlspecialchars($ev['name']); ?></strong>: Spend over RM <?php echo $min; ?> to get <?php echo $val; ?>.
            </div>
            <?php endif; endif; ?>
            <?php if (!empty($cartItems) && $cartDiscount > 0): ?>
            <div class="cart-summary" style="margin: 0 14px 12px; padding: 12px 0; border-top: 1px solid #e2e8f0;">
                <div style="display:flex; justify-content: space-between; font-size: 0.95rem; color:#64748b;">
                    <span>Subtotal</span>
                    <span>RM <?php echo number_format($cartSubtotal, 2); ?></span>
                </div>
                <div style="display:flex; justify-content: space-between; font-size: 0.95rem; color:#15803d; margin-top: 4px;">
                    <span>Discount</span>
                    <span>− RM <?php echo number_format($cartDiscount, 2); ?></span>
                </div>
                <div style="display:flex; justify-content: space-between; font-size: 1.05rem; font-weight: 700; margin-top: 8px; color:#0f172a;">
                    <span>Total to pay</span>
                    <span>RM <?php echo number_format($cartTotalFinal, 2); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($cartItems)): ?>
                <div class="cart-empty">🛒 Your cart is empty. Add items from the menu above.</div>
            <?php else: ?>
                <form method="POST">
                    <?php foreach ($cartItems as $row):
                        $itemTotal = $row['price'] * $row['quantity'];
                        $cartImgSrc = !empty($row['image']) ? '/beta-assignment/' . $row['image'] : '/beta-assignment/uploads/menu/placeholder.svg';
                        ?>
                        <div class="cart-row">
                            <div class="cart-item-thumb">
                                <img src="<?php echo $cartImgSrc; ?>" alt=""
                                    onerror="this.src='/beta-assignment/uploads/menu/placeholder.svg'">
                            </div>
                            <div class="cart-item-name">
                                <?php echo htmlspecialchars($row['name']); ?>
                                <?php if (!empty($row['customization'])): ?>
                                    <div class="cart-item-custom"><?php echo htmlspecialchars($row['customization']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="cart-row-qty">
                                <input type="number" min="1" name="quantities[<?php echo $row['menu_item_id']; ?>]"
                                    value="<?php echo (int) $row['quantity']; ?>" class="qty-input">
                            </div>
                            <div class="cart-item-total">RM <?php echo number_format($itemTotal, 2); ?></div>
                            <button type="submit" name="remove_item" value="<?php echo $row['menu_item_id']; ?>"
                                class="cart-remove">Remove</button>
                        </div>
                    <?php endforeach; ?>

                    <div class="cart-actions-row">
                        <button type="submit" name="update_cart" class="btn-update-cart">Update Cart</button>
                        <button type="submit" name="checkout" class="btn-update-cart"
                            style="margin-left:10px; background:#16a34a;">Pay</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <script>
        // Event banner carousel (left/right)
        (function() {
            const track = document.getElementById('eventBannerTrack');
            const prevBtn = document.getElementById('eventBannerPrev');
            const nextBtn = document.getElementById('eventBannerNext');
            const dotsContainer = document.getElementById('eventBannerDots');
            if (!track) return;
            const total = track.children.length;
            let index = 0;
            function goTo(i) {
                index = (i + total) % total;
                track.style.transform = 'translateX(-' + (index * 100) + '%)';
                if (dotsContainer) {
                    dotsContainer.querySelectorAll('.event-banner-dot').forEach(function(dot, j) {
                        dot.classList.toggle('active', j === index);
                    });
                }
            }
            if (prevBtn) prevBtn.addEventListener('click', function() { goTo(index - 1); });
            if (nextBtn) nextBtn.addEventListener('click', function() { goTo(index + 1); });
            if (dotsContainer) {
                dotsContainer.querySelectorAll('.event-banner-dot').forEach(function(dot) {
                    dot.addEventListener('click', function() { goTo(parseInt(this.getAttribute('data-index'), 10)); });
                });
            }
        })();

        //search filter for menu cards
        const searchInput = document.getElementById('searchInput');
        const menuContainer = document.getElementById('menuContainer');
        const cartBadge = document.querySelector('.cart-badge');
        const cartOverlay = document.getElementById('cartOverlay');
        const cartClose = document.getElementById('cartClose');
        const menuCards = document.querySelectorAll('.menu-card');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const term = searchInput.value.toLowerCase();
                const cards = menuContainer.querySelectorAll('.menu-card');

                cards.forEach(card => {
                    const foodTitle = card.querySelector('.food-title')?.textContent.toLowerCase() || '';
                    const merchant = card.querySelector('.merchant')?.textContent.toLowerCase() || '';
                    const desc = card.querySelector('.desc')?.textContent.toLowerCase() || '';

                    if (!term || foodTitle.includes(term) || merchant.includes(term) || desc.includes(term)) {
                        card.style.display = 'flex';
                    } else {
                        card.style.display = 'none';
                    }
                });
            });
        }

        if (cartBadge && cartOverlay) {
            cartBadge.addEventListener('click', () => {
                cartOverlay.classList.toggle('show');
            });
        }

        if (cartClose && cartOverlay) {
            cartClose.addEventListener('click', () => {
                cartOverlay.classList.remove('show');
            });
        }

        // Click menu card to open item detail modal
        const itemModal = document.getElementById('itemModal');
        const itemModalClose = document.getElementById('itemModalClose');
        const itemModalTitle = document.getElementById('itemModalTitle');
        const itemModalMerchant = document.getElementById('itemModalMerchant');
        const itemModalDesc = document.getElementById('itemModalDesc');
        const itemModalPrice = document.getElementById('itemModalPrice');
        const itemModalTime = document.getElementById('itemModalTime');
        const itemModalImage = document.getElementById('itemModalImage');
        const itemModalMenuId = document.getElementById('itemModalMenuId');
        const itemModalQty = document.getElementById('itemModalQty');
        const itemDetailForm = document.getElementById('itemDetailForm');
        const itemModalPayNow = document.getElementById('itemModalPayNow');

        if (menuCards.length && itemModal) {
            menuCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    if (card.classList.contains('sold-out')) return;
                    const id = card.getAttribute('data-item-id');
                    if (!id) return;
                    itemModalTitle.textContent = card.getAttribute('data-name') || '';
                    itemModalMerchant.textContent = card.getAttribute('data-merchant') || '';
                    itemModalDesc.textContent = card.getAttribute('data-desc') || '';
                    itemModalPrice.textContent = 'RM ' + (card.getAttribute('data-price') || '0.00');
                    itemModalTime.textContent = 'Prepare: ' + (card.getAttribute('data-prep') || '0') + ' min';
                    itemModalMenuId.value = id;
                    itemModalQty.value = 1;
                    const imgSrc = card.getAttribute('data-image') || '';
                    itemModalImage.innerHTML = imgSrc ? '<img src="' + imgSrc.replace(/"/g, '&quot;') + '" alt="">' : '';
                    itemDetailForm.querySelectorAll('input[name="customization[]"]').forEach(cb => cb.checked = false);
                    document.getElementById('itemModalDirectPay').value = '';
                    itemModal.classList.add('show');
                });
            });
        }
        if (itemModalClose && itemModal) {
            itemModalClose.addEventListener('click', () => itemModal.classList.remove('show'));
        }
        if (itemModal) {
            itemModal.addEventListener('click', (e) => { if (e.target === itemModal) itemModal.classList.remove('show'); });
        }
        if (itemModalPayNow && itemDetailForm) {
            itemModalPayNow.addEventListener('click', () => {
                document.getElementById('itemModalDirectPay').value = '1';
                itemDetailForm.submit();
            });
        }
        // Open cart when ?pay=1 (after "Pay now")
        if (window.location.search.indexOf('pay=1') !== -1 && cartOverlay) {
            cartOverlay.classList.add('show');
        }
    </script>
</body>

</html>