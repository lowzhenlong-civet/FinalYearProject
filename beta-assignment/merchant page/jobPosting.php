<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'merchant') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$merchant_id = $_SESSION['user_id'];

//handle job posting (create)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_job'])) {
    $title = trim($_POST['title'] ?? '');
    $type = trim($_POST['type'] ?? 'Part-Time');
    $description = trim($_POST['description'] ?? '');
    $wage = trim($_POST['wage'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $shifts = isset($_POST['shifts']) && is_array($_POST['shifts']) ? $_POST['shifts'] : [];
    $requirements = isset($_POST['requirements']) && is_array($_POST['requirements']) ? $_POST['requirements'] : [];
    $requirements = array_filter(array_map('trim', $requirements));
    if (!empty($requirements)) {
        $description .= "\n\nRequirements:\n" . implode("\n", array_map(function($r) { return '• ' . $r; }, $requirements));
    }

    $query = "INSERT INTO job_postings (merchant_id, title, type, description, wage, location, posted_date, job_post_status) 
              VALUES (:merchant_id, :title, :type, :description, :wage, :location, CURDATE(), 'pending')";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':merchant_id', $merchant_id);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':type', $type);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':wage', $wage);
    $stmt->bindParam(':location', $location);
    if ($stmt->execute()) {
        $job_id = (int) $db->lastInsertId();
        $shiftQuery = "INSERT INTO job_shifts (job_id, shift_description) VALUES (:job_id, :shift)";
        $shiftStmt = $db->prepare($shiftQuery);
        foreach ($shifts as $shift) {
            if (trim($shift) !== '') {
                $shiftStmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
                $shiftStmt->bindValue(':shift', trim($shift));
                $shiftStmt->execute();
            }
        }
        setJobPending($db, $job_id);
        header("Location: jobPosting.php?success=1");
        exit();
    }
}

function setJobPending($db, $job_id) {
    $u = $db->prepare("UPDATE job_postings SET job_post_status = 'pending' WHERE id = :id");
    $u->bindParam(':id', $job_id, PDO::PARAM_INT);
    $u->execute();
}

//close posting (set job_post_status to closed)
if (isset($_GET['close'])) {
    $job_id = (int) $_GET['close'];
    if ($job_id > 0) {
        $stmt = $db->prepare("UPDATE job_postings SET job_post_status = 'closed' WHERE id = :id AND merchant_id = :mid");
        $stmt->bindParam(':id', $job_id, PDO::PARAM_INT);
        $stmt->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
        $stmt->execute();
        header("Location: jobPosting.php");
        exit();
    }
}

//handle job update (re-edit and re-submit for verification when pending or rejected)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_job'], $_POST['job_id'])) {
    $job_id = (int) $_POST['job_id'];
    if ($job_id > 0) {
        $check = $db->prepare("SELECT id, job_post_status FROM job_postings WHERE id = :id AND merchant_id = :mid");
        $check->bindParam(':id', $job_id, PDO::PARAM_INT);
        $check->bindParam(':mid', $merchant_id, PDO::PARAM_INT);
        $check->execute();
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        $st = $existing['job_post_status'] ?? $existing['status'] ?? '';
        if ($existing && in_array($st, ['pending', 'rejected'], true)) {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $wage = trim($_POST['wage'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $shifts = isset($_POST['shifts']) && is_array($_POST['shifts']) ? $_POST['shifts'] : [];
            $requirements = isset($_POST['requirements']) && is_array($_POST['requirements']) ? $_POST['requirements'] : [];
            $requirements = array_filter(array_map('trim', $requirements));
            if (!empty($requirements)) {
                $description .= "\n\nRequirements:\n" . implode("\n", array_map(function($r) { return '• ' . $r; }, $requirements));
            }
            $db->prepare("UPDATE job_postings SET title = :title, description = :desc, wage = :wage, location = :location WHERE id = :id AND merchant_id = :mid")
               ->execute([
                   ':title' => $title, ':desc' => $description, ':wage' => $wage, ':location' => $location,
                   ':id' => $job_id, ':mid' => $merchant_id
               ]);
            setJobPending($db, $job_id);
            $db->prepare("UPDATE job_postings SET rejection_reason = NULL WHERE id = :id")->execute([':id' => $job_id]);
            $db->prepare("DELETE FROM job_shifts WHERE job_id = :id")->execute([':id' => $job_id]);
            $ins = $db->prepare("INSERT INTO job_shifts (job_id, shift_description) VALUES (:job_id, :shift)");
            foreach ($shifts as $shift) {
                if (trim($shift) !== '') {
                    $ins->execute([':job_id' => $job_id, ':shift' => trim($shift)]);
                }
            }
            header("Location: jobPosting.php?updated=1");
            exit();
        }
    }
}

//handle job deletion
if (isset($_GET['delete'])) {
    $job_id = (int) $_GET['delete'];
    if ($job_id > 0) {
        $db->prepare("DELETE FROM job_postings WHERE id = :id AND merchant_id = :merchant_id")
           ->execute([':id' => $job_id, ':merchant_id' => $merchant_id]);
        header("Location: jobPosting.php");
        exit();
    }
}

//get merchant's job postings
$query = "SELECT j.*, 
          (SELECT COUNT(*) FROM job_applications WHERE job_id = j.id) as application_count,
          (SELECT GROUP_CONCAT(shift_description SEPARATOR '|') FROM job_shifts WHERE job_id = j.id) as shifts_text
          FROM job_postings j 
          WHERE j.merchant_id = :merchant_id 
          ORDER BY j.posted_date DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':merchant_id', $merchant_id);
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

//get user email
$userQuery = "SELECT email FROM users WHERE id = :id";
$userStmt = $db->prepare($userQuery);
$userStmt->bindParam(':id', $merchant_id);
$userStmt->execute();
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$current_page = 'jobs';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Job Postings</title>
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
                    Welcome back, <?php echo htmlspecialchars($user['email']); ?>!
                </h3>
            </div>
        </div>
        <a href="/beta-assignment/logout.php"><button class="logout-btn">Logout</button></a>
    </div>

    <div class="tabs">
        <a href="merchant.php" class="tab">Menu management</a>
        <a href="orders.php" class="tab">Orders</a>
        <a href="jobPosting.php" class="tab active">Job posting</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div class="success-msg" style="background:#e0f0e9;color:#166b41;padding:12px 20px;border-radius:12px;margin-bottom:16px;">
        ✓ Job submitted. It is now <strong>Pending</strong> and waiting for admin to approve or reject. You will see the status here—no need to go to the admin page.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
    <div class="success-msg" style="background:#e0f0e9;color:#166b41;padding:12px 20px;border-radius:12px;margin-bottom:16px;">
        ✓ Job updated and re-submitted for admin verification. Status set to <strong>Pending</strong>.
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['close_error'])): ?>
    <div class="success-msg" style="background:#fef2f2;color:#b91c1c;padding:12px 20px;border-radius:12px;margin-bottom:16px;">
        Could not close posting. Ensure the database has the <code>job_post_status</code> column (run <code>data/schema_job_status.sql</code> if needed).
    </div>
    <?php endif; ?>

    <div class="job-page-header">
        <div>
            <h3>Job Postings</h3>
            <p class="subtitle">Create and manage part-time job opportunities for students</p>
        </div>
        <button type="button" class="create-job-btn" id="openCreateModal"><span>+</span> Create Job Posting</button>
    </div>

    <!--create job posting modal-->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <div class="modal-header">
                <div>
                    <h3>Create New Job Posting</h3>
                    <p class="modal-subtitle">Post a part-time job opportunity for students.</p>
                </div>
                <button type="button" class="modal-close" id="closeCreateModal" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Job Title *</label>
                        <input type="text" name="title" required placeholder="e.g., Part-Time Cashier">
                    </div>
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" required rows="4" placeholder="Describe the role and responsibilities."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Hourly Rate (RM) *</label>
                        <input type="number" name="wage" required step="0.01" value="10.00" placeholder="10.00">
                    </div>
                    <div class="form-group">
                        <label>Location *</label>
                        <input type="text" name="location" required placeholder="e.g., Building A">
                    </div>
                    <div class="form-group">
                        <label>Available Shifts</label>
                        <div id="shiftsContainer">
                            <div class="shift-row">
                                <input type="text" name="shifts[]" placeholder="e.g., Monday 12pm-2pm">
                                <button type="button" class="add-row-btn" onclick="addShiftInput()">+</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Requirements</label>
                        <div id="requirementsContainer">
                            <div class="req-row">
                                <input type="text" name="requirements[]" placeholder="e.g., Good communication skills">
                                <button type="button" class="add-row-btn" onclick="addRequirementInput()">+</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <input type="hidden" name="subtitle" value="">
                    <input type="hidden" name="type" value="Part-Time">
                    <button type="submit" name="post_job" class="btn-submit">Create Job Posting</button>
                </div>
            </form>
        </div>
    </div>

    <!--edit job posting modal (for pending/rejected jobs)-->
    <div class="modal-overlay" id="editModal">
        <div class="modal-box">
            <div class="modal-header">
                <div>
                    <h3>Edit Job Posting</h3>
                    <p class="modal-subtitle">Update the details and re-submit for admin verification.</p>
                </div>
                <button type="button" class="modal-close" id="closeEditModal" aria-label="Close">&times;</button>
            </div>
            <form method="POST" action="" id="editJobForm">
                <input type="hidden" name="update_job" value="1">
                <input type="hidden" name="job_id" id="edit_job_id" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Job Title *</label>
                        <input type="text" name="title" id="edit_title" required placeholder="e.g., Part-Time Cashier">
                    </div>
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" id="edit_description" required rows="4" placeholder="Describe the role and responsibilities."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Hourly Rate (RM) *</label>
                        <input type="number" name="wage" id="edit_wage" required step="0.01" placeholder="10.00">
                    </div>
                    <div class="form-group">
                        <label>Location *</label>
                        <input type="text" name="location" id="edit_location" required placeholder="e.g., Building A">
                    </div>
                    <div class="form-group">
                        <label>Available Shifts</label>
                        <div id="edit_shiftsContainer">
                            <div class="shift-row">
                                <input type="text" name="shifts[]" placeholder="e.g., Monday 12pm-2pm">
                                <button type="button" class="add-row-btn" onclick="addEditShiftInput()">+</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Requirements</label>
                        <div id="edit_requirementsContainer">
                            <div class="req-row">
                                <input type="text" name="requirements[]" placeholder="e.g., Good communication skills">
                                <button type="button" class="add-row-btn" onclick="addEditRequirementInput()">+</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn-submit">Update & Re-submit for verification</button>
                </div>
            </form>
        </div>
    </div>

    <div id="jobsContainer">
        <?php if (empty($jobs)): ?>
            <div style="background: white; border-radius: 20px; padding: 40px; text-align: center; color: #637e9c;">
                No job postings yet. Click "Create Job Posting" to add one.
            </div>
        <?php else: ?>
            <?php foreach ($jobs as $job): 
                $desc = $job['description'] ?? '';
                $reqs = [];
                if (preg_match('/\n\s*Requirements:\s*\n(.*)/s', $desc, $m)) {
                    $desc = trim(preg_replace('/\n\s*Requirements:\s*\n.*/s', '', $desc));
                    $lines = preg_split('/\n/', trim($m[1]));
                    foreach ($lines as $line) {
                        $line = trim($line, " •-\t");
                        if ($line !== '') $reqs[] = $line;
                    }
                }
                $job_status = $job['job_post_status'] ?? $job['status'] ?? 'active';
                $is_pending = ($job_status === 'pending');
                $is_active = ($job_status === 'active');
                $is_rejected = ($job_status === 'rejected');
                $is_closed = ($job_status === 'closed');
                $shifts_text = $job['shifts_text'] ?? '';
                $shifts_arr = $shifts_text ? explode('|', $shifts_text) : [];
                $applicant_count = (int)($job['application_count'] ?? 0);
                $rejection_reason = $job['rejection_reason'] ?? '';
            ?>
            <div class="job-card" data-job-id="<?php echo (int)$job['id']; ?>"
                 data-title="<?php echo htmlspecialchars($job['title']); ?>"
                 data-description="<?php echo htmlspecialchars($desc); ?>"
                 data-wage="<?php echo htmlspecialchars($job['wage']); ?>"
                 data-location="<?php echo htmlspecialchars($job['location']); ?>"
                 data-shifts="<?php echo htmlspecialchars(json_encode($shifts_arr)); ?>"
                 data-requirements="<?php echo htmlspecialchars(json_encode($reqs)); ?>">
                <div class="job-header">
                    <div class="job-title">
                        <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                        <?php if ($is_rejected): ?>
                        <span class="status-rejected" style="background:#fee2e2;color:#b91c1c;padding:6px 12px;border-radius:20px;font-size:0.9rem;font-weight:600;">Rejected</span>
                        <?php elseif ($is_pending): ?>
                        <span class="status-pending" style="background:#fef3c7;color:#b45309;padding:6px 12px;border-radius:20px;font-size:0.9rem;font-weight:600;">Pending</span>
                        <?php elseif ($is_active): ?>
                        <span class="status-open">Open</span>
                        <?php elseif ($is_closed): ?>
                        <span class="status-closed">Closed</span>
                        <?php else: ?>
                        <span class="status-closed">Closed</span>
                        <?php endif; ?>
                        <span class="applicants-wrap"><span class="applicants-text"><?php echo $applicant_count; ?> applicants</span></span>
                    </div>
                </div>
                <?php if ($is_pending): ?>
                <div class="pending-notice" style="background:#fffbeb;border:1px solid #fde68a;color:#b45309;padding:10px 14px;border-radius:10px;margin-bottom:12px;font-size:0.9rem;">
                    Awaiting admin approval. Students will not see this job until it is approved.
                </div>
                <?php endif; ?>
                
                <?php if ($is_rejected): ?>
                <div class="rejection-notice" style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:12px;margin-bottom:15px;">
                    <strong>Rejected by admin.</strong>
                    <?php if ($rejection_reason !== ''): ?>
                    <span>Reason: <?php echo nl2br(htmlspecialchars($rejection_reason)); ?></span>
                    <?php else: ?>
                    <span>No reason provided.</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="job-description" style="color: #334155; margin: 15px 0;">
                    <?php echo nl2br(htmlspecialchars($desc)); ?>
                </div>
                
                <div class="job-meta">
                    <span>Wage: <?php echo (is_numeric($job['wage']) ? 'RM ' . $job['wage'] : htmlspecialchars($job['wage'])); ?>/hour</span>
                    <span>Location: <?php echo htmlspecialchars($job['location']); ?></span>
                    <span>Posted Date: <?php echo htmlspecialchars($job['posted_date']); ?></span>
                </div>
                
                <?php if (!empty($shifts_arr)): ?>
                <div class="shifts-section">
                    <strong>Shifts:</strong>
                    <div class="shifts-list">
                        <?php foreach ($shifts_arr as $shift): ?>
                            <span class="shift-tag"><?php echo htmlspecialchars(trim($shift)); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($reqs)): ?>
                <div class="requirements-section">
                    <strong>Requirements:</strong>
                    <ul class="requirements-list">
                        <?php foreach ($reqs as $r): ?>
                            <li><?php echo htmlspecialchars($r); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="job-actions">
                    <?php if ($applicant_count > 0): ?>
                    <a href="jobApplications.php?job_id=<?php echo (int)$job['id']; ?>" class="applications-badge">View applications</a>
                    <?php endif; ?>
                    <?php if ($is_pending || $is_rejected): ?>
                    <button type="button" class="edit-job-btn" onclick="openEditModal(this.closest('.job-card'))">Edit & re-post</button>
                    <?php endif; ?>
                    <?php if ($is_active): ?>
                    <a href="?close=<?php echo (int)$job['id']; ?>" class="close-posting-btn" onclick="return confirm('Close this posting? Students will no longer see it.');">Close Posting</a>
                    <?php endif; ?>
                    <span class="delete-btn-wrap">
                        <a href="?delete=<?php echo (int)$job['id']; ?>" class="delete-btn" onclick="return confirm('Permanently delete this job posting? This cannot be undone.');">Delete</a>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function addShiftInput() {
            const container = document.getElementById('shiftsContainer');
            const div = document.createElement('div');
            div.className = 'shift-row';
            div.innerHTML = '<input type="text" name="shifts[]" placeholder="e.g., Tuesday 12pm-2pm"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
            container.appendChild(div);
        }
        function addRequirementInput() {
            const container = document.getElementById('requirementsContainer');
            const div = document.createElement('div');
            div.className = 'req-row';
            div.innerHTML = '<input type="text" name="requirements[]" placeholder="e.g., Able to work during lunch hours"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
            container.appendChild(div);
        }
        document.getElementById('openCreateModal').addEventListener('click', function() {
            document.getElementById('createModal').classList.add('show');
        });
        document.getElementById('closeCreateModal').addEventListener('click', function() {
            document.getElementById('createModal').classList.remove('show');
        });
        document.getElementById('createModal').addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
        function openEditModal(card) {
            if (!card) return;
            var id = card.getAttribute('data-job-id');
            var title = card.getAttribute('data-title') || '';
            var desc = card.getAttribute('data-description') || '';
            var wage = card.getAttribute('data-wage') || '';
            var location = card.getAttribute('data-location') || '';
            var shifts = [];
            try { shifts = JSON.parse(card.getAttribute('data-shifts') || '[]'); } catch (e) {}
            var reqs = [];
            try { reqs = JSON.parse(card.getAttribute('data-requirements') || '[]'); } catch (e) {}
            document.getElementById('edit_job_id').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_description').value = desc;
            document.getElementById('edit_wage').value = wage;
            document.getElementById('edit_location').value = location;
            var shiftCont = document.getElementById('edit_shiftsContainer');
            shiftCont.innerHTML = '';
            if (shifts.length === 0) {
                shiftCont.innerHTML = '<div class="shift-row"><input type="text" name="shifts[]" placeholder="e.g., Monday 12pm-2pm"><button type="button" class="add-row-btn" onclick="addEditShiftInput()">+</button></div>';
            } else {
                shifts.forEach(function(s, i) {
                    var div = document.createElement('div');
                    div.className = 'shift-row';
                    div.innerHTML = '<input type="text" name="shifts[]" value="' + (s || '').replace(/"/g, '&quot;') + '" placeholder="e.g., Monday 12pm-2pm"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
                    shiftCont.appendChild(div);
                });
                var addRow = document.createElement('div');
                addRow.className = 'shift-row';
                addRow.innerHTML = '<input type="text" name="shifts[]" placeholder="Add another shift"><button type="button" class="add-row-btn" onclick="addEditShiftInput()">+</button>';
                shiftCont.appendChild(addRow);
            }
            var reqCont = document.getElementById('edit_requirementsContainer');
            reqCont.innerHTML = '';
            if (reqs.length === 0) {
                reqCont.innerHTML = '<div class="req-row"><input type="text" name="requirements[]" placeholder="e.g., Good communication skills"><button type="button" class="add-row-btn" onclick="addEditRequirementInput()">+</button></div>';
            } else {
                reqs.forEach(function(r) {
                    var div = document.createElement('div');
                    div.className = 'req-row';
                    div.innerHTML = '<input type="text" name="requirements[]" value="' + (r || '').replace(/"/g, '&quot;') + '" placeholder="Requirement"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
                    reqCont.appendChild(div);
                });
                var addR = document.createElement('div');
                addR.className = 'req-row';
                addR.innerHTML = '<input type="text" name="requirements[]" placeholder="Add another"><button type="button" class="add-row-btn" onclick="addEditRequirementInput()">+</button>';
                reqCont.appendChild(addR);
            }
            document.getElementById('editModal').classList.add('show');
        }
        function addEditShiftInput() {
            var c = document.getElementById('edit_shiftsContainer');
            var d = document.createElement('div');
            d.className = 'shift-row';
            d.innerHTML = '<input type="text" name="shifts[]" placeholder="e.g., Tuesday 12pm-2pm"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
            c.appendChild(d);
        }
        function addEditRequirementInput() {
            var c = document.getElementById('edit_requirementsContainer');
            var d = document.createElement('div');
            d.className = 'req-row';
            d.innerHTML = '<input type="text" name="requirements[]" placeholder="e.g., Able to work during lunch hours"><button type="button" class="add-row-btn" onclick="this.parentElement.remove()">−</button>';
            c.appendChild(d);
        }
        document.getElementById('closeEditModal').addEventListener('click', function() { document.getElementById('editModal').classList.remove('show'); });
        document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) this.classList.remove('show'); });
        var successMsg = document.querySelector('.success-msg');
        if (successMsg) setTimeout(function() { successMsg.style.display = 'none'; }, 5000);
    </script>
    </div>
</body>
</html>