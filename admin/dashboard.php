<?php
// Admin Dashboard
// Save this as: admin/dashboard.php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get some basic statistics
try {
    // Count total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $total_users = $stmt->fetch()['total_users'];
    
    // Count users by type
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $user_stats = [];
    while ($row = $stmt->fetch()) {
        $user_stats[$row['user_type']] = $row['count'];
    }
    
    // Get recent users (last 5)
    $stmt = $pdo->query("SELECT full_name, email, user_type, created_at FROM users ORDER BY created_at DESC LIMIT 5");
    $recent_users = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Road Runner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Admin</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Admin Navigation -->
    <div class="admin_nav">
        <div class="container">
            <div class="admin_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="#" onclick="alert('Coming soon!')">Manage Users</a>
                <a href="#" onclick="alert('Coming soon!')">Manage Buses</a>
                <a href="routes.php">Manage Routes</a>
                <a href="#" onclick="alert('Coming soon!')">View Bookings</a>
                <a href="#" onclick="alert('Coming soon!')">Reports</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome to Admin Dashboard, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!
        </div>

        <!-- Display Errors -->
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="dashboard_grid">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_users ?? 0; ?></div>
                <div class="stat_label">Total Users</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $user_stats['admin'] ?? 0; ?></div>
                <div class="stat_label">Administrators</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $user_stats['operator'] ?? 0; ?></div>
                <div class="stat_label">Bus Operators</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $user_stats['passenger'] ?? 0; ?></div>
                <div class="stat_label">Passengers</div>
            </div>
        </div>

        <!-- Recent Users Table -->
        <div class="mb_2">
            <h3>Recent Users</h3>
            <div class="table_container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>User Type</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_users)): ?>
                            <?php foreach ($recent_users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="badge badge_<?php echo $user['user_type']; ?>">
                                            <?php echo ucfirst($user['user_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text_center" style="color: #666;">No users found</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid">
            <div class="feature_card">
                <h4>User Management</h4>
                <p>Manage user accounts, permissions, and view user activity.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">Manage Users</button>
            </div>
            
            <div class="feature_card">
                <h4>System Settings</h4>
                <p>Configure system settings, payment options, and general preferences.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">Settings</button>
            </div>
            
            <div class="feature_card">
                <h4>Reports & Analytics</h4>
                <p>View booking reports, revenue analytics, and system performance.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">View Reports</button>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Admin Panel. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>