<?php
session_start();
session_destroy();
header("Location: /lost-and-found/auth/login.php");
exit();
?>