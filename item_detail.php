<?php
session_start();
include("./includes/db.php");

// Check if item ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /lost-and-found/items.php");
    exit();
}

$item_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'] ?? null;

// Handle claim submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['claim_item'])) {
    if (!$user_id) {
        header("Location: /lost-and-found/auth/login.php");
        exit();
    }
    
    $statement = trim($_POST['statement']);
    
    // Check if user already claimed this item
    $check_stmt = $conn->prepare("SELECT claim_id FROM claim_approvals WHERE item_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $item_id, $user_id);
    $check_stmt->execute();
    $existing_claim = $check_stmt->get_result();
    
    if ($existing_claim->num_rows > 0) {
        $error_message = "You have already submitted a claim for this item.";
    } else {
        // Insert new claim
        $claim_stmt = $conn->prepare("INSERT INTO claim_approvals (item_id, user_id, statement) VALUES (?, ?, ?)");
        $claim_stmt->bind_param("iis", $item_id, $user_id, $statement);
        
        if ($claim_stmt->execute()) {
            $success_message = "Your claim has been submitted successfully and is pending admin approval.";
        } else {
            $error_message = "Error submitting claim. Please try again.";
        }
        $claim_stmt->close();
    }
    $check_stmt->close();
}

// Handle comment submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_comment'])) {
    if (!$user_id) {
        header("Location: /lost-and-found/auth/login.php");
        exit();
    }
    
    $comment_description = trim($_POST['comment_description']);
    
    if (!empty($comment_description)) {
        // Check if the item is approved (admin_id is not null)
        $check_approved = $conn->prepare("SELECT admin_id FROM items WHERE item_id = ? AND admin_id IS NOT NULL");
        $check_approved->bind_param("i", $item_id);
        $check_approved->execute();
        $approved_result = $check_approved->get_result();
        
        if ($approved_result->num_rows > 0) {
            // Get the next note_no for this item
            $next_note_stmt = $conn->prepare("SELECT COALESCE(MAX(note_no), 0) + 1 as next_note_no FROM notes WHERE item_id = ?");
            $next_note_stmt->bind_param("i", $item_id);
            $next_note_stmt->execute();
            $next_note_result = $next_note_stmt->get_result();
            $next_note_no = $next_note_result->fetch_assoc()['next_note_no'];
            $next_note_stmt->close();
            
            // Insert new comment with calculated note_no
            $comment_stmt = $conn->prepare("INSERT INTO notes (item_id, note_no, user_id, description) VALUES (?, ?, ?, ?)");
            $comment_stmt->bind_param("iiis", $item_id, $next_note_no, $user_id, $comment_description);
            
            if ($comment_stmt->execute()) {
                $comment_success_message = "Your comment has been added successfully.";
            } else {
                $comment_error_message = "Error adding comment. Please try again.";
            }
            $comment_stmt->close();
        } else {
            $comment_error_message = "You can only add comments to approved items.";
        }
        $check_approved->close();
    } else {
        $comment_error_message = "Comment cannot be empty.";
    }
}

// Fetch item details with user and photo information
$query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at, i.admin_id,
           u.first_name, u.last_name, u.user_id as owner_id,
           l.location_details, p.url as photo_url,
           GROUP_CONCAT(c.name SEPARATOR ', ') as categories
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    LEFT JOIN locations l ON i.location_id = l.location_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN item_categories ic ON i.item_id = ic.item_id
    LEFT JOIN categories c ON ic.category_id = c.category_id
    WHERE i.item_id = ?
    GROUP BY i.item_id, i.title, i.description, i.status, i.posted_at, i.admin_id,
             u.first_name, u.last_name, u.user_id, l.location_details, p.url
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $item_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: /lost-and-found/items.php");
    exit();
}

$item = $result->fetch_assoc();

