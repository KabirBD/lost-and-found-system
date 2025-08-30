<?php
session_start();
include("../includes/db.php");
include("../includes/admin_navigation.php");

// Check if user is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$admin_user_id = $_SESSION['user_id'];

// Get admin_id for the current user
$admin_query = $conn->prepare("SELECT admin_id FROM admins WHERE user_id = ?");
$admin_query->bind_param("i", $admin_user_id);
$admin_query->execute();
$admin_result = $admin_query->get_result();
$admin_data = $admin_result->fetch_assoc();
$admin_id = $admin_data['admin_id'];

// Handle claim approval/rejection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['approve_claim'])) {
        $claim_id = (int)$_POST['claim_id'];
        $item_id = (int)$_POST['item_id'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Approve the claim
            $approve_stmt = $conn->prepare("UPDATE claim_approvals SET admin_id = ?, approved_at = NOW() WHERE claim_id = ?");
            $approve_stmt->bind_param("ii", $admin_id, $claim_id);
            $approve_stmt->execute();
            
            // Update item status to claimed
            $item_stmt = $conn->prepare("UPDATE items SET status = 'claimed' WHERE item_id = ?");
            $item_stmt->bind_param("i", $item_id);
            $item_stmt->execute();
            
            // Get claim and item details for notifications
            $claim_details_query = "
                SELECT ca.user_id as claimant_id, i.item_id, i.title, i.user_id as owner_id,
                       u1.first_name as claimant_name, u2.first_name as owner_name
                FROM claim_approvals ca
                JOIN items i ON ca.item_id = i.item_id
                JOIN users u1 ON ca.user_id = u1.user_id
                JOIN users u2 ON i.user_id = u2.user_id
                WHERE ca.claim_id = ?";
            $details_stmt = $conn->prepare($claim_details_query);
            $details_stmt->bind_param("i", $claim_id);
            $details_stmt->execute();
            $details = $details_stmt->get_result()->fetch_assoc();
            
            // Send notification to claimant
            $claimant_message = "Your claim for '" . $details['title'] . "' has been approved! You can now contact the item owner. <a href='/lost-and-found/item_detail.php?id=" . $details['item_id'] . "' class='alert-link'>View Item</a>";
            $notify_claimant = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
            $notify_claimant->bind_param("sii", $claimant_message, $admin_id, $details['claimant_id']);
            $notify_claimant->execute();
            
            // Send notification to item owner
            $owner_message = "Your item '" . $details['title'] . "' has been claimed by " . $details['claimant_name'] . ". Please coordinate the handover. <a href='/lost-and-found/item_detail.php?id=" . $details['item_id'] . "' class='alert-link'>View Item</a>";
            $notify_owner = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
            $notify_owner->bind_param("sii", $owner_message, $admin_id, $details['owner_id']);
            $notify_owner->execute();
            
            $conn->commit();
            $success_message = "Claim approved successfully and parties notified!";
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error approving claim: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_claim'])) {
        $claim_id = (int)$_POST['claim_id'];
        
        // Get claim details before deletion for notification
        $claim_details_query = "
            SELECT ca.user_id as claimant_id, i.item_id, i.title, u.first_name as claimant_name
            FROM claim_approvals ca
            JOIN items i ON ca.item_id = i.item_id
            JOIN users u ON ca.user_id = u.user_id
            WHERE ca.claim_id = ?";
        $details_stmt = $conn->prepare($claim_details_query);
        $details_stmt->bind_param("i", $claim_id);
        $details_stmt->execute();
        $details = $details_stmt->get_result()->fetch_assoc();
        
        // Delete the claim (rejection)
        $reject_stmt = $conn->prepare("DELETE FROM claim_approvals WHERE claim_id = ?");
        $reject_stmt->bind_param("i", $claim_id);
        
        if ($reject_stmt->execute()) {
            // Send notification to claimant
            if ($details) {
                $claimant_message = "Your claim for '" . $details['title'] . "' has been rejected. Please review the item details and try again if you believe this is your item. <a href='/lost-and-found/item_detail.php?id=" . $details['item_id'] . "' class='alert-link'>View Item</a>";
                $notify_claimant = $conn->prepare("INSERT INTO notifications (message, admin_id, user_id) VALUES (?, ?, ?)");
                $notify_claimant->bind_param("sii", $claimant_message, $admin_id, $details['claimant_id']);
                $notify_claimant->execute();
            }
            
            $success_message = "Claim rejected successfully and claimant notified!";
        } else {
            $error_message = "Error rejecting claim.";
        }
    }
}

