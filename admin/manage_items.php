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

// Handle item deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    // First, check if the item is approved (only approved items can be deleted)
    $check_approved_stmt = $conn->prepare("SELECT approved_at FROM items WHERE item_id = ?");
    $check_approved_stmt->bind_param("i", $item_id);
    $check_approved_stmt->execute();
    $check_result = $check_approved_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        $error_message = "Item not found.";
    } else {
        $item_data = $check_result->fetch_assoc();
        if ($item_data['approved_at'] === null) {
            $error_message = "Only approved items can be deleted. Please approve the item first or use the approve items page to manage pending items.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Initialize PhotoCleanupManager
                $photoCleanup = new PhotoCleanupManager($conn);
        
                // Delete related claims first
                $delete_claims_stmt = $conn->prepare("DELETE FROM claim_approvals WHERE item_id = ?");
                $delete_claims_stmt->bind_param("i", $item_id);
                $delete_claims_stmt->execute();
                
                // Delete item categories
                $delete_categories_stmt = $conn->prepare("DELETE FROM item_categories WHERE item_id = ?");
                $delete_categories_stmt->bind_param("i", $item_id);
                $delete_categories_stmt->execute();
                
                // Delete the item (CASCADE will handle photo deletion and trigger cleanup)
                $delete_item_stmt = $conn->prepare("DELETE FROM items WHERE item_id = ?");
                $delete_item_stmt->bind_param("i", $item_id);
                $delete_item_stmt->execute();
                
                $conn->commit();
                
                // Process photo cleanup queue (this will handle actual file deletion)
                $photoCleanup->processCleanupQueue();
                
                $success_message = "Approved item deleted successfully!";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error deleting item: " . $e->getMessage();
            }
        }
    }
    $check_approved_stmt->close();
}

// Fetch all items with user and photo information
$filter = $_GET['filter'] ?? 'all';
$where_conditions = ["i.approved_at IS NOT NULL"];
$status_filter = "All";