// Check if current user has already claimed this item
$user_claim = null;
if ($user_id) {
    $claim_query = "
        SELECT ca.*, a.admin_id, au.first_name as admin_first_name, au.last_name as admin_last_name
        FROM claim_approvals ca
        LEFT JOIN admins a ON ca.admin_id = a.admin_id
        LEFT JOIN users au ON a.user_id = au.user_id
        WHERE ca.item_id = ? AND ca.user_id = ?
    ";
    $claim_stmt = $conn->prepare($claim_query);
    $claim_stmt->bind_param("ii", $item_id, $user_id);
    $claim_stmt->execute();
    $user_claim = $claim_stmt->get_result()->fetch_assoc();
    $claim_stmt->close();
}

// Fetch comments for this item (only for approved items)
$comments = [];
$comments_query = "
    SELECT n.item_id, n.note_no, n.description, n.created_at,
           u.first_name, u.last_name, u.user_id
    FROM notes n
    JOIN users u ON n.user_id = u.user_id
    JOIN items i ON n.item_id = i.item_id
    WHERE n.item_id = ? AND i.admin_id IS NOT NULL
    ORDER BY n.note_no ASC
";
$comments_stmt = $conn->prepare($comments_query);
$comments_stmt->bind_param("i", $item_id);
$comments_stmt->execute();
$comments_result = $comments_stmt->get_result();
while ($comment = $comments_result->fetch_assoc()) {
    $comments[] = $comment;
}

