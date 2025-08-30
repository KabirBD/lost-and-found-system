<?php
function renderNavigation($current_page = '') {
    $user_id = $_SESSION['user_id'] ?? null;
    $is_admin = $_SESSION['is_admin'] ?? false;
    $user_name = $_SESSION['name'] ?? '';
    
    // Get unread notifications count for logged-in users
    $unread_count = 0;
    if ($user_id) {

        $host = "localhost";
        $user = "root";
        $pass = "";
        $dbname = "lost_and_found";
        
        $notification_conn = new mysqli($host, $user, $pass, $dbname);
        if (!$notification_conn->connect_error) {
            $unread_query = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND received_at IS NULL";
            $unread_stmt = $notification_conn->prepare($unread_query);
            $unread_stmt->bind_param("i", $user_id);
            $unread_stmt->execute();
            $unread_result = $unread_stmt->get_result();
            $unread_count = $unread_result->fetch_assoc()['unread_count'];
            $notification_conn->close();
        }
    }
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand font-weight-bold" href="/lost-and-found/index.php">
            <i class="bi bi-search mr-2"></i>Lost & Found
        </a>
        
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav mr-auto">
                <?php if ($user_id): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>" href="/lost-and-found/user/dashboard.php">
                            <i class="bi bi-speedometer2 mr-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'items' ? 'active' : ''; ?>" href="/lost-and-found/items.php">
                            <i class="bi bi-grid mr-1"></i>All Items
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'post' ? 'active' : ''; ?>" href="/lost-and-found/post_item.php">
                            <i class="bi bi-plus-circle mr-1"></i>Post Item
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'my-items' ? 'active' : ''; ?>" href="/lost-and-found/my_items.php">
                            <i class="bi bi-person-lines-fill mr-1"></i>My Items
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $current_page === 'notifications' ? 'active' : ''; ?>" href="/lost-and-found/notifications.php">
                            <i class="bi bi-bell mr-1"></i>Notifications
                            <?php if ($unread_count > 0): ?>
                                <span class="badge badge-danger rounded-pill ml-1"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php if ($is_admin): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'admin' ? 'active' : ''; ?>" href="/lost-and-found/admin/dashboard.php">
                                <i class="bi bi-shield-check mr-1"></i>Admin Panel
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
            
            <ul class="navbar-nav">
                <?php if ($user_id): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-toggle="dropdown">
                            <i class="bi bi-person-circle mr-1"></i><?php echo htmlspecialchars($user_name); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-right">
                            <li><a class="dropdown-item" href="/lost-and-found/user/profile.php?id=<?php echo $user_id; ?>">
                                <i class="bi bi-person mr-2"></i>Profile
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/lost-and-found/auth/logout.php">
                                <i class="bi bi-box-arrow-right mr-2"></i>Logout
                            </a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/lost-and-found/auth/login.php">
                            <i class="bi bi-box-arrow-in-right mr-1"></i>Login
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/lost-and-found/auth/register.php">
                            <i class="bi bi-person-plus mr-1"></i>Register
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<?php
}
?>
