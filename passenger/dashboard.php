<?php
// Passenger Dashboard - FIXED VERSION
// Save this as: passenger/dashboard.php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is passenger
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'passenger') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get passenger statistics and info
try {
    // Get passenger info
    $stmt = $pdo->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $passenger_info = $stmt->fetch();
    
    // Get actual booking statistics
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_bookings FROM bookings WHERE passenger_id = ?");
    $stmt->execute([$user_id]);
    $total_bookings = $stmt->fetch()['total_bookings'] ?? 0;
    
    // Get upcoming trips
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_trips 
        FROM bookings b 
        JOIN schedules s ON b.schedule_id = s.schedule_id 
        WHERE b.passenger_id = ? 
        AND b.booking_status IN ('pending', 'confirmed') 
        AND CONCAT(b.travel_date, ' ', s.departure_time) > NOW()
    ");
    $stmt->execute([$user_id]);
    $upcoming_trips = $stmt->fetch()['upcoming_trips'] ?? 0;
    
    // Get completed trips
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as completed_trips 
        FROM bookings b 
        JOIN schedules s ON b.schedule_id = s.schedule_id 
        WHERE b.passenger_id = ? 
        AND b.booking_status IN ('confirmed', 'completed') 
        AND CONCAT(b.travel_date, ' ', s.departure_time) <= NOW()
    ");
    $stmt->execute([$user_id]);
    $completed_trips = $stmt->fetch()['completed_trips'] ?? 0;
    
    // Get total spent
    $stmt = $pdo->prepare("
        SELECT SUM(total_amount) as total_spent 
        FROM bookings 
        WHERE passenger_id = ? 
        AND booking_status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$user_id]);
    $total_spent = $stmt->fetch()['total_spent'] ?? 0;
    
    // Get recent bookings (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_reference, b.passenger_name, b.travel_date, b.booking_status, b.total_amount,
            s.departure_time, s.arrival_time,
            r.route_name, r.origin, r.destination,
            seat.seat_number,
            bus.bus_name, bus.bus_number
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        WHERE b.passenger_id = ?
        ORDER BY b.booking_date DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_bookings = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    $total_bookings = 0;
    $upcoming_trips = 0;
    $completed_trips = 0;
    $total_spent = 0;
    $recent_bookings = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - Road Runner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="dashboard.php">My Dashboard</a></li>
                    <li><a href="../search_buses.php">Search Buses</a></li>
                    <li><a href="../my_bookings.php">My Bookings</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! Ready for your next journey?
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
                <div class="stat_number"><?php echo $total_bookings; ?></div>
                <div class="stat_label">Total Bookings</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $upcoming_trips; ?></div>
                <div class="stat_label">Upcoming Trips</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $completed_trips; ?></div>
                <div class="stat_label">Completed Trips</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($total_spent); ?></div>
                <div class="stat_label">Total Spent</div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
            
            <!-- Recent Bookings -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Recent Bookings</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Route</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_bookings)): ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong><br>
                                        <small>Seat: <?php echo htmlspecialchars($booking['seat_number']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($booking['origin']); ?> → <?php echo htmlspecialchars($booking['destination']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($booking['bus_name']); ?> (<?php echo htmlspecialchars($booking['bus_number']); ?>)</small>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($booking['travel_date'])); ?><br>
                                        <small><?php echo date('g:i A', strtotime($booking['departure_time'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge_<?php echo $booking['booking_status'] === 'confirmed' ? 'active' : ($booking['booking_status'] === 'pending' ? 'operator' : 'inactive'); ?>">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text_center" style="color: #666;">
                                    No bookings found. <a href="../search_buses.php">Book your first trip!</a>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($recent_bookings)): ?>
                    <div class="p_1">
                        <a href="../my_bookings.php" class="btn btn_primary">View All Bookings</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Profile Information -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Profile Information</h3>
                <?php if ($passenger_info): ?>
                    <table class="table">
                        <tr>
                            <td><strong>Full Name:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['full_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['email']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['phone']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Member Since:</strong></td>
                            <td><?php echo date('M j, Y', strtotime($passenger_info['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Account Status:</strong></td>
                            <td><span class="badge badge_active">Active</span></td>
                        </tr>
                    </table>
                    <div class="p_1">
                        <button class="btn btn_primary" onclick="alert('Profile editing feature coming soon!')">Edit Profile</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid">
            <div class="feature_card">
                <h4>🔍 Search Buses</h4>
                <p>Find and book bus tickets for your next journey. Choose from various routes and operators.</p>
                <button class="btn btn_primary" onclick="window.location.href='../search_buses.php'">Search Buses</button>
            </div>
            
            <div class="feature_card">
                <h4>🎫 My Bookings</h4>
                <p>View, manage, and track all your current and past bookings. Download tickets and get updates.</p>
                <button class="btn btn_primary" onclick="window.location.href='../my_bookings.php'">View Bookings</button>
            </div>
            
            <div class="feature_card">
                <h4>📦 Send Parcel</h4>
                <p>Send parcels through our integrated delivery service along with passenger routes.</p>
                <button class="btn btn_success" onclick="alert('Parcel service feature coming soon!')">Send Parcel</button>
            </div>
            
            <div class="feature_card">
                <h4>⭐ Reviews</h4>
                <p>Rate and review your travel experiences. Help other passengers make informed choices.</p>
                <button class="btn btn_primary" onclick="alert('Review system feature coming soon!')">My Reviews</button>
            </div>
            
            <div class="feature_card">
                <h4>💳 Payment History</h4>
                <p>View your payment history, download receipts, and manage payment methods.</p>
                <button class="btn btn_primary" onclick="alert('Payment history feature coming soon!')">Payment History</button>
            </div>
            
            <div class="feature_card">
                <h4>🆘 Support</h4>
                <p>Need help? Contact our customer support team for assistance with bookings or account issues.</p>
                <button class="btn btn_success" onclick="alert('Support: Call +94 11 123 4567 or Email: support@roadrunner.lk')">Get Help</button>
            </div>
        </div>

        <!-- Travel Tips -->
        <div class="alert alert_info mt_2">
            <h4>🌟 Travel Tips for Road Runner</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>🎯 Smart Seat Selection:</strong><br>
                    Use our gender-based seat visualization to choose seats that ensure your comfort and privacy.
                </div>
                <div>
                    <strong>📱 Digital Tickets:</strong><br>
                    Download your tickets to your phone. No need to print - just show your digital ticket when boarding.
                </div>
                <div>
                    <strong>⏰ Arrive Early:</strong><br>
                    Arrive at the departure point at least 15 minutes before scheduled departure time.
                </div>
                <div>
                    <strong>📞 Stay Updated:</strong><br>
                    Check for any schedule changes or updates via SMS or email notifications.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Safe travels!</p>
        </div>
    </footer>
</body>
</html>