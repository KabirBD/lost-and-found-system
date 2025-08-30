<?php
session_start();
include("includes/db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = $_GET['mark_read'];
    $mark_read_stmt = $conn->prepare("UPDATE notifications SET received_at = NOW() WHERE notification_id = ? AND user_id = ?");
    $mark_read_stmt->bind_param("ii", $notification_id, $user_id);
    $mark_read_stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Mark all as read if requested
if (isset($_GET['mark_all_read'])) {
    $mark_all_read_stmt = $conn->prepare("UPDATE notifications SET received_at = NOW() WHERE user_id = ? AND received_at IS NULL");
    $mark_all_read_stmt->bind_param("i", $user_id);
    $mark_all_read_stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Delete notification if requested
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $notification_id = $_GET['delete'];
    $delete_stmt = $conn->prepare("DELETE FROM notifications WHERE notification_id = ? AND user_id = ?");
    $delete_stmt->bind_param("ii", $notification_id, $user_id);
    $delete_stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Delete all notifications if requested
if (isset($_GET['delete_all'])) {
    $delete_all_stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    $delete_all_stmt->bind_param("i", $user_id);
    $delete_all_stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Fetch notifications for the user
$notifications_query = "
    SELECT n.notification_id, n.message, n.sent_at, n.received_at,
           a.admin_id, u.first_name as admin_first_name, u.last_name as admin_last_name
    FROM notifications n
    LEFT JOIN admins a ON n.admin_id = a.admin_id
    LEFT JOIN users u ON a.user_id = u.user_id
    WHERE n.user_id = ?
    ORDER BY n.sent_at DESC
";

$notifications_stmt = $conn->prepare($notifications_query);
$notifications_stmt->bind_param("i", $user_id);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();

// Count unread notifications
$unread_count_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND received_at IS NULL";
$unread_count_stmt = $conn->prepare($unread_count_query);
$unread_count_stmt->bind_param("i", $user_id);
$unread_count_stmt->execute();
$unread_count_result = $unread_count_stmt->get_result();
$unread_count = $unread_count_result->fetch_assoc()['unread_count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Lost and Found</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .notification-card {
            border-left: 4px solid #007bff;
            transition: all 0.3s ease;
        }
        .notification-card.unread {
            background-color: #f8f9fa;
            border-left-color: #28a745;
        }
        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .notification-time {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .notification-actions {
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .notification-card:hover .notification-actions {
            opacity: 1;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('includes/navigation.php'); renderNavigation('notifications'); ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-bell me-2"></i>Notifications</h1>
                    <?php if ($unread_count > 0): ?>
                        <div>
                            <span class="badge bg-primary me-2"><?php echo $unread_count; ?> Unread</span>
                            <a href="?mark_all_read=1" class="btn btn-outline-primary btn-sm me-2">
                                <i class="bi bi-check-all"></i> Mark All Read
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if ($notifications_result->num_rows > 0): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-danger btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item text-danger" href="?delete_all=1" 
                                       onclick="return confirm('Are you sure you want to delete all notifications? This action cannot be undone.')">
                                    <i class="bi bi-trash me-2"></i>Delete All Notifications
                                </a></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($notifications_result->num_rows > 0): ?>
                    <div class="notifications-list">
                        <?php while ($notification = $notifications_result->fetch_assoc()): ?>
                            <div class="card notification-card mb-3 <?php echo is_null($notification['received_at']) ? 'unread' : ''; ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2">
                                                <?php if (is_null($notification['received_at'])): ?>
                                                    <span class="badge bg-success me-2">New</span>
                                                <?php endif; ?>
                                                <small class="notification-time">
                                                    <?php echo date('M j, Y g:i A', strtotime($notification['sent_at'])); ?>
                                                    <?php if (!empty($notification['admin_first_name'])): ?>
                                                        - by Admin <?php echo htmlspecialchars($notification['admin_first_name'] . ' ' . $notification['admin_last_name']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <p class="mb-0"><?php echo $notification['message']; ?></p>
                                        </div>
                                        
                                        <?php if (is_null($notification['received_at'])): ?>
                                            <div class="notification-actions ms-3">
                                                <a href="?mark_read=<?php echo $notification['notification_id']; ?>" 
                                                   class="btn btn-outline-success btn-sm me-1" title="Mark as read">
                                                    <i class="bi bi-check"></i>
                                                </a>
                                                <a href="?delete=<?php echo $notification['notification_id']; ?>" 
                                                   class="btn btn-outline-danger btn-sm" title="Delete notification"
                                                   onclick="return confirm('Are you sure you want to delete this notification?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="notification-actions ms-3">
                                                <i class="bi bi-check-circle text-success me-2" title="Read"></i>
                                                <a href="?delete=<?php echo $notification['notification_id']; ?>" 
                                                   class="btn btn-outline-danger btn-sm" title="Delete notification"
                                                   onclick="return confirm('Are you sure you want to delete this notification?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="bi bi-bell-slash text-muted" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-muted">No Notifications</h4>
                        <p class="text-muted">You don't have any notifications yet. When admins approve your items or claims, you'll see notifications here.</p>
                        <a href="items.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left me-2"></i>Back to Items
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Back to dashboard link -->
                <div class="text-center mt-4">
                    <a href="items.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Items
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
</body>
</html>
