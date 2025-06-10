<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$error = '';

// Handle refund processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_refund'])) {
    $booking_id = $_POST['booking_id'] ?? '';
    
    try {
        // Update booking status to refunded
        $stmt = $pdo->prepare("UPDATE bookings SET booking_status = 'refunded' WHERE booking_id = ? AND booking_status = 'cancelled'");
        $stmt->execute([$booking_id]);
        
        if ($stmt->rowCount() > 0) {
            $message = "Refund processed successfully. Seat is now available for booking.";
        } else {
            $error = "Booking not found or already processed.";
        }
    } catch (PDOException $e) {
        $error = "Error processing refund: " . $e->getMessage();
    }
}

// Get all cancelled bookings awaiting refund
try {
    $stmt = $pdo->query("
        SELECT 
            b.booking_id, b.booking_reference, b.passenger_name, b.total_amount, b.booking_date,
            b.travel_date, s.departure_time,
            r.route_name, r.origin, r.destination,
            bus.bus_name, bus.bus_number,
            seat.seat_number,
            u.full_name as passenger_full_name, u.email as passenger_email
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN users u ON b.passenger_id = u.user_id
        WHERE b.booking_status = 'cancelled'
        ORDER BY b.booking_date DESC
    ");
    $pending_refunds = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error loading refund data: " . $e->getMessage();
    $pending_refunds = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Management - Road Runner Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Admin</div>
                <ul class="nav_links">
                    <li><a href="dashboard.php">Dashboard</a></li>
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
                <a href="routes.php">Manage Routes</a>
                <a href="refunds.php">Process Refunds</a>
                <a href="#" onclick="alert('Coming soon!')">Manage Users</a>
                <a href="#" onclick="alert('Coming soon!')">View All Bookings</a>
                <a href="#" onclick="alert('Coming soon!')">Reports</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">Refund Management</h2>

        <!-- Display Messages -->
        <?php if ($message): ?>
            <div class="alert alert_success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo count($pending_refunds); ?></div>
                <div class="stat_label">Pending Refunds</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format(array_sum(array_column($pending_refunds, 'total_amount'))); ?></div>
                <div class="stat_label">Total Refund Amount</div>
            </div>
        </div>

        <!-- Pending Refunds -->
        <div class="table_container">
            <h3 class="p_1 mb_1">Pending Refunds (<?php echo count($pending_refunds); ?>)</h3>
            
            <?php if (empty($pending_refunds)): ?>
                <div class="p_2 text_center">
                    <p>No pending refunds at this time.</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking Details</th>
                            <th>Passenger</th>
                            <th>Trip Details</th>
                            <th>Amount</th>
                            <th>Cancelled Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_refunds as $refund): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($refund['booking_reference']); ?></strong><br>
                                    <small>Seat: <?php echo htmlspecialchars($refund['seat_number']); ?></small><br>
                                    <small><?php echo htmlspecialchars($refund['passenger_name']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($refund['passenger_full_name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($refund['passenger_email']); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($refund['route_name']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($refund['origin']); ?> → <?php echo htmlspecialchars($refund['destination']); ?></small><br>
                                    <small><?php echo date('M j, Y g:i A', strtotime($refund['travel_date'] . ' ' . $refund['departure_time'])); ?></small><br>
                                    <small>Bus: <?php echo htmlspecialchars($refund['bus_name']); ?></small>
                                </td>
                                <td>
                                    <strong style="color: #e74c3c;">LKR <?php echo number_format($refund['total_amount']); ?></strong>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($refund['booking_date'])); ?><br>
                                    <small><?php echo date('g:i A', strtotime($refund['booking_date'])); ?></small>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Process refund of LKR <?php echo number_format($refund['total_amount']); ?>? This will make the seat available for booking.');">
                                        <input type="hidden" name="booking_id" value="<?php echo $refund['booking_id']; ?>">
                                        <button type="submit" name="process_refund" class="btn btn_success" style="font-size: 0.8rem; padding: 0.5rem 1rem;">
                                            Process Refund
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Info Panel -->
        <div class="alert alert_info mt_2">
            <h4>💡 Refund Processing Guidelines</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>Processing Steps:</strong><br>
                    1. Verify cancellation is legitimate<br>
                    2. Process refund through payment system<br>
                    3. Click "Process Refund" to release seat
                </div>
                <div>
                    <strong>Seat Availability:</strong><br>
                    • Cancelled seats stay unavailable during refund processing<br>
                    • Seats become available after refund completion<br>
                    • This prevents booking conflicts
                </div>
                <div>
                    <strong>Business Benefits:</strong><br>
                    • Professional refund handling<br>
                    • Clear audit trail<br>
                    • Prevents immediate re-booking issues
                </div>
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