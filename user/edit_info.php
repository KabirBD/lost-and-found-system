<?php
session_start();
include("../includes/db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /lost-and-found/auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['update_profile_picture'])) {
        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            // Validate file type
            if (!in_array($file['type'], $allowed_types)) {
                $error_message = "Only JPEG, PNG, and GIF images are allowed.";
            } 
            // Validate file size
            else if ($file['size'] > $max_size) {
                $error_message = "Image size must be less than 5MB.";
            } 
            else {
                // Generate unique filename
                $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = time() . '_' . uniqid() . '.' . $file_extension;
                $upload_path = '../uploads/' . $new_filename;
                $url_path = '../uploads/' . $new_filename;
                
                // Create uploads directory if it doesn't exist
                if (!is_dir('../uploads')) {
                    mkdir('../uploads', 0755, true);
                }
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Get current photo_id to delete old photo if exists
                    $current_photo_query = "SELECT photo_id FROM users WHERE user_id = ?";
                    $current_photo_stmt = $conn->prepare($current_photo_query);
                    $current_photo_stmt->bind_param("i", $user_id);
                    $current_photo_stmt->execute();
                    $current_photo_result = $current_photo_stmt->get_result();
                    $current_photo = $current_photo_result->fetch_assoc();
                    $old_photo_id = $current_photo['photo_id'] ?? null;
                    $current_photo_stmt->close();
                    
                    // Insert new photo record
                    $photo_insert = $conn->prepare("INSERT INTO photos (url) VALUES (?)");
                    $photo_insert->bind_param("s", $url_path);
                    
                    if ($photo_insert->execute()) {
                        $new_photo_id = $conn->insert_id;
                        
                        // Update user's photo_id
                        $update_user_photo = $conn->prepare("UPDATE users SET photo_id = ? WHERE user_id = ?");
                        $update_user_photo->bind_param("ii", $new_photo_id, $user_id);
                        
                        if ($update_user_photo->execute()) {
                            // Delete old photo file and record if exists
                            if ($old_photo_id) {
                                $old_photo_query = "SELECT url FROM photos WHERE photo_id = ?";
                                $old_photo_stmt = $conn->prepare($old_photo_query);
                                $old_photo_stmt->bind_param("i", $old_photo_id);
                                $old_photo_stmt->execute();
                                $old_photo_result = $old_photo_stmt->get_result();
                                $old_photo_data = $old_photo_result->fetch_assoc();
                                
                                if ($old_photo_data && file_exists($old_photo_data['url'])) {
                                    unlink($old_photo_data['url']);
                                }
                                
                                // Delete old photo record
                                $delete_old_photo = $conn->prepare("DELETE FROM photos WHERE photo_id = ?");
                                $delete_old_photo->bind_param("i", $old_photo_id);
                                $delete_old_photo->execute();
                                $delete_old_photo->close();
                                $old_photo_stmt->close();
                            }
                            
                            $success_message = "Profile picture updated successfully!";
                        } else {
                            $error_message = "Error updating profile picture in database.";
                        }
                        $update_user_photo->close();
                    } else {
                        $error_message = "Error saving photo information.";
                    }
                    $photo_insert->close();
                } else {
                    $error_message = "Error uploading file.";
                }
            }
        } else {
            $error_message = "Please select a valid image file.";
        }
    }
    
    if (isset($_POST['delete_profile_picture'])) {
        // Handle profile picture deletion
        $current_photo_query = "SELECT photo_id FROM users WHERE user_id = ?";
        $current_photo_stmt = $conn->prepare($current_photo_query);
        $current_photo_stmt->bind_param("i", $user_id);
        $current_photo_stmt->execute();
        $current_photo_result = $current_photo_stmt->get_result();
        $current_photo = $current_photo_result->fetch_assoc();
        $photo_id = $current_photo['photo_id'] ?? null;
        $current_photo_stmt->close();
        
        if ($photo_id) {
            // Get photo file path
            $photo_query = "SELECT url FROM photos WHERE photo_id = ?";
            $photo_stmt = $conn->prepare($photo_query);
            $photo_stmt->bind_param("i", $photo_id);
            $photo_stmt->execute();
            $photo_result = $photo_stmt->get_result();
            $photo_data = $photo_result->fetch_assoc();
            $photo_stmt->close();
            
            // Update user to remove photo_id
            $update_user = $conn->prepare("UPDATE users SET photo_id = NULL WHERE user_id = ?");
            $update_user->bind_param("i", $user_id);
            
            if ($update_user->execute()) {
                // Delete photo file if exists
                if ($photo_data && file_exists($photo_data['url'])) {
                    unlink($photo_data['url']);
                }
                
                // Delete photo record
                $delete_photo = $conn->prepare("DELETE FROM photos WHERE photo_id = ?");
                $delete_photo->bind_param("i", $photo_id);
                $delete_photo->execute();
                $delete_photo->close();
                
                $success_message = "Profile picture deleted successfully!";
            } else {
                $error_message = "Error deleting profile picture.";
            }
            $update_user->close();
        } else {
            $error_message = "No profile picture to delete.";
        }
    }
    
    if (isset($_POST['update_basic_info'])) {
        // Update basic information
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $house_no = trim($_POST['house_no']);
        $street = trim($_POST['street']);
        $city = trim($_POST['city']);
        
        if (!empty($first_name) && !empty($last_name)) {
            $update_query = "UPDATE users SET first_name = ?, last_name = ?, house_no = ?, street = ?, city = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sssssi", $first_name, $last_name, $house_no, $street, $city, $user_id);
            
            if ($update_stmt->execute()) {
                $success_message = "Basic information updated successfully!";
            } else {
                $error_message = "Error updating basic information.";
            }
            $update_stmt->close();
        } else {
            $error_message = "First name and last name are required.";
        }
    }
    
    if (isset($_POST['update_emails'])) {
        // Update emails
        $emails = array_filter(array_map('trim', $_POST['emails']), 'strlen');
        
        // Check if at least one email is provided
        if (empty($emails)) {
            $error_message = "At least one email address is required.";
        } else {
            // Delete existing emails
            $delete_emails = $conn->prepare("DELETE FROM user_emails WHERE user_id = ?");
            $delete_emails->bind_param("i", $user_id);
            $delete_emails->execute();
            $delete_emails->close();
            
            // Insert new emails
            $email_success = true;
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $insert_email = $conn->prepare("INSERT INTO user_emails (user_id, email) VALUES (?, ?)");
                    $insert_email->bind_param("is", $user_id, $email);
                    if (!$insert_email->execute()) {
                        $email_success = false;
                        if ($conn->errno == 1062) { // Duplicate entry error
                            $error_message = "Email '$email' is already in use by another user.";
                        } else {
                            $error_message = "Error saving email '$email'.";
                        }
                        break;
                    }
                    $insert_email->close();
                } else {
                    $email_success = false;
                    $error_message = "Invalid email format: '$email'";
                    break;
                }
            }
            if ($email_success) {
                $success_message = "Email addresses updated successfully!";
            }
        }
    }
    
    if (isset($_POST['update_phones'])) {
        // Update phones
        $phones = array_filter(array_map('trim', $_POST['phones']), 'strlen');
        
        // Delete existing phones
        $delete_phones = $conn->prepare("DELETE FROM user_phones WHERE user_id = ?");
        $delete_phones->bind_param("i", $user_id);
        $delete_phones->execute();
        $delete_phones->close();
        
        // Insert new phones
        if (!empty($phones)) {
            $phone_success = true;
            foreach ($phones as $phone) {
                // Basic phone validation (you can enhance this)
                if (preg_match('/^[\d\s\-\+\(\)\.]{10,20}$/', $phone)) {
                    $insert_phone = $conn->prepare("INSERT INTO user_phones (user_id, phone) VALUES (?, ?)");
                    $insert_phone->bind_param("is", $user_id, $phone);
                    if (!$insert_phone->execute()) {
                        $phone_success = false;
                        $error_message = "Error saving phone '$phone'.";
                        break;
                    }
                    $insert_phone->close();
                } else {
                    $phone_success = false;
                    $error_message = "Invalid phone format: '$phone'";
                    break;
                }
            }
            if ($phone_success) {
                $success_message = "Phone numbers updated successfully!";
            }
        } else {
            $success_message = "All phone numbers removed.";
        }
    }
}