// Fetch all claims with item and user details
$filter = $_GET['filter'] ?? 'pending';
$where_clause = "";
$status_filter = "";

switch ($filter) {
    case 'approved':
        $where_clause = "WHERE ca.approved_at IS NOT NULL";
        $status_filter = "Approved";
        break;
    case 'all':
        $status_filter = "All";
        break;
    default:
        $where_clause = "WHERE ca.approved_at IS NULL";
        $status_filter = "Pending";
}

$claims_query = "
    SELECT ca.claim_id, ca.statement, ca.claimed_at, ca.approved_at,
           i.item_id, i.title as item_title, i.status as item_status,
           u.first_name, u.last_name, u.user_id as claimant_id,
           owner.first_name as owner_first_name, owner.last_name as owner_last_name,
           p.url as photo_url,
           admin_user.first_name as admin_first_name, admin_user.last_name as admin_last_name
    FROM claim_approvals ca
    JOIN items i ON ca.item_id = i.item_id
    JOIN users u ON ca.user_id = u.user_id
    JOIN users owner ON i.user_id = owner.user_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN admins a ON ca.admin_id = a.admin_id
    LEFT JOIN users admin_user ON a.user_id = admin_user.user_id
    $where_clause
    ORDER BY ca.claimed_at DESC
";

