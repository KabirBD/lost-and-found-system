
<?php
include("../includes/db.php");
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.password, 
               CASE WHEN a.admin_id IS NOT NULL THEN 1 ELSE 0 END AS is_admin,
               a.admin_id
        FROM users u
        INNER JOIN user_emails ue ON u.user_id = ue.user_id
        LEFT JOIN admins a ON u.user_id = a.user_id
        WHERE ue.email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            // Set session
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['name'] = $row['first_name'] . " " . $row['last_name'];
            $_SESSION['is_admin'] = $row['is_admin'];
            if ($row['is_admin']) {
                $_SESSION['admin_id'] = $row['admin_id'];
            }

            // Redirect based on role
            if ($_SESSION['is_admin']) {
                header("Location: /lost-and-found/admin/dashboard.php");
            } else {
                header("Location: /lost-and-found/user/dashboard.php");
            }
            exit();
        } else {
            $error = "Incorrect password.";
        }
    } else {
        $error = "No user found with that email.";
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
    <title>Login - Lost and Found</title>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .login-header {
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

        .login-links {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        /* Additional spacing improvements */
        .alert {
            margin-bottom: 1.5rem;
        }
        
        .btn {
            margin-bottom: 0.5rem;
        }
        
        .text-decoration-none:hover {
            text-decoration: underline !important;
        }
        
        /* Ensure consistent icon spacing */
        .login-header .bi {
            margin-right: 0.5rem;
        }
        
        .form-floating label .bi {
            margin-right: 0.5rem;
        }
        
        .btn .bi {
            margin-right: 0.5rem;
        }
        
        .alert .bi {
            margin-right: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="login-card">
                    <div class="login-header">
                        <h2 class="mb-2">
                            <i class="bi bi-search icon-spacing"></i>Lost & Found
                        </h2>
                        <p class="mb-0 opacity-75">Welcome back! Please sign in to your account.</p>
                    </div>

                    <div class="p-4">
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill icon-spacing"></i>
                                <?= htmlspecialchars($error) ?>
                            </div>
                        <?php endif; ?>

                        <form action="login.php" method="post">
                            <div class="form-floating">
                                <input type="email" class="form-control" id="email" name="email"
                                    placeholder="name@example.com" required>
                                <label for="email">
                                    <i class="bi bi-envelope icon-spacing"></i>Email address
                                </label>
                            </div>

                            <div class="form-floating">
                                <input type="password" class="form-control" id="password" name="password"
                                    placeholder="Password" required>
                                <label for="password">
                                    <i class="bi bi-lock icon-spacing"></i>Password
                                </label>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right icon-spacing"></i>Sign In
                                </button>
                            </div>
                        </form>

                        <div class="login-links">
                            <p class="text-muted mb-2">Don't have an account?</p>
                            <a href="register.php" class="btn btn-outline-primary btn-spacing">
                                <i class="bi bi-person-plus icon-spacing"></i>Create Account
                            </a>
                        </div>

                        <div class="text-center mt-3">
                            <a href="../index.php" class="text-muted text-decoration-none">
                                <i class="bi bi-arrow-left icon-spacing"></i>Back to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/script/jquery.js"></script>
    <script src="../assets/script/bootstrap.min.js"></script>
</body>

</html>