<?php
// Start session
session_start();

// Clear all session data
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to index.php
header("Location: index.php");
exit();
