<?php
session_start();
include("includes/db.php");

// Get recent items for homepage display
$recent_items_query = "
    SELECT i.item_id, i.title, i.status, i.posted_at, 
           u.first_name, u.last_name, p.url as photo_url
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    ORDER BY i.posted_at DESC
    LIMIT 6
";
$recent_items = $conn->query($recent_items_query);

// Get statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_items,
        SUM(CASE WHEN status = 'found' THEN 1 ELSE 0 END) as found_items,
        SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed_items
    FROM items
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost & Found - Campus Item Recovery System</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/css/spacing.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5rem 0;
        }
        .feature-card {
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            height: 100%;
        }
        .feature-card:hover {
            transform: translateY(-5px);
        }
        .stats-card {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef);
            border: none;
            border-radius: 15px;
        }
        .item-preview {
            height: 150px;
            object-fit: cover;
            border-radius: 10px;
        }
        .item-placeholder {
            height: 150px;
            background: #f8f9fa;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <?php include('includes/navigation.php'); renderNavigation('home'); ?>
    
    <!-- Hero Section -->
    <section class="hero-section text-center">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 mx-auto">
                    <h1 class="display-4 font-weight-bold mb-4">Lost Something? Found Something?</h1>
                    <p class="lead mb-4">Connect lost items with their rightful owners through our campus community platform.</p>
                    <div class="text-center">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <a href="/lost-and-found/post_item.php" class="btn btn-light btn-lg mr-2">
                                <i class="bi bi-plus-circle mr-2"></i>Post an Item
                            </a>
                            <a href="/lost-and-found/items.php" class="btn btn-outline-light btn-lg">
                                <i class="bi bi-search mr-2"></i>Browse Items
                            </a>
                        <?php else: ?>
                            <a href="/lost-and-found/auth/register.php" class="btn btn-light btn-lg mr-2">
                                <i class="bi bi-person-plus mr-2"></i>Get Started
                            </a>
                            <a href="/lost-and-found/auth/login.php" class="btn btn-outline-light btn-lg">
                                <i class="bi bi-box-arrow-in-right mr-2"></i>Login
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-4">
                    <div class="card stats-card text-center p-4">
                        <div class="card-body">
                            <i class="bi bi-collection display-1 text-primary mb-3"></i>
                            <h3 class="font-weight-bold text-primary"><?php echo isset($stats['total_items']) ? $stats['total_items'] : 0; ?></h3>
                            <p class="text-muted mb-0">Total Items</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-4">
                    <div class="card stats-card text-center p-4">
                        <div class="card-body">
                            <i class="bi bi-search display-1 text-danger mb-3"></i>
                            <h3 class="font-weight-bold text-danger"><?php echo isset($stats['lost_items']) ? $stats['lost_items'] : 0; ?></h3>
                            <p class="text-muted mb-0">Lost Items</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-4">
                    <div class="card stats-card text-center p-4">
                        <div class="card-body">
                            <i class="bi bi-check-circle display-1 text-success mb-3"></i>
                            <h3 class="font-weight-bold text-success"><?php echo isset($stats['found_items']) ? $stats['found_items'] : 0; ?></h3>
                            <p class="text-muted mb-0">Found Items</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-4">
                    <div class="card stats-card text-center p-4">
                        <div class="card-body">
                            <i class="bi bi-heart-fill display-1 text-warning mb-3"></i>
                            <h3 class="font-weight-bold text-warning"><?php echo isset($stats['claimed_items']) ? $stats['claimed_items'] : 0; ?></h3>
                            <p class="text-muted mb-0">Reunited</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="py-5">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-lg-8 mx-auto">
                    <h2 class="display-5 font-weight-bold mb-3">How It Works</h2>
                    <p class="lead text-muted">Simple steps to reunite lost items with their owners</p>
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card feature-card text-center p-4">
                        <div class="card-body">
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                                <i class="bi bi-camera display-1 text-primary"></i>
                            </div>
                            <h4 class="font-weight-bold mb-3">Post with Photos</h4>
                            <p class="text-muted">Upload clear photos and detailed descriptions of lost or found items to help with identification.</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card feature-card text-center p-4">
                        <div class="card-body">
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                                <i class="bi bi-search display-1 text-success"></i>
                            </div>
                            <h4 class="font-weight-bold mb-3">Browse & Search</h4>
                            <p class="text-muted">Easily browse through all posted items or search for specific items you've lost.</p>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card feature-card text-center p-4">
                        <div class="card-body">
                            <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width: 80px; height: 80px;">
                                <i class="bi bi-hand-thumbs-up display-1 text-warning"></i>
                            </div>
                            <h4 class="font-weight-bold mb-3">Claim & Verify</h4>
                            <p class="text-muted">Submit a claim with verification details, and get your item back through our secure process.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Recent Items Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row text-center mb-5">
                <div class="col-lg-8 mx-auto">
                    <h2 class="display-5 fw-bold mb-3">Recent Items</h2>
                    <p class="lead text-muted">Latest lost and found items posted by our community</p>
                </div>
            </div>
            
            <div class="row g-4">
                <?php while ($item = $recent_items->fetch_assoc()): ?>
                    <div class="col-lg-4 col-md-6">
                        <div class="card h-100">
                            <?php if (!empty($item['photo_url'])): ?>
                                <img src="<?php echo htmlspecialchars($item['photo_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($item['title']); ?>" 
                                     class="card-img-top item-preview">
                            <?php else: ?>
                                <div class="item-placeholder">
                                    <i class="bi bi-image fs-1"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title"><?php echo htmlspecialchars($item['title']); ?></h5>
                                    <span class="badge <?php echo $item['status'] === 'lost' ? 'bg-danger' : 'bg-success'; ?>">
                                        <?php echo ucfirst($item['status']); ?>
                                    </span>
                                </div>
                                <p class="text-muted small mb-3">
                                    Posted by <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?>
                                    <br>
                                    <?php echo date('M j, Y', strtotime($item['posted_at'])); ?>
                                </p>
                                <a href="/lost-and-found/item_detail.php?id=<?php echo $item['item_id']; ?>" class="btn btn-primary btn-sm">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
            
            <div class="text-center mt-5">
                <a href="/lost-and-found/items.php" class="btn btn-outline-primary btn-lg">
                    <i class="bi bi-grid me-2"></i>View All Items
                </a>
            </div>
        </div>
    </section>

    <!-- Call to Action -->
    <section class="py-5 bg-primary text-white">
        <div class="container text-center">
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <h2 class="display-6 fw-bold mb-3">Ready to Get Started?</h2>
                    <p class="lead mb-4">Join our community and help reunite lost items with their owners.</p>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <a href="/lost-and-found/auth/register.php" class="btn btn-light btn-lg">
                            <i class="bi bi-person-plus me-2"></i>Create Account
                        </a>
                    <?php else: ?>
                        <a href="/lost-and-found/post_item.php" class="btn btn-light btn-lg">
                            <i class="bi bi-plus-circle me-2"></i>Post Your First Item
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white py-4">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p class="mb-0">&copy; 2025 Lost & Found System. Built for campus community.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0">
                        <i class="bi bi-heart-fill text-danger"></i> 
                        Connecting people with their belongings
                    </p>
                </div>
            </div>
        </div>
    </footer>

    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
</body>
</html>