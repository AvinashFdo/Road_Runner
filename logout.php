<?php
// Step 5a: Logout System
// Save this as: logout.php

session_start();

// Destroy all session data
session_unset();
session_destroy();

// Redirect to homepage with logout message
header('Location: index.php?logout=success');
exit();
?>