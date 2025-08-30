<?php
session_start();
include("./includes/db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $title = trim($_POST["title"]);
    $description = trim($_POST["description"]);
    $status = $_POST["status"] === "found" ? "found" : "lost";
    $location_details = trim($_POST["location_details"]);
    $location_type = $_POST["location_type"] ?? '';
    $location_specific = trim($_POST["location_specific"] ?? '');
    $categories = $_POST["categories"] ?? [];

    // Validate categories
    if (empty($categories)) {
        $error_message = "Please select at least one category for the item.";
    } else {
        // Handle photo upload
        $photo_id = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = './uploads/';

        // Create uploads directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $file_type = $_FILES['photo']['type'];
        $file_size = $_FILES['photo']['size'];

        if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
            $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            $unique_filename = time() . '_' . uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $unique_filename;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                // Insert photo into database
                $stmt_photo = $conn->prepare("INSERT INTO photos (url) VALUES (?)");
                $stmt_photo->bind_param("s", $upload_path);
                if ($stmt_photo->execute()) {
                    $photo_id = $stmt_photo->insert_id;
                }
                $stmt_photo->close();
            } else {
                $error_message = "Error uploading photo.";
            }
        } else {
            $error_message = "Invalid file type or file too large. Please upload JPG, PNG, or GIF files under 5MB.";
        }
    }

    // Insert location first (if location_details given)
    $location_id = null;
    if (!empty($location_details) || !empty($location_type)) {
        $final_location_details = $location_details;
        if (!empty($location_type) && !empty($location_specific)) {
            $final_location_details = $location_details . " - " . $location_type . ": " . $location_specific;
        } elseif (!empty($location_type)) {
            $final_location_details = $location_details . " - " . $location_type;
        }
        
        $stmt_loc = $conn->prepare("INSERT INTO locations (location_details) VALUES (?)");
        $stmt_loc->bind_param("s", $final_location_details);
        if ($stmt_loc->execute()) {
            $location_id = $stmt_loc->insert_id;
            
            // Insert into specific location table based on type
            if (!empty($location_type) && !empty($location_specific)) {
                switch ($location_type) {
                    case 'classroom':
                        $parts = explode(',', $location_specific);
                        $room_no = trim($parts[0] ?? '');
                        $floor = isset($parts[1]) ? intval(trim($parts[1])) : null;
                        $building = trim($parts[2] ?? '');
                        
                        $stmt_class = $conn->prepare("INSERT INTO classrooms (location_id, room_no, floor, building) VALUES (?, ?, ?, ?)");
                        $stmt_class->bind_param("isis", $location_id, $room_no, $floor, $building);
                        $stmt_class->execute();
                        $stmt_class->close();
                        break;
                        
                    case 'shop':
                        $stmt_shop = $conn->prepare("INSERT INTO shops (location_id, shop_name) VALUES (?, ?)");
                        $stmt_shop->bind_param("is", $location_id, $location_specific);
                        $stmt_shop->execute();
                        $stmt_shop->close();
                        break;
                        
                    case 'gate':
                        $stmt_gate = $conn->prepare("INSERT INTO gates (location_id, gate_no) VALUES (?, ?)");
                        $stmt_gate->bind_param("is", $location_id, $location_specific);
                        $stmt_gate->execute();
                        $stmt_gate->close();
                        break;
                        
                    case 'place':
                        $stmt_place = $conn->prepare("INSERT INTO places (location_id, name) VALUES (?, ?)");
                        $stmt_place->bind_param("is", $location_id, $location_specific);
                        $stmt_place->execute();
                        $stmt_place->close();
                        break;
                }
            }
        }
        $stmt_loc->close();
    }

    // Insert item (items now require admin approval)
    $stmt = $conn->prepare("
        INSERT INTO items (title, description, status, user_id, location_id, photo_id, approved_at)
        VALUES (?, ?, ?, ?, ?, ?, NULL)
    ");
    // If location_id or photo_id is null, bind_param requires null cast as "i"
    if ($location_id === null) {
        $location_id = null; // explicitly null for binding
    }
    if ($photo_id === null) {
        $photo_id = null; // explicitly null for binding
    }
    $stmt->bind_param("sssiii", $title, $description, $status, $user_id, $location_id, $photo_id);

    if ($stmt->execute()) {
        $item_id = $stmt->insert_id;
        
        // Insert categories for this item
        $cat_stmt = $conn->prepare("INSERT INTO item_categories (item_id, category_id) VALUES (?, ?)");
        foreach ($categories as $category_id) {
            $cat_stmt->bind_param("ii", $item_id, $category_id);
            $cat_stmt->execute();
        }
        $cat_stmt->close();
        
        // Redirect to items page after successful submission
        header("Location: /lost-and-found/items.php?success=1&pending=1");
        exit();
    } else {
        $error_message = "Error posting item: " . $stmt->error;
    }

    $stmt->close();
    } // Close the categories validation if statement
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Item - Lost and Found</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/css/spacing.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .form-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 2rem;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }

        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }

        .file-input-container input[type=file] {
            position: absolute;
            left: -9999px;
        }

        .file-input-label {
            cursor: pointer;
            display: block;
            padding: 0.5rem 1rem;
            border: 2px dashed #dee2e6;
            border-radius: 0.375rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .file-input-label:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }

        .selected-file {
            margin-top: 0.5rem;
            padding: 0.5rem;
            background-color: #e7f3ff;
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }
    </style>
