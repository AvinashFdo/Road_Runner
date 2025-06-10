<?php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get booking references from URL
$booking_refs = $_GET['booking_refs'] ?? '';
if (empty($booking_refs)) {
    header('Location: search_buses.php');
    exit();
}

// Function to convert database seat number to horizontal visual layout number
function getHorizontalSeatNumber($seatNumber, $busId, $pdo) {
    try {
        // Get bus seat configuration
        $stmt = $pdo->prepare("SELECT seat_configuration FROM buses WHERE bus_id = ?");
        $stmt->execute([$busId]);
        $seatConfig = $stmt->fetch()['seat_configuration'] ?? '2x2';

        $config = explode('x', $seatConfig);
        $leftSeats = (int)$config[0];
        $rightSeats = (int)$config[1];
        $seatsPerRow = $leftSeats + $rightSeats;

        $stmt = $pdo->prepare("SELECT seat_number FROM seats WHERE bus_id = ? ORDER BY seat_number ASC");
        $stmt->execute([$busId]);
        $allSeats = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $alphabeticalPosition = array_search($seatNumber, $allSeats);
        if ($alphabeticalPosition === false) {
            return $seatNumber; 
        }
        
        $totalRows = count($allSeats) / $seatsPerRow;
        $seatLetter = substr($seatNumber, 0, 1); 
        $seatRowNum = (int)substr($seatNumber, 1); 
        $positionInRow = ord($seatLetter) - ord('A');
        
        $horizontalNumber = (($seatRowNum - 1) * $seatsPerRow) + $positionInRow + 1;
        
        return $horizontalNumber;
        
    } catch (PDOException $e) {
        // If there's an error, return the original seat number
        return $seatNumber;
    }
}

// Convert comma-separated booking references to array
$booking_references = explode(',', $booking_refs);
$bookings = [];
$total_amount = 0;
$bus_info = null;

