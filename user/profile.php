<?php
session_start();
include("../includes/db.php");

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /lost-and-found/index.php");
    exit();
}

$profile_user_id = (int)$_GET['id'];
$current_user_id = $_SESSION['user_id'] ?? null;

// Fetch user profile information
$user_query = "
    SELECT u.user_id, u.first_name, u.last_name, u.created_at,
           p.url as photo_url, ue.email
    FROM users u
    LEFT JOIN photos p ON u.photo_id = p.photo_id
    LEFT JOIN user_emails ue ON u.user_id = ue.user_id
    WHERE u.user_id = ?
";

$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $profile_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: /lost-and-found/index.php");
    exit();
}

$user = $result->fetch_assoc();

// Get user statistics
$stats_query = "
    SELECT 
        COUNT(CASE WHEN i.admin_id IS NOT NULL THEN 1 END) as items_posted,
        COUNT(CASE WHEN i.status = 'found' AND i.admin_id IS NOT NULL THEN 1 END) as items_found,
        COUNT(CASE WHEN i.status = 'lost' AND i.admin_id IS NOT NULL THEN 1 END) as items_lost,
        COUNT(CASE WHEN ca.approved_at IS NOT NULL THEN 1 END) as successful_claims
    FROM items i
    LEFT JOIN claim_approvals ca ON i.item_id = ca.item_id AND ca.user_id = ?
    WHERE i.user_id = ?
";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("ii", $profile_user_id, $profile_user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Get recent approved items posted by this user (public view)
$items_query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at,
           p.url as photo_url, l.location_details
    FROM items i
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN locations l ON i.location_id = l.location_id
    WHERE i.user_id = ? AND i.admin_id IS NOT NULL
    ORDER BY i.posted_at DESC
    LIMIT 6
";

$items_stmt = $conn->prepare($items_query);
$items_stmt->bind_param("i", $profile_user_id);
$items_stmt->execute();
$recent_items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check if this user is an admin
$admin_check = $conn->prepare("SELECT admin_id FROM admins WHERE user_id = ?");
$admin_check->bind_param("i", $profile_user_id);
$admin_check->execute();
$is_profile_admin = $admin_check->get_result()->num_rows > 0;

$stmt->close();
$stats_stmt->close();
$items_stmt->close();
$admin_check->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> - User Profile</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
        }
        .profile-avatar {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .profile-placeholder {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border: 4px solid white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 2rem;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .item-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .item-card:hover {
            transform: translateY(-3px);
        }
        .status-badge {
            font-size: 0.75rem;
        }
        .admin-badge {
            background: linear-gradient(45deg, #ffd700, #ff8c00);
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation(); ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="d-flex align-items-center">
                        <div class="me-4">
                            <?php if (!empty($user['photo_url'])): ?>
                                <img src="<?php echo htmlspecialchars($user['photo_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($user['first_name']); ?>" 
                                     class="profile-avatar rounded-circle"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="profile-placeholder" style="display: none;">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                            <?php else: ?>
                                <div class="profile-placeholder">
                                    <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h1 class="h2 mb-2">
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                <?php if ($is_profile_admin): ?>
                                    <span class="badge admin-badge ms-2">
                                        <i class="bi bi-shield-check me-1"></i>Admin
                                    </span>
                                <?php endif; ?>
                            </h1>
                            <p class="mb-0 opacity-75">
                                <i class="bi bi-calendar me-2"></i>
                                Member since <?php echo date('F Y', strtotime($user['created_at'])); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <?php if ($current_user_id == $profile_user_id): ?>
                        <a href="/lost-and-found/user/dashboard.php" class="btn btn-light btn-lg">
                            <i class="bi bi-pencil me-2"></i>Edit Profile
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Content -->
    <div class="container mt-4">
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="bi bi-collection text-primary fs-1 mb-2 d-block"></i>
                        <h3 class="text-primary mb-1"><?php echo $stats['items_posted']; ?></h3>
                        <p class="text-muted mb-0">Items Posted</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="bi bi-search text-success fs-1 mb-2 d-block"></i>
                        <h3 class="text-success mb-1"><?php echo $stats['items_found']; ?></h3>
                        <p class="text-muted mb-0">Items Found</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="bi bi-exclamation-triangle text-warning fs-1 mb-2 d-block"></i>
                        <h3 class="text-warning mb-1"><?php echo $stats['items_lost']; ?></h3>
                        <p class="text-muted mb-0">Items Lost</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card stat-card text-center">
                    <div class="card-body">
                        <i class="bi bi-check-circle text-info fs-1 mb-2 d-block"></i>
                        <h3 class="text-info mb-1"><?php echo $stats['successful_claims']; ?></h3>
                        <p class="text-muted mb-0">Successful Claims</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Items -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-grid me-2"></i>Recent Items Posted
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_items)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-1 mb-3 d-block"></i>
                                <h6>No items posted yet</h6>
                                <p class="mb-0">This user hasn't posted any approved items.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($recent_items as $item): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card item-card h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="card-title mb-0">
                                                        <a href="/lost-and-found/item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                                           class="text-decoration-none">
                                                            <?php echo htmlspecialchars($item['title']); ?>
                                                        </a>
                                                    </h6>
                                                    <span class="badge status-badge <?php echo $item['status'] === 'found' ? 'bg-success' : 'bg-warning'; ?>">
                                                        <?php echo ucfirst($item['status']); ?>
                                                    </span>
                                                </div>
                                                <p class="card-text text-muted small mb-2">
                                                    <?php echo htmlspecialchars(substr($item['description'], 0, 100)) . (strlen($item['description']) > 100 ? '...' : ''); ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">
                                                        <i class="bi bi-calendar me-1"></i>
                                                        <?php echo date('M j, Y', strtotime($item['posted_at'])); ?>
                                                    </small>
                                                    <?php if (!empty($item['location_details'])): ?>
                                                        <small class="text-muted">
                                                            <i class="bi bi-geo-alt me-1"></i>
                                                            <?php echo htmlspecialchars(substr($item['location_details'], 0, 20)) . (strlen($item['location_details']) > 20 ? '...' : ''); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if (count($recent_items) >= 6): ?>
                                <div class="text-center mt-3">
                                    <a href="/lost-and-found/items.php?user=<?php echo $profile_user_id; ?>" class="btn btn-outline-primary">
                                        <i class="bi bi-grid me-2"></i>View All Items
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
</body>
</html>
