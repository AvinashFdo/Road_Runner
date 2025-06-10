<?php
// Operator Dashboard
// Save this as: operator/dashboard.php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is operator
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get operator statistics
try {
    // Get actual bus count for this operator
    $stmt = $pdo->prepare("SELECT COUNT(*) as bus_count FROM buses WHERE operator_id = ?");
    $stmt->execute([$user_id]);
    $total_buses = $stmt->fetch()['bus_count'];
    
    // Get active bus count
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_buses FROM buses WHERE operator_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $active_buses = $stmt->fetch()['active_buses'];
    
    // Get active schedules count for this operator
    $stmt = $pdo->prepare("SELECT COUNT(*) as schedule_count FROM schedules s JOIN buses b ON s.bus_id = b.bus_id WHERE b.operator_id = ? AND s.status = 'active'");
    $stmt->execute([$user_id]);
    $active_routes = $stmt->fetch()['schedule_count'];
    
    // For now, we'll show sample statistics for bookings and revenue
    $today_bookings = 0; // Will be populated when we add bookings
    $total_revenue = 0; // Will be populated when we add payment data
    
    // Get operator info
    $stmt = $pdo->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $operator_info = $stmt->fetch();
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}

// Sample recent activities (will be replaced with real data later)
$recent_activities = [
    ['action' => 'Bus Route Added', 'details' => 'Colombo to Kandy', 'time' => '2 hours ago'],
    ['action' => 'Schedule Updated', 'details' => 'Evening departure times', 'time' => '5 hours ago'],
    ['action' => 'New Booking', 'details' => 'Seat A12 - Morning trip', 'time' => '1 day ago'],
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Dashboard - Road Runner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Operator</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Operator Navigation -->
    <div class="operator_nav">
        <div class="container">
            <div class="operator_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="buses.php">My Buses</a>
                <a href="schedules.php">Routes & Schedules</a>
                <a href="#" onclick="alert('Coming soon!')">Bookings</a>
                <a href="#" onclick="alert('Coming soon!')">Revenue Reports</a>
                <a href="#" onclick="alert('Coming soon!')">Profile Settings</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome to Operator Dashboard, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!
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
                <div class="stat_number"><?php echo $total_buses; ?></div>
                <div class="stat_label">Total Buses</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $active_buses; ?></div>
                <div class="stat_label">Active Buses</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $active_routes; ?></div>
                <div class="stat_label">Active Schedules</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($total_revenue); ?></div>
                <div class="stat_label">Total Revenue</div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
            
            <!-- Operator Information -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Operator Information</h3>
                <?php if ($operator_info): ?>
                    <table class="table">
                        <tr>
                            <td><strong>Full Name:</strong></td>
                            <td><?php echo htmlspecialchars($operator_info['full_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?php echo htmlspecialchars($operator_info['email']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td><?php echo htmlspecialchars($operator_info['phone']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Member Since:</strong></td>
                            <td><?php echo date('M j, Y', strtotime($operator_info['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Account Status:</strong></td>
                            <td><span class="badge badge_active">Active</span></td>
                        </tr>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Recent Activities -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Recent Activities</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_activities as $activity): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($activity['action']); ?></td>
                                <td><?php echo htmlspecialchars($activity['details']); ?></td>
                                <td><?php echo htmlspecialchars($activity['time']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid">
            <div class="feature_card">
                <h4>🚌 Manage Buses</h4>
                <p>Add new buses to your fleet, update bus information, and manage seating configurations.</p>
                <button class="btn btn_primary" onclick="window.location.href='buses.php'">Manage Buses</button>
            </div>
            
            <div class="feature_card">
                <h4>🗺️ Routes & Schedules</h4>
                <p>Create and manage your bus routes, set departure times, and update schedules.</p>
                <button class="btn btn_primary" onclick="window.location.href='schedules.php'">Manage Routes</button>
            </div>
            
            <div class="feature_card">
                <h4>📊 View Bookings</h4>
                <p>Monitor current bookings, check passenger details, and manage seat assignments.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">View Bookings</button>
            </div>
            
            <div class="feature_card">
                <h4>💰 Revenue Reports</h4>
                <p>Track your earnings, view payment history, and generate financial reports.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">View Reports</button>
            </div>
            
            <div class="feature_card">
                <h4>⚙️ Settings</h4>
                <p>Update your profile information, manage payment details, and configure preferences.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">Settings</button>
            </div>
            
            <div class="feature_card">
                <h4>📞 Support</h4>
                <p>Get help with your account, report issues, or contact our support team.</p>
                <button class="btn btn_success" onclick="alert('Support: Call +94 11 123 4567')">Get Support</button>
            </div>
        </div>

        <!-- Getting Started Guide -->
        <div class="alert alert_info mt_2">
            <h4>Getting Started as a Bus Operator</h4>
            <p><strong>Welcome to Road Runner!</strong> Here's how to get started:</p>
            <ol style="margin: 1rem 0; padding-left: 2rem;">
                <li><strong>Add Your Buses:</strong> Register your buses with seating configuration</li>
                <li><strong>Create Routes:</strong> Set up your travel routes with pickup/drop points</li>
                <li><strong>Set Schedules:</strong> Define departure times and pricing</li>
                <li><strong>Go Live:</strong> Start accepting bookings from passengers</li>
            </ol>
            <p><em>Need help? Contact our support team for assistance with setting up your account.</em></p>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Operator Panel. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>