try {
    // Get booking details for all references
    foreach ($booking_references as $booking_ref) {
        $stmt = $pdo->prepare("
            SELECT 
                b.booking_id, b.booking_reference, b.passenger_name, b.passenger_gender, 
                b.travel_date, b.total_amount, b.booking_status, b.payment_status, b.booking_date,
                s.departure_time, s.arrival_time,
                bus.bus_id, bus.bus_name, bus.bus_number, bus.bus_type, bus.amenities,
                r.route_name, r.origin, r.destination, r.distance_km, r.estimated_duration,
                seat.seat_number, seat.seat_type,
                u.full_name as operator_name, u.phone as operator_phone
            FROM bookings b
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN buses bus ON s.bus_id = bus.bus_id
            JOIN routes r ON s.route_id = r.route_id
            JOIN seats seat ON b.seat_id = seat.seat_id
            JOIN users u ON bus.operator_id = u.user_id
            WHERE b.booking_reference = ? AND b.passenger_id = ?
        ");
        $stmt->execute([$booking_ref, $_SESSION['user_id']]);
        $booking = $stmt->fetch();
        
        if ($booking) {
            // Add horizontal seat number
            $booking['horizontal_seat_number'] = getHorizontalSeatNumber($booking['seat_number'], $booking['bus_id'], $pdo);
            $bookings[] = $booking;
            $total_amount += $booking['total_amount'];
            
            // Store bus info from first booking (all bookings are for same bus)
            if (!$bus_info) {
                $bus_info = $booking;
            }
        }
    }
    
    // If no bookings found, redirect
    if (empty($bookings)) {
        header('Location: search_buses.php');
        exit();
    }
    
} catch (PDOException $e) {
    $error = "Error retrieving booking information: " . $e->getMessage();
}

// Generate simple PDF ticket using basic PDF structure
function generateSimplePDFTicket($bookings, $bus_info) {
    
    $content = "ROAD RUNNER - BUS TICKETS\n\n";
    $content .= "=====================================\n";
    $content .= "TRIP INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Route: " . $bus_info['route_name'] . "\n";
    $content .= "From: " . $bus_info['origin'] . "\n";
    $content .= "To: " . $bus_info['destination'] . "\n";
    $content .= "Travel Date: " . date('l, F j, Y', strtotime($bus_info['travel_date'])) . "\n";
    $content .= "Departure Time: " . date('g:i A', strtotime($bus_info['departure_time'])) . "\n";
    
    if ($bus_info['arrival_time']) {
        $content .= "Arrival Time: " . date('g:i A', strtotime($bus_info['arrival_time'])) . "\n";
    }
    
    $content .= "\n=====================================\n";
    $content .= "BUS DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Bus Name: " . $bus_info['bus_name'] . "\n";
    $content .= "Bus Number: " . $bus_info['bus_number'] . "\n";
    $content .= "Bus Type: " . $bus_info['bus_type'] . "\n";
    $content .= "Operator: " . $bus_info['operator_name'] . "\n";
    $content .= "Contact: " . $bus_info['operator_phone'] . "\n";
    
    if ($bus_info['amenities']) {
        $content .= "Amenities: " . $bus_info['amenities'] . "\n";
    }
    
    $content .= "\n=====================================\n";
    $content .= "PASSENGER DETAILS\n";
    $content .= "=====================================\n";
    
    $total = 0;
    foreach ($bookings as $index => $booking) {
        $content .= "\nPassenger " . ($index + 1) . ":\n";
        $content .= "  Name: " . $booking['passenger_name'] . "\n";
        $content .= "  Seat Number: " . $booking['horizontal_seat_number'] . "\n";
        $content .= "  Gender: " . ucfirst($booking['passenger_gender']) . "\n";
        $content .= "  Booking Reference: " . $booking['booking_reference'] . "\n";
        $content .= "  Amount: LKR " . number_format($booking['total_amount']) . "\n";
        $total += $booking['total_amount'];
    }
    
    $content .= "\n=====================================\n";
    $content .= "PAYMENT SUMMARY\n";
    $content .= "=====================================\n";
    $content .= "Number of Passengers: " . count($bookings) . "\n";
    $content .= "Total Amount: LKR " . number_format($total) . "\n";
    $content .= "Payment Status: " . ucfirst($bookings[0]['payment_status']) . "\n";
    $content .= "Booking Date: " . date('F j, Y \a\t g:i A', strtotime($bookings[0]['booking_date'])) . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "IMPORTANT INSTRUCTIONS\n";
    $content .= "=====================================\n";
    $content .= "• Arrive 15 minutes before departure\n";
    $content .= "• Carry valid photo ID for verification\n";
    $content .= "• Keep booking references for journey\n";
    $content .= "• Contact operator for schedule changes\n";
    $content .= "• Free cancellation up to 2 hours before\n";
    $content .= "• Seats numbered 1,2,3... from front-left across rows\n";
    
    $content .= "\n=====================================\n";
    $content .= "CONTACT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Operator Contact: " . $bus_info['operator_phone'] . "\n";
    $content .= "Road Runner Support: +94 11 123 4567\n";
    $content .= "Email: support@roadrunner.lk\n";
    $content .= "Website: www.roadrunner.lk\n";
    
    $content .= "\n=====================================\n";
    $content .= "Thank you for choosing Road Runner!\n";
    $content .= "Have a safe and comfortable journey!\n";
    $content .= "=====================================\n";
    
    return $content;
}

// Handle PDF download (as formatted text file that can be converted to PDF)
if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    $filename = 'RoadRunner_Tickets_' . date('Ymd_His') . '.txt';
    $content = generateSimplePDFTicket($bookings, $bus_info);
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - Road Runner</title>
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
                    <li><a href="search_buses.php">Search Buses</a></li>
                    <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                        <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            
            <!-- Success Message -->
            <div class="alert alert_success mb_2">
                <h2 style="margin-bottom: 1rem;">🎉 Booking Confirmed!</h2>
                <p style="font-size: 1.1rem;">Your bus tickets have been successfully booked. Please save your booking references for future use.</p>
            </div>

            <!-- Trip Information -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">Trip Information</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route Details</h4>
                            <p><strong>Route:</strong> <?php echo htmlspecialchars($bus_info['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($bus_info['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($bus_info['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo $bus_info['distance_km']; ?> km</p>
                            <?php if ($bus_info['estimated_duration']): ?>
                                <p><strong>Duration:</strong> <?php echo htmlspecialchars($bus_info['estimated_duration']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Schedule</h4>
                            <p><strong>Travel Date:</strong> <?php echo date('D, M j, Y', strtotime($bus_info['travel_date'])); ?></p>
                            <p><strong>Departure:</strong> <?php echo date('g:i A', strtotime($bus_info['departure_time'])); ?></p>
                            <?php if ($bus_info['arrival_time']): ?>
                                <p><strong>Arrival:</strong> <?php echo date('g:i A', strtotime($bus_info['arrival_time'])); ?></p>
                            <?php endif; ?>
                            <p><strong>Booking Date:</strong> <?php echo date('M j, Y g:i A', strtotime($bus_info['booking_date'])); ?></p>
                        </div>
                        
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Bus Details</h4>
                            <p><strong>Bus:</strong> <?php echo htmlspecialchars($bus_info['bus_name']); ?></p>
                            <p><strong>Number:</strong> <?php echo htmlspecialchars($bus_info['bus_number']); ?></p>
                            <p><strong>Type:</strong> 
                                <span class="badge badge_<?php echo strtolower(str_replace('-', '', $bus_info['bus_type'])); ?>">
                                    <?php echo $bus_info['bus_type']; ?>
                                </span>
                            </p>
                            <p><strong>Operator:</strong> <?php echo htmlspecialchars($bus_info['operator_name']); ?></p>
                            <p><strong>Contact:</strong> <?php echo htmlspecialchars($bus_info['operator_phone']); ?></p>
                        </div>
                    </div>
                    
                    <?php if ($bus_info['amenities']): ?>
                        <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #eee;">
                            <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">Amenities</h4>
                            <p><?php echo htmlspecialchars($bus_info['amenities']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Passenger Details -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">Passenger Details (<?php echo count($bookings); ?> passenger<?php echo count($bookings) > 1 ? 's' : ''; ?>)</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Passenger Name</th>
                            <th>Seat Number</th>
                            <th>Seat Type</th>
                            <th>Gender</th>
                            <th>Booking Reference</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($booking['passenger_name']); ?></strong></td>
                                <td>
                                    <span style="background: #4caf50; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: bold;">
                                        <?php echo $booking['horizontal_seat_number']; ?>
                                    </span>
                                </td>
                                <td><?php echo ucfirst($booking['seat_type']); ?></td>
                                <td>
                                    <span class="badge badge_<?php echo $booking['passenger_gender']; ?>">
                                        <?php echo ucfirst($booking['passenger_gender']); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px;">
                                        <?php echo $booking['booking_reference']; ?>
                                    </code>
                                </td>
                                <td><strong>LKR <?php echo number_format($booking['total_amount']); ?></strong></td>
                                <td>
                                    <span class="badge badge_active">
                                        <?php echo ucfirst($booking['booking_status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payment Summary -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">Payment Summary</h3>
                <div class="p_2">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <span>Number of passengers:</span>
                        <strong><?php echo count($bookings); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <span>Price per seat:</span>
                        <strong>LKR <?php echo number_format($bookings[0]['total_amount']); ?></strong>
                    </div>
                    <div style="border-top: 2px solid #eee; padding-top: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 1.2rem;">
                            <span><strong>Total Amount:</strong></span>
                            <span style="color: #e74c3c; font-weight: bold; font-size: 1.4rem;">
                                LKR <?php echo number_format($total_amount); ?>
                            </span>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Payment Status:</span>
                            <span class="badge badge_operator" style="font-size: 0.9rem;">
                                <?php echo ucfirst($bookings[0]['payment_status']); ?>
                            </span>
                        </div>
                        <p style="color: #666; font-size: 0.9rem; margin-top: 0.5rem;">
                            Payment can be made at the time of boarding or through our payment portal.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0;">
                <a href="?<?php echo http_build_query($_GET); ?>&download=pdf" class="btn btn_primary" style="text-align: center;">
                    📄 Download Tickets
                </a>
                <a href="search_buses.php" class="btn btn_success" style="text-align: center;">
                    🔍 Book Another Trip
                </a>
                <a href="my_bookings.php" class="btn" style="text-align: center; background: #34495e;">
                    📋 View All Bookings
                </a>
            </div>

            

            <!-- Important Information -->
            <div class="alert alert_info">
                <h4>📋 Important Information</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                    <div>
                        <strong>Before Departure:</strong><br>
                        • Arrive 15 minutes before departure<br>
                        • Carry valid ID for verification<br>
                        • Keep booking reference handy
                    </div>
                    <div>
                        <strong>Cancellation Policy:</strong><br>
                        • Free cancellation up to 2 hours before departure<br>
                        • Contact operator for cancellations<br>
                        • Refunds processed within 3-5 business days
                    </div>
                    <div>
                        <strong>Contact Support:</strong><br>
                        • Operator: <?php echo htmlspecialchars($bus_info['operator_phone']); ?><br>
                        • Road Runner Support: +94 11 123 4567<br>
                        • Email: support@roadrunner.lk
                    </div>
                    <div>
                        <strong>Your Booking References:</strong><br>
                        <?php foreach ($bookings as $booking): ?>
                            • <?php echo $booking['booking_reference']; ?><br>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Thank you for choosing us for your journey!</p>
        </div>
    </footer>

    <script>
        // Auto-scroll to top on page load
        window.addEventListener('load', function() {
            window.scrollTo(0, 0);
        });
        
        // Smooth animation for success message
        document.addEventListener('DOMContentLoaded', function() {
            const successAlert = document.querySelector('.alert_success');
            if (successAlert) {
                successAlert.style.opacity = '0';
                successAlert.style.transform = 'translateY(-20px)';
                successAlert.style.transition = 'all 0.5s ease-in-out';
                
                setTimeout(() => {
                    successAlert.style.opacity = '1';
                    successAlert.style.transform = 'translateY(0)';
                }, 100);
            }
        });
    </script>
</body>
</html>