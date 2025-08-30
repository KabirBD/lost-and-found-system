<?php
session_start();
include("../includes/db.php");
include("../includes/admin_navigation.php");

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$current_admin_id = $_SESSION['admin_id'] ?? null;

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_admin' && isset($_POST['user_email'])) {
        $user_email = trim($_POST['user_email']);
        
        // Find user by email
        $user_query = "SELECT u.user_id, u.first_name, u.last_name FROM users u 
                       JOIN user_emails ue ON u.user_id = ue.user_id 
                       WHERE ue.email = ?";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bind_param("s", $user_email);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        
        if ($user_result->num_rows > 0) {
            $user = $user_result->fetch_assoc();
            $user_id = $user['user_id'];
            
            // Check if user is already an admin
            $admin_check = $conn->prepare("SELECT admin_id FROM admins WHERE user_id = ?");
            $admin_check->bind_param("i", $user_id);
            $admin_check->execute();
            $admin_check_result = $admin_check->get_result();
            
            if ($admin_check_result->num_rows === 0) {
                // Add user as admin
                $add_admin = $conn->prepare("INSERT INTO admins (user_id) VALUES (?)");
                $add_admin->bind_param("i", $user_id);
                
                if ($add_admin->execute()) {
                    // Send notification
                    if ($current_admin_id) {
                        $message = "Congratulations! You have been granted administrator privileges for the Lost and Found system.";
                        $notify_stmt = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
                        $notify_stmt->bind_param("sii", $message, $current_admin_id, $user_id);
                        $notify_stmt->execute();
                    }
                    
                    $success_message = "User " . $user['first_name'] . " " . $user['last_name'] . " has been added as an admin.";
                } else {
                    $error_message = "Error adding admin.";
                }
            } else {
                $error_message = "User is already an admin.";
            }
        } else {
            $error_message = "User with email '$user_email' not found.";
        }
    }
    
    if ($action === 'remove_admin' && isset($_POST['admin_id'])) {
        $admin_to_remove = $_POST['admin_id'];
        
        // Prevent removing self
        if ($admin_to_remove == $current_admin_id) {
            $error_message = "You cannot remove yourself as an admin.";
        } else {
            // Get user info before removing
            $admin_info_query = "SELECT u.first_name, u.last_name, u.user_id FROM admins a 
                                JOIN users u ON a.user_id = u.user_id WHERE a.admin_id = ?";
            $admin_info_stmt = $conn->prepare($admin_info_query);
            $admin_info_stmt->bind_param("i", $admin_to_remove);
            $admin_info_stmt->execute();
            $admin_info_result = $admin_info_stmt->get_result();
            $admin_info = $admin_info_result->fetch_assoc();
            
            // Remove admin
            $remove_admin = $conn->prepare("DELETE FROM admins WHERE admin_id = ?");
            $remove_admin->bind_param("i", $admin_to_remove);
            
            if ($remove_admin->execute()) {
                // Send notification
                if ($current_admin_id && $admin_info) {
                    $message = "Your administrator privileges for the Lost and Found system have been revoked.";
                    $notify_stmt = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
                    $notify_stmt->bind_param("sii", $message, $current_admin_id, $admin_info['user_id']);
                    $notify_stmt->execute();
                }
                
                $success_message = "Admin " . $admin_info['first_name'] . " " . $admin_info['last_name'] . " has been removed.";
            } else {
                $error_message = "Error removing admin.";
            }
        }
    }
}

// Fetch all admins
$admins_query = "
    SELECT a.admin_id, a.assigned_at, u.user_id, u.first_name, u.last_name,
           ue.email, p.url as photo_url
    FROM admins a
    JOIN users u ON a.user_id = u.user_id
    LEFT JOIN user_emails ue ON u.user_id = ue.user_id
    LEFT JOIN photos p ON u.photo_id = p.photo_id
    GROUP BY a.admin_id
    ORDER BY a.assigned_at DESC
";
$admins_result = $conn->query($admins_query);

// Get admin statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM admins) as total_admins,
        (SELECT COUNT(*) FROM items WHERE admin_id IS NOT NULL) as items_approved,
        (SELECT COUNT(*) FROM claim_approvals WHERE admin_id IS NOT NULL) as claims_processed
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/spacing.css" rel="stylesheet">
    <link href="../assets/css/form-styling.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderAdminStyles(); ?>
    <style>
        .admin-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .admin-card:hover {
            transform: translateY(-2px);
        }
        .admin-avatar {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 50%;
        }
        .admin-placeholder {
            width: 60px;
            height: 60px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-weight: bold;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation('admin'); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('manage_admins'); ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10"">
                <div class="p-4">
                    <h2><i class="bi bi-people-fill me-2"></i>Manage Administrators</h2>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card text-white bg-primary">
                                <div class="card-body">
                                    <h5 class="card-title">Total Admins</h5>
                                    <h2><?php echo $stats['total_admins']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-success">
                                <div class="card-body">
                                    <h5 class="card-title">Items Approved</h5>
                                    <h2><?php echo $stats['items_approved']; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-info">
                                <div class="card-body">
                                    <h5 class="card-title">Claims Processed</h5>
                                    <h2><?php echo $stats['claims_processed']; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Add New Admin -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="bi bi-person-plus me-2"></i>Add New Administrator</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" class="row g-3">
                                <div class="col-md-8">
                                    <label for="user_email" class="form-label">User Email</label>
                                    <input type="email" class="form-control" id="user_email" name="user_email" 
                                           placeholder="Enter the email of the user to make admin" required>
                                    <div class="form-text">The user must already be registered in the system.</div>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" name="action" value="add_admin" class="btn btn-primary">
                                        <i class="bi bi-plus-circle me-2"></i>Add Admin
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Current Admins -->
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-people me-2"></i>Current Administrators</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($admins_result->num_rows > 0): ?>
                                <div class="row">
                                    <?php while ($admin = $admins_result->fetch_assoc()): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card admin-card">
                                                <div class="card-body">
                                                    <div class="d-flex align-items-center">
                                                        <div class="me-3">
                                                            <?php if (!empty($admin['photo_url'])): ?>
                                                                <img src="../uploads/<?php echo htmlspecialchars($admin['photo_url']); ?>" 
                                                                     alt="<?php echo htmlspecialchars($admin['first_name']); ?>" 
                                                                     class="admin-avatar"
                                                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                                <div class="admin-placeholder" style="display: none;">
                                                                    <?php echo strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)); ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <div class="admin-placeholder">
                                                                    <?php echo strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1)); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-1"><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h6>
                                                            <p class="text-muted mb-1 small">
                                                                <?php echo htmlspecialchars($admin['email']); ?>
                                                            </p>
                                                            <p class="text-muted mb-0 small">
                                                                Admin since: <?php echo date('M j, Y', strtotime($admin['assigned_at'])); ?>
                                                            </p>
                                                        </div>
                                                        <?php if ($admin['admin_id'] != $current_admin_id): ?>
                                                            <div>
                                                                <form method="POST" class="d-inline">
                                                                    <input type="hidden" name="admin_id" value="<?php echo $admin['admin_id']; ?>">
                                                                    <button type="submit" name="action" value="remove_admin" 
                                                                            class="btn btn-outline-danger btn-sm"
                                                                            onclick="return confirm('Remove <?php echo htmlspecialchars($admin['first_name']); ?> as admin?')">
                                                                        <i class="bi bi-person-dash"></i> Remove
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        <?php else: ?>
                                                            <div>
                                                                <span class="badge bg-primary">You</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                                    <h5 class="mt-3 text-muted">No Administrators Found</h5>
                                </div>
                            <?php endif; ?>
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
