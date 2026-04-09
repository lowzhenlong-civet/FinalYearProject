<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/MenuManager.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$manager = new MenuManager($_SESSION['user_id']);

//handle add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $prepTime = max(1, intval($_POST['prepTime'] ?? 5));
    $imagePath = null;

    //handle image upload
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);

        if (in_array($mime, $allowed)) {
            $uploadDir = __DIR__ . '/../uploads/menu/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'merchant_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . strtolower($ext);
            $target = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $imagePath = 'uploads/menu/' . $filename;
            }
        }
    }

    if ($name !== '' && $price > 0) {
        $manager->addMenu($name, $category, $description, $price, $prepTime, $imagePath);
        header("Location: merchant.php?added=1");
        exit();
    }
}

//toggle availability 
if (isset($_GET['toggle'])) {
    $manager->toggleAvailability($_GET['toggle']);
    header("Location: merchant.php");
    exit();
}

//delete menu item
if (isset($_GET['delete'])) {
    $manager->deleteMenu($_GET['delete']);
    header("Location: merchant.php");
    exit();
}

//edit menu item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_menu'], $_POST['menu_id'])) {
    $menu_id = (int) $_POST['menu_id'];
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $prepTime = max(1, intval($_POST['prepTime'] ?? 5));
    $imagePath = null;

    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['image']['tmp_name']);
        if (in_array($mime, $allowed)) {
            $uploadDir = __DIR__ . '/../uploads/menu/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'merchant_' . $_SESSION['user_id'] . '_' . uniqid() . '.' . strtolower($ext);
            $target = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                $imagePath = 'uploads/menu/' . $filename;
            }
        }
    }

    if ($menu_id > 0 && $name !== '' && $price > 0) {
        $manager->updateMenu($menu_id, $name, $category, $description, $price, $prepTime, $imagePath);
        header("Location: merchant.php?edited=1");
        exit();
    }
}

//get merchant menus
$menus = (array) $manager->getMerchantMenus();

//get merchant email
$userQuery = "SELECT email FROM users WHERE id = :id";
$userStmt = $db->prepare($userQuery);
$userStmt->bindParam(':id', $_SESSION['user_id']);
$userStmt->execute();
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$merchant_email = $user['email'] ?? 'Merchant';
$current_page = 'menu';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Menu Management</title>
    <link rel="stylesheet" href="/beta-assignment/assets/order.css">
    <link rel="stylesheet" href="/beta-assignment/assets/merchant.css">
