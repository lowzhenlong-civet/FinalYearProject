<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: /beta-assignment/login.php");
    exit();
}

$db = (new Database())->getConnection();
$student_id = $_SESSION['user_id'];

//handle job application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_job'])) {
    $job_id = $_POST['job_id'];
    $selected_shifts = $_POST['selected_shifts'] ?? [];
    
    //check if already applied
    $checkQuery = "SELECT id FROM job_applications WHERE student_id = :student_id AND job_id = :job_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':student_id', $student_id);
    $checkStmt->bindParam(':job_id', $job_id);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() == 0) {
        $shifts_json = json_encode($selected_shifts);
        $query = "INSERT INTO job_applications (student_id, job_id, shifts_applied) 
                  VALUES (:student_id, :job_id, :shifts)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':job_id', $job_id);
        $stmt->bindParam(':shifts', $shifts_json);
        $stmt->execute();
        
        header("Location: jobSearch.php?applied=1");
        exit();
    }
}

//only show jobs that admin has approved
$query = "SELECT j.*, u.email as merchant_email,
          (SELECT GROUP_CONCAT(shift_description SEPARATOR '|') FROM job_shifts WHERE job_id = j.id) as shifts_string
          FROM job_postings j
          JOIN users u ON j.merchant_id = u.id
          ORDER BY j.posted_date DESC";
try {
    $stmt = $db->prepare($query);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $jobs = array_values(array_filter($jobs, function ($j) {
        $s = isset($j['job_post_status']) ? trim((string)$j['job_post_status']) : (isset($j['status']) ? trim((string)$j['status']) : '');
        return $s === 'active';
    }));
} catch (Exception $e) {
    $jobs = [];
}

//get applications by this student
$appQuery = "SELECT job_id FROM job_applications WHERE student_id = :student_id";
$appStmt = $db->prepare($appQuery);
$appStmt->bindParam(':student_id', $student_id);
$appStmt->execute();
$applied_jobs = $appStmt->fetchAll(PDO::FETCH_COLUMN);
$current_page = 'jobs';
$theme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GigFood TARUMT Platform · Job Search</title>
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
        <a href="jobSearch.php" class="tab active">Job Search</a>
        <a href="orderHistory.php" class="tab">Order History</a>
        <a href="myApplications.php" class="tab">My Applications</a>
        <a href="activityHistory.php" class="tab" id="nav-activity">Activity History</a>
    </div>

    <div class="intro-section">
        <h3>Find Part-Time Jobs</h3>
        <p>Browse available positions and apply for flexible shifts that fit your schedule</p>
        <input type="text" class="search-box" id="searchInput" placeholder="Search jobs by title, description or location...">
    </div>

    <?php if (isset($_GET['applied'])): ?>
    <div class="success-msg" style="background:#e0f0e9;color:#166b41;padding:12px 20px;border-radius:12px;margin-bottom:16px;">
        ✓ Application submitted successfully!
    </div>
    <?php endif; ?>

    <div class="category-title">
        <h4>Job Search</h4>
    </div>

    <div id="jobContainer">
        <?php if (empty($jobs)): ?>
            <div style="background: white; border-radius: 15px; padding: 40px; text-align: center; color: #64748b;">
                 No jobs available at the moment.
            </div>
        <?php else: ?>
            <?php foreach ($jobs as $job): 
                $shifts = explode('|', $job['shifts_string'] ?? '');
                $already_applied = in_array($job['id'], $applied_jobs);
            ?>
            <div class="job-card" data-job-id="<?php echo $job['id']; ?>">
                <div class="job-header">
                    <div class="job-title">
                        <h3><?php echo htmlspecialchars($job['title']); ?></h3>
                        <div class="job-subtitle"><?php echo htmlspecialchars($job['subtitle'] ?? $job['merchant_email']); ?></div>
                    </div>
                    <span class="job-type"><?php echo htmlspecialchars($job['type']); ?></span>
                </div>
                
                <div class="job-description">
                    <?php echo nl2br(htmlspecialchars($job['description'])); ?>
                </div>
                
                <div class="job-meta">
                    <span>Wage: <?php echo htmlspecialchars($job['wage']); ?></span>
                    <span>Location: <?php echo htmlspecialchars($job['location']); ?></span>
                    <span>Posted Date: <?php echo htmlspecialchars($job['posted_date']); ?></span>
                </div>
                
                <?php if (!empty($shifts) && !$already_applied): ?>
                <form method="POST" action="" class="apply-form" data-job-id="<?php echo $job['id']; ?>">
                    <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                    <div class="shifts-section">
                        <strong>Select shifts you can work:</strong>
                        <div class="shifts-list">
                            <?php foreach ($shifts as $shift): 
                                if (empty($shift)) continue;
                            ?>
                            <label class="shift-checkbox">
                                <input type="checkbox" name="selected_shifts[]" value="<?php echo htmlspecialchars($shift); ?>">
                                <?php echo htmlspecialchars($shift); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <button type="submit" name="apply_job" class="apply-btn">Apply Now</button>
                </form>
                <?php elseif ($already_applied): ?>
                <div style="margin-top: 15px;">
                    <span class="applied-badge">✓ Applied</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        document.getElementById('searchInput').addEventListener('input', function() {
            const term = this.value.toLowerCase();
            const cards = document.querySelectorAll('.job-card');
            
            cards.forEach(card => {
                const title = card.querySelector('.job-title h3')?.textContent.toLowerCase() || '';
                const subtitle = card.querySelector('.job-subtitle')?.textContent.toLowerCase() || '';
                const description = card.querySelector('.job-description')?.textContent.toLowerCase() || '';
                const location = card.querySelector('.job-meta span:last-child')?.textContent.toLowerCase() || '';
                
                if (title.includes(term) || subtitle.includes(term) || description.includes(term) || location.includes(term)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        //shift checkbox styling
        document.querySelectorAll('.shift-checkbox input').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    this.parentElement.classList.add('selected');
                } else {
                    this.parentElement.classList.remove('selected');
                }
            });
        });

        //hide success message after 10 seconds
        const successMsg = document.querySelector('.success-msg');
        if (successMsg) {
            setTimeout(() => successMsg.style.display = 'none', 10000); //10 seconds
        }
    </script>
    </div>
</body>
</html>