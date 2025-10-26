<?php
require_once __DIR__ . '/includes/config.php';

// Get request details
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 1;

$stmt = $conn->prepare("
    SELECT 
        dr.*,
        s.name as supply_name,
        u.name as hospital_name,
        h.name as hospital_facility,
        h.location as hospital_address,
        op.name as operator_name,
        d.model as drone_model,
        d.capacity as drone_capacity
    FROM DeliveryRequests dr
    JOIN Supplies s ON dr.supply_id = s.supply_id
    JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    LEFT JOIN Users op ON dr.operator_id = op.user_id
    LEFT JOIN Drones d ON dr.drone_id = d.drone_id
    WHERE dr.request_id = ?
");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();

// Get delivery logs
$stmt = $conn->prepare("SELECT * FROM DeliveryLogs WHERE request_id = ? ORDER BY timestamp ASC");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payment details
$stmt = $conn->prepare("SELECT * FROM Payments WHERE request_id = ?");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

// Get tracking data
$stmt = $conn->prepare("SELECT latitude, longitude, altitude, speed, recorded_at FROM DeliveryTracking WHERE request_id = ? ORDER BY recorded_at ASC");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$trackingPoints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$currentLocation = $trackingPoints ? $trackingPoints[count($trackingPoints) - 1] : null;

// KUET Central Field coordinates (pickup point)
$pickupLat = 22.9019;
$pickupLng = 89.5267;
$pickupName = "KUET Central Field (Drone Station)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Delivery Tracking - Request #<?php echo $requestId; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?php echo BASE_PATH; ?>/assets/css/style.css">
    <style>
        #map { height: 500px; width: 100%; border-radius: 12px; }
        .status-timeline { position: relative; padding-left: 30px; }
        .status-timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(to bottom, #10b981, #3b82f6);
        }
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        .timeline-dot {
            position: absolute;
            left: -26px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #10b981;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #10b981;
        }
        .info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            backdrop-filter: blur(10px);
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="row mb-4">
        <div class="col">
            <a href="<?php echo BASE_PATH; ?>/home.php" class="btn btn-outline-primary mb-3">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
            <h1 class="text-gradient"><i class="bi bi-geo-alt-fill"></i> Live Delivery Tracking</h1>
            <p class="text-muted">Request #<?php echo $requestId; ?> - Real-time drone delivery monitoring</p>
        </div>
    </div>

    <!-- Status Cards -->
    <div class="info-card">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="stat-box">
                    <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                    <h6 class="mt-2">Supply</h6>
                    <strong><?php echo htmlspecialchars($request['supply_name']); ?></strong>
                    <div><small>Quantity: <?php echo $request['quantity']; ?></small></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <i class="bi bi-hospital" style="font-size: 2rem;"></i>
                    <h6 class="mt-2">Hospital</h6>
                    <strong><?php echo htmlspecialchars($request['hospital_facility']); ?></strong>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <i class="bi bi-person-badge" style="font-size: 2rem;"></i>
                    <h6 class="mt-2">Operator</h6>
                    <strong><?php echo htmlspecialchars($request['operator_name']); ?></strong>
                    <div><small><?php echo htmlspecialchars($request['drone_model']); ?></small></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <i class="bi bi-cash-stack" style="font-size: 2rem;"></i>
                    <h6 class="mt-2">Payment</h6>
                    <strong>৳<?php echo number_format($request['payment_amount'], 2); ?></strong>
                    <div>
                        <?php
                        $paymentBadgeMap = [
                            'paid' => 'success',
                            'pending' => 'warning',
                            'unpaid' => 'danger',
                            'refunded' => 'secondary'
                        ];
                        $paymentBadge = $paymentBadgeMap[$request['payment_status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?php echo $paymentBadge; ?>"><?php echo ucfirst($request['payment_status']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Map Section -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-map"></i> Delivery Route</h5>
                </div>
                <div class="card-body p-0">
                    <div id="map"></div>
                    <div class="p-3">
                        <div class="row">
                            <div class="col-6">
                                <strong><i class="bi bi-geo-fill text-success"></i> Pickup:</strong><br>
                                <small><?php echo $pickupName; ?></small>
                            </div>
                            <div class="col-6">
                                <strong><i class="bi bi-geo-alt-fill text-danger"></i> Delivery:</strong><br>
                                <small><?php echo htmlspecialchars($request['destination']); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($currentLocation): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-broadcast"></i> Latest Drone Telemetry</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <strong>Coordinates</strong>
                            <div>Lat: <?php echo number_format($currentLocation['latitude'], 5); ?></div>
                            <div>Lng: <?php echo number_format($currentLocation['longitude'], 5); ?></div>
                        </div>
                        <div class="col-md-4">
                            <strong>Flight Data</strong>
                            <div>Altitude: <?php echo $currentLocation['altitude'] !== null ? (int) $currentLocation['altitude'] . ' m' : 'N/A'; ?></div>
                            <div>Speed: <?php echo $currentLocation['speed'] !== null ? number_format($currentLocation['speed'], 1) . ' km/h' : 'N/A'; ?></div>
                        </div>
                        <div class="col-md-4">
                            <strong>Last Update</strong>
                            <div><?php echo date('M d, Y h:i A', strtotime($currentLocation['recorded_at'])); ?></div>
                            <small class="text-muted">Tracking data uploaded by operator</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Details -->
            <?php if ($payment): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-credit-card"></i> Payment Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <strong>Amount:</strong> ৳<?php echo number_format($payment['amount'], 2); ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Method:</strong> <?php echo htmlspecialchars($payment['payment_method']); ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Transaction ID:</strong> <?php echo htmlspecialchars($payment['transaction_id']); ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Date:</strong> <?php echo date('M d, Y h:i A', strtotime($payment['payment_date'])); ?>
                        </div>
                        <div class="col-12">
                            <strong>Status:</strong> 
                            <span class="badge bg-success"><?php echo ucfirst($payment['status']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Timeline Section -->
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Delivery Timeline</h5>
                    <small class="text-muted">Status: 
                        <?php
                        $statusColors = [
                            'pending' => 'warning',
                            'approved' => 'primary',
                            'in-transit' => 'info',
                            'delivered' => 'success',
                            'cancelled' => 'danger'
                        ];
                        $color = $statusColors[$request['status']] ?? 'secondary';
                        ?>
                        <span class="badge bg-<?php echo $color; ?>"><?php echo ucfirst($request['status']); ?></span>
                    </small>
                </div>
                <div class="card-body">
                    <div class="status-timeline">
                        <?php foreach ($logs as $log): ?>
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <small class="text-muted d-block mb-1">
                                <i class="bi bi-clock"></i> 
                                <?php echo date('M d, Y h:i A', strtotime($log['timestamp'])); ?>
                            </small>
                            <p class="mb-0"><?php echo htmlspecialchars($log['notes']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>"></script>
<script>
let map, pickupMarker, deliveryMarker, routeLine, droneMarker;
const trackingPoints = <?php echo json_encode(array_map(static function ($point) {
    return [
        'lat' => (float) $point['latitude'],
        'lng' => (float) $point['longitude'],
        'recorded_at' => $point['recorded_at'],
    ];
}, $trackingPoints), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;

function initMap() {
    const pickup = { lat: <?php echo $pickupLat; ?>, lng: <?php echo $pickupLng; ?> };
    const delivery = { lat: <?php echo $request['latitude']; ?>, lng: <?php echo $request['longitude']; ?> };

    // Center map between two points
    const centerLat = (pickup.lat + delivery.lat) / 2;
    const centerLng = (pickup.lng + delivery.lng) / 2;

    map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: centerLat, lng: centerLng },
        zoom: 13,
        mapTypeId: 'terrain'
    });

    const bounds = new google.maps.LatLngBounds();

    // Pickup marker (green)
    pickupMarker = new google.maps.Marker({
        position: pickup,
        map: map,
        title: '<?php echo $pickupName; ?>',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 12,
            fillColor: '#10b981',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 3
        },
        label: {
            text: 'P',
            color: 'white',
            fontWeight: 'bold'
        }
    });

    // Delivery marker (red)
    deliveryMarker = new google.maps.Marker({
        position: delivery,
        map: map,
        title: '<?php echo htmlspecialchars($request['destination']); ?>',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 12,
            fillColor: '#ef4444',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 3
        },
        label: {
            text: 'D',
            color: 'white',
            fontWeight: 'bold'
        }
    });

    // Draw route line
    const pathPoints = [pickup];
    if (trackingPoints.length) {
        trackingPoints.forEach(point => {
            pathPoints.push({ lat: point.lat, lng: point.lng });
        });
    }
    pathPoints.push(delivery);

    routeLine = new google.maps.Polyline({
        path: pathPoints,
        geodesic: true,
        strokeColor: '#3b82f6',
        strokeOpacity: 0.8,
        strokeWeight: 3,
        icons: [{
            icon: {
                path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                scale: 3,
                fillColor: '#3b82f6',
                fillOpacity: 1,
                strokeColor: '#ffffff',
                strokeWeight: 1
            },
            offset: '50%'
        }]
    });
    routeLine.setMap(map);

    // Info windows
    const pickupInfo = new google.maps.InfoWindow({
        content: '<div style="padding:10px;"><strong>Pickup Point</strong><br><?php echo $pickupName; ?><br><small>Coordinates: <?php echo $pickupLat; ?>, <?php echo $pickupLng; ?></small></div>'
    });

    const deliveryInfo = new google.maps.InfoWindow({
        content: '<div style="padding:10px;"><strong>Delivery Point</strong><br><?php echo htmlspecialchars($request['destination']); ?><br><small>Hospital: <?php echo htmlspecialchars($request['hospital_facility']); ?></small></div>'
    });

    pickupMarker.addListener('click', () => {
        pickupInfo.open(map, pickupMarker);
    });

    deliveryMarker.addListener('click', () => {
        deliveryInfo.open(map, deliveryMarker);
    });

    bounds.extend(pickup);
    bounds.extend(delivery);

    if (trackingPoints.length) {
        const latest = trackingPoints[trackingPoints.length - 1];
        const dronePosition = { lat: latest.lat, lng: latest.lng };
        droneMarker = new google.maps.Marker({
            position: dronePosition,
            map: map,
            title: 'Current Drone Position',
            icon: {
                path: google.maps.SymbolPath.FORWARD_CLOSED_ARROW,
                scale: 6,
                fillColor: '#f97316',
                fillOpacity: 1,
                strokeColor: '#ffffff',
                strokeWeight: 2
            }
        });

        const droneInfo = new google.maps.InfoWindow({
            content: '<div style="padding:10px;">' +
                     '<strong>Drone Position</strong><br>' +
                     'Lat: ' + latest.lat.toFixed(5) + '<br>' +
                     'Lng: ' + latest.lng.toFixed(5) + '<br>' +
                     '<small>Updated: ' + latest.recorded_at + '</small>' +
                     '</div>'
        });

        droneMarker.addListener('click', () => {
            droneInfo.open(map, droneMarker);
        });
    }

    // Auto-open info windows
    setTimeout(() => {
        pickupInfo.open(map, pickupMarker);
        deliveryInfo.open(map, deliveryMarker);
        if (droneMarker) {
            google.maps.event.trigger(droneMarker, 'click');
        }
    }, 500);

    // Fit bounds to show both markers
    if (droneMarker) {
        bounds.extend(droneMarker.getPosition());
    }
    map.fitBounds(bounds);
}

window.addEventListener('load', initMap);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