</head>
<body class="merchant-page with-sidebar <?php echo $theme === 'dark' ? 'theme-dark' : ''; ?>">
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
        <a href="merchant.php" class="tab active" id="nav-menu">Menu management</a>
        <a href="orders.php" class="tab" id="nav-orders">Orders</a>
        <a href="jobPosting.php" class="tab" id="nav-jobs">Job posting</a>
    </div>

    <?php if (isset($_GET['added']) && $_GET['added'] == '1'): ?>
    <div class="success-msg" style="background:#e0f0e9;color:#166b41;padding:12px 20px;border-radius:12px;margin-bottom:16px;font-weight:500;">
        ✓ Food item added successfully. It appears in the list below.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['edited']) && $_GET['edited'] == '1'): ?>
    <div class="success-msg" style="background:#e0f0e9;color:#166b41;padding:12px 20px;border-radius:12px;margin-bottom:16px;font-weight:500;">
        ✓ Menu item updated. It is now <strong>Pending</strong> until admin approves — students will not see the updated version until then.
    </div>
    <?php endif; ?>

    <div class="intro-section">
        <div class="merchant-header-row">
            <h3>Menu Items</h3>
            <div style="display: flex; gap: 20px; align-items: center;">
                <button type="button" class="add-btn" id="showAddBtn"><span>+</span> Add Item</button>
            </div>
        </div>
        <p>Manage your food items and availability in real-time</p>

        <div class="add-form-collapse" id="addFormPanel">
            <form method="POST" action="" id="addItemForm" enctype="multipart/form-data">
                <div class="form-row add-item-grid">
                    <div class="form-group fg-name">
                        <label>Food Name *</label>
                        <input type="text" name="name" required placeholder="e.g. Nasi Lemak" maxlength="100">
                    </div>
                    <div class="form-group fg-category">
                        <label>Category *</label>
                        <input type="text" name="category" required placeholder="e.g. Main, Drink, Snack" maxlength="50">
                    </div>
                    <div class="form-group fg-price">
                        <label>Price (RM) *</label>
                        <input type="number" name="price" required step="0.01" min="0" placeholder="5.00">
                    </div>
                    <div class="form-group fg-prep">
                        <label>Prep Time (min) *</label>
                        <input type="number" name="prepTime" required min="1" max="120" value="10" placeholder="10">
                    </div>
                    <div class="form-group fg-image">
                        <label>Image</label>
                        <input type="file" name="image" accept="image/*">
                    </div>
                    <div class="form-group fg-desc">
                        <label>Description</label>
                        <textarea name="description" placeholder="Short description of the item" maxlength="300"></textarea>
                    </div>
                    <div class="form-group fg-submit">
                        <button type="submit" name="add" class="btn-submit">Add item</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="category-title">
        <h4>Your Menu Items</h4>
    </div>

    <div id="menuContainer" class="menu-grid">
        <?php if (empty($menus)): ?>
            <div class="empty-note" style="grid-column: 1 / -1;">No menu items yet — add using the button above.</div>
        <?php else: ?>
            <?php foreach ($menus as $item): 
                $imgSrc = !empty($item->image) ? '/beta-assignment/' . $item->image : '/beta-assignment/uploads/menu/placeholder.svg';
                $availClass = $item->available ? '' : ' unavailable';
                $toggleLabel = $item->available ? 'Set as Unavailable' : 'Set as Available';
                $isPending = isset($item->approval_status) && $item->approval_status === 'pending';
            ?>
            <div class="menu-card" data-id="<?php echo $item->id; ?>"
                 data-name="<?php echo htmlspecialchars($item->name); ?>"
                 data-category="<?php echo htmlspecialchars($item->category ?? ''); ?>"
                 data-description="<?php echo htmlspecialchars($item->description ?? ''); ?>"
                 data-price="<?php echo number_format($item->price, 2); ?>"
                 data-preptime="<?php echo (int)$item->prepTime; ?>">
                <div class="card-image-wrap">
                    <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($item->name); ?>" onerror="this.src='/beta-assignment/uploads/menu/placeholder.svg'">
                    <span class="card-availability-tag<?php echo $availClass; ?>"><?php echo $item->available ? 'Available' : 'Unavailable'; ?></span>
                    <?php if ($isPending): ?>
                    <span class="card-pending-tag">Pending approval</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="food-title"><?php echo htmlspecialchars($item->name); ?></div>
                    <div class="card-category-tag"><?php echo htmlspecialchars($item->category ?? 'General'); ?></div>
                    <div class="desc"><?php echo htmlspecialchars($item->description ?? ''); ?></div>
                    <div class="price-time">
                        <span class="price">RM <?php echo number_format($item->price, 2); ?></span>
                        <span class="time">Prepare Time: <?php echo $item->prepTime; ?> min</span>
                    </div>
                    <div class="card-actions">
                        <div class="toggle-switch-wrap">
                            <a href="?toggle=<?php echo $item->id; ?>" class="toggle-link" style="text-decoration: none;">
                                <div class="toggle-switch <?php echo $item->available ? 'on' : ''; ?>">
                                    <input type="checkbox" <?php echo $item->available ? 'checked' : ''; ?> aria-hidden="true">
                                </div>
                            </a>
                            <label><?php echo $toggleLabel; ?></label>
                        </div>
                        <button type="button" class="card-edit-btn" data-edit-id="<?php echo $item->id; ?>">Edit</button>
                        <a href="?delete=<?php echo $item->id; ?>" class="card-delete" onclick="return confirm('Are you sure you want to delete this item?')">Delete</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Edit menu item modal -->
    <div id="editMenuModal" class="edit-menu-modal">
        <div class="edit-menu-modal-box">
            <button type="button" class="edit-menu-modal-close" id="editMenuModalClose">&times;</button>
            <h3>Edit menu item</h3>
            <p class="edit-modal-note">After saving, the item will be <strong>Pending</strong> until admin approves. Students will not see the updated version until then.</p>
            <form method="POST" enctype="multipart/form-data" id="editMenuForm">
                <input type="hidden" name="edit_menu" value="1">
                <input type="hidden" name="menu_id" id="editMenuId" value="">
                <div class="form-group">
                    <label>Food Name *</label>
                    <input type="text" name="name" id="editName" required placeholder="e.g. Nasi Lemak" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <input type="text" name="category" id="editCategory" required placeholder="e.g. Main, Drink" maxlength="50">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="editDescription" placeholder="Short description" maxlength="300"></textarea>
                </div>
                <div class="form-group">
                    <label>Image (leave empty to keep current)</label>
                    <input type="file" name="image" accept="image/*">
                </div>
                <div class="form-group">
                    <label>Price (RM) *</label>
                    <input type="number" name="price" id="editPrice" required step="0.01" min="0" placeholder="5.00">
                </div>
                <div class="form-group">
                    <label>Prep Time (min) *</label>
                    <input type="number" name="prepTime" id="editPreptime" required min="1" max="120" value="10">
                </div>
                <button type="submit" class="btn-submit">Save changes (will need admin approval)</button>
            </form>
        </div>
    </div>

    <style>
        .card-pending-tag { position: absolute; bottom: 12px; left: 12px; background: #f59e0b; color: #fff; font-weight: 600; font-size: 0.75rem; padding: 4px 10px; border-radius: 12px; }
        .card-edit-btn { background: #1d4ed8; color: #fff; border: none; padding: 6px 14px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; text-decoration: none; }
        .card-edit-btn:hover { background: #1e40af; color: #fff; }
        .edit-menu-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .edit-menu-modal.show { display: flex; }
        .edit-menu-modal-box { background: #fff; border-radius: 16px; max-width: 440px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 24px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.2); }
        .edit-menu-modal-close { position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
        .edit-menu-modal-box h3 { margin: 0 0 8px 0; font-size: 1.25rem; }
        .edit-modal-note { font-size: 0.9rem; color: #64748b; margin-bottom: 20px; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const addBtn = document.getElementById('showAddBtn');
            const addPanel = document.getElementById('addFormPanel');

            addBtn.addEventListener('click', function (e) {
                e.preventDefault();
                addPanel.classList.toggle('show');
                addBtn.innerHTML = addPanel.classList.contains('show') ? 'X Close' : '<span>+</span> Add Item';
            });

            const editModal = document.getElementById('editMenuModal');
            const editModalClose = document.getElementById('editMenuModalClose');
            const editForm = document.getElementById('editMenuForm');
            document.querySelectorAll('.card-edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var card = btn.closest('.menu-card');
                    if (!card) return;
                    document.getElementById('editMenuId').value = card.getAttribute('data-id') || '';
                    document.getElementById('editName').value = card.getAttribute('data-name') || '';
                    document.getElementById('editCategory').value = card.getAttribute('data-category') || '';
                    document.getElementById('editDescription').value = card.getAttribute('data-description') || '';
                    document.getElementById('editPrice').value = card.getAttribute('data-price') || '';
                    document.getElementById('editPreptime').value = card.getAttribute('data-preptime') || '10';
                    if (editModal) editModal.classList.add('show');
                });
            });
            if (editModalClose && editModal) editModalClose.addEventListener('click', function() { editModal.classList.remove('show'); });
            if (editModal) editModal.addEventListener('click', function(e) { if (e.target === editModal) editModal.classList.remove('show'); });

            var successMsg = document.querySelector('.success-msg');
            if (successMsg) setTimeout(function() { successMsg.style.display = 'none'; }, 10000);
        });
    </script>
    </div>
</body>
</html>