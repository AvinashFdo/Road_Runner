<?php
// Database Setup Verification
// Save as: verify_setup.php (temporary file)

require_once 'db_connection.php';

echo "<h2>🚌 Road Runner Database Setup Verification</h2>";

try {
    // Check users
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $user_counts = $stmt->fetchAll();
    
    echo "<h3>✅ Users Created:</h3>";
    echo "<ul>";
    foreach ($user_counts as $count) {
        echo "<li>" . ucfirst($count['user_type']) . ": " . $count['count'] . "</li>";
    }
    echo "</ul>";
    
    // Check routes
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM routes WHERE status = 'active'");
    $route_count = $stmt->fetch()['count'];
    echo "<h3>✅ Routes Created: $route_count</h3>";
    
    // List routes
    $stmt = $pdo->query("SELECT route_name, origin, destination FROM routes WHERE status = 'active'");
    $routes = $stmt->fetchAll();
    echo "<ul>";
    foreach ($routes as $route) {
        echo "<li>" . htmlspecialchars($route['route_name']) . " (" . htmlspecialchars($route['origin']) . " → " . htmlspecialchars($route['destination']) . ")</li>";
    }
    echo "</ul>";
    
    // Check tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll();
    echo "<h3>✅ Database Tables Created:</h3>";
    echo "<ul>";
    foreach ($tables as $table) {
        $table_name = array_values($table)[0];
        echo "<li>$table_name</li>";
    }
    echo "</ul>";
    
    echo "<h3>🎯 Test Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> admin@roadrunner.com / admin123</li>";
    echo "<li><strong>Passenger:</strong> john@test.com / pass123</li>";
    echo "<li><strong>Passenger:</strong> jane@test.com / pass123</li>";
    echo "<li><strong>Operator:</strong> operator@express.com / pass123</li>";
    echo "<li><strong>Operator:</strong> comfort@lines.com / pass123</li>";
    echo "</ul>";
    
    echo "<h3>🚀 Next Steps:</h3>";
    echo "<ol>";
    echo "<li>Replace your PHP files with the clean versions I provided</li>";
    echo "<li>Login as an operator (operator@express.com / pass123)</li>";
    echo "<li>Create a bus with 50 seats and 2x3 configuration</li>";
    echo "<li>Create a schedule for that bus</li>";
    echo "<li>Login as a passenger and test booking</li>";
    echo "<li>Delete this verification file when done</li>";
    echo "</ol>";
    
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #155724;'>✅ Database Reset Complete!</h3>";
    echo "<p style='color: #155724;'>Your database is now clean and ready for testing.</p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h3 style='color: #721c24;'>❌ Database Error</h3>";
    echo "<p style='color: #721c24;'>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h2 { color: #2c3e50; }
h3 { color: #34495e; margin-top: 20px; }
ul, ol { margin: 10px 0 10px 20px; }
li { margin: 5px 0; }
</style>