switch ($filter) {
    case 'lost':
        $where_conditions[] = "i.status = 'lost'";
        $status_filter = "Lost";
        break;
    case 'found':
        $where_conditions[] = "i.status = 'found'";
        $status_filter = "Found";
        break;
    case 'claimed':
        $where_conditions[] = "i.status = 'claimed'";
        $status_filter = "Claimed";
        break;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

$items_query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at, i.approved_at,
           u.first_name, u.last_name, u.user_id,
           l.location_details, p.url as photo_url,
           COUNT(ca.claim_id) as claim_count
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    LEFT JOIN locations l ON i.location_id = l.location_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN claim_approvals ca ON i.item_id = ca.item_id
    $where_clause
    GROUP BY i.item_id
    ORDER BY i.posted_at DESC
";

$items_result = $conn->query($items_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Items - Admin</title>
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
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
        }
        .item-placeholder {
            width: 80px;
            height: 80px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation('admin'); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('manage_items'); ?>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">Manage Items</h1>
                        <p class="text-muted">Oversee all approved lost and found items. Only approved items can be deleted from this page.</p>
                    </div>
                    
                    <!-- Filter Buttons -->
                    <div class="btn-group" role="group">
                        <a href="?filter=all" class="btn btn-<?php echo $filter === 'all' ? 'primary' : 'outline-primary'; ?>">
                            All Items
                        </a>
                        <a href="?filter=lost" class="btn btn-<?php echo $filter === 'lost' ? 'primary' : 'outline-primary'; ?>">
                            Lost
                        </a>
                        <a href="?filter=found" class="btn btn-<?php echo $filter === 'found' ? 'primary' : 'outline-primary'; ?>">
                            Found
                        </a>
                        <a href="?filter=claimed" class="btn btn-<?php echo $filter === 'claimed' ? 'primary' : 'outline-primary'; ?>">
                            Claimed
                        </a>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Items List -->
                <?php if ($items_result->num_rows > 0): ?>
                    <div class="row g-4">
                        <?php while ($item = $items_result->fetch_assoc()): ?>
                            <div class="col-12 mb-3">
                                <div class="card item-card">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <!-- Item Thumbnail -->
                                            <div class="col-auto">
                                                <?php if (!empty($item['photo_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($item['photo_url']); ?>" 
                                                         alt="Item photo" class="item-thumbnail">
                                                <?php else: ?>
                                                    <div class="item-placeholder">
                                                        <i class="bi bi-image fs-4"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Item Details -->
                                            <div class="col">
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <h5 class="mb-2">
                                                            <a href="../item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                                               class="text-decoration-none">
                                                                <?php echo htmlspecialchars($item['title']); ?>
                                                            </a>
                                                            <?php if ($item['claim_count'] > 0): ?>
                                                                <span class="badge bg-warning text-dark ms-2">
                                                                    <?php echo $item['claim_count']; ?> claim<?php echo $item['claim_count'] > 1 ? 's' : ''; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </h5>
                                                        
                                                        <?php if (!empty($item['description'])): ?>
                                                            <p class="text-muted mb-2">
                                                                <?php 
                                                                $description = htmlspecialchars($item['description']);
                                                                echo strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;
                                                                ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        
                                                        <div class="row g-3 text-sm">
                                                            <div class="col-sm-4">
                                                                <strong>Posted by:</strong><br>
                                                                <span class="text-muted">
                                                                    <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="col-sm-4">
                                                                <strong>Posted on:</strong><br>
                                                                <span class="text-muted">
                                                                    <?php echo date('M j, Y', strtotime($item['posted_at'])); ?>
                                                                </span>
                                                            </div>
                                                            <?php if (!empty($item['location_details'])): ?>
                                                                <div class="col-sm-4">
                                                                    <strong>Location:</strong><br>
                                                                    <span class="text-muted">
                                                                        <?php echo htmlspecialchars($item['location_details']); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Status and Actions -->
                                                    <div class="col-md-4 text-end">
                                                        <div class="mb-3">
                                                            <span class="badge bg-<?php 
                                                                echo $item['status'] === 'lost' ? 'danger' : 
                                                                    ($item['status'] === 'found' ? 'success' : 'warning'); 
                                                            ?> fs-6 px-3 py-2">
                                                                <?php echo ucfirst($item['status']); ?>
                                                            </span>
                                                            <br>
                                                            <span class="badge bg-success mt-1 fs-6 px-2 py-1">
                                                                <i class="bi bi-check-circle me-1"></i>Approved
                                                            </span>
                                                        </div>
                                                        
                                                        <div class="d-grid gap-2">
                                                            <a href="../item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm">
                                                                <i class="bi bi-eye me-1"></i>View Details
                                                            </a>
                                                            
                                                            <?php if ($item['claim_count'] > 0): ?>
                                                                <a href="manage_claims.php?item_id=<?php echo $item['item_id']; ?>" 
                                                                   class="btn btn-warning btn-sm">
                                                                    <i class="bi bi-clipboard-check me-1"></i>
                                                                    View Claims (<?php echo $item['claim_count']; ?>)
                                                                </a>
                                                            <?php endif; ?>
                                                            
                                                            <form method="POST" class="d-inline" 
                                                                  onsubmit="return confirm('Are you sure you want to delete this APPROVED item? This action cannot be undone and will also delete all related claims and photos.')">
                                                                <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                                <input type="hidden" name="delete_item" value="1">
                                                                <button type="submit" class="btn btn-danger btn-sm mt-2" 
                                                                        title="Delete this approved item permanently">
                                                                    <i class="bi bi-trash me-1"></i>Delete Approved Item
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <div class="mb-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                        </div>
                        <h3 class="text-muted mb-3">No <?php echo $status_filter; ?> Items</h3>
                        <p class="text-muted">There are currently no <?php echo strtolower($status_filter); ?> items in the system.</p>
                        <?php if ($filter !== 'all'): ?>
                            <a href="?filter=all" class="btn btn-outline-primary">View All Items</a>
                        <?php endif; ?>
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