</head>

<body class="bg-light">
    <?php include('includes/navigation.php');
    renderNavigation('post'); ?>
    <!-- Header Section -->
    <div class="page-header">
        <div class="container">
            <div class="row">
                <div class="col-12 text-center">
                    <h1 class="display-4 mb-3">Post a Lost or Found Item</h1>
                    <p class="lead">Help reunite items with their owners or find your lost belongings</p>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h3 class="card-title mb-0">
                            <i class="bi bi-plus-circle mr-2"></i>Post a Lost or Found Item
                        </h3>
                    </div>
                    <div class="card-body">
                        <!-- Navigation breadcrumb -->
                        <nav aria-label="breadcrumb" class="mb-4">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="/lost-and-found/items.php">Items</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Post Item</li>
                            </ol>
                        </nav>

                        <!-- Error Messages -->
                        <?php if (isset($error_message)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill mr-2"></i>
                                <?php echo htmlspecialchars($error_message); ?>
                                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <!-- Post Item Form -->
                        <form method="post" action="post_item.php" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="row">
                            <!-- Item Information Section Header -->
                            <div class="col-12 mb-4">
                                <h5 class="border-bottom pb-2 text-primary">
                                    <i class="bi bi-info-circle mr-2"></i>Item Information
                                </h5>
                            </div>
                            
                            <!-- Title Field -->
                            <div class="col-12 mb-3">
                                <label for="title" class="form-label font-weight-bold">
                                    Item Title <span class="text-danger">*</span>
                                </label>
                                <input type="text"
                                    class="form-control form-control-lg"
                                    id="title"
                                    name="title"
                                    required
                                    maxlength="150"
                                    placeholder="e.g., Blue iPhone 13, Brown Leather Wallet"
                                    value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                                <div class="invalid-feedback">
                                    Please provide a descriptive title for the item.
                                </div>
                            </div>

                            <!-- Description Field -->
                            <div class="col-12 mb-3">
                                <label for="description" class="form-label font-weight-bold">Description</label>
                                <textarea class="form-control"
                                    id="description"
                                    name="description"
                                    rows="4"
                                    placeholder="Provide additional details about the item (color, brand, distinguishing features, etc.)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                <small class="form-text text-muted">Help others identify the item with specific details.</small>
                            </div>

                            <!-- Category Selection -->
                            <div class="col-12 mb-3">
                                <label for="categories" class="form-label font-weight-bold">Categories <span class="text-danger">*</span></label>
                                <select class="form-control custom-select" id="categories" name="categories[]" multiple required style="height: auto; min-height: 120px;">
                                    <?php
                                    // Fetch categories from database
                                    $cat_query = "SELECT category_id, name FROM categories ORDER BY name";
                                    $cat_result = $conn->query($cat_query);
                                    $selected_categories = isset($_POST['categories']) ? $_POST['categories'] : [];
                                    
                                    while ($category = $cat_result->fetch_assoc()): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                                <?php echo in_array($category['category_id'], $selected_categories) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Hold Ctrl (Windows) or Cmd (Mac) to select multiple categories that best describe your item.</small>
                                <div class="invalid-feedback">
                                    Please select at least one category for the item.
                                </div>
                            </div>

                            <!-- Status and Location Section Header -->
                            <div class="col-12 mb-4 mt-4">
                                <h5 class="border-bottom pb-2 text-primary">
                                    <i class="bi bi-geo-alt mr-2"></i>Status & Location Details
                                </h5>
                            </div>

                            <!-- Status and Location Row -->
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label font-weight-bold">Status</label>
                                <select class="form-control custom-select custom-select-lg" id="status" name="status">
                                    <option value="lost" <?php echo (isset($_POST['status']) && $_POST['status'] === 'lost') ? 'selected' : ''; ?>>
                                        🔍 Lost - I lost this item
                                    </option>
                                    <option value="found" <?php echo (isset($_POST['status']) && $_POST['status'] === 'found') ? 'selected' : ''; ?>>
                                        ✅ Found - I found this item
                                    </option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="location_details" class="form-label font-weight-bold">Location</label>
                                <input type="text"
                                    class="form-control form-control-lg"
                                    id="location_details"
                                    name="location_details"
                                    maxlength="255"
                                    placeholder="e.g., Library 2nd floor, Main cafeteria"
                                    value="<?php echo isset($_POST['location_details']) ? htmlspecialchars($_POST['location_details']) : ''; ?>">
                                <small class="form-text text-muted">Where did you lose/find this item?</small>
                            </div>

                            <!-- Location Type -->
                            <div class="col-md-6 mb-3">
                                <label for="location_type" class="form-label font-weight-bold">Location Type</label>
                                <select class="form-control custom-select custom-select-lg" id="location_type" name="location_type" onchange="toggleLocationSpecific()">
                                    <option value="">Select type (Optional)</option>
                                    <option value="classroom" <?php echo (isset($_POST['location_type']) && $_POST['location_type'] === 'classroom') ? 'selected' : ''; ?>>Classroom</option>
                                    <option value="shop" <?php echo (isset($_POST['location_type']) && $_POST['location_type'] === 'shop') ? 'selected' : ''; ?>>Shop</option>
                                    <option value="gate" <?php echo (isset($_POST['location_type']) && $_POST['location_type'] === 'gate') ? 'selected' : ''; ?>>Gate</option>
                                    <option value="place" <?php echo (isset($_POST['location_type']) && $_POST['location_type'] === 'place') ? 'selected' : ''; ?>>General Place</option>
                                </select>
                            </div>

                            <!-- Location Specific Details -->
                            <div class="col-12 mb-3" id="location_specific_div" style="display: none;">
                                <label for="location_specific" class="form-label font-weight-bold">Specific Details</label>
                                <input type="text"
                                    class="form-control"
                                    id="location_specific"
                                    name="location_specific"
                                    placeholder=""
                                    value="<?php echo isset($_POST['location_specific']) ? htmlspecialchars($_POST['location_specific']) : ''; ?>">
                                <small class="form-text text-muted" id="location_help">
                                    <!-- Dynamic help text based on location type -->
                                </small>
                            </div>

                            <!-- Photo Section Header -->
                            <div class="col-12 mb-4 mt-4">
                                <h5 class="border-bottom pb-2 text-primary">
                                    <i class="bi bi-camera mr-2"></i>Photo Upload
                                </h5>
                            </div>

                            <!-- Photo Upload -->
                            <div class="col-12 mb-4">
                                <label class="form-label font-weight-bold">Photo (Optional)</label>
                                <div class="file-input-container">
                                    <input type="file" id="photo" name="photo" accept="image/*" onchange="displaySelectedFile(this)">
                                    <label for="photo" class="file-input-label">
                                        <div class="text-center py-3">
                                            <i class="bi bi-cloud-upload display-1 text-muted mb-2"></i>
                                            <div class="font-weight-bold text-primary">Click to upload a photo</div>
                                            <div class="text-muted small">or drag and drop</div>
                                            <div class="text-muted small mt-2">
                                                Supported formats: JPG, PNG, GIF • Max size: 5MB
                                            </div>
                                        </div>
                                    </label>
                                    <div id="selected-file" class="selected-file d-none">
                                        <i class="bi bi-file-earmark-image mr-2"></i>
                                        <span id="file-name"></span>
                                        <button type="button" class="btn btn-sm btn-outline-danger ml-2" onclick="clearFileInput()">
                                            Remove
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Submit Buttons -->
                            <div class="col-12">
                                <div class="btn-toolbar justify-content-between" role="toolbar">
                                    <div class="btn-group" role="group">
                                        <a href="/lost-and-found/items.php" class="btn btn-outline-secondary btn-lg">
                                            <i class="bi bi-arrow-left mr-2"></i>Cancel
                                        </a>
                                    </div>
                                    <div class="btn-group" role="group">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="bi bi-plus-circle mr-2"></i>Post Item
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and Custom Scripts -->
    <script src="./assets/script/jquery.js"></script>
    <script src="./assets/script/bootstrap.min.js"></script>
    <script>
        // Form validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();

        // File input handling
        function displaySelectedFile(input) {
            const fileDisplay = document.getElementById('selected-file');
            const fileName = document.getElementById('file-name');

            if (input.files && input.files[0]) {
                fileName.textContent = input.files[0].name;
                fileDisplay.classList.remove('d-none');
            } else {
                fileDisplay.classList.add('d-none');
            }
        }

        function clearFileInput() {
            document.getElementById('photo').value = '';
            document.getElementById('selected-file').classList.add('d-none');
        }

        // Drag and drop functionality
        const fileLabel = document.querySelector('.file-input-label');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileLabel.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            fileLabel.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            fileLabel.addEventListener(eventName, unhighlight, false);
        });

        function highlight(e) {
            fileLabel.classList.add('border-primary', 'bg-light');
        }

        function unhighlight(e) {
            fileLabel.classList.remove('border-primary', 'bg-light');
        }

        fileLabel.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            document.getElementById('photo').files = files;
            displaySelectedFile(document.getElementById('photo'));
        }

        // Location type handling
        function toggleLocationSpecific() {
            const locationType = document.getElementById('location_type').value;
            const specificDiv = document.getElementById('location_specific_div');
            const specificInput = document.getElementById('location_specific');
            const helpText = document.getElementById('location_help');

            if (locationType) {
                specificDiv.style.display = 'block';
                let placeholder = '';
                let help = '';

                switch (locationType) {
                    case 'classroom':
                        placeholder = 'e.g., Room 101, 2, Building A (Room, Floor, Building)';
                        help = 'Format: Room Number, Floor, Building (separated by commas)';
                        break;
                    case 'shop':
                        placeholder = 'e.g., Campus Bookstore, Coffee Shop';
                        help = 'Enter the name of the shop';
                        break;
                    case 'gate':
                        placeholder = 'e.g., Gate 1, Main Gate, Side Gate';
                        help = 'Enter the gate number or name';
                        break;
                    case 'place':
                        placeholder = 'e.g., Main Hall, Auditorium, Parking Lot';
                        help = 'Enter the name of the place';
                        break;
                }

                specificInput.placeholder = placeholder;
                helpText.textContent = help;
            } else {
                specificDiv.style.display = 'none';
                specificInput.value = '';
            }
        }

        // Initialize location type on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleLocationSpecific();
        });
    </script>
</body>

</html>