<?php
// Gender-Based Seat Selection System with Simple Seat Numbers
// Save this as: seat_selection.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get parameters
$schedule_id = $_GET['schedule_id'] ?? '';
$travel_date = $_GET['travel_date'] ?? '';

if (empty($schedule_id) || empty($travel_date)) {
    header('Location: search_buses.php');
    exit();
}

// Validate travel date
if (strtotime($travel_date) < strtotime('today')) {
    header('Location: search_buses.php');
    exit();
}

$error = '';
$bus_info = null;
$seats = [];

try {
    // Get bus and route information
    $stmt = $pdo->prepare("
        SELECT 
            s.schedule_id, s.departure_time, s.arrival_time, s.base_price,
            b.bus_id, b.bus_name, b.bus_number, b.bus_type, b.total_seats, b.seat_configuration, b.amenities,
            r.route_name, r.origin, r.destination, r.distance_km, r.estimated_duration,
            u.full_name as operator_name
        FROM schedules s
        JOIN buses b ON s.bus_id = b.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN users u ON b.operator_id = u.user_id
        WHERE s.schedule_id = ? AND s.status = 'active' AND b.status = 'active' AND r.status = 'active'
    ");
    $stmt->execute([$schedule_id]);
    $bus_info = $stmt->fetch();
    
    if (!$bus_info) {
        header('Location: search_buses.php');
        exit();
    }
    
    // Get all seats for this bus with booking information
    $stmt = $pdo->prepare("
        SELECT 
            s.seat_id, s.seat_number, s.seat_type,
            b.booking_id, b.passenger_gender, b.booking_status
        FROM seats s
        LEFT JOIN bookings b ON s.seat_id = b.seat_id 
            AND b.travel_date = ? 
            AND b.booking_status IN ('pending', 'confirmed')
        WHERE s.bus_id = ?
        ORDER BY s.seat_number ASC
    ");
    $stmt->execute([$travel_date, $bus_info['bus_id']]);
    $seats = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error loading seat information: " . $e->getMessage();
}

// Organize seats by rows for display
$seat_map = [];
if (!empty($seats)) {
    $config = explode('x', $bus_info['seat_configuration']);
    $left_seats = (int)$config[0];
    $right_seats = (int)$config[1];
    $seats_per_row = $left_seats + $right_seats;
    
    foreach ($seats as $seat) {
        // Extract row number from seat number (e.g., A01 -> 1, B02 -> 2)
        $row = (int)substr($seat['seat_number'], 1);
        if (!isset($seat_map[$row])) {
            $seat_map[$row] = [];
        }
        $seat_map[$row][] = $seat;
    }
    
    // Sort rows
    ksort($seat_map);
}

// Handle seat booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_seats'])) {
    $passengers_data = $_POST['passengers'] ?? [];
    
    // Validation
    if (empty($passengers_data)) {
        $error = "Please add at least one passenger and select seats.";
    } else {
        $all_valid = true;
        $selected_seats = [];
        
        // Get user's phone number from database for all bookings
        $stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_phone = $stmt->fetch()['phone'] ?? '';
        
        // Validate each passenger
        foreach ($passengers_data as $index => $passenger) {
            $seat_id = $passenger['seat_id'] ?? '';
            $name = trim($passenger['name'] ?? '');
            $gender = $passenger['gender'] ?? '';
            
            if (empty($seat_id) || empty($name) || empty($gender)) {
                $error = "Please fill in all details for passenger " . ($index + 1) . " and select a seat.";
                $all_valid = false;
                break;
            }
            
            // Validate name (only letters, spaces, dots, and common punctuation)
            if (!preg_match('/^[a-zA-Z\s\.\-\']+$/', $name)) {
                $error = "Please enter a valid name for passenger " . ($index + 1) . ".";
                $all_valid = false;
                break;
            }
            
            // Validate gender
            if (!in_array($gender, ['male', 'female'])) {
                $error = "Please select a valid gender for passenger " . ($index + 1) . ".";
                $all_valid = false;
                break;
            }
            
            if (in_array($seat_id, $selected_seats)) {
                $error = "You cannot select the same seat for multiple passengers.";
                $all_valid = false;
                break;
            }
            
            $selected_seats[] = $seat_id;
        }
        
        if ($all_valid) {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Clean up any cancelled bookings that might conflict
                $stmt = $pdo->prepare("DELETE FROM bookings WHERE booking_status = 'cancelled' AND travel_date = ?");
                $stmt->execute([$travel_date]);
                
                $booking_references = [];
                $total_amount = count($passengers_data) * $bus_info['base_price'];
                
                // Check if all seats are still available (with retry logic)
                $max_retries = 3;
                $retry_count = 0;
                
                while ($retry_count < $max_retries) {
                    $conflicting_seats = [];
                    
                    foreach ($selected_seats as $seat_id) {
                        $stmt = $pdo->prepare("
                            SELECT booking_id FROM bookings 
                            WHERE seat_id = ? AND travel_date = ? AND booking_status IN ('pending', 'confirmed')
                        ");
                        $stmt->execute([$seat_id, $travel_date]);
                        if ($stmt->fetch()) {
                            $conflicting_seats[] = $seat_id;
                        }
                    }
                    
                    if (empty($conflicting_seats)) {
                        break; // All seats are available
                    }
                    
                    $retry_count++;
                    if ($retry_count >= $max_retries) {
                        throw new Exception("One or more selected seats have been booked by other passengers. Please refresh the page and select different seats.");
                    }
                    
                    // Small delay before retry
                    usleep(100000); // 0.1 seconds
                }
                
                // Create bookings for each passenger
                foreach ($passengers_data as $passenger) {
                    // Generate unique booking reference
                    $attempts = 0;
                    do {
                        $booking_reference = 'RR' . date('ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_reference = ?");
                        $stmt->execute([$booking_reference]);
                        $attempts++;
                        
                        // Prevent infinite loop
                        if ($attempts > 10) {
                            throw new Exception("Unable to generate unique booking reference. Please try again.");
                        }
                    } while ($stmt->fetch());
                    
                    // Create booking using account holder's phone number
                    $stmt = $pdo->prepare("
                        INSERT INTO bookings 
                        (booking_reference, passenger_id, schedule_id, seat_id, passenger_name, passenger_phone, passenger_gender, travel_date, total_amount, booking_status, payment_status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'pending')
                    ");
                    $stmt->execute([
                        $booking_reference,
                        $_SESSION['user_id'],
                        $schedule_id,
                        $passenger['seat_id'],
                        $passenger['name'],
                        $user_phone, // Use account holder's phone for all bookings
                        $passenger['gender'],
                        $travel_date,
                        $bus_info['base_price']
                    ]);
                    
                    $booking_references[] = $booking_reference;
                }
                
                // Commit transaction
                $pdo->commit();
                
                // Redirect to booking confirmation with all booking references
                header('Location: booking_confirmation.php?booking_refs=' . implode(',', $booking_references));
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction
                $pdo->rollback();
                $error = "Booking failed: " . $e->getMessage();
            } catch (PDOException $e) {
                $pdo->rollback();
                $error = "Booking failed: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Seats - Road Runner</title>
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
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($bus_info): ?>
        <!-- Trip Information -->
        <div class="alert alert_info">
            <h3 style="margin-bottom: 1rem;">
                <?php echo htmlspecialchars($bus_info['route_name']); ?>
                <span style="color: #666; font-weight: normal; font-size: 0.9rem;">
                    - <?php echo date('D, M j, Y', strtotime($travel_date)); ?>
                </span>
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <div>
                    <strong>Bus:</strong> <?php echo htmlspecialchars($bus_info['bus_name']); ?> (<?php echo htmlspecialchars($bus_info['bus_number']); ?>)<br>
                    <strong>Type:</strong> <?php echo htmlspecialchars($bus_info['bus_type']); ?>
                </div>
                <div>
                    <strong>Route:</strong> <?php echo htmlspecialchars($bus_info['origin']); ?> → <?php echo htmlspecialchars($bus_info['destination']); ?><br>
                    <strong>Operator:</strong> <?php echo htmlspecialchars($bus_info['operator_name']); ?>
                </div>
                <div>
                    <strong>Departure:</strong> <?php echo date('g:i A', strtotime($bus_info['departure_time'])); ?>
                    <?php if ($bus_info['arrival_time']): ?>
                        → <?php echo date('g:i A', strtotime($bus_info['arrival_time'])); ?>
                    <?php endif; ?><br>
                    <strong>Price:</strong> LKR <?php echo number_format($bus_info['base_price']); ?>
                </div>
            </div>
        </div>

        <!-- Seat Selection Interface -->
        <div class="seat_selection_container">
            <!-- Bus Layout -->
            <div>
                <div class="bus_layout">
                    <div class="bus_header">
                        <h3>Select Your Seat</h3>
                        <p style="color: #666; margin: 0;">Click on available seats to select</p>
                    </div>
                    
                    <div class="driver_area">
                        🚗 Driver
                    </div>
                    
                    <div id="seat_map">
                        <?php if (!empty($seat_map)): ?>
                            <?php 
                            $config = explode('x', $bus_info['seat_configuration']);
                            $left_seats = (int)$config[0];
                            $right_seats = (int)$config[1];
                            $seat_counter = 1; // Simple counter starting from 1
                            ?>
                            
                            <?php foreach ($seat_map as $row_num => $row_seats): ?>
                                <div class="seat_row">
                                    <!-- Left side seats -->
                                    <div class="seat_group_left">
                                        <?php for ($i = 0; $i < $left_seats; $i++): ?>
                                            <?php if (isset($row_seats[$i])): ?>
                                                <?php $seat = $row_seats[$i]; ?>
                                                <div 
                                                    class="seat <?php echo $seat['booking_id'] ? 'booked' : 'available'; ?> <?php echo $seat['booking_id'] ? strtolower($seat['passenger_gender'] ?? 'neutral') : 'neutral'; ?>"
                                                    data-seat-id="<?php echo $seat['seat_id']; ?>"
                                                    data-seat-number="<?php echo htmlspecialchars($seat['seat_number']); ?>"
                                                    data-seat-type="<?php echo htmlspecialchars($seat['seat_type']); ?>"
                                                    <?php echo $seat['booking_id'] ? 'data-booked="true" title="This seat is already booked"' : 'onclick="selectSeat(this)" title="Click to select seat ' . $seat_counter . '"'; ?>
                                                >
                                                    <?php echo $seat_counter++; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                    
                                    <!-- Aisle -->
                                    <div class="aisle">
                                        AISLE
                                    </div>
                                    
                                    <!-- Right side seats -->
                                    <div class="seat_group_right">
                                        <?php for ($i = $left_seats; $i < $left_seats + $right_seats; $i++): ?>
                                            <?php if (isset($row_seats[$i])): ?>
                                                <?php $seat = $row_seats[$i]; ?>
                                                <div 
                                                    class="seat <?php echo $seat['booking_id'] ? 'booked' : 'available'; ?> <?php echo $seat['booking_id'] ? strtolower($seat['passenger_gender'] ?? 'neutral') : 'neutral'; ?>"
                                                    data-seat-id="<?php echo $seat['seat_id']; ?>"
                                                    data-seat-number="<?php echo htmlspecialchars($seat['seat_number']); ?>"
                                                    data-seat-type="<?php echo htmlspecialchars($seat['seat_type']); ?>"
                                                    <?php echo $seat['booking_id'] ? 'data-booked="true" title="This seat is already booked"' : 'onclick="selectSeat(this)" title="Click to select seat ' . $seat_counter . '"'; ?>
                                                >
                                                    <?php echo $seat_counter++; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Legend -->
                    <div class="seat_legend">
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #f5f5f5; border-color: #999;"></div>
                            <span>Available</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #1976d2; border-color: #1976d2;"></div>
                            <span>Male Passenger</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #c2185b; border-color: #c2185b;"></div>
                            <span>Female Passenger</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #4caf50; border-color: #4caf50;"></div>
                            <span>Selected</span>
                        </div>
                    </div>
                    
                    <!-- Simple Info -->
                    <div style="background: #e8f4fd; border-radius: 8px; padding: 1rem; margin-top: 1rem; font-size: 0.9rem; text-align: center;">
                        <strong>🪑 Simple Seat Numbering:</strong> Seats are numbered 1, 2, 3, 4... from front to back, left to right
                    </div>
                </div>
            </div>
            
            <!-- Booking Form -->
            <div class="booking_form">
                <h3>Passenger Details</h3>
                
                <button type="button" class="add_passenger_btn" onclick="addPassenger()">
                    + Add Passenger
                </button>
                
                <form method="POST" action="" id="booking_form">
                    <div id="passengers_container" class="passengers_container">
                        <!-- Passengers will be added here dynamically -->
                    </div>
                    
                    <div style="border-top: 1px solid #eee; padding-top: 1rem; margin-top: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Price per seat:</span>
                            <span>LKR <?php echo number_format($bus_info['base_price']); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                            <span>Number of passengers:</span>
                            <span id="passenger_count">0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: bold;">
                            <span>Total Amount:</span>
                            <span style="color: #e74c3c;" id="total_amount">LKR 0</span>
                        </div>
                    </div>
                    
                    <button type="submit" name="book_seats" class="btn btn_primary" style="width: 100%; margin-top: 1rem;" disabled id="book_button">
                        Add Passengers First
                    </button>
                </form>
                
                <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem; color: #666;">
                    <a href="search_buses.php">← Back to Search</a>
                </p>
            </div>
        </div>

        <!-- Instructions -->
        <div class="alert alert_info mt_2">
            <h4>🎯 How to Book Your Seats</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>1. Add Passengers:</strong><br>
                    Click "Add Passenger" and enter names and gender for each traveler.
                </div>
                <div>
                    <strong>2. Select Seats:</strong><br>
                    Click on available seats (numbered 1, 2, 3...) to assign them to your passengers.
                </div>
                <div>
                    <strong>3. Gender-Based Colors:</strong><br>
                    Seats show the gender of current passengers for your comfort and privacy.
                </div>
                <div>
                    <strong>4. Complete Booking:</strong><br>
                    Review details and click "Book" to confirm your reservation.
                </div>
            </div>
        </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Choose your comfort zone!</p>
        </div>
    </footer>

    <script src="assets/js/seat_selection.js"></script>
    <script>
        // Initialize the seat selection system
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($bus_info): ?>
                initSeatSelection(<?php echo $bus_info['base_price']; ?>, '<?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES); ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>