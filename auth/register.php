
<?php
include("../includes/db.php");
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $first_name = trim($_POST["first_name"]);
    $last_name = trim($_POST["last_name"]);
    $email = trim($_POST["email"]);
    $password = password_hash($_POST["password"], PASSWORD_DEFAULT);
    $house_no = trim($_POST["house_no"]);
    $street = trim($_POST["street"]);
    $city = trim($_POST["city"]);
    $photo_id = null;
    $error = null;

    // Check if email already exists
    $email_check_stmt = $conn->prepare("SELECT user_id FROM user_emails WHERE email = ?");
    $email_check_stmt->bind_param("s", $email);
    $email_check_stmt->execute();
    $email_check_result = $email_check_stmt->get_result();

    if ($email_check_result->num_rows > 0) {
        $error = "An account with this email address already exists. Please use a different email or try logging in.";
    } else {
        // 1️⃣ Handle photo upload and insert into `photos` table
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photo_name = basename($_FILES["photo"]["name"]);
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $unique_name = time() . "_" . preg_replace("/[^A-Za-z0-9_\.-]/", "_", $photo_name);
            $target_file = $target_dir . $unique_name;

            if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
                $stmt_photo = $conn->prepare("INSERT INTO photos (url) VALUES (?)");
                $stmt_photo->bind_param("s", $target_file);
                $stmt_photo->execute();
                $photo_id = $stmt_photo->insert_id;
            }
        }

        // 2️⃣ Insert into `users`
        $stmt_user = $conn->prepare("
            INSERT INTO users (first_name, last_name, password, photo_id, house_no, street, city, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt_user->bind_param("sssisss", $first_name, $last_name, $password, $photo_id, $house_no, $street, $city);
        
        if ($stmt_user->execute()) {
            $user_id = $stmt_user->insert_id;

            // 3️⃣ Insert email into `user_emails`
            try {
                $stmt_email = $conn->prepare("INSERT INTO user_emails (user_id, email) VALUES (?, ?)");
                $stmt_email->bind_param("is", $user_id, $email);
                $stmt_email->execute();

                // (Optional) Insert phone number if included in form
                if (!empty($_POST["phone"])) {
                    $phone = trim($_POST["phone"]);
                    $stmt_phone = $conn->prepare("INSERT INTO user_phones (user_id, phone) VALUES (?, ?)");
                    $stmt_phone->bind_param("is", $user_id, $phone);
                    $stmt_phone->execute();
                }

                // Set session variables and redirect
                $_SESSION['user_id'] = $user_id;
                $_SESSION['name'] = $first_name . " " . $last_name;
                $_SESSION['is_admin'] = 0;
                
                header("Location: /lost-and-found/user/dashboard.php");
                exit();
            } catch (mysqli_sql_exception $e) {
                // Handle duplicate email error
                if ($e->getCode() === 1062) { // Duplicate entry error code
                    $error = "An account with this email address already exists. Please use a different email.";
                    // Delete the user that was just created since email insertion failed
                    $conn->prepare("DELETE FROM users WHERE user_id = ?")->execute([$user_id]);
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/spacing.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <title>Register - Lost and Found</title>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        .register-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
            max-width: 1000px;
            margin: 0 auto;
        }
        .register-header {
            background: linear-gradient(45deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .form-floating {
            margin-bottom: 1rem;
        }
        .btn-primary {
            background: linear-gradient(45deg, #667eea, #764ba2);
            border: none;
            padding: 0.75rem;
        }
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .file-upload-area:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .file-upload-area.has-file {
            border-color: #198754;
            background-color: #d1e7dd;
        }
        
        /* Wide file upload area for horizontal layout */
        .file-upload-area-wide {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 1rem 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: #f8f9fa;
        }
        .file-upload-area-wide:hover {
            border-color: #0d6efd;
            background-color: #e3f2fd;
        }
        .file-upload-area-wide.has-file {
            border-color: #198754;
            background-color: #d1e7dd;
        }
        
        /* Responsive adjustments */
        @media (max-width: 991.98px) {
            .register-card {
                margin: 0 1rem;
            }
            .register-header {
                padding: 1.5rem;
            }
            body {
                padding: 1rem 0;
            }
        }
        
        @media (max-width: 575.98px) {
            .register-header h2 {
                font-size: 1.5rem;
            }
            .file-upload-area-wide {
                min-height: 70px;
                padding: 0.75rem 1rem;
            }
            .file-upload-area-wide .d-flex {
                flex-direction: column;
                text-align: center;
            }
            .file-upload-area-wide .fs-2 {
                font-size: 1.5rem !important;
                margin-bottom: 0.5rem !important;
                margin-right: 0 !important;
            }
        }
        
        /* Section spacing improvements */
        .section-divider {
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 992px) {
            .form-section {
                height: fit-content;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-xl-10">
                <div class="register-card">
                    <div class="register-header">
                        <h2 class="mb-2">
                            <i class="bi bi-person-plus me-2"></i>Create Account
                        </h2>
                        <p class="mb-0 opacity-75">Join our campus lost and found community</p>
                    </div>
                    
                    <div class="p-4">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill icon-spacing"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form action="register.php" method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
                            <div class="row g-4">
                                <!-- Personal Information Section - 2 Columns -->
                                <div class="col-12">
                                    <div class="form-section">
                                        <h5 class="text-muted mb-3 section-divider">
                                            <i class="bi bi-person icon-spacing"></i>Personal Information
                                        </h5>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="first_name" name="first_name" 
                                                           placeholder="First Name" required>
                                                    <label for="first_name">First Name</label>
                                                    <div class="invalid-feedback">Please provide your first name.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="last_name" name="last_name" 
                                                           placeholder="Last Name" required>
                                                    <label for="last_name">Last Name</label>
                                                    <div class="invalid-feedback">Please provide your last name.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="email" class="form-control" id="email" name="email" 
                                                           placeholder="name@example.com" required>
                                                    <label for="email">
                                                        <i class="bi bi-envelope icon-spacing"></i>Email Address
                                                    </label>
                                                    <div class="invalid-feedback">Please provide a valid email address.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="password" class="form-control" id="password" name="password" 
                                                           placeholder="Password" required minlength="6">
                                                    <label for="password">
                                                        <i class="bi bi-lock icon-spacing"></i>Password
                                                    </label>
                                                    <div class="invalid-feedback">Password must be at least 6 characters long.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="tel" class="form-control" id="phone" name="phone" 
                                                           placeholder="Phone Number">
                                                    <label for="phone">
                                                        <i class="bi bi-telephone icon-spacing"></i>Phone Number (Optional)
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Photo Upload Section - Wide, Low Height -->
                                <div class="col-12">
                                    <div class="form-section">
                                        <h5 class="text-muted mb-3 section-divider">
                                            <i class="bi bi-camera icon-spacing"></i>Profile Photo (Optional)
                                        </h5>
                                        <div class="file-upload-area-wide" onclick="document.getElementById('photo').click()">
                                            <div class="d-flex align-items-center justify-content-center">
                                                <i class="bi bi-cloud-upload fs-2 text-muted me-3"></i>
                                                <div class="text-center">
                                                    <div class="fw-bold">Click to upload your photo</div>
                                                    <div class="text-muted small">JPG, PNG, GIF up to 5MB</div>
                                                </div>
                                            </div>
                                            <input type="file" id="photo" name="photo" class="d-none" accept="image/*" onchange="handleFileSelect(this)">
                                            <div id="file-name" class="mt-2 text-success fw-bold d-none text-center"></div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Address Information Section - 2 Columns -->
                                <div class="col-12">
                                    <div class="form-section">
                                        <h5 class="text-muted mb-3 section-divider">
                                            <i class="bi bi-house icon-spacing"></i>Address Information
                                        </h5>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="house_no" name="house_no" 
                                                           placeholder="House No" required>
                                                    <label for="house_no">House No</label>
                                                    <div class="invalid-feedback">Please provide house number.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="street" name="street" 
                                                           placeholder="Street" required>
                                                    <label for="street">Street</label>
                                                    <div class="invalid-feedback">Please provide street name.</div>
                                                </div>
                                            </div>
                                            
                                            <div class="col-md-6">
                                                <div class="form-floating">
                                                    <input type="text" class="form-control" id="city" name="city" 
                                                           placeholder="City" required>
                                                    <label for="city">City</label>
                                                    <div class="invalid-feedback">Please provide city name.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Submit Buttons - Full Width -->
                                <div class="col-12">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="bi bi-person-check me-2"></i>Create Account
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        
                        <div class="text-center mt-4">
                            <p class="text-muted mb-3">Already have an account?</p>
                            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                                <a href="login.php" class="btn btn-outline-primary">
                                    <i class="bi bi-box-arrow-in-right icon-spacing"></i>Sign In
                                </a>
                                <a href="../index.php" class="btn btn-outline-secondary ml-2">
                                    <i class="bi bi-arrow-left icon-spacing"></i>Back to Home
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
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

        // File upload handling
        function handleFileSelect(input) {
            const fileArea = document.querySelector('.file-upload-area-wide');
            const fileName = document.getElementById('file-name');
            
            if (input.files && input.files[0]) {
                fileArea.classList.add('has-file');
                fileName.textContent = input.files[0].name;
                fileName.classList.remove('d-none');
            } else {
                fileArea.classList.remove('has-file');
                fileName.classList.add('d-none');
            }
        }
    </script>
</body>
</html>