// Fetch all claims for this item (for item owner to see)
$all_claims = [];
if ($user_id && $user_id == $item['owner_id']) {
    $claims_query = "
        SELECT ca.*, u.first_name, u.last_name, u.user_id as claimant_id
        FROM claim_approvals ca
        JOIN users u ON ca.user_id = u.user_id
        WHERE ca.item_id = ?
        ORDER BY ca.claimed_at DESC
    ";
    $claims_stmt = $conn->prepare($claims_query);
    $claims_stmt->bind_param("i", $item_id);
    $claims_stmt->execute();
    $claims_result = $claims_stmt->get_result();
    while ($claim = $claims_result->fetch_assoc()) {
        $all_claims[] = $claim;
    }
    $claims_stmt->close();
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($item['title']); ?> - Lost and Found</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Fix modal issues */
        .modal {
            z-index: 1050 !important;
        }
        .modal-backdrop {
            z-index: 1040 !important;
        }
        
        .item-image {
            max-height: 400px;
            width: 100%;
            object-fit: cover;
            border-radius: 10px;
        }
        .default-image {
            height: 300px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 18px;
            border-radius: 10px;
            border: 2px dashed #dee2e6;
        }
        .status-badge {
            font-size: 1rem;
            padding: 0.5rem 1rem;
        }
        .detail-card {
            border: none;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .claim-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 2rem;
        }
        .claim-form {
            background: white;
            color: #333;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .timeline-item {
            border-left: 3px solid #0d6efd;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        .contact-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
        }
        .comments-section {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .comments-list {
            max-height: 600px;
            overflow-y: auto;
        }
        .comment-item {
            transition: all 0.2s ease;
            padding: 1rem;
            margin: 0.5rem 0;
            border-radius: 10px;
        }
        .comment-item:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        .user-avatar {
            font-size: 14px;
            font-weight: bold;
        }
        .comment-content {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
            margin-top: 0.75rem;
        }
        .comment-form {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 15px;
            padding: 1.5rem;
        }
        .comment-number {
            font-size: 0.8rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php include('includes/navigation.php'); renderNavigation(); ?>
    
    <?php if ($item): ?>
    <div class="container mt-4 mb-5">
        <!-- Breadcrumb Navigation -->
        <nav aria-label="breadcrumb" class="mb-4">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/lost-and-found/items.php" class="text-decoration-none">All Items</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($item['title']); ?></li>
            </ol>
        </nav>

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

        <div class="row">
            <!-- Main Item Details -->
            <div class="col-lg-8">
                <div class="card detail-card mb-4">
                    <div class="card-body p-4">
                        <!-- Item Header -->
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h1 class="card-title h2 mb-2"><?php echo htmlspecialchars($item['title']); ?></h1>
                                <span class="badge status-badge <?php echo $item['status'] === 'lost' ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo $item['status'] === 'lost' ? '🔍 Lost Item' : '✅ Found Item'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Item Image -->
                        <div class="mb-4">
                            <?php if (!empty($item['photo_url'])): ?>
                                <img src="<?php echo htmlspecialchars($item['photo_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                     class="item-image">
                            <?php else: ?>
                                <div class="default-image">
                                    <div class="text-center">
                                        <i class="bi bi-image fs-1 mb-2"></i><br>
                                        No Image Available
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Item Description -->
                        <?php if (!empty($item['description'])): ?>
                            <div class="mb-4">
                                <h5>Description</h5>
                                <p class="text-muted"><?php echo nl2br(htmlspecialchars($item['description'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <!-- Item Details Grid -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <h6 class="text-muted">Posted By</h6>
                                <p class="mb-0">
                                    <a href="/lost-and-found/user/profile.php?id=<?php echo $item['owner_id']; ?>" 
                                       class="text-decoration-none fw-bold">
                                        <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                                    </a>
                                </p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <h6 class="text-muted">Posted Date</h6>
                                <p class="mb-0"><?php echo date('F j, Y \a\t g:i A', strtotime($item['posted_at'])); ?></p>
                            </div>
                            <?php if (!empty($item['location_details'])): ?>
                                <div class="col-12 mb-3">
                                    <h6 class="text-muted">Location</h6>
                                    <p class="mb-0">
                                        <i class="bi bi-geo-alt text-primary me-2"></i>
                                        <?php echo htmlspecialchars($item['location_details']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($item['categories'])): ?>
                                <div class="col-12 mb-3">
                                    <h6 class="text-muted">Categories</h6>
                                    <p class="mb-0">
                                        <i class="bi bi-tags text-primary me-2"></i>
                                        <?php echo htmlspecialchars($item['categories']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Claims Section for Item Owner -->
                <?php if ($user_id && $user_id == $item['owner_id'] && !empty($all_claims)): ?>
                    <div class="card detail-card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-person-check me-2"></i>
                                Claims for this Item (<?php echo count($all_claims); ?>)
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($all_claims as $claim): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($claim['first_name'] . ' ' . $claim['last_name']); ?></h6>
                                            <p class="text-muted mb-2"><?php echo nl2br(htmlspecialchars($claim['statement'])); ?></p>
                                            <small class="text-muted">
                                                Claimed on <?php echo date('M j, Y \a\t g:i A', strtotime($claim['claimed_at'])); ?>
                                            </small>
                                        </div>
                                        <div>
                                            <?php if ($claim['approved_at']): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Pending</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Claim Section -->
                <?php if ($user_id && $user_id != $item['owner_id'] && $item['status'] !== 'claimed'): ?>
                    <div class="claim-section mb-4">
                        <h4 class="mb-3">
                            <i class="bi bi-hand-thumbs-up me-2"></i>
                            Claim this Item
                        </h4>
                        
                        <?php if ($user_claim): ?>
                            <div class="alert alert-info">
                                <h6>Your Claim Status</h6>
                                <p class="mb-2"><?php echo nl2br(htmlspecialchars($user_claim['statement'])); ?></p>
                                <small>
                                    Submitted: <?php echo date('M j, Y', strtotime($user_claim['claimed_at'])); ?><br>
                                    Status: 
                                    <?php if ($user_claim['approved_at']): ?>
                                        <span class="badge bg-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Pending Review</span>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php else: ?>
                            <p class="mb-3">If this item belongs to you, submit a claim with details to verify ownership.</p>
                            
                            <form method="post" class="claim-form">
                                <div class="mb-3">
                                    <label for="statement" class="form-label fw-bold">
                                        Describe why this item belongs to you <span class="text-danger">*</span>
                                    </label>
                                    <textarea class="form-control" 
                                              id="statement" 
                                              name="statement" 
                                              rows="4" 
                                              required
                                              placeholder="Provide specific details about the item that only the owner would know (e.g., contents, distinguishing marks, where/when you lost it, etc.)"></textarea>
                                </div>
                                
                                <button type="submit" name="claim_item" class="btn btn-primary w-100">
                                    <i class="bi bi-check-circle me-2"></i>
                                    Submit Claim
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php elseif (!$user_id): ?>
                    <div class="claim-section mb-4">
                        <h4 class="mb-3">
                            <i class="bi bi-hand-thumbs-up me-2"></i>
                            Claim this Item
                        </h4>
                        <p class="mb-3">You need to be logged in to claim this item.</p>
                        <a href="/lost-and-found/auth/login.php" class="btn btn-light w-100">
                            <i class="bi bi-box-arrow-in-right me-2"></i>
                            Login to Claim
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Contact Information -->
                <?php 
                // Check if current user is an approved claimer
                $is_approved_claimer = false;
                if ($user_id) {
                    $approved_claim_check = $conn->prepare("SELECT claim_id FROM claim_approvals WHERE item_id = ? AND user_id = ? AND approved_at IS NOT NULL");
                    $approved_claim_check->bind_param("ii", $item_id, $user_id);
                    $approved_claim_check->execute();
                    $is_approved_claimer = $approved_claim_check->get_result()->num_rows > 0;
                    $approved_claim_check->close();
                }
                ?>
                
                <?php if (($item['status'] === 'found' || $item['status'] === 'claimed') && $is_approved_claimer): ?>
                    <div class="contact-info">
                        <h5 class="mb-3">
                            <i class="bi bi-person-lines-fill me-2"></i>
                            Contact Information
                        </h5>
                        <div class="alert alert-success mb-3">
                            <i class="bi bi-check-circle-fill mr-2"></i>
                            <strong>Great news!</strong> Your claim has been approved. You can now contact the item finder.
                        </div>
                        <p class="text-muted mb-3">Contact the item finder:</p>
                        <div class="text-center">
                            <button class="btn btn-outline-primary" data-toggle="modal" data-target="#contactModal">
                                <i class="bi bi-envelope mr-2"></i>
                                Contact Finder
                            </button>
                        </div>
                    </div>
                <?php elseif (($item['status'] === 'lost' || $item['status'] === 'claimed') && $is_approved_claimer): ?>
                    <div class="contact-info">
                        <h5 class="mb-3">
                            <i class="bi bi-person-lines-fill mr-2"></i>
                            Contact Information
                        </h5>
                        <div class="alert alert-success mb-3">
                            <i class="bi bi-check-circle-fill mr-2"></i>
                            <strong>Great news!</strong> Your claim has been approved. You can now contact the item owner.
                        </div>
                        <p class="text-muted mb-3">Contact the person who lost this item:</p>
                        <div class="text-center">
                            <button class="btn btn-outline-primary" data-toggle="modal" data-target="#contactModal">
                                <i class="bi bi-envelope mr-2"></i>
                                Contact Owner
                            </button>
                        </div>
                    </div>
                <?php elseif (($item['status'] === 'found' || $item['status'] === 'lost') && $user_id && !$is_approved_claimer): ?>
                    <div class="contact-info">
                        <h5 class="mb-3">
                            <i class="bi bi-shield-lock me-2"></i>
                            Contact Information
                        </h5>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Contact information is only available to users with approved claims for this item.
                        </div>
                        <p class="text-muted">Submit a claim to request access to contact details.</p>
                    </div>
                <?php elseif (($item['status'] === 'found' || $item['status'] === 'lost')): ?>
                    <div class="contact-info">
                        <h5 class="mb-3">
                            <i class="bi bi-person-lines-fill me-2"></i>
                            Contact Information
                        </h5>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Please <a href="/lost-and-found/auth/login.php" class="alert-link">login</a> and submit a claim to access contact information.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Comments Section -->
                <?php if ($item['admin_id'] ?? null): ?>
                    <div class="card comments-section">
                        <div class="card-header d-flex justify-content-between align-items-center bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-chat-dots me-2"></i>Comments
                            </h5>
                            <span class="badge bg-light text-primary"><?php echo count($comments); ?></span>
                        </div>
                        <div class="card-body">
                            <!-- Add Comment Form (Only for logged-in users) -->
                            <?php if ($user_id): ?>
                                <?php if (isset($comment_success_message)): ?>
                                    <div class="alert alert-success alert-dismissible fade show">
                                        <i class="bi bi-check-circle-fill me-2"></i><?php echo $comment_success_message; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <?php if (isset($comment_error_message)): ?>
                                    <div class="alert alert-danger alert-dismissible fade show">
                                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $comment_error_message; ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                    </div>
                                <?php endif; ?>

                                <div class="comment-form mb-4">
                                    <form method="POST">
                                        <div class="mb-3">
                                            <textarea class="form-control" name="comment_description" rows="3" 
                                                      placeholder="Share your thoughts about this item..." required></textarea>
                                        </div>
                                        <button type="submit" name="add_comment" class="btn btn-primary btn-sm">
                                            <i class="bi bi-chat-left-text me-2"></i>Add Comment
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <a href="/lost-and-found/auth/login.php" class="alert-link">Login</a> to join the discussion and add comments.
                                </div>
                            <?php endif; ?>

                            <!-- Display Comments -->
                            <?php if (empty($comments)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-chat-square-text fs-3 mb-2 d-block opacity-50"></i>
                                    <h6 class="mb-1">No comments yet</h6>
                                    <p class="mb-0 small">Be the first to comment!</p>
                                </div>
                            <?php else: ?>
                                <div class="comments-list" style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($comments as $comment): ?>
                                        <div class="comment-item mb-3 p-2 border-bottom">
                                            <div class="d-flex align-items-start">
                                                <div class="user-avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                     style="width: 35px; height: 35px; flex-shrink: 0; font-size: 0.8rem;">
                                                    <?php echo strtoupper(substr($comment['first_name'], 0, 1) . substr($comment['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                                        <h6 class="mb-0 small fw-bold">
                                                            <a href="/lost-and-found/user/profile.php?id=<?php echo $comment['user_id']; ?>" 
                                                               class="text-decoration-none">
                                                                <?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?>
                                                            </a>
                                                            <?php if ($comment['user_id'] == $item['owner_id']): ?>
                                                                <span class="badge bg-success ms-1" style="font-size: 0.65rem;">Owner</span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <small class="text-muted">
                                                            <?php echo date('M j, g:i A', strtotime($comment['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($comment['description'])); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center py-4">
                            <i class="bi bi-hourglass-split fs-3 text-muted mb-2 d-block"></i>
                            <h6 class="text-muted small">Comments available after admin approval</h6>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Contact Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" role="dialog" aria-labelledby="contactModalLabel" aria-hidden="true" style="z-index: 9999;">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel">
                        <?php echo $item['status'] === 'found' ? 'Contact Item Finder' : 'Contact Item Owner'; ?>
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <?php if ($user_id && $is_approved_claimer): ?>
                        <?php
                        // Get all contact information for approved claimers only
                        $emails = [];
                        $phones = [];
                        
                        try {
                            // Get emails from user_emails table
                            $emails_query = "SELECT email FROM user_emails WHERE user_id = ?";
                            $emails_stmt = $conn->prepare($emails_query);
                            if ($emails_stmt) {
                                $emails_stmt->bind_param("i", $item['owner_id']);
                                $emails_stmt->execute();
                                $emails_result = $emails_stmt->get_result();
                                while ($email_row = $emails_result->fetch_assoc()) {
                                    $emails[] = $email_row['email'];
                                }
                                $emails_stmt->close();
                            }
                            
                            // Get phones from user_phones table
                            $phones_query = "SELECT phone FROM user_phones WHERE user_id = ?";
                            $phones_stmt = $conn->prepare($phones_query);
                            if ($phones_stmt) {
                                $phones_stmt->bind_param("i", $item['owner_id']);
                                $phones_stmt->execute();
                                $phones_result = $phones_stmt->get_result();
                                while ($phone_row = $phones_result->fetch_assoc()) {
                                    $phones[] = $phone_row['phone'];
                                }
                                $phones_stmt->close();
                            }
                        } catch (Exception $e) {
                            // Handle database errors gracefully
                            error_log("Contact query error: " . $e->getMessage());
                        }
                        ?>
                        
                        <div class="text-center mb-3">
                            <div class="user-avatar bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                                 style="width: 60px; height: 60px;">
                                <?php echo strtoupper(substr($item['first_name'], 0, 1) . substr($item['last_name'], 0, 1)); ?>
                            </div>
                        </div>
                        
                        <h6 class="text-center mb-3">
                            <a href="/lost-and-found/user/profile.php?id=<?php echo $item['owner_id']; ?>" 
                               class="text-decoration-none">
                                <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                            </a>
                        </h6>
                        
                        <div class="contact-details">
                            <?php if (!empty($emails)): ?>
                                <div class="d-flex align-items-start mb-3">
                                    <i class="bi bi-envelope-fill text-primary mr-3 fs-5 mt-1"></i>
                                    <div class="flex-grow-1">
                                        <strong>Email<?php echo count($emails) > 1 ? 's' : ''; ?>:</strong><br>
                                        <?php foreach ($emails as $index => $email): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($email); ?>" 
                                               class="text-decoration-none d-block<?php echo $index > 0 ? ' mt-1' : ''; ?>">
                                                <?php echo htmlspecialchars($email); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($phones)): ?>
                                <div class="d-flex align-items-start mb-3">
                                    <i class="bi bi-telephone-fill text-success mr-3 fs-5 mt-1"></i>
                                    <div class="flex-grow-1">
                                        <strong>Phone<?php echo count($phones) > 1 ? 's' : ''; ?>:</strong><br>
                                        <?php foreach ($phones as $index => $phone): ?>
                                            <a href="tel:<?php echo htmlspecialchars($phone); ?>" 
                                               class="text-decoration-none d-block<?php echo $index > 0 ? ' mt-1' : ''; ?>">
                                                <?php echo htmlspecialchars($phone); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (empty($emails) && empty($phones)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle mr-2"></i>
                                    This user hasn't provided contact information yet. You can try contacting them through the campus administration.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle mr-2"></i>
                            <strong>Privacy Notice:</strong> This contact information is only visible to you because your claim for this item has been approved.
                        </div>
                    <?php else: ?>
                        <p>To contact <strong><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></strong> about this item, please use the following information:</p>
                        <div class="alert alert-info">
                            <p class="mb-0"><strong>Note:</strong> For privacy reasons, direct contact information is not displayed. Please visit the campus security office or contact administration to arrange communication with the item finder.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <?php if ($user_id && $is_approved_claimer): ?>
                        <?php if (!empty($emails)): ?>
                            <a href="mailto:<?php echo htmlspecialchars($emails[0]); ?>?subject=Regarding your <?php echo $item['status']; ?> item: <?php echo urlencode($item['title']); ?>" 
                               class="btn btn-primary">
                                <i class="bi bi-envelope mr-2"></i>Send Email
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            console.log('Document ready, jQuery version:', $.fn.jquery);
            console.log('Bootstrap loaded:', typeof $.fn.modal !== 'undefined');
            
            // Handle modal button clicks
            $('[data-target="#contactModal"]').click(function(e) {
                console.log('Contact button clicked');
                $('#contactModal').modal('show');
            });
            
            // Test modal events
            $('#contactModal').on('shown.bs.modal', function () {
                console.log('Modal shown successfully');
            });
            
            $('#contactModal').on('show.bs.modal', function () {
                console.log('Modal about to show');
            });
        });
    </script>
</body>
</html>
