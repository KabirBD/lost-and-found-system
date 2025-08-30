<?php
function renderAdminNavigation($current_page = '') {
?>
<!-- Admin Sidebar -->
<div class="col-md-3 col-lg-2 p-0">
    <div class="admin-sidebar p-3">
        <h5 class="text-white mb-4">
            <i class="bi bi-shield-check mr-2"></i>Admin Panel
        </h5>
        
        <nav class="sidebar-nav">
            <a href="dashboard.php" class="nav-link <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2 mr-2"></i>Dashboard
            </a>
            <a href="approve_items.php" class="nav-link <?php echo $current_page === 'approve_items' ? 'active' : ''; ?>">
                <i class="bi bi-check-circle mr-2"></i>Approve Items
                <?php
                // Get pending items count
                global $conn;
                if (isset($conn)) {
                    $pending_items_query = "SELECT COUNT(*) as pending FROM items WHERE approved_at IS NULL";
                    $pending_items_result = $conn->query($pending_items_query);
                    if ($pending_items_result) {
                        $pending_items = $pending_items_result->fetch_assoc()['pending'];
                        if ($pending_items > 0): ?>
                            <span class="badge badge-warning ml-2"><?php echo $pending_items; ?></span>
                        <?php endif;
                    }
                }
                ?>
            </a>
            <a href="manage_items.php" class="nav-link <?php echo $current_page === 'manage_items' ? 'active' : ''; ?>">
                <i class="bi bi-box mr-2"></i>Manage Items
            </a>
            <a href="manage_claims.php" class="nav-link <?php echo $current_page === 'manage_claims' ? 'active' : ''; ?>">
                <i class="bi bi-clipboard-check mr-2"></i>Manage Claims
                <?php
                // Get pending claims count
                if (isset($conn)) {
                    $pending_claims_query = "SELECT COUNT(*) as pending FROM claim_approvals WHERE approved_at IS NULL";
                    $pending_claims_result = $conn->query($pending_claims_query);
                    if ($pending_claims_result) {
                        $pending_claims = $pending_claims_result->fetch_assoc()['pending'];
                        if ($pending_claims > 0): ?>
                            <span class="badge badge-danger ml-2"><?php echo $pending_claims; ?></span>
                        <?php endif;
                    }
                }
                ?>
            </a>
            <a href="manage_users.php" class="nav-link <?php echo $current_page === 'manage_users' ? 'active' : ''; ?>">
                <i class="bi bi-people mr-2"></i>Manage Users
            </a>
            <a href="manage_admins.php" class="nav-link <?php echo $current_page === 'manage_admins' ? 'active' : ''; ?>">
                <i class="bi bi-people-fill mr-2"></i>Manage Admins
            </a>
        </nav>
    </div>
</div>
<?php
}

function renderAdminStyles() {
?>
<style>
    .admin-sidebar {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: calc(100vh - 76px);
    }
    .sidebar-nav .nav-link {
        color: rgba(255, 255, 255, 0.8);
        padding: 0.75rem 1.5rem;
        border-radius: 8px;
        margin-bottom: 0.25rem;
        display: block;
        text-decoration: none;
    }
    .sidebar-nav .nav-link:hover,
    .sidebar-nav .nav-link.active {
        color: white;
        background: rgba(255, 255, 255, 0.1);
    }
    .sidebar-nav .nav-link .badge {
        font-size: 0.7rem;
    }
</style>
<?php
}
?>
