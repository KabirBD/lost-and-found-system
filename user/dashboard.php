<?php
session_start();
include("../includes/db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Get user profile information including photo and all details
$user_query = "
    SELECT u.first_name, u.last_name, u.house_no, u.street, u.city, u.created_at,
           p.url as profile_photo
    FROM users u 
    LEFT JOIN photos p ON u.photo_id = p.photo_id 
    WHERE u.user_id = ?
";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_info = $user_result->fetch_assoc();

// Get user emails
$emails_query = "SELECT email FROM user_emails WHERE user_id = ?";
$emails_stmt = $conn->prepare($emails_query);
$emails_stmt->bind_param("i", $user_id);
$emails_stmt->execute();
$emails_result = $emails_stmt->get_result();
$user_emails = [];
while ($email_row = $emails_result->fetch_assoc()) {
    $user_emails[] = $email_row['email'];
}

// Get user phones
$phones_query = "SELECT phone FROM user_phones WHERE user_id = ?";
$phones_stmt = $conn->prepare($phones_query);
$phones_stmt->bind_param("i", $user_id);
$phones_stmt->execute();
$phones_result = $phones_stmt->get_result();
$user_phones = [];
while ($phone_row = $phones_result->fetch_assoc()) {
    $user_phones[] = $phone_row['phone'];
}

// Check if user is admin
$admin_query = "SELECT admin_id, assigned_at FROM admins WHERE user_id = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("i", $user_id);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$is_admin = $admin_result->num_rows > 0;
$admin_info = $admin_result->fetch_assoc();

// Get user statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_items,
        SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) as found_items,
        SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed_items,
        SUM(CASE WHEN admin_id IS NOT NULL THEN 1 ELSE 0 END) as approved_items,
        SUM(CASE WHEN admin_id IS NULL THEN 1 ELSE 0 END) as pending_items
    FROM items WHERE user_id = ?
";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

// Get claims statistics
$claims_query = "
    SELECT 
        COUNT(*) as total_claims,
        SUM(CASE WHEN approved_at IS NOT NULL THEN 1 ELSE 0 END) as approved_claims,
        SUM(CASE WHEN approved_at IS NULL THEN 1 ELSE 0 END) as pending_claims
    FROM claim_approvals WHERE user_id = ?
";
$claims_stmt = $conn->prepare($claims_query);
$claims_stmt->bind_param("i", $user_id);
$claims_stmt->execute();
$claims_stats = $claims_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css" />
    <link rel="stylesheet" href="../assets/css/spacing.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <title>Dashboard - Lost and Found</title>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .profile-info-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .stats-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            height: 100%;
        }

        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }

        .stats-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stats-number {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stats-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: #6c757d;
        }

        .profile-photo-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .profile-placeholder-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            border: 4px solid #fff;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .info-row {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f3f4;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            display: flex;
            align-items: center;
        }

        .info-value {
            color: #212529;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }

        /* Icon and button spacing improvements */
        .icon-spacing {
            margin-right: 0.5rem;
        }

        .btn-spacing {
            margin-right: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .card-spacing {
            margin-bottom: 1.5rem;
        }

        .stats-grid {
            gap: 1.25rem;
        }

        /* Consistent icon-text spacing throughout */
        .bi:not(.badge .bi) {
            margin-right: 0.4rem;
        }

        /* Button group spacing */
        .btn-group .btn {
            margin-right: 0.5rem;
        }

        .btn-group .btn:last-child {
            margin-right: 0;
        }

        /* Card spacing in grids */
        .row .card {
            margin-bottom: 1.25rem;
        }

        .badge-custom {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 8px;
        }

        .card-header-custom {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
            padding: 1.25rem 1.5rem;
        }

        .quick-action-btn {
            padding: 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: block;
            text-align: center;
        }

        .quick-action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }

        .quick-action-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            display: block;
        }

        .progress-custom {
            height: 8px;
            border-radius: 4px;
            background-color: #e9ecef;
        }

        .icon-spacing {
            margin-right: 0.75rem;
        }
    </style>
</head>

