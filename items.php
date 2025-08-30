<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include("./includes/db.php");

// Debug: Check if form data is being received
// echo "<pre>GET data: " . print_r($_GET, true) . "</pre>";

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$category_filter = $_GET['category'] ?? '';
$location_filter = $_GET['location'] ?? '';
$user_filter = $_GET['user'] ?? '';

// Build WHERE clause
$where_conditions = ["i.approved_at IS NOT NULL"]; // Only show approved items
$params = [];
$param_types = '';

if (!empty($search)) {
    $where_conditions[] = "(i.title LIKE ? OR i.description LIKE ? OR l.location_details LIKE ?)";
    $search_param = '%' . $search . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "i.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($location_filter)) {
    $where_conditions[] = "l.location_details = ?";
    $params[] = $location_filter;
    $param_types .= 's';
}

if (!empty($user_filter)) {
    $where_conditions[] = "i.user_id = ?";
    $params[] = $user_filter;
    $param_types .= 'i';
}

if (!empty($category_filter)) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM item_categories ic WHERE ic.item_id = i.item_id AND ic.category_id = ?)";
    $params[] = $category_filter;
    $param_types .= 'i';
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch all approved items with user name, location details, and photo
$query = "
    SELECT i.item_id, i.title, i.description, i.status, i.posted_at, 
           u.first_name, u.last_name, u.user_id, l.location_details, p.url as photo_url,
           GROUP_CONCAT(c.name SEPARATOR ', ') as categories
    FROM items i
    JOIN users u ON i.user_id = u.user_id
    LEFT JOIN locations l ON i.location_id = l.location_id
    LEFT JOIN photos p ON i.photo_id = p.photo_id
    LEFT JOIN item_categories ic ON i.item_id = ic.item_id
    LEFT JOIN categories c ON ic.category_id = c.category_id
    $where_clause
    GROUP BY i.item_id, i.title, i.description, i.status, i.posted_at, 
             u.first_name, u.last_name, u.user_id, l.location_details, p.url
    ORDER BY i.posted_at DESC
";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    if ($param_types) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

// Get unique locations for filter dropdown
$locations_query = "SELECT DISTINCT location_details FROM locations WHERE location_details IS NOT NULL AND location_details != '' ORDER BY location_details";
$locations_result = $conn->query($locations_query);
$locations = [];
while ($row = $locations_result->fetch_assoc()) {
    $locations[] = $row;
}

// Get categories for filter dropdown
$categories_query = "SELECT category_id, name FROM categories ORDER BY name";
$categories_result = $conn->query($categories_query);
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lost and Found Items</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/css/spacing.css" rel="stylesheet">
    <link href="./assets/css/form-styling.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .item-card {
            margin-bottom: 20px;
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .item-image {
            height: 200px;
            object-fit: cover;
            width: 100%;
        }

        .default-image {
            height: 200px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            font-size: 14px;
            border-bottom: 1px solid #dee2e6;
        }

        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1;
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-img-top-container {
            position: relative;
            overflow: hidden;
        }

        .card-body {
            padding: 1.25rem;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }

        .card-title a {
            color: #2c3e50;
            text-decoration: none;
        }

        .card-title a:hover {
            color: #667eea;
        }

        .card-text {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1rem;
        }

        .card-footer {
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            padding: 1rem 1.25rem;
        }

        .card-footer .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.6rem 1.2rem;
        }

        .card-footer .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }

        .card-footer .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6b4190 100%);
        }

        .text-muted {
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
        }

        .text-muted a {
            color: #667eea !important;
            text-decoration: none;
        }

        .text-muted a:hover {
            color: #5a6fd8 !important;
        }

        /* Responsive improvements */
        @media (max-width: 768px) {
            .item-card {
                margin-bottom: 1.5rem;
            }

            .col-md-4 {
                margin-bottom: 1rem;
            }
        }
    </style>
</head>

