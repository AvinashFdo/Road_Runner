<?php
// Step 2: Database Connection File
// Save this as: db_connection.php

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';  // Default XAMPP MySQL password is empty
$database = 'roadrunner_db';

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
    
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Optional: Uncomment the line below to test connection (remove after testing)
    // echo "Database connection successful!";
    
} catch(PDOException $e) {
    // If connection fails, show error and stop execution
    die("Connection failed: " . $e->getMessage());
}

// Function to test database connection
function testConnection() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM users");
        $result = $stmt->fetch();
        return "Database connected successfully! Found " . $result['user_count'] . " users in database.";
    } catch(PDOException $e) {
        return "Database connection test failed: " . $e->getMessage();
    }
}
?>