$claims_result = $conn->query($claims_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Claims - Admin</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/spacing.css" rel="stylesheet">
    <link href="../assets/css/form-styling.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <?php renderAdminStyles(); ?>
    <?php renderAdminStyles(); ?>
    <style>
        .claim-card {
            border: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s ease;
        }
        .claim-card:hover {
            transform: translateY(-2px);
        }
        .item-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
        }
        .item-placeholder {
            width: 60px;
            height: 60px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 1.5rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('../includes/navigation.php'); renderNavigation('admin'); ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php renderAdminNavigation('manage_claims'); ?>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h3 mb-1">Manage Claims</h1>
                        <p class="text-muted">Review and approve item ownership claims</p>
                    </div>
                    
                    <!-- Filter Buttons -->
                    <div class="btn-group" role="group">
                        <a href="?filter=pending" class="btn btn-<?php echo $filter === 'pending' ? 'primary' : 'outline-primary'; ?>">
                            Pending
                        </a>
                        <a href="?filter=approved" class="btn btn-<?php echo $filter === 'approved' ? 'primary' : 'outline-primary'; ?>">
                            Approved
                        </a>
                        <a href="?filter=all" class="btn btn-<?php echo $filter === 'all' ? 'primary' : 'outline-primary'; ?>">
                            All Claims
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

                <!-- Claims List -->
                <?php if ($claims_result->num_rows > 0): ?>
                    <div class="row g-4">
                        <?php while ($claim = $claims_result->fetch_assoc()): ?>
                            <div class="col-12">
                                <div class="card claim-card">
                                    <div class="card-body">
                                        <div class="row align-items-start">
                                            <!-- Item Thumbnail -->
                                            <div class="col-auto">
                                                <?php if (!empty($claim['photo_url'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($claim['photo_url']); ?>" 
                                                         alt="Item photo" class="item-thumbnail">
                                                <?php else: ?>
                                                    <div class="item-placeholder">
                                                        <i class="bi bi-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <!-- Claim Details -->
                                            <div class="col">
                                                <div class="row">
                                                    <div class="col-md-8">
                                                        <h5 class="mb-2">
                                                            <a href="../item_detail.php?id=<?php echo $claim['item_id']; ?>" 
                                                               class="text-decoration-none">
                                                                <?php echo htmlspecialchars($claim['item_title']); ?>
                                                            </a>
                                                        </h5>
                                                        
                                                        <div class="mb-3">
                                                            <h6 class="text-muted mb-1">Claim Statement:</h6>
                                                            <p class="mb-2"><?php echo nl2br(htmlspecialchars($claim['statement'])); ?></p>
                                                        </div>
                                                        
                                                        <div class="row g-3 text-sm">
                                                            <div class="col-sm-6">
                                                                <strong>Claimant:</strong><br>
                                                                <span class="text-muted">
                                                                    <?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <strong>Item Owner:</strong><br>
                                                                <span class="text-muted">
                                                                    <?php echo htmlspecialchars($claim['owner_first_name'] . ' ' . $claim['owner_last_name']); ?>
                                                                </span>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <strong>Claimed On:</strong><br>
                                                                <span class="text-muted">
                                                                    <?php echo date('M j, Y \a\t g:i A', strtotime($claim['claimed_at'])); ?>
                                                                </span>
                                                            </div>
                                                            <?php if ($claim['approved_at']): ?>
                                                                <div class="col-sm-6">
                                                                    <strong>Approved By:</strong><br>
                                                                    <span class="text-muted">
                                                                        <?php echo htmlspecialchars($claim['admin_first_name'] . ' ' . $claim['admin_last_name']); ?>
                                                                        <br>
                                                                        <?php echo date('M j, Y \a\t g:i A', strtotime($claim['approved_at'])); ?>
                                                                    </span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Action Buttons -->
                                                    <div class="col-md-4 text-end">
                                                        <?php if (!$claim['approved_at']): ?>
                                                            <div class="d-grid gap-2">
                                                                <form method="post" style="display: inline;">
                                                                    <input type="hidden" name="claim_id" value="<?php echo $claim['claim_id']; ?>">
                                                                    <input type="hidden" name="item_id" value="<?php echo $claim['item_id']; ?>">
                                                                    <button type="submit" name="approve_claim" 
                                                                            class="btn btn-success w-100"
                                                                            onclick="return confirm('Are you sure you want to approve this claim?')">
                                                                        <i class="bi bi-check-circle me-2"></i>Approve Claim
                                                                    </button>
                                                                </form>
                                                                
                                                                <form method="post" style="display: inline;">
                                                                    <input type="hidden" name="claim_id" value="<?php echo $claim['claim_id']; ?>">
                                                                    <button type="submit" name="reject_claim" 
                                                                            class="btn btn-danger w-100"
                                                                            onclick="return confirm('Are you sure you want to reject this claim? This action cannot be undone.')">
                                                                        <i class="bi bi-x-circle me-2"></i>Reject Claim
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="badge bg-success fs-6 px-3 py-2">
                                                                <i class="bi bi-check-circle me-2"></i>Approved
                                                            </span>
                                                        <?php endif; ?>
                                                        
                                                        <div class="mt-3">
                                                            <a href="../item_detail.php?id=<?php echo $claim['item_id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm w-100">
                                                                <i class="bi bi-eye me-2"></i>View Item
                                                            </a>
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
                            <i class="bi bi-clipboard-x display-1 text-muted"></i>
                        </div>
                        <h3 class="text-muted mb-3">No <?php echo $status_filter; ?> Claims</h3>
                        <p class="text-muted">There are currently no <?php echo strtolower($status_filter); ?> claims to review.</p>
                        <?php if ($filter !== 'all'): ?>
                            <a href="?filter=all" class="btn btn-outline-primary">View All Claims</a>
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
