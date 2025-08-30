<?php
session_start();
include("./includes/db.php");
include("./includes/PhotoCleanupManager.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle item deletion
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['delete_item'])) {
    $item_id = (int)$_POST['item_id'];
    
    // Verify item belongs to current user
    $verify_stmt = $conn->prepare("SELECT user_id FROM items WHERE item_id = ?");
    $verify_stmt->bind_param("i", $item_id);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    
    if ($verify_result->num_rows > 0 && $verify_result->fetch_assoc()['user_id'] == $user_id) {
        // Initialize PhotoCleanupManager
        $photoCleanup = new PhotoCleanupManager($conn);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Delete related claims first (if any)
            $delete_claims_stmt = $conn->prepare("DELETE FROM claim_approvals WHERE item_id = ?");
            $delete_claims_stmt->bind_param("i", $item_id);
            $delete_claims_stmt->execute();
            $delete_claims_stmt->close();
            
            // Delete the item (CASCADE will handle photo deletion and trigger cleanup)
            $delete_stmt = $conn->prepare("DELETE FROM items WHERE item_id = ?");
            $delete_stmt->bind_param("i", $item_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            $conn->commit();
            
            // Process photo cleanup queue (this will handle actual file deletion)
            $photoCleanup->processCleanupQueue();
            
            $success_message = "Item deleted successfully!";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error deleting item: " . $e->getMessage();
        };
    }
    $verify_stmt->close();
}

// Fetch user's items with photos and claim counts (including pending items)
$query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at, i.approved_at,
           l.location_details, p.url as photo_url,
           COUNT(ca.claim_id) as claim_count
    FROM items i
    LEFT JOIN locations l ON i.location_id = l.location_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN claim_approvals ca ON i.item_id = ca.item_id
    WHERE i.user_id = ?
    GROUP BY i.item_id
    ORDER BY i.posted_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Items - Lost and Found</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .item-card {
            transition: transform 0.2s ease;
        }
        .item-card:hover {
            transform: translateY(-2px);
        }
        .item-image {
            height: 150px;
            object-fit: cover;
            width: 100%;
            border-radius: 8px;
        }
        .default-image {
            height: 150px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
        .claims-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1;
        }
        .image-container {
            position: relative;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('includes/navigation.php'); renderNavigation('my-items'); ?>
    
    <div class="container mt-4 mb-5">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="h2 mb-1">My Items</h1>
                        <p class="text-muted">Manage your posted lost and found items</p>
                    </div>
                    <a href="post_item.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-2"></i>Post New Item
                    </a>
                </div>
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

        <!-- Info about pending items -->
        <?php 
        $pending_count = 0;
        $result_array = [];
        while ($temp_item = $result->fetch_assoc()) {
            $result_array[] = $temp_item;
            if (is_null($temp_item['approved_at'])) $pending_count++;
        }
        
        if ($pending_count > 0): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Note:</strong> You have <?php echo $pending_count; ?> item(s) pending admin approval. 
                Pending items are not visible to other users until approved.
            </div>
        <?php endif; ?>

        <!-- Items Grid -->
        <?php if (count($result_array) > 0): ?>
            <div class="row g-4">
                <?php foreach ($result_array as $item): ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="card item-card h-100 border-0 shadow-sm">
                            <div class="image-container">
                                <?php if (!empty($item['photo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['photo_url']); ?>" 
                                         alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                         class="card-img-top item-image">
                                <?php else: ?>
                                    <div class="default-image card-img-top">
                                        <i class="bi bi-image fs-1"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($item['claim_count'] > 0): ?>
                                    <span class="badge bg-warning claims-badge">
                                        <?php echo $item['claim_count']; ?> claim<?php echo $item['claim_count'] > 1 ? 's' : ''; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-body d-flex flex-column">
                                <div class="mb-3">
                                    <h5 class="card-title mb-2">
                                        <a href="item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                           class="text-decoration-none text-dark">
                                            <?php echo htmlspecialchars($item['title']); ?>
                                        </a>
                                    </h5>
                                    
                                    <?php if (!empty($item['description'])): ?>
                                        <p class="card-text text-muted small">
                                            <?php 
                                            $description = htmlspecialchars($item['description']);
                                            echo strlen($description) > 80 ? substr($description, 0, 80) . '...' : $description;
                                            ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-auto">
                                    <!-- Status and Location -->
                                    <div class="mb-3">
                                        <form method="post" class="d-inline">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <?php if (is_null($item['approved_at'])): ?>
                                                        <span class="badge bg-warning fs-6 me-2">
                                                            <i class="bi bi-clock"></i> Pending Approval
                                                        </span>
                                                    <?php endif; ?>
                                                    <span class="badge <?php echo $item['status'] === 'lost' ? 'bg-danger' : 'bg-success'; ?> fs-6">
                                                        <?php echo ucfirst($item['status']); ?>
                                                    </span>
                                                </div>
                                                <?php if (is_null($item['approved_at']) || $item['status'] !== 'claimed'): ?>
                                                <form method="POST" class="d-inline" 
                                                      onsubmit="return confirm('Are you sure you want to delete this item? This action cannot be undone.')">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                    <input type="hidden" name="delete_item" value="1">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="bi bi-trash me-1"></i>Delete
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        
                                        <?php if (!empty($item['location_details'])): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    <i class="bi bi-geo-alt me-1"></i>
                                                    <?php echo htmlspecialchars($item['location_details']); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Posted Date -->
                                    <div class="mb-3">
                                        <small class="text-muted">
                                            <i class="bi bi-calendar me-1"></i>
                                            Posted <?php echo date('M j, Y', strtotime($item['posted_at'])); ?>
                                        </small>
                                    </div>
                                    
                                    <!-- Action Buttons -->
                                    <div class="d-grid gap-2">
                                        <a href="item_detail.php?id=<?php echo $item['item_id']; ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-eye me-1"></i>View Details
                                            <?php if ($item['claim_count'] > 0): ?>
                                                <span class="badge bg-warning text-dark ms-1"><?php echo $item['claim_count']; ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Empty State -->
            <div class="text-center py-5">
                <div class="mb-4">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                </div>
                <h3 class="text-muted mb-3">No Items Posted Yet</h3>
                <p class="text-muted mb-4">You haven't posted any lost or found items. Get started by posting your first item!</p>
                <a href="post_item.php" class="btn btn-primary btn-lg">
                    <i class="bi bi-plus-circle me-2"></i>Post Your First Item
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
    <script>
        // Delete confirmation handler
        function confirmDelete(itemId, itemTitle) {
            if (confirm(`Are you sure you want to delete "${itemTitle}"? This action cannot be undone.`)) {
                // Create and submit form
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete';
                
                const itemIdInput = document.createElement('input');
                itemIdInput.type = 'hidden';
                itemIdInput.name = 'item_id';
                itemIdInput.value = itemId;
                
                form.appendChild(actionInput);
                form.appendChild(itemIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
