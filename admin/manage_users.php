<?php
session_start();
include("../includes/db.php");
include("../includes/admin_navigation.php");
include("../includes/PhotoCleanupManager.php");

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$current_admin_id = $_SESSION['admin_id'] ?? null;
$current_user_id = $_SESSION['user_id'];

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $user_to_delete = $_POST['user_id'];

    // Prevent admin from deleting themselves
    if ($user_to_delete == $current_user_id) {
        $error_message = "You cannot delete your own account.";
    } else {
        // Get user info before deleting
        $user_info_query = "SELECT first_name, last_name FROM users WHERE user_id = ?";
        $user_info_stmt = $conn->prepare($user_info_query);
        $user_info_stmt->bind_param("i", $user_to_delete);
        $user_info_stmt->execute();
        $user_info_result = $user_info_stmt->get_result();
        $user_info = $user_info_result->fetch_assoc();

        if ($user_info) {
            // Start transaction
            $conn->begin_transaction();

            try {
                // Initialize PhotoCleanupManager
                $photoCleanup = new PhotoCleanupManager($conn);

                // Delete user (CASCADE will handle related records and triggers will queue photo cleanup)
                $delete_user = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $delete_user->bind_param("i", $user_to_delete);
                $delete_user->execute();

                // Commit transaction
                $conn->commit();

                // Process photo cleanup queue (this will handle actual file deletion)
                $photoCleanup->processCleanupQueue();

                $success_message = "User " . $user_info['first_name'] . " " . $user_info['last_name'] . " has been deleted successfully.";
            } catch (Exception $e) {
                // Rollback transaction
                $conn->rollback();
                $error_message = "Error deleting user: " . $e->getMessage();
            }
        } else {
            $error_message = "User not found.";
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$sort = $_GET['sort'] ?? 'name';

// Build WHERE clause
$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR ue.email LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if (!empty($role_filter)) {
    if ($role_filter === 'admin') {
        $where_conditions[] = "a.admin_id IS NOT NULL";
    } elseif ($role_filter === 'user') {
        $where_conditions[] = "a.admin_id IS NULL";
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Determine sort order
$order_clause = "ORDER BY ";
switch ($sort) {
    case 'name':
        $order_clause .= "u.first_name, u.last_name";
        break;
    case 'date':
        $order_clause .= "u.created_at DESC";
        break;
    case 'email':
        $order_clause .= "ue.email";
        break;
    default:
        $order_clause .= "u.first_name, u.last_name";
        break;
}

// Fetch users with admin status and statistics
$users_query = "
    SELECT u.user_id, u.first_name, u.last_name, u.created_at,
           ue.email, p.url as photo_url,
           a.admin_id, a.assigned_at,
           COUNT(DISTINCT i.item_id) as total_items,
           COUNT(DISTINCT ca.claim_id) as total_claims
    FROM users u
    LEFT JOIN user_emails ue ON u.user_id = ue.user_id
    LEFT JOIN photos p ON u.photo_id = p.photo_id
    LEFT JOIN admins a ON u.user_id = a.user_id
    LEFT JOIN items i ON u.user_id = i.user_id
    LEFT JOIN claim_approvals ca ON u.user_id = ca.user_id
    $where_clause
    GROUP BY u.user_id, u.first_name, u.last_name, u.created_at, ue.email, p.url, a.admin_id, a.assigned_at
    $order_clause
";

if (!empty($params)) {
    $users_stmt = $conn->prepare($users_query);
    if ($param_types) {
        $users_stmt->bind_param($param_types, ...$params);
    }
    $users_stmt->execute();
    $users_result = $users_stmt->get_result();
} else {
    $users_result = $conn->query($users_query);
}

// Get statistics
$stats_query = "
    SELECT 
        COUNT(DISTINCT u.user_id) as total_users,
        COUNT(DISTINCT a.admin_id) as total_admins,
        COUNT(DISTINCT CASE WHEN u.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN u.user_id END) as new_users_30_days
    FROM users u
    LEFT JOIN admins a ON u.user_id = a.user_id
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Admin Panel</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/spacing.css" rel="stylesheet">
    <link href="../assets/css/form-styling.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderAdminStyles(); ?>
    <style>
        .user-avatar {
            width: 40px;
            height: 40px;
            object-fit: cover;
        }

        .user-placeholder {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1rem;
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
            margin-bottom: 1rem;
        }

        /* Consistent icon-text spacing */
        .bi:not(.badge .bi) {
            margin-right: 0.4rem;
        }

        /* Button group spacing */
        .btn-group .btn {
            margin-right: 0.25rem;
        }

        .btn-group .btn:last-child {
            margin-right: 0;
        }

        /* Card and table spacing */
        .row .card {
            margin-bottom: 1.25rem;
        }
    </style>
</head>

<body class="bg-light">
    <?php include('../includes/navigation.php');
    renderNavigation('admin'); ?>

    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('manage_users'); ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <!-- Header with Stats -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h1><i class="bi bi-people icon-spacing"></i>Manage Users</h1>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row g-3 mb-4 stats-grid">
                        <div class="col-md-2">
                            <div class="card card-spacing">
                                <div class="card-body text-center py-3 px-2">
                                    <i class="bi bi-people fs-3 text-primary mb-2"></i>
                                    <h4 class="text-primary mb-1"><?php echo $stats['total_users']; ?></h4>
                                    <p class="text-muted mb-0 small">Total Users</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card card-spacing">
                                <div class="card-body text-center py-3 px-2">
                                    <i class="bi bi-shield-check fs-3 text-success mb-2"></i>
                                    <h4 class="text-success mb-1"><?php echo $stats['total_admins']; ?></h4>
                                    <p class="text-muted mb-0 small">Administrators</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card card-spacing">
                                <div class="card-body text-center py-3 px-2">
                                    <i class="bi bi-person-plus fs-3 text-info mb-2"></i>
                                    <h4 class="text-info mb-1"><?php echo $stats['new_users_30_days']; ?></h4>
                                    <p class="text-muted mb-0 small">New Users (30 days)</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card card-spacing">
                                <div class="card-body text-center py-3 px-2">
                                    <i class="bi bi-person-check fs-3 text-warning mb-2"></i>
                                    <h4 class="text-warning mb-1"><?php echo $stats['total_users'] - $stats['total_admins']; ?></h4>
                                    <p class="text-muted mb-0 small">Regular Users</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages -->
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Search and Filter -->
                    <div class="card filter-card mb-4">
                        <div class="card-body">
                            <form method="GET" class="row g-3 align-items-end">
                                <div class="col-lg-4 col-md-6 col-12">
                                    <label for="search" class="form-label">Search Users</label>
                                    <div class="search-container">
                                        <!-- <i class="bi bi-search search-icon"></i> -->
                                        <input type="text" class="form-control search-input" id="search" name="search"
                                            placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-3 col-6">
                                    <label for="role" class="form-label">Role Filter</label>
                                    <select class="form-select" id="role" name="role">
                                        <option value="">👥 All Users</option>
                                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>🛡️ Administrators</option>
                                        <option value="user" <?php echo $role_filter === 'user' ? 'selected' : ''; ?>>👤 Regular Users</option>
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-3 col-6">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select class="form-select" id="sort" name="sort">
                                        <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>📝 Name</option>
                                        <option value="date" <?php echo $sort === 'date' ? 'selected' : ''; ?>>📅 Registration Date</option>
                                        <option value="email" <?php echo $sort === 'email' ? 'selected' : ''; ?>>📧 Email</option>
                                    </select>
                                </div>
                                <div class="col-lg-4 col-md-12 col-12">
                                    <div class="d-flex gap-2 flex-wrap justify-content-lg-start justify-content-md-center">
                                        <button type="submit" class="btn btn-filter flex-fill flex-lg-grow-0">
                                            <i class="bi bi-search icon-spacing"></i>Search
                                        </button>
                                        <a href="manage_users.php" class="btn btn-reset flex-fill flex-lg-grow-0 ml-2">
                                            <i class="bi bi-arrow-clockwise icon-spacing"></i>Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Users List</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>User</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Joined</th>
                                            <th>Activity</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($user = $users_result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($user['photo_url'])): ?>
                                                            <img src="<?php echo htmlspecialchars($user['photo_url']); ?>"
                                                                alt="Profile" class="user-avatar rounded-circle me-3 mr-2"
                                                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                        <?php else: ?>
                                                            <div class="user-placeholder rounded-circle me-3" style="display: none;">
                                                                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="fw-bold">
                                                                <a href="/lost-and-found/user/profile.php?id=<?php echo $user['user_id']; ?>"
                                                                    class="text-decoration-none">
                                                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                                </a>
                                                            </div>
                                                            <small class="text-muted">ID: #<?php echo $user['user_id']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($user['email'])): ?>
                                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($user['email']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">No email</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($user['admin_id'])): ?>
                                                        <span class="badge bg-warning text-dark">
                                                            <i class="bi bi-shield-check me-1"></i>Administrator
                                                        </span>
                                                        <br><small class="text-muted">Since <?php echo date('M Y', strtotime($user['assigned_at'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary">
                                                            <i class="bi bi-person me-1"></i>User
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                                                    <br><small class="text-muted"><?php echo date('g:i A', strtotime($user['created_at'])); ?></small>
                                                </td>
                                                <td>
                                                    <small class="text-muted">
                                                        <i class="bi bi-collection me-1"></i><?php echo $user['total_items']; ?> items<br>
                                                        <i class="bi bi-hand-thumbs-up me-1"></i><?php echo $user['total_claims']; ?> claims
                                                    </small>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <a href="/lost-and-found/user/profile.php?id=<?php echo $user['user_id']; ?>"
                                                            class="btn btn-outline-primary" title="View Profile">
                                                            <i class="bi bi-eye icon-spacing"></i>View
                                                        </a>
                                                        <?php if ($user['user_id'] != $current_user_id): ?>
                                                            <button type="button" class="btn btn-outline-danger"
                                                                onclick="confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')"
                                                                title="Delete User">
                                                                <i class="bi bi-trash icon-spacing"></i>Delete
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm User Deletion</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone!
                        </div>
                        <p>Are you sure you want to delete user <strong id="userName"></strong>?</p>
                        <p class="text-muted small">This will permanently delete:</p>
                        <ul class="text-muted small">
                            <li>User account and profile information</li>
                            <li>All items posted by this user</li>
                            <li>All claims made by this user</li>
                            <li>All notifications for this user</li>
                            <li>All related data</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST" class="d-inline" id="deleteForm">
                            <input type="hidden" name="action" value="delete_user">
                            <input type="hidden" name="user_id" id="deleteUserId">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash me-2"></i>Delete User
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script src="../assets/script/jquery.js"></script>
        <script src="../assets/script/bootstrap.min.js"></script>
        <script>
            function confirmDelete(userId, userName) {
                document.getElementById('userName').textContent = userName;
                document.getElementById('deleteUserId').value = userId;
                new bootstrap.Modal(document.getElementById('deleteModal')).show();
            }
        </script>
</body>

</html>