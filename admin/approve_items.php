<?php
session_start();
include("../includes/db.php");
include("../includes/admin_navigation.php");

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? null;

// Handle approval action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $item_id = $_POST['item_id'];
    $action = $_POST['action'];
    
    if ($action === 'approve' && $admin_id) {
        // Approve the item
        $approve_stmt = $conn->prepare("UPDATE items SET admin_id = ?, approved_at = NOW() WHERE item_id = ?");
        $approve_stmt->bind_param("ii", $admin_id, $item_id);
        
        if ($approve_stmt->execute()) {
            // Get item and user details for notification
            $item_query = "SELECT i.title, i.user_id, u.first_name FROM items i JOIN users u ON i.user_id = u.user_id WHERE i.item_id = ?";
            $item_stmt = $conn->prepare($item_query);
            $item_stmt->bind_param("i", $item_id);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item_info = $item_result->fetch_assoc();
            
            // Send notification to user
            $message = "Your item '" . $item_info['title'] . "' has been approved and is now visible to other users. <a href='/lost-and-found/item_detail.php?id=" . $item_id . "' class='alert-link'>View Item</a>";
            $notify_stmt = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
            $notify_stmt->bind_param("sii", $message, $admin_id, $item_info['user_id']);
            $notify_stmt->execute();
            
            $success_message = "Item approved successfully and user notified.";
        } else {
            $error_message = "Error approving item.";
        }
    } elseif ($action === 'reject') {
        // Delete the item (rejection)
        $item_query = "SELECT i.title, i.user_id, u.first_name FROM items i JOIN users u ON i.user_id = u.user_id WHERE i.item_id = ?";
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param("i", $item_id);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        $item_info = $item_result->fetch_assoc();
        
        $delete_stmt = $conn->prepare("DELETE FROM items WHERE item_id = ?");
        $delete_stmt->bind_param("i", $item_id);
        
        if ($delete_stmt->execute()) {
            // Send notification to user
            if ($admin_id) {
                $message = "Your item '" . $item_info['title'] . "' has been rejected. Please ensure it meets our community guidelines and try posting again.";
                $notify_stmt = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
                $notify_stmt->bind_param("sii", $message, $admin_id, $item_info['user_id']);
                $notify_stmt->execute();
            }
            
            $success_message = "Item rejected and user notified.";
        } else {
            $error_message = "Error rejecting item.";
        }
    }
}

// Fetch pending items (not approved yet)
$pending_query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at,
           u.first_name, u.last_name, u.user_id,
           l.location_details, p.url as photo_url
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    LEFT JOIN locations l ON i.location_id = l.location_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    WHERE i.approved_at IS NULL
    ORDER BY i.posted_at DESC
";
$pending_result = $conn->query($pending_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Items - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/spacing.css" rel="stylesheet">
    <link href="../assets/css/form-styling.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderAdminStyles(); ?>
    <?php renderAdminStyles(); ?>
    <style>
        .item-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .item-card:hover {
            transform: translateY(-2px);
        }
        .item-thumbnail {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }
        .item-placeholder {
            width: 100px;
            height: 100px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 12px;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation('admin'); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('approve_items'); ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="bi bi-check-circle me-2"></i>Pending Item Approvals</h2>
                        <span class="badge bg-warning fs-6"><?php echo $pending_result->num_rows; ?> Pending</span>
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

                    <!-- Pending Items -->
                    <?php if ($pending_result->num_rows > 0): ?>
                        <div class="row">
                            <?php while ($item = $pending_result->fetch_assoc()): ?>
                                <div class="col-lg-6 mb-4">
                                    <div class="card item-card">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-4">
                                                    <?php if (!empty($item['photo_url'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($item['photo_url']); ?>" 
                                                             alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                                             class="item-thumbnail">
                                                    <?php else: ?>
                                                        <div class="item-placeholder">
                                                            <span>No Image</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-8">
                                                    <h5 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h5>
                                                    <p class="card-text">
                                                        <small class="text-muted">
                                                            By: <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?><br>
                                                            Posted: <?php echo date('M j, Y g:i A', strtotime($item['posted_at'])); ?><br>
                                                            Status: <span class="badge bg-<?php echo $item['status'] === 'lost' ? 'danger' : 'success'; ?>">
                                                                <?php echo ucfirst($item['status']); ?>
                                                            </span>
                                                        </small>
                                                    </p>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <p class="text-muted small">
                                                            <?php echo htmlspecialchars(substr($item['description'], 0, 100)) . (strlen($item['description']) > 100 ? '...' : ''); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['location_details'])): ?>
                                                        <p class="text-muted small">
                                                            <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($item['location_details']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    
                                                    <div class="mt-3">
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                            <button type="submit" name="action" value="approve" 
                                                                    class="btn btn-success btn-sm me-2"
                                                                    onclick="return confirm('Approve this item?')">
                                                                <i class="bi bi-check-lg"></i> Approve
                                                            </button>
                                                        </form>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                            <button type="submit" name="action" value="reject" 
                                                                    class="btn btn-danger btn-sm"
                                                                    onclick="return confirm('Reject this item? This will delete it permanently.')">
                                                                <i class="bi bi-x-lg"></i> Reject
                                                            </button>
                                                        </form>
                                                        <a href="../item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                                           class="btn btn-outline-primary btn-sm ms-2" target="_blank">
                                                            <i class="bi bi-eye"></i> View Details
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                            <h4 class="mt-3 text-muted">No Pending Items</h4>
                            <p class="text-muted">All items have been reviewed. Great job!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
</body>
</html>
