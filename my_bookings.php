<?php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_refs = $_POST['booking_refs'] ?? '';
    $booking_references = explode(',', $booking_refs);
    
    if (!empty($booking_references)) {
        try {
            $pdo->beginTransaction();
            
            $cancelled_count = 0;
            $errors = [];
            
            foreach ($booking_references as $booking_ref) {
                $booking_ref = trim($booking_ref);
                
                // Check if booking can be cancelled
                $stmt = $pdo->prepare("
                    SELECT b.*, s.departure_time 
                    FROM bookings b 
                    JOIN schedules s ON b.schedule_id = s.schedule_id 
                    WHERE b.booking_reference = ? AND b.passenger_id = ? AND b.booking_status IN ('pending', 'confirmed')
                ");
                $stmt->execute([$booking_ref, $user_id]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $errors[] = "Booking $booking_ref not found or cannot be cancelled.";
                    continue;
                }
                
                // Check timing (2 hours before departure)
                $departure_datetime = $booking['travel_date'] . ' ' . $booking['departure_time'];
                $hours_until = (strtotime($departure_datetime) - time()) / 3600;
                
                if ($hours_until < 2) {
                    $errors[] = "Cannot cancel $booking_ref - less than 2 hours to departure.";
                    continue;
                }
                
                // Cancel the booking
                $stmt = $pdo->prepare("UPDATE bookings SET booking_status = 'cancelled' WHERE booking_reference = ?");
                $stmt->execute([$booking_ref]);
                $cancelled_count++;
            }
            
            $pdo->commit();
            
            if ($cancelled_count > 0) {
                $message = "$cancelled_count booking(s) cancelled successfully. Refund will be processed within 3-5 business days.";
            }
            
            if (!empty($errors)) {
                $error = implode(' ', $errors);
            }
            
        } catch (PDOException $e) {
            $pdo->rollback();
            $error = "Error cancelling bookings: " . $e->getMessage();
        }
    }
}

