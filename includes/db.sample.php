<?php
// Database configuration for Lost & Found application
// Copy this file to db.php and update with your database credentials

$host = "localhost";        // Database host (usually localhost)
$user = "root";            // Database username (default: root for XAMPP)
$pass = "";                // Database password (default: empty for XAMPP)
$dbname = "lost_and_found"; // Database name

// Create database connection
$conn = new mysqli($host, $user, $pass, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8 for proper character handling
$conn->set_charset("utf8");
?>