// Get current user information
$user_query = "SELECT u.first_name, u.last_name, u.house_no, u.street, u.city, u.photo_id, p.url as profile_photo 
               FROM users u 
               LEFT JOIN photos p ON u.photo_id = p.photo_id 
               WHERE u.user_id = ?";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_info = $user_result->fetch_assoc();
$user_stmt->close();

// Get current emails
$emails_query = "SELECT email FROM user_emails WHERE user_id = ? ORDER BY email";
$emails_stmt = $conn->prepare($emails_query);
$emails_stmt->bind_param("i", $user_id);
$emails_stmt->execute();
$emails_result = $emails_stmt->get_result();
$current_emails = [];
while ($email_row = $emails_result->fetch_assoc()) {
    $current_emails[] = $email_row['email'];
}
$emails_stmt->close();

// Get current phones
$phones_query = "SELECT phone FROM user_phones WHERE user_id = ? ORDER BY phone";
$phones_stmt = $conn->prepare($phones_query);
$phones_stmt->bind_param("i", $user_id);
$phones_stmt->execute();
$phones_result = $phones_stmt->get_result();
$current_phones = [];
while ($phone_row = $phones_result->fetch_assoc()) {
    $current_phones[] = $phone_row['phone'];
}
$phones_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile Information - Lost and Found</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            margin: 2rem auto;
            max-width: 800px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px 20px 0 0;
            padding: 2rem;
            text-align: center;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }
        
        .section-title {
            color: #495057;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #667eea;
        }
        
        .dynamic-input-group {
            margin-bottom: 1rem;
        }
        
        .input-with-remove {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .input-with-remove input {
            margin-right: 0.5rem;
        }
        
        .btn-remove {
            min-width: 40px;
            height: 38px;
        }
        
        .btn-add {
            border: 2px dashed #667eea;
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
            font-weight: 500;
        }
        
        .btn-add:hover {
            background: rgba(102, 126, 234, 0.2);
            border-color: #5a6fd8;
            color: #5a6fd8;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 500;
        }
        
        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
            border-radius: 10px;
        }
        
        .form-control {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .profile-picture-container {
            position: relative;
            display: inline-block;
        }
        
        .profile-picture-preview {
            transition: transform 0.3s ease;
        }
        
        .profile-picture-preview:hover {
            transform: scale(1.05);
        }
        
        .profile-picture-placeholder {
            transition: all 0.3s ease;
        }
        
        .profile-picture-placeholder:hover {
            transform: scale(1.05);
            opacity: 0.9;
        }
        
        input[type="file"] {
            transition: border-color 0.3s ease;
        }
        
        input[type="file"]:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
    </style>
</head>
<body>
    <?php include('../includes/navigation.php'); renderNavigation(); ?>
    
    <div class="container">
        <div class="main-container">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="mb-2">
                    <i class="bi bi-person-gear me-3"></i>Edit Profile Information
                </h1>
                <p class="mb-0 opacity-90">Update your profile picture, personal details, email addresses, and phone numbers</p>
            </div>
            
            <div class="p-4">
                <!-- Success/Error Messages -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Picture Form -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-camera me-2"></i>Profile Picture
                    </h4>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row align-items-center">
                            <div class="col-md-4 text-center">
                                <div class="profile-picture-container mb-3">
                                    <?php if (!empty($user_info['profile_photo'])): ?>
                                        <img src="<?php echo htmlspecialchars($user_info['profile_photo']); ?>" 
                                             alt="Profile Picture" 
                                             class="profile-picture-preview rounded-circle"
                                             style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #667eea;">
                                    <?php else: ?>
                                        <div class="profile-picture-placeholder rounded-circle d-flex align-items-center justify-content-center"
                                             style="width: 150px; height: 150px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-size: 3rem; margin: 0 auto;">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="profile_picture" class="form-label fw-bold">
                                        <i class="bi bi-upload me-2"></i>Choose New Profile Picture
                                    </label>
                                    <input type="file" class="form-control" id="profile_picture" name="profile_picture" 
                                           accept="image/*" onchange="previewImage(this)">
                                    <div class="form-text">
                                        <i class="bi bi-info-circle me-1"></i>
                                        Supported formats: JPEG, PNG, GIF. Maximum size: 5MB.
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2">
                                    <button type="submit" name="update_profile_picture" class="btn btn-primary">
                                        <i class="bi bi-save me-2"></i>Update Picture
                                    </button>
                                    <?php if (!empty($user_info['profile_photo'])): ?>
                                        <button type="submit" name="delete_profile_picture" class="btn btn-outline-danger" 
                                                onclick="return confirm('Are you sure you want to delete your profile picture?')">
                                            <i class="bi bi-trash me-2"></i>Delete Picture
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Basic Information Form -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-person-circle me-2"></i>Basic Information
                    </h4>
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="first_name" class="form-label fw-bold">
                                    <i class="bi bi-person me-2"></i>First Name *
                                </label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?php echo htmlspecialchars($user_info['first_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="last_name" class="form-label fw-bold">
                                    <i class="bi bi-person me-2"></i>Last Name *
                                </label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?php echo htmlspecialchars($user_info['last_name']); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="house_no" class="form-label fw-bold">
                                    <i class="bi bi-house me-2"></i>House No.
                                </label>
                                <input type="text" class="form-control" id="house_no" name="house_no" 
                                       value="<?php echo htmlspecialchars($user_info['house_no'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="street" class="form-label fw-bold">
                                    <i class="bi bi-signpost me-2"></i>Street
                                </label>
                                <input type="text" class="form-control" id="street" name="street" 
                                       value="<?php echo htmlspecialchars($user_info['street'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="city" class="form-label fw-bold">
                                    <i class="bi bi-geo-alt me-2"></i>City
                                </label>
                                <input type="text" class="form-control" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($user_info['city'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="update_basic_info" class="btn btn-primary">
                                <i class="bi bi-save me-2"></i>Update Basic Info
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Email Addresses Form -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-envelope me-2"></i>Email Addresses *
                    </h4>
                    <p class="text-muted small mb-3">
                        <i class="bi bi-info-circle me-1"></i>At least one email address is required
                    </p>
                    <form method="POST" id="emailForm">
                        <div id="emailInputs">
                            <?php if (!empty($current_emails)): ?>
                                <?php foreach ($current_emails as $email): ?>
                                    <div class="input-with-remove">
                                        <input type="email" class="form-control" name="emails[]" 
                                               value="<?php echo htmlspecialchars($email); ?>" 
                                               placeholder="Enter email address">
                                        <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="input-with-remove">
                                    <input type="email" class="form-control" name="emails[]" 
                                           placeholder="Enter email address">
                                    <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-add w-100" onclick="addEmailInput()">
                                <i class="bi bi-plus-circle me-2"></i>Add Another Email
                            </button>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="update_emails" class="btn btn-primary">
                                <i class="bi bi-save me-2"></i>Update Emails
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Phone Numbers Form -->
                <div class="form-section">
                    <h4 class="section-title">
                        <i class="bi bi-telephone me-2"></i>Phone Numbers
                    </h4>
                    <form method="POST" id="phoneForm">
                        <div id="phoneInputs">
                            <?php if (!empty($current_phones)): ?>
                                <?php foreach ($current_phones as $phone): ?>
                                    <div class="input-with-remove">
                                        <input type="tel" class="form-control" name="phones[]" 
                                               value="<?php echo htmlspecialchars($phone); ?>" 
                                               placeholder="Enter phone number">
                                        <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="input-with-remove">
                                    <input type="tel" class="form-control" name="phones[]" 
                                           placeholder="Enter phone number">
                                    <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <button type="button" class="btn btn-add w-100" onclick="addPhoneInput()">
                                <i class="bi bi-plus-circle me-2"></i>Add Another Phone
                            </button>
                        </div>
                        <div class="text-end">
                            <button type="submit" name="update_phones" class="btn btn-primary">
                                <i class="bi bi-save me-2"></i>Update Phones
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Back to Dashboard -->
                <div class="text-center">
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
    <script>
        function addEmailInput() {
            const container = document.getElementById('emailInputs');
            const inputGroup = document.createElement('div');
            inputGroup.className = 'input-with-remove';
            inputGroup.innerHTML = `
                <input type="email" class="form-control" name="emails[]" placeholder="Enter email address">
                <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            container.appendChild(inputGroup);
        }
        
        function addPhoneInput() {
            const container = document.getElementById('phoneInputs');
            const inputGroup = document.createElement('div');
            inputGroup.className = 'input-with-remove';
            inputGroup.innerHTML = `
                <input type="tel" class="form-control" name="phones[]" placeholder="Enter phone number">
                <button type="button" class="btn btn-outline-danger btn-remove" onclick="removeInput(this)">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            container.appendChild(inputGroup);
        }
        
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                const file = input.files[0];
                
                // Validate file size (5MB)
                if (file.size > 5 * 1024 * 1024) {
                    alert('Image size must be less than 5MB.');
                    input.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) {
                    alert('Only JPEG, PNG, and GIF images are allowed.');
                    input.value = '';
                    return;
                }
                
                reader.onload = function(e) {
                    const container = document.querySelector('.profile-picture-container');
                    container.innerHTML = `
                        <img src="${e.target.result}" 
                             alt="Profile Picture Preview" 
                             class="profile-picture-preview rounded-circle"
                             style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #667eea;">
                    `;
                };
                reader.readAsDataURL(file);
            }
        }
        
        function removeInput(button) {
            const container = button.closest('#emailInputs, #phoneInputs');
            const inputGroups = container.querySelectorAll('.input-with-remove');
            const isEmailContainer = container.id === 'emailInputs';
            
            // For emails: always keep at least one input field (required)
            // For phones: allow removing all fields (optional)
            if (inputGroups.length > 1) {
                button.closest('.input-with-remove').remove();
            } else if (isEmailContainer) {
                // For email: clear the input but keep the field (at least one email required)
                const input = button.closest('.input-with-remove').querySelector('input');
                input.value = '';
                alert('At least one email address is required. The field has been cleared but not removed.');
            } else {
                // For phone: clear the input but keep the field
                const input = button.closest('.input-with-remove').querySelector('input');
                input.value = '';
            }
        }
        
        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Check if this is the email form
                    if (form.id === 'emailForm') {
                        const emailInputs = form.querySelectorAll('input[name="emails[]"]');
                        let hasValidEmail = false;
                        
                        emailInputs.forEach(input => {
                            if (input.value.trim()) {
                                hasValidEmail = true;
                            }
                        });
                        
                        if (!hasValidEmail) {
                            e.preventDefault();
                            alert('At least one email address is required.');
                            return;
                        }
                    }
                    
                    // General required field validation
                    const inputs = form.querySelectorAll('input[required]');
                    let valid = true;
                    
                    inputs.forEach(input => {
                        if (!input.value.trim()) {
                            input.classList.add('is-invalid');
                            valid = false;
                        } else {
                            input.classList.remove('is-invalid');
                        }
                    });
                    
                    if (!valid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
        });
    </script>
</body>
</html>