// Get all bookings and group them
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_id, b.booking_reference, b.passenger_name, b.passenger_gender,
            b.travel_date, b.total_amount, b.booking_status, b.payment_status, b.booking_date,
            s.departure_time, s.arrival_time, s.schedule_id,
            bus.bus_name, bus.bus_number, bus.bus_type,
            r.route_name, r.origin, r.destination,
            seat.seat_number,
            u.full_name as operator_name, u.phone as operator_phone
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN users u ON bus.operator_id = u.user_id
        WHERE b.passenger_id = ?
        ORDER BY b.travel_date DESC, s.departure_time DESC, b.booking_date DESC
    ");
    $stmt->execute([$user_id]);
    $all_bookings = $stmt->fetchAll();
    
    // Initialize arrays
    $upcoming_trips = [];
    $past_trips = [];
    $cancelled_trips = [];
    
    // Only process if we have bookings
    if (!empty($all_bookings)) {
        // Group bookings by trip
        $grouped_bookings = [];
        foreach ($all_bookings as $booking) {
            // Ensure all required fields exist
            if (empty($booking['travel_date']) || empty($booking['schedule_id']) || empty($booking['booking_date'])) {
                continue; // Skip invalid booking data
            }
            
            $group_key = $booking['travel_date'] . '_' . $booking['schedule_id'] . '_' . substr($booking['booking_date'], 0, 16);
            
            if (!isset($grouped_bookings[$group_key])) {
                $grouped_bookings[$group_key] = [
                    'trip_info' => $booking,
                    'passengers' => [],
                    'total_amount' => 0,
                    'booking_references' => [],
                    'booking_date' => $booking['booking_date']
                ];
            }
            
            $grouped_bookings[$group_key]['passengers'][] = [
                'name' => $booking['passenger_name'],
                'gender' => $booking['passenger_gender'],
                'seat_number' => $booking['seat_number'],
                'booking_reference' => $booking['booking_reference'],
                'amount' => $booking['total_amount']
            ];
            
            $grouped_bookings[$group_key]['total_amount'] += $booking['total_amount'];
            $grouped_bookings[$group_key]['booking_references'][] = $booking['booking_reference'];
        }
        
        // Separate by status and date
        foreach ($grouped_bookings as $group) {
            // Ensure required fields exist in trip_info
            if (empty($group['trip_info']['travel_date']) || empty($group['trip_info']['departure_time'])) {
                continue;
            }
            
            $trip_datetime = strtotime($group['trip_info']['travel_date'] . ' ' . $group['trip_info']['departure_time']);
            $is_future = $trip_datetime > time();
            
            if ($group['trip_info']['booking_status'] === 'cancelled') {
                $cancelled_trips[] = $group;
            } elseif ($is_future) {
                $upcoming_trips[] = $group;
            } else {
                $past_trips[] = $group;
            }
        }
    }
    
} catch (PDOException $e) {
    $error = "Error loading bookings: " . $e->getMessage();
    $upcoming_trips = [];
    $past_trips = [];
    $cancelled_trips = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                        <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="search_buses.php">Search Buses</a></li>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <h2 class="mb_2">My Bookings</h2>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert_success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert_error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo count($upcoming_trips); ?></div>
                <div class="stat_label">Upcoming Trips</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($past_trips); ?></div>
                <div class="stat_label">Completed Trips</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($cancelled_trips); ?></div>
                <div class="stat_label">Cancelled Bookings</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($upcoming_trips) + count($past_trips) + count($cancelled_trips); ?></div>
                <div class="stat_label">Total Trips</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div style="border-bottom: 2px solid #eee; margin-bottom: 2rem;">
            <div style="display: flex; gap: 2rem;">
                <button class="tab_btn active" onclick="showTab('upcoming')" id="upcoming-tab">
                    Upcoming (<?php echo count($upcoming_trips); ?>)
                </button>
                <button class="tab_btn" onclick="showTab('past')" id="past-tab">
                    Past (<?php echo count($past_trips); ?>)
                </button>
                <button class="tab_btn" onclick="showTab('cancelled')" id="cancelled-tab">
                    Cancelled (<?php echo count($cancelled_trips); ?>)
                </button>
            </div>
        </div>

        <!-- Upcoming Trips -->
        <div id="upcoming-content" class="tab_content active">
            <h3 class="mb_1">Upcoming Trips</h3>
            <?php if (empty($upcoming_trips)): ?>
                <div class="alert alert_info">
                    <h4>No upcoming trips</h4>
                    <p>You don't have any upcoming bookings. Ready to plan your next journey?</p>
                    <a href="search_buses.php" class="btn btn_primary mt_1">Search Buses</a>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming_trips as $group): ?>
                    <?php 
                    $trip = $group['trip_info'];
                    $departure_datetime = strtotime($trip['travel_date'] . ' ' . $trip['departure_time']);
                    $hours_until = (strtotime($trip['travel_date'] . ' ' . $trip['departure_time']) - time()) / 3600;
                    $can_cancel = $hours_until >= 2;
                    ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($trip['route_name']); ?>
                                    <span class="badge badge_active" style="margin-left: 1rem;">
                                        <?php echo ucfirst($trip['booking_status']); ?>
                                    </span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($trip['origin']); ?> → <?php echo htmlspecialchars($trip['destination']); ?><br>
                                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip['travel_date'])); ?><br>
                                    <strong>Time:</strong> <?php echo date('g:i A', strtotime($trip['departure_time'])); ?>
                                    <?php if ($trip['arrival_time']): ?>
                                        → <?php echo date('g:i A', strtotime($trip['arrival_time'])); ?>
                                    <?php endif; ?><br>
                                    <strong>Bus:</strong> <?php echo htmlspecialchars($trip['bus_name']); ?> (<?php echo $trip['bus_number']; ?>)<br>
                                    <strong>Operator:</strong> <?php echo htmlspecialchars($trip['operator_name']); ?> | <?php echo $trip['operator_phone']; ?>
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <?php if ($can_cancel): ?>
                                    <form method="POST" onsubmit="return confirm('Cancel this entire booking (<?php echo count($group['passengers']); ?> seat(s))?');" style="margin-bottom: 0.5rem;">
                                        <input type="hidden" name="booking_refs" value="<?php echo implode(',', $group['booking_references']); ?>">
                                        <button type="submit" name="cancel_booking" class="btn" style="background: #e74c3c; font-size: 0.9rem;">
                                            Cancel Booking
                                        </button>
                                    </form>
                                    <small style="color: #27ae60;">✓ Free cancellation</small>
                                <?php else: ?>
                                    <button class="btn" disabled style="background: #95a5a6; font-size: 0.9rem;">
                                        Cannot Cancel
                                    </button>
                                    <small style="color: #e74c3c;">Less than 2 hours to departure</small>
                                <?php endif; ?>
                                
                                <div style="margin-top: 0.5rem;">
                                    <a href="booking_confirmation.php?booking_refs=<?php echo implode(',', $group['booking_references']); ?>" class="btn btn_primary" style="font-size: 0.9rem;">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Passengers -->
                        <div class="passenger-list">
                            <h5 style="margin-bottom: 1rem; color: #2c3e50;">
                                Passengers (<?php echo count($group['passengers']); ?>)
                            </h5>
                            
                            <?php foreach ($group['passengers'] as $passenger): ?>
                                <div class="passenger-item">
                                    <div class="passenger-details">
                                        <strong><?php echo htmlspecialchars($passenger['name']); ?></strong>
                                        <div class="passenger-meta">
                                            <span class="badge badge_<?php echo $passenger['gender']; ?>">
                                                <?php echo ucfirst($passenger['gender']); ?>
                                            </span>
                                            <span>Seat <?php echo $passenger['seat_number']; ?></span>
                                            <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                <?php echo $passenger['booking_reference']; ?>
                                            </code>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>LKR <?php echo number_format($passenger['amount']); ?></strong>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Total -->
                        <div class="total-summary">
                            <div>Total Amount (<?php echo count($group['passengers']); ?> seat<?php echo count($group['passengers']) > 1 ? 's' : ''; ?>):</div>
                            <div>LKR <?php echo number_format($group['total_amount']); ?></div>
                        </div>
                        
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                            <strong>Payment:</strong> 
                            <span class="badge badge_<?php echo $trip['payment_status'] === 'paid' ? 'active' : 'operator'; ?>">
                                <?php echo ucfirst($trip['payment_status']); ?>
                            </span>
                            | <strong>Booked:</strong> <?php echo date('M j, Y g:i A', strtotime($group['booking_date'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Past Trips -->
        <div id="past-content" class="tab_content">
            <h3 class="mb_1">Past Trips</h3>
            <?php if (empty($past_trips)): ?>
                <div class="alert alert_info">
                    <p>No past trips found. Your completed journeys will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($past_trips as $group): ?>
                    <?php $trip = $group['trip_info']; ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($trip['route_name']); ?>
                                    <span class="badge badge_inactive" style="margin-left: 1rem;">Completed</span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip['travel_date'])); ?><br>
                                    <strong>Route:</strong> <?php echo htmlspecialchars($trip['origin']); ?> → <?php echo htmlspecialchars($trip['destination']); ?><br>
                                    <strong>Bus:</strong> <?php echo htmlspecialchars($trip['bus_name']); ?> (<?php echo $trip['bus_number']; ?>)
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <a href="booking_confirmation.php?booking_refs=<?php echo implode(',', $group['booking_references']); ?>" class="btn btn_primary" style="font-size: 0.9rem;">
                                    View Details
                                </a>
                            </div>
                        </div>
                        
                        <!-- Passengers -->
                        <div class="passenger-list">
                            <h5 style="margin-bottom: 1rem; color: #2c3e50;">
                                Passengers (<?php echo count($group['passengers']); ?>)
                            </h5>
                            
                            <?php foreach ($group['passengers'] as $passenger): ?>
                                <div class="passenger-item">
                                    <div class="passenger-details">
                                        <strong><?php echo htmlspecialchars($passenger['name']); ?></strong>
                                        <div class="passenger-meta">
                                            <span>Seat <?php echo $passenger['seat_number']; ?></span>
                                            <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                <?php echo $passenger['booking_reference']; ?>
                                            </code>
                                        </div>
                                    </div>
                                    <div>LKR <?php echo number_format($passenger['amount']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Total -->
                        <div class="total-summary">
                            <div>Total Amount:</div>
                            <div>LKR <?php echo number_format($group['total_amount']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Cancelled Trips -->
        <div id="cancelled-content" class="tab_content">
            <h3 class="mb_1">Cancelled Bookings</h3>
            <?php if (empty($cancelled_trips)): ?>
                <div class="alert alert_info">
                    <p>No cancelled bookings found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cancelled_trips as $group): ?>
                    <?php $trip = $group['trip_info']; ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($trip['route_name']); ?>
                                    <span class="badge badge_inactive" style="margin-left: 1rem;">Cancelled</span>
                                </h4>
                                
                                <div style="color: #666;">
                                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip['travel_date'])); ?><br>
                                    <strong>Route:</strong> <?php echo htmlspecialchars($trip['origin']); ?> → <?php echo htmlspecialchars($trip['destination']); ?><br>
                                    <strong>Refund Status:</strong> Processing
                                </div>
                            </div>
                        </div>
                        
                        <!-- Passengers -->
                        <div class="passenger-list">
                            <?php foreach ($group['passengers'] as $passenger): ?>
                                <div class="passenger-item">
                                    <div class="passenger-details">
                                        <strong><?php echo htmlspecialchars($passenger['name']); ?></strong>
                                        <div class="passenger-meta">
                                            <span>Seat <?php echo $passenger['seat_number']; ?></span>
                                            <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                <?php echo $passenger['booking_reference']; ?>
                                            </code>
                                        </div>
                                    </div>
                                    <div>LKR <?php echo number_format($passenger['amount']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Total -->
                        <div class="total-summary">
                            <div>Total Refund Amount:</div>
                            <div>LKR <?php echo number_format($group['total_amount']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid mt_2">
            <div class="feature_card">
                <h4>🔍 Book New Trip</h4>
                <p>Find and book your next journey with our smart seat selection.</p>
                <a href="search_buses.php" class="btn btn_primary">Search Buses</a>
            </div>
            <div class="feature_card">
                <h4>📞 Need Help?</h4>
                <p>Contact support for assistance with bookings or refunds.</p>
                <button class="btn btn_success" onclick="alert('Support: +94 11 123 4567\nEmail: support@roadrunner.lk')">Get Support</button>
            </div>
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your journey, our priority!</p>
        </div>
    </footer>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            const contents = document.querySelectorAll('.tab_content');
            contents.forEach(content => content.classList.remove('active'));
            
            const buttons = document.querySelectorAll('.tab_btn');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName + '-content').classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
        }
    </script>
</body>
</html>