<body class="bg-light">
    <?php include('../includes/navigation.php');
    renderNavigation('dashboard'); ?>

    <div class="dashboard-container">
        <!-- Welcome Header -->
        <div class="welcome-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="h2 mb-2 fw-bold">Welcome <?php echo htmlspecialchars($user_info['first_name']); ?>!</h1>
                    <p class="mb-0 opacity-90">Manage your lost and found items</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <div class="d-flex align-items-center justify-content-md-end">
                        <?php if (!empty($user_info['profile_photo']) && file_exists($user_info['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($user_info['profile_photo']); ?>"
                                alt="Profile Photo" class="profile-photo-large me-3">
                        <?php else: ?>
                            <div class="profile-placeholder-large me-3">
                                <?php echo strtoupper(substr($user_info['first_name'], 0, 1) . substr($user_info['last_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Profile Information Card -->
        <div class="profile-info-card card">
            <div class="card-header card-header-custom">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 section-title">
                        <i class="bi bi-person-circle icon-spacing"></i>Profile Information
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($is_admin): ?>
                            <span class="badge badge-custom bg-warning text-dark">
                                <i class="bi bi-shield-check me-2"></i>Administrator
                            </span>
                        <?php endif; ?>
                        <a href="edit_info.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil-square me-2"></i>Edit Info
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row">
                    <!-- Basic Information -->
                    <div class="col-md-6">
                        <h6 class="section-title">
                            <i class="bi bi-info-circle icon-spacing"></i>Basic Information
                        </h6>
                        <div class="info-row">
                            <div class="row">
                                <div class="col-5">
                                    <span class="info-label">
                                        <i class="bi bi-person icon-spacing"></i>Full Name:
                                    </span>
                                </div>
                                <div class="col-7">
                                    <span class="info-value"><?php echo htmlspecialchars($user_info['first_name'] . ' ' . $user_info['last_name']); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="row">
                                <div class="col-5">
                                    <span class="info-label">
                                        <i class="bi bi-hash icon-spacing"></i>User ID:
                                    </span>
                                </div>
                                <div class="col-7">
                                    <span class="info-value">#<?php echo $user_id; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="info-row">
                            <div class="row">
                                <div class="col-5">
                                    <span class="info-label">
                                        <i class="bi bi-calendar-check icon-spacing"></i>Member Since:
                                    </span>
                                </div>
                                <div class="col-7">
                                    <span class="info-value"><?php echo date('M j, Y', strtotime($user_info['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php if ($is_admin): ?>
                            <div class="info-row">
                                <div class="row">
                                    <div class="col-5">
                                        <span class="info-label">
                                            <i class="bi bi-shield-check icon-spacing"></i>Admin Since:
                                        </span>
                                    </div>
                                    <div class="col-7">
                                        <span class="info-value"><?php echo date('M j, Y', strtotime($admin_info['assigned_at'])); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Contact Information -->
                    <div class="col-md-6">
                        <h6 class="section-title">
                            <i class="bi bi-telephone icon-spacing"></i>Contact Information
                        </h6>

                        <!-- Address -->
                        <div class="info-row">
                            <div class="row">
                                <div class="col-4">
                                    <span class="info-label">
                                        <i class="bi bi-house icon-spacing"></i>Address:
                                    </span>
                                </div>
                                <div class="col-8">
                                    <?php if (!empty($user_info['house_no']) || !empty($user_info['street']) || !empty($user_info['city'])): ?>
                                        <span class="info-value">
                                            <?php
                                            $address_parts = array_filter([
                                                $user_info['house_no'],
                                                $user_info['street'],
                                                $user_info['city']
                                            ]);
                                            echo htmlspecialchars(implode(', ', $address_parts));
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Email -->
                        <div class="info-row">
                            <div class="row">
                                <div class="col-4">
                                    <span class="info-label">
                                        <i class="bi bi-envelope icon-spacing"></i>Email(s):
                                    </span>
                                </div>
                                <div class="col-8">
                                    <?php if (!empty($user_emails)): ?>
                                        <?php foreach ($user_emails as $email): ?>
                                            <span class="badge badge-custom bg-light text-dark mb-1 d-block"><?php echo htmlspecialchars($email); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Phone -->
                        <div class="info-row">
                            <div class="row">
                                <div class="col-4">
                                    <span class="info-label">
                                        <i class="bi bi-phone icon-spacing"></i>Phone(s):
                                    </span>
                                </div>
                                <div class="col-8">
                                    <?php if (!empty($user_phones)): ?>
                                        <?php foreach ($user_phones as $phone): ?>
                                            <span class="badge badge-custom bg-light text-dark mb-1 d-block"><?php echo htmlspecialchars($phone); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Item Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-collection stats-icon text-primary"></i>
                        <div class="stats-number text-primary"><?php echo isset($stats['total_items']) ? $stats['total_items'] : 0; ?></div>
                        <div class="stats-label">Total Items</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-search stats-icon text-danger"></i>
                        <div class="stats-number text-danger"><?php echo isset($stats['lost_items']) ? $stats['lost_items'] : 0; ?></div>
                        <div class="stats-label">Lost Items</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-check-circle stats-icon text-success"></i>
                        <div class="stats-number text-success"><?php echo isset($stats['found_items']) ? $stats['found_items'] : 0; ?></div>
                        <div class="stats-label">Found Items</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-heart-fill stats-icon text-warning"></i>
                        <div class="stats-number text-warning"><?php echo isset($stats['claimed_items']) ? $stats['claimed_items'] : 0; ?></div>
                        <div class="stats-label">Claimed Items</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-check-square stats-icon text-info"></i>
                        <div class="stats-number text-info"><?php echo isset($stats['approved_items']) ? $stats['approved_items'] : 0; ?></div>
                        <div class="stats-label">Approved</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card stats-card text-center">
                    <div class="card-body p-3">
                        <i class="bi bi-clock stats-icon text-secondary"></i>
                        <div class="stats-number text-secondary"><?php echo isset($stats['pending_items']) ? $stats['pending_items'] : 0; ?></div>
                        <div class="stats-label">Pending</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Claims Statistics -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stats-card text-center">
                    <div class="card-body p-4">
                        <i class="bi bi-hand-thumbs-up stats-icon text-success"></i>
                        <div class="stats-number text-success"><?php echo isset($claims_stats['total_claims']) ? $claims_stats['total_claims'] : 0; ?></div>
                        <div class="stats-label">Total Claims Made</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card text-center">
                    <div class="card-body p-4">
                        <i class="bi bi-check-circle-fill stats-icon text-primary"></i>
                        <div class="stats-number text-primary"><?php echo isset($claims_stats['approved_claims']) ? $claims_stats['approved_claims'] : 0; ?></div>
                        <div class="stats-label">Approved Claims</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card text-center">
                    <div class="card-body p-4">
                        <i class="bi bi-hourglass-split stats-icon text-warning"></i>
                        <div class="stats-number text-warning"><?php echo isset($claims_stats['pending_claims']) ? $claims_stats['pending_claims'] : 0; ?></div>
                        <div class="stats-label">Pending Claims</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions and Account Summary -->
        <div class="row g-4">
            <div class="col-md-8">
                <div class="card stats-card">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0 section-title">
                            <i class="bi bi-lightning icon-spacing"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6 my-2">
                                <a href="../post_item.php" class="btn btn-primary quick-action-btn w-100">
                                    <i class="bi bi-plus-circle quick-action-icon"></i>
                                    <div class="fw-bold">Post New Item</div>
                                    <small class="opacity-75">Report lost or found items</small>
                                </a>
                            </div>
                            <div class="col-md-6 my-2">
                                <a href="../items.php" class="btn btn-outline-primary quick-action-btn w-100">
                                    <i class="bi bi-search quick-action-icon"></i>
                                    <div class="fw-bold">Browse Items</div>
                                    <small class="opacity-75">Find lost items or help others</small>
                                </a>
                            </div>
                            <div class="col-md-6 my-2">
                                <a href="../my_items.php" class="btn btn-outline-success quick-action-btn w-100">
                                    <i class="bi bi-person-lines-fill quick-action-icon"></i>
                                    <div class="fw-bold">My Items</div>
                                    <small class="opacity-75">Manage your posted items</small>
                                </a>
                            </div>
                            <div class="col-md-6 my-2">
                                <a href="../notifications.php" class="btn btn-outline-info quick-action-btn w-100">
                                    <i class="bi bi-bell quick-action-icon"></i>
                                    <div class="fw-bold">Notifications</div>
                                    <small class="opacity-75">View updates and alerts</small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-header card-header-custom">
                        <h5 class="card-title mb-0 section-title">
                            <i class="bi bi-person-check icon-spacing"></i>Account Summary
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="info-row">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-shield-check icon-spacing text-success"></i>
                                <div>
                                    <div class="fw-bold">Account Status</div>
                                    <div class="d-flex gap-2 mt-1">
                                        <span class="badge badge-custom bg-success text-white">Active</span>
                                        <?php if ($is_admin): ?>
                                            <span class="badge badge-custom bg-warning text-dark ml-2">Admin</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="d-flex align-items-center mb-3">
                                <i class="bi bi-calendar-event icon-spacing text-primary"></i>
                                <div>
                                    <div class="fw-bold">Member Since</div>
                                    <div class="text-muted"><?php echo date('F j, Y', strtotime($user_info['created_at'])); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="info-row">
                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-graph-up icon-spacing text-info"></i>
                                    <div class="fw-bold">Profile Completion</div>
                                </div>
                                <?php
                                $completion = 0;
                                $completion += !empty($user_info['first_name']) ? 20 : 0;
                                $completion += !empty($user_info['last_name']) ? 20 : 0;
                                $completion += !empty($user_emails) ? 20 : 0;
                                $completion += (!empty($user_info['house_no']) || !empty($user_info['street']) || !empty($user_info['city'])) ? 20 : 0;
                                $completion += !empty($user_info['profile_photo']) ? 20 : 0;

                                $completion_color = $completion >= 80 ? 'success' : ($completion >= 60 ? 'warning' : 'danger');
                                ?>
                                <div class="progress progress-custom">
                                    <div class="progress-bar bg-<?php echo $completion_color; ?>" role="progressbar"
                                        style="width: <?php echo $completion; ?>%" aria-valuenow="<?php echo $completion; ?>"
                                        aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <small class="text-muted"><?php echo $completion; ?>% complete</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
</body>

</html>