<body>
    <?php include('includes/navigation.php');
    renderNavigation('items'); ?>
    <div class="container mt-4">
        <h1 class="mb-4">Lost and Found Items</h1>

        <!-- Success Message -->
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill mr-2"></i>
                <strong>Success!</strong>
                <?php if (isset($_GET['pending']) && $_GET['pending'] == '1'): ?>
                    Your item has been submitted and is pending admin approval. You'll be notified once it's approved.
                <?php else: ?>
                    Your item has been posted successfully and is now visible to other users.
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Search and Filter Section -->
        <div class="mb-4">
            <form method="GET" action="items.php" class="filter-form">
                <!-- First Row - Main Filters -->
                <div class="row align-items-end">
                    <div class="col-lg-6 col-md-6 col-12">
                        <label for="search" class="form-label">Search Items</label>
                        <div class="input-group input-group-search">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" class="form-control search-input" id="search" name="search"
                                placeholder="Search items, descriptions, locations..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-3 col-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-control custom-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>>🔍 Lost</option>
                            <option value="found" <?php echo $status_filter === 'found' ? 'selected' : ''; ?>>✅ Found</option>
                            <option value="claimed" <?php echo $status_filter === 'claimed' ? 'selected' : ''; ?>>🎯 Claimed</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-3 col-9">
                        <label for="category" class="form-label">Category</label>
                        <select class="form-control custom-select" id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat_row): ?>
                                <option value="<?php echo $cat_row['category_id']; ?>"
                                    <?php echo $category_filter == $cat_row['category_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat_row['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Second Row - Location and Action Buttons -->
                <div class="row mt-2 align-items-end">
                    <div class="col-lg-8 col-md-8 col-12 mb-3">
                        <label for="location" class="form-label">Location</label>
                        <select class="form-control custom-select" id="location" name="location">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $loc_row): ?>
                                <option value="<?php echo htmlspecialchars($loc_row['location_details']); ?>"
                                    <?php echo $location_filter === $loc_row['location_details'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($loc_row['location_details']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-4 col-12 mb-3">
                        <div class="btn-group w-100" role="group">
                            <button type="submit" class="btn btn-filter">
                                <i class="bi bi-funnel"></i> Filter
                            </button>
                            <a href="items.php" class="btn btn-reset">
                                <i class="bi bi-arrow-clockwise"></i> Reset
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Items Display Section -->
        <?php if ($result->num_rows > 0): ?>
            <div class="row">
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div class="col-md-4 col-sm-6 col-12 mb-4">
                        <div class="card item-card h-100">
                            <div class="card-img-top-container">
                                <?php if (!empty($row['photo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($row['photo_url']); ?>"
                                        alt="<?php echo htmlspecialchars($row['title']); ?>"
                                        class="card-img-top item-image">
                                <?php else: ?>
                                    <div class="default-image">
                                        <span>No Image Available</span>
                                    </div>
                                <?php endif; ?>

                                <span class="badge status-badge <?php echo $row['status'] === 'lost' ? 'bg-danger' : 'bg-success'; ?>">
                                    <?php echo ucfirst(htmlspecialchars($row['status'])); ?>
                                </span>
                            </div>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">
                                    <a href="item_detail.php?id=<?php echo $row['item_id']; ?>"
                                        class="text-dark">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                    </a>
                                </h5>

                                <?php if (!empty($row['description'])): ?>
                                    <p class="card-text">
                                        <?php
                                        $description = htmlspecialchars($row['description']);
                                        echo strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description;
                                        ?>
                                    </p>
                                <?php endif; ?>

                                <div class="mt-auto">
                                    <small class="text-muted">
                                        <i class="bi bi-person"></i> Posted by:
                                        <a href="/lost-and-found/user/profile.php?id=<?php echo $row['user_id']; ?>">
                                            <strong><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></strong>
                                        </a>
                                    </small><br>

                                    <small class="text-muted">
                                        <i class="bi bi-clock"></i>
                                        <?php echo date('M j, Y g:i A', strtotime($row['posted_at'])); ?>
                                    </small><br>

                                    <?php if (!empty($row['location_details'])): ?>
                                        <small class="text-muted">
                                            <i class="bi bi-geo-alt"></i>
                                            <?php echo htmlspecialchars($row['location_details']); ?>
                                        </small><br>
                                    <?php endif; ?>

                                    <?php if (!empty($row['categories'])): ?>
                                        <small class="text-muted">
                                            <i class="bi bi-tags"></i>
                                            <?php echo htmlspecialchars($row['categories']); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-footer">
                                <a href="item_detail.php?id=<?php echo $row['item_id']; ?>"
                                    class="btn btn-primary btn-sm w-100">
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center" role="alert">
                <h4 class="alert-heading">No Items Found</h4>
                <p>There are currently no lost or found items posted.</p>
                <hr>
                <p class="mb-0">
                    <a href="post_item.php" class="btn btn-primary">Post an Item</a>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-hide success messages after 5 seconds
            $('.alert-success').delay(5000).fadeOut(300);
        });
    </script>
</body>

</html>