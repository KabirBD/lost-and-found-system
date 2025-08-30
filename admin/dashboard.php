<?php
session_start();
include("../includes/db.php");
include("../includes/admin_navigation.php");

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$admin_name = $_SESSION['name'];

// Get admin profile information including photo
$admin_query = "SELECT u.first_name, u.last_name, p.url as profile_photo FROM users u LEFT JOIN photos p ON u.photo_id = p.photo_id WHERE u.user_id = ?";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bind_param("i", $user_id);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin_info = $admin_result->fetch_assoc();

// Get statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(*) FROM items) as total_items,
        (SELECT COUNT(*) FROM claim_approvals WHERE approved_at IS NULL) as pending_claims,
        (SELECT COUNT(*) FROM claim_approvals) as total_claims
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Get recent activity
$recent_items_query = "
    SELECT i.item_id, i.title, i.status, i.posted_at, 
           u.first_name, u.last_name
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    ORDER BY i.posted_at DESC
    LIMIT 5
";
$recent_items = $conn->query($recent_items_query);

// Get pending claims
$pending_claims_query = "
    SELECT ca.claim_id, ca.claimed_at, ca.statement,
           i.title as item_title, i.item_id,
           u.first_name, u.last_name
    FROM claim_approvals ca
    JOIN items i ON ca.item_id = i.item_id
    JOIN users u ON ca.user_id = u.user_id
    WHERE ca.approved_at IS NULL
    ORDER BY ca.claimed_at DESC
    LIMIT 5
";
$pending_claims = $conn->query($pending_claims_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Lost and Found</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/spacing.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderAdminStyles(); ?>
    <?php renderAdminStyles(); ?>
    <style>
        .stats-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .profile-photo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .profile-placeholder {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .activity-item {
            border-left: 4px solid #007bff;
            padding-left: 1rem;
        }
        
        /* Icon and spacing improvements */
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
        
        /* Consistent icon-text spacing */
        .bi:not(.badge .bi) {
            margin-right: 0.4rem;
        }
        
        /* Button and card spacing in grids */
        .row .card {
            margin-bottom: 1.25rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation('admin'); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('dashboard'); ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-0">Admin Dashboard</h1>
                        <p class="text-muted">Manage the lost and found system</p>
                    </div>
                    <div class="d-flex align-items-center">
                        <?php if (!empty($admin_info['profile_photo']) && file_exists($admin_info['profile_photo'])): ?>
                            <img src="<?php echo htmlspecialchars($admin_info['profile_photo']); ?>" 
                                 alt="Admin Photo" class="profile-photo me-3">
                        <?php else: ?>
                            <div class="profile-placeholder me-3">
                                <?php echo strtoupper(substr($admin_info['first_name'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h5 class="mb-0"><?php echo htmlspecialchars($admin_info['first_name'] . ' ' . $admin_info['last_name']); ?></h5>
                            <small class="text-muted">Administrator</small>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="card stats-card text-center p-4">
                            <div class="card-body">
                                <i class="bi bi-people fs-1 text-primary mb-3"></i>
                                <h3 class="fw-bold text-primary"><?php echo isset($stats['total_users']) ? $stats['total_users'] : 0; ?></h3>
                                <p class="text-muted mb-0">Total Users</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stats-card text-center p-4">
                            <div class="card-body">
                                <i class="bi bi-collection fs-1 text-info mb-3"></i>
                                <h3 class="fw-bold text-info"><?php echo isset($stats['total_items']) ? $stats['total_items'] : 0; ?></h3>
                                <p class="text-muted mb-0">Total Items</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stats-card text-center p-4">
                            <div class="card-body">
                                <i class="bi bi-clipboard-check fs-1 text-warning mb-3"></i>
                                <h3 class="fw-bold text-warning"><?php echo isset($stats['pending_claims']) ? $stats['pending_claims'] : 0; ?></h3>
                                <p class="text-muted mb-0">Pending Claims</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="card stats-card text-center p-4">
                            <div class="card-body">
                                <i class="bi bi-check-circle fs-1 text-success mb-3"></i>
                                <h3 class="fw-bold text-success"><?php echo isset($stats['total_claims']) ? $stats['total_claims'] : 0; ?></h3>
                                <p class="text-muted mb-0">Total Claims</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions and Activity -->
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning me-2"></i>Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-3">
                                    <a href="approve_items.php" class="btn btn-primary btn-lg mb-2">
                                        <i class="bi bi-check-circle me-2"></i>
                                        Approve Items
                                        <?php 
                                        $pending_items_query = "SELECT COUNT(*) as pending FROM items WHERE approved_at IS NULL";
                                        $pending_items_result = $conn->query($pending_items_query);
                                        $pending_items = $pending_items_result->fetch_assoc()['pending'];
                                        if ($pending_items > 0): ?>
                                            <span class="badge bg-warning ms-2"><?php echo $pending_items; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="manage_claims.php" class="btn btn-warning btn-lg mb-2">
                                        <i class="bi bi-clipboard-check me-2"></i>
                                        Manage Claims
                                        <?php if ($stats['pending_claims'] > 0): ?>
                                            <span class="badge bg-danger ms-2"><?php echo $stats['pending_claims']; ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <a href="manage_items.php" class="btn btn-info btn-lg mb-2">
                                        <i class="bi bi-box me-2"></i>
                                        Manage Items
                                    </a>
                                    <a href="manage_admins.php" class="btn btn-secondary btn-lg mb-2">
                                        <i class="bi bi-people-fill me-2"></i>
                                        Manage Admins
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-activity me-2"></i>Recent Activity
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if ($recent_items->num_rows > 0): ?>
                                    <div class="activity-list">
                                        <?php while ($activity = $recent_items->fetch_assoc()): ?>
                                            <div class="activity-item mb-3 pb-3 border-bottom">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h6 class="mb-1">
                                                            <?php echo htmlspecialchars($activity['title']); ?>
                                                        </h6>
                                                        <p class="text-muted mb-1 small">
                                                            Posted by <?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?>
                                                        </p>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, Y g:i A', strtotime($activity['posted_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $activity['status'] === 'lost' ? 'danger' : 'success'; ?>">
                                                        <?php echo ucfirst($activity['status']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                    <div class="text-center mt-3">
                                        <a href="manage_items.php" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-arrow-right me-1"></i>View All Items
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                                        <p class="text-muted mt-2">No recent activity</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Claims Section -->
                <?php if ($pending_claims->num_rows > 0): ?>
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-exclamation-triangle me-2"></i>Pending Claims Requiring Attention
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php while ($claim = $pending_claims->fetch_assoc()): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="border rounded p-3 bg-light">
                                                    <h6><?php echo htmlspecialchars($claim['item_title']); ?></h6>
                                                    <p class="text-muted mb-2 small">
                                                        Claimed by: <?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?>
                                                    </p>
                                                    <p class="small mb-2">
                                                        <?php echo htmlspecialchars(substr($claim['statement'], 0, 100)) . '...'; ?>
                                                    </p>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-muted">
                                                            <?php echo date('M j, Y', strtotime($claim['claimed_at'])); ?>
                                                        </small>
                                                        <a href="manage_claims.php" class="btn btn-warning btn-sm">
                                                            Review
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
</body>
</html>

<?php
$conn->close();
?>
