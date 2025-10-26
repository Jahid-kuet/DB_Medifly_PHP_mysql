<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['hospital']);

$userId = currentUserId();

$supplies = [];
$result = $conn->query('SELECT supply_id, name, quantity, unit_price FROM Supplies ORDER BY name');
while ($row = $result->fetch_assoc()) {
    $supplies[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'pay') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $transactionId = trim($_POST['transaction_id'] ?? '');
        $txnNotes = trim($_POST['payment_notes'] ?? '');

        if (!$requestId || $paymentMethod === '') {
            setFlash('Payment method is required.', 'danger');
            header('Location: ' . BASE_PATH . '/hospital/requests.php');
            exit;
        }

        $stmt = $conn->prepare('SELECT payment_amount, payment_status FROM DeliveryRequests WHERE request_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $requestId, $userId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();

        if (!$request) {
            setFlash('Request not found.', 'danger');
            header('Location: ' . BASE_PATH . '/hospital/requests.php');
            exit;
        }

        if ($request['payment_status'] === 'paid') {
            setFlash('Payment already completed for this request.', 'warning');
            header('Location: ' . BASE_PATH . '/hospital/requests.php');
            exit;
        }

        if (($request['payment_amount'] ?? 0) <= 0) {
            setFlash('Payment amount not available yet. Please contact admin.', 'danger');
            header('Location: ' . BASE_PATH . '/hospital/requests.php');
            exit;
        }

        try {
            $conn->begin_transaction();

            // Check if a pending payment already exists for this request
            $stmt = $conn->prepare("SELECT payment_id FROM Payments WHERE request_id = ? AND status = 'pending'");
            $stmt->bind_param('i', $requestId);
            $stmt->execute();
            $existingPayment = $stmt->get_result()->fetch_assoc();

            if ($existingPayment) {
                $stmt = $conn->prepare('UPDATE Payments SET amount = ?, payment_method = ?, transaction_id = ?, notes = ?, payment_date = NOW() WHERE payment_id = ?');
                $stmt->bind_param('dsssi', $request['payment_amount'], $paymentMethod, $transactionId, $txnNotes, $existingPayment['payment_id']);
                $stmt->execute();
            } else {
                $stmt = $conn->prepare('INSERT INTO Payments (request_id, amount, payment_method, transaction_id, status, notes) VALUES (?, ?, ?, ?, "pending", ?)');
                $stmt->bind_param('idsss', $requestId, $request['payment_amount'], $paymentMethod, $transactionId, $txnNotes);
                $stmt->execute();
            }

            $stmt = $conn->prepare('UPDATE DeliveryRequests SET payment_status = "pending", payment_method = ? WHERE request_id = ?');
            $stmt->bind_param('si', $paymentMethod, $requestId);
            $stmt->execute();

            $timestamp = date('Y-m-d H:i:s');
            $logNote = sprintf('Hospital submitted payment via %s (Txn: %s)', $paymentMethod, $transactionId !== '' ? $transactionId : 'N/A');
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $logNote);
            $stmt->execute();

            $conn->commit();
            setFlash('Payment submitted successfully. Waiting for admin verification.', 'info');
        } catch (Exception $e) {
            $conn->rollback();
            setFlash('Unable to submit payment: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/hospital/requests.php');
        exit;
    }

    $supplyId = (int) ($_POST['supply_id'] ?? 0);
    $destination = trim($_POST['destination'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $latitude = !empty($_POST['latitude']) ? (float) $_POST['latitude'] : null;
    $longitude = !empty($_POST['longitude']) ? (float) $_POST['longitude'] : null;

    // allow submission when either a textual destination is provided
    // OR valid latitude and longitude are supplied (manual coordinate mode)
    if (!$supplyId || ($destination === '' && ($latitude === null || $longitude === null)) || $quantity < 1) {
        setFlash('Supply, destination and valid quantity are required.', 'danger');
        header('Location: ' . BASE_PATH . '/hospital/requests.php');
        exit;
    }

    $stmt = $conn->prepare('SELECT unit_price FROM Supplies WHERE supply_id = ?');
    $stmt->bind_param('i', $supplyId);
    $stmt->execute();
    $supply = $stmt->get_result()->fetch_assoc();
    $unitPrice = $supply ? (float) $supply['unit_price'] : 0.0;
    $paymentAmount = round($unitPrice * $quantity, 2);

    $stmt = $conn->prepare('INSERT INTO DeliveryRequests (user_id, supply_id, destination, quantity, latitude, longitude, status, payment_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $status = 'pending';
    $stmt->bind_param('iisiddsd', $userId, $supplyId, $destination, $quantity, $latitude, $longitude, $status, $paymentAmount);
    $stmt->execute();
    $requestId = $conn->insert_id;

    if ($notes !== '') {
        $timestamp = date('Y-m-d H:i:s');
        $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $requestId, $timestamp, $notes);
        $stmt->execute();
    }

    setFlash('Delivery request submitted successfully.');
    header('Location: ' . BASE_PATH . '/hospital/requests.php');
    exit;
}

$requests = [];
$stmt = $conn->prepare('SELECT dr.request_id, dr.destination, dr.status, dr.payment_status, dr.payment_amount, dr.payment_method, dr.quantity, dr.latitude, dr.longitude, s.name AS supply_name, dr.operator_id, dr.drone_id, op.name AS operator_name, d.model AS drone_model, dr.created_at
    FROM DeliveryRequests dr
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    LEFT JOIN Users op ON dr.operator_id = op.user_id
    LEFT JOIN Drones d ON dr.drone_id = d.drone_id
    WHERE dr.user_id = ?
    ORDER BY dr.request_id DESC');
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

// Load delivery logs for displayed requests so hospital can see admin notes inline
$logsByRequest = [];
if (!empty($requests)) {
    $ids = array_map(function ($r) { return (int) $r['request_id']; }, $requests);
    $in = implode(',', $ids);
    $logSql = "SELECT * FROM DeliveryLogs WHERE request_id IN ($in) ORDER BY timestamp ASC";
    $logRes = $conn->query($logSql);
    while ($l = $logRes->fetch_assoc()) {
        $logsByRequest[$l['request_id']][] = $l;
    }
}

$pageTitle = 'My Delivery Requests | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><i class="bi bi-plus-circle"></i> New Delivery Request</h2>
                <form method="post" novalidate>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label" for="supply_id">Supply Item</label>
                        <select class="form-select" id="supply_id" name="supply_id" required>
                            <option value="">Select supply</option>
                            <?php foreach ($supplies as $supply): ?>
                                <option value="<?php echo (int) $supply['supply_id']; ?>">
                                    <?php echo htmlspecialchars($supply['name']); ?> (Available: <?php echo (int) $supply['quantity']; ?>, ৳<?php echo number_format((float) ($supply['unit_price'] ?? 0), 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="quantity">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                        <div class="form-text" id="priceSummary">Select a supply to view unit price.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="destination">Delivery Location</label>
                        <input type="text" class="form-control" id="destination" name="destination" placeholder="Click on map or type address" required>
                        <input type="hidden" id="latitude" name="latitude">
                        <input type="hidden" id="longitude" name="longitude">

                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="manualCoordsToggle">
                            <label class="form-check-label" for="manualCoordsToggle">Enter coordinates manually</label>
                        </div>

                        <div id="manualCoords" class="row g-2 mt-2" style="display:none;">
                            <div class="col-6">
                                <input type="number" step="0.000001" class="form-control" id="manual_latitude" placeholder="Latitude">
                            </div>
                            <div class="col-6">
                                <input type="number" step="0.000001" class="form-control" id="manual_longitude" placeholder="Longitude">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Location on Map</label>
                        <div id="map" class="map-container"></div>
                        <small class="text-muted">Click on the map to select delivery location</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="notes">Notes for Admin (optional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any specific notes"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-send"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5 mb-3"><i class="bi bi-clock-history"></i> Request History</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Supply</th>
                                <th>Qty</th>
                                <th>Destination</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$requests): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
                                        <p class="text-muted mt-2">No requests submitted yet.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $paymentModals = []; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php
                                    $statusColors = [
                                        'pending' => 'warning',
                                        'approved' => 'primary',
                                        'payment-pending' => 'info',
                                        'in-transit' => 'info',
                                        'delivered' => 'success',
                                        'cancelled' => 'danger'
                                    ];
                                    $statusColor = $statusColors[$request['status']] ?? 'secondary';
                                    
                                    $paymentColors = [
                                        'unpaid' => 'danger',
                                        'pending' => 'warning',
                                        'paid' => 'success',
                                        'refunded' => 'secondary'
                                    ];
                                    $paymentColor = $paymentColors[$request['payment_status'] ?? 'unpaid'] ?? 'secondary';
                                    $canPay = ($request['payment_status'] === 'unpaid' && ($request['payment_amount'] ?? 0) > 0 && in_array($request['status'], ['pending', 'approved', 'payment-pending'], true));
                                    $awaitingVerification = ($request['payment_status'] === 'pending');
                                    ?>
                                    <tr>
                                        <td><span class="badge bg-secondary">#<?php echo (int) $request['request_id']; ?></span></td>
                                        <td><?php echo htmlspecialchars($request['supply_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo (int) ($request['quantity'] ?? 1); ?></td>
                                        <td>
                                            <small><?php echo htmlspecialchars($request['destination'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (($request['payment_amount'] ?? 0) > 0): ?>
                                                <strong>৳<?php echo number_format($request['payment_amount'], 2); ?></strong>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $paymentColor; ?>"><?php echo ucfirst($request['payment_status'] ?? 'unpaid'); ?></span>
                                            <?php if ($awaitingVerification): ?>
                                                <small class="text-muted d-block">Awaiting admin verification</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $statusColor; ?>"><?php echo ucfirst($request['status']); ?></span></td>
                                        <td class="d-flex gap-2 flex-wrap">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo BASE_PATH; ?>/track_delivery.php?id=<?php echo (int) $request['request_id']; ?>" target="_blank">
                                                <i class="bi bi-geo-alt"></i> Track
                                            </a>
                                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#notesModal-<?php echo (int) $request['request_id']; ?>">
                                                <i class="bi bi-chat-dots"></i> Notes
                                            </button>
                                            <?php if ($request['latitude'] && $request['longitude']): ?>
                                                <button class="btn btn-sm btn-outline-info" type="button" onclick="showLocationModal(<?php echo $request['latitude']; ?>, <?php echo $request['longitude']; ?>, '<?php echo htmlspecialchars($request['destination']); ?>')">
                                                    <i class="bi bi-map"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($canPay || $awaitingVerification): ?>
                                                <button class="btn btn-sm btn-success" type="button" data-bs-toggle="modal" data-bs-target="#payModal-<?php echo (int) $request['request_id']; ?>">
                                                    <i class="bi bi-wallet2"></i> <?php echo $awaitingVerification ? 'Update Payment' : 'Pay Now'; ?>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <!-- Notes Modal -->
                                    <div class="modal fade" id="notesModal-<?php echo (int) $request['request_id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Request #<?php echo (int) $request['request_id']; ?> - Notes</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <?php $logs = $logsByRequest[$request['request_id']] ?? []; ?>
                                                    <?php if (empty($logs)): ?>
                                                        <div class="alert alert-info">No notes found for this request.</div>
                                                    <?php else: ?>
                                                        <ul class="list-group">
                                                            <?php foreach ($logs as $log): ?>
                                                                <li class="list-group-item">
                                                                    <small class="text-muted d-block"><?php echo date('M d, Y h:i A', strtotime($log['timestamp'])); ?></small>
                                                                    <div><?php echo nl2br(htmlspecialchars($log['notes'])); ?></div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($canPay || $awaitingVerification): ?>
                                        <?php ob_start(); ?>
                                        <div class="modal fade hospital-pay-modal" id="payModal-<?php echo (int) $request['request_id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="pay">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title"><i class="bi bi-credit-card"></i> Payment for Request #<?php echo (int) $request['request_id']; ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p class="mb-3">Amount Due: <strong>৳<?php echo number_format($request['payment_amount'] ?? 0, 2); ?></strong></p>
                                                            <div class="mb-3">
                                                                <label class="form-label">Payment Method</label>
                                                                <select class="form-select" name="payment_method" required>
                                                                    <option value="">Select method</option>
                                                                    <?php
                                                                    $methods = ['bKash', 'Nagad', 'Rocket', 'Bank Transfer', 'Cash'];
                                                                    foreach ($methods as $method):
                                                                    ?>
                                                                        <option value="<?php echo $method; ?>" <?php echo ($request['payment_method'] === $method) ? 'selected' : ''; ?>><?php echo $method; ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Transaction ID <small class="text-muted">(optional)</small></label>
                                                                <input type="text" class="form-control" name="transaction_id" placeholder="e.g., TRX123456">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes to Admin <small class="text-muted">(optional)</small></label>
                                                                <textarea class="form-control" name="payment_notes" rows="2" placeholder="Add any remark"></textarea>
                                                            </div>
                                                            <div class="alert alert-info">
                                                                <i class="bi bi-info-circle"></i> After submitting, the admin team will verify your payment before dispatching the drone.
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">
                                                                <i class="bi bi-check-circle"></i> Submit Payment
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php $paymentModals[] = ob_get_clean(); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isset($paymentModals) && !empty($paymentModals)) { echo implode('', $paymentModals); } ?>

<!-- Location View Modal -->
<div class="modal fade" id="locationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pin-map"></i> Delivery Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="modalAddress" class="text-muted mb-3"></p>
                <div id="viewMap" class="map-container"></div>
            </div>
        </div>
    </div>
</div>

<style>
.map-container {
    height: 400px;
    width: 100%;
    border-radius: 8px;
    border: 2px solid #dee2e6;
}
</style>

<script>
let map, marker, viewMap, viewMarker;

function showMapError(message) {
    const mapContainer = document.getElementById('map');
    if (mapContainer) {
        mapContainer.innerHTML = `<div class="d-flex align-items-center justify-content-center text-muted" style="height:100%">${message}</div>`;
    }
}

function initMap() {
    const boot = () => {
        if (typeof google === 'undefined' || !google.maps) {
            console.error('Google Maps API failed to load');
            showMapError('Google Maps could not be loaded. Please verify API key configuration.');
            alert('Google Maps could not be loaded. Please check your API key or internet connection.');
            return;
        }

        const mapElement = document.getElementById('map');
        if (!mapElement) {
            console.warn('Map container not found on hospital requests page.');
            return;
        }

        const khulna = { lat: <?php echo DEFAULT_LAT; ?>, lng: <?php echo DEFAULT_LNG; ?> };

        map = new google.maps.Map(mapElement, {
            center: khulna,
            zoom: 13,
            mapTypeControl: false,
            streetViewControl: false
        });

        marker = new google.maps.Marker({
            map: map,
            draggable: true,
            position: khulna
        });

        const geocoder = new google.maps.Geocoder();

        // Click on map to set location
        map.addListener('click', (e) => {
            marker.setPosition(e.latLng);
            updateLocation(e.latLng, geocoder);
        });

        // Drag marker
        marker.addListener('dragend', () => {
            updateLocation(marker.getPosition(), geocoder);
        });

        // Autocomplete for destination field
        const input = document.getElementById('destination');
        if (!input) {
            console.warn('Destination input not found.');
            return;
        }

        const autocomplete = new google.maps.places.Autocomplete(input, {
            bounds: new google.maps.LatLngBounds(
                new google.maps.LatLng(22.7, 89.4),
                new google.maps.LatLng(22.9, 89.6)
            ),
            strictBounds: true
        });

        autocomplete.addListener('place_changed', () => {
            const place = autocomplete.getPlace();
            if (place.geometry) {
                map.setCenter(place.geometry.location);
                marker.setPosition(place.geometry.location);
                document.getElementById('latitude').value = place.geometry.location.lat();
                document.getElementById('longitude').value = place.geometry.location.lng();
                // sync manual inputs if visible
                const manualLat = document.getElementById('manual_latitude');
                const manualLng = document.getElementById('manual_longitude');
                if (manualLat && manualLng) {
                    manualLat.value = place.geometry.location.lat();
                    manualLng.value = place.geometry.location.lng();
                }
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}

function updateLocation(location, geocoder) {
    document.getElementById('latitude').value = location.lat();
    document.getElementById('longitude').value = location.lng();

    // sync manual inputs if visible
    const manualLat = document.getElementById('manual_latitude');
    const manualLng = document.getElementById('manual_longitude');
    if (manualLat && manualLng) {
        manualLat.value = location.lat();
        manualLng.value = location.lng();
    }

    geocoder.geocode({ location: location }, (results, status) => {
        if (status === 'OK' && results[0]) {
            document.getElementById('destination').value = results[0].formatted_address;
        }
    });
}

function showLocationModal(lat, lng, address) {
    document.getElementById('modalAddress').textContent = address;
    
    const modal = new bootstrap.Modal(document.getElementById('locationModal'));
    modal.show();

    setTimeout(() => {
        const location = { lat: lat, lng: lng };
        
        if (!viewMap) {
            viewMap = new google.maps.Map(document.getElementById('viewMap'), {
                center: location,
                zoom: 15
            });
        } else {
            viewMap.setCenter(location);
        }

        if (viewMarker) {
            viewMarker.setMap(null);
        }
        
        viewMarker = new google.maps.Marker({
            map: viewMap,
            position: location,
            title: address
        });
    }, 300);
}

// Global error handler for Google Maps
window.gm_authFailure = function() {
    // Show a non-blocking inline warning so users can still type the address manually
    showMapError('Google Maps authentication failed. Map features are disabled.');

    // Add a visible dismissible alert near the map/form with troubleshooting steps
    const container = document.querySelector('.card-body');
    if (container) {
        const existing = document.getElementById('mapsAuthWarning');
        if (!existing) {
            const alert = document.createElement('div');
            alert.id = 'mapsAuthWarning';
            alert.className = 'alert alert-warning';
            alert.style.whiteSpace = 'pre-line';
            alert.innerHTML = '<strong>Google Maps authentication failed.</strong> Map/autocomplete features are disabled.\n\n' +
                'Possible causes:\n' +
                '- API key is invalid or restricted\n' +
                '- Maps JavaScript API or Places API not enabled\n' +
                '- Billing not enabled on Google Cloud Console\n\n' +
                'You can still type the full address manually. Administrators should check the API key in <code>includes/config.php</code>.';
            container.prepend(alert);
        }
    }

    console.error('Google Maps API Key Error - Check:\n1) API key validity\n2) Maps JavaScript API & Places API enabled\n3) HTTP referrer restrictions allow this origin (e.g. http://localhost/*)\n4) Billing enabled on Google Cloud Console');
};

const supplyPrices = <?php echo json_encode(array_column($supplies, 'unit_price', 'supply_id'), JSON_NUMERIC_CHECK); ?>;

function updatePriceSummary() {
    const summary = document.getElementById('priceSummary');
    const supplySelect = document.getElementById('supply_id');
    const quantityInput = document.getElementById('quantity');

    if (!summary || !supplySelect || !quantityInput) {
        return;
    }

    const supplyId = supplySelect.value;
    const quantity = parseInt(quantityInput.value, 10) || 0;
    const unitPrice = supplyPrices[supplyId] ?? 0;

    if (!supplyId || unitPrice <= 0) {
        summary.textContent = 'Select a supply to view unit price.';
        return;
    }

    const total = unitPrice * quantity;
    summary.textContent = `Unit price: ৳${unitPrice.toLocaleString(undefined, { minimumFractionDigits: 2 })} • Estimated total: ৳${total.toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.hospital-pay-modal').forEach((modal) => {
        if (!modal.dataset.movedToBody) {
            document.body.appendChild(modal);
            modal.dataset.movedToBody = 'true';
        }
    });

    const locationModalEl = document.getElementById('locationModal');
    if (locationModalEl && !locationModalEl.dataset.movedToBody) {
        document.body.appendChild(locationModalEl);
        locationModalEl.dataset.movedToBody = 'true';
    }

    // Move any other modals (e.g., payment/notes modals created inside the table) to the body
    document.querySelectorAll('.modal').forEach((m) => {
        if (!m.dataset.movedToBody) {
            document.body.appendChild(m);
            m.dataset.movedToBody = 'true';
        }
    });

    const supplySelect = document.getElementById('supply_id');
    const quantityInput = document.getElementById('quantity');

    if (!supplySelect || !quantityInput) {
        return;
    }

    supplySelect.addEventListener('change', updatePriceSummary);
    quantityInput.addEventListener('input', updatePriceSummary);
    updatePriceSummary();

    // Manual coords toggle and synchronization
    const manualToggle = document.getElementById('manualCoordsToggle');
    const manualBox = document.getElementById('manualCoords');
    const manualLat = document.getElementById('manual_latitude');
    const manualLng = document.getElementById('manual_longitude');
    const hiddenLat = document.getElementById('latitude');
    const hiddenLng = document.getElementById('longitude');

    if (manualToggle && manualBox && manualLat && manualLng) {
        manualToggle.addEventListener('change', () => {
            if (manualToggle.checked) {
                manualBox.style.display = 'flex';
            } else {
                manualBox.style.display = 'none';
                // clear manual values and hidden fields to avoid stale coords
                manualLat.value = '';
                manualLng.value = '';
                if (hiddenLat) hiddenLat.value = '';
                if (hiddenLng) hiddenLng.value = '';
            }
        });

        // when manual inputs change, copy to hidden fields and move marker if available
        const applyManualCoords = () => {
            const latVal = parseFloat(manualLat.value);
            const lngVal = parseFloat(manualLng.value);
            if (!isNaN(latVal) && !isNaN(lngVal)) {
                hiddenLat.value = latVal;
                hiddenLng.value = lngVal;
                if (typeof marker !== 'undefined' && marker && typeof map !== 'undefined' && map) {
                    const pos = new google.maps.LatLng(latVal, lngVal);
                    marker.setPosition(pos);
                    map.setCenter(pos);
                }
            }
        };

        manualLat.addEventListener('input', applyManualCoords);
        manualLng.addEventListener('input', applyManualCoords);

        // Ensure manual coords are copied to hidden fields before submit
        const requestForm = document.querySelector('form[method="post"]');
        if (requestForm) {
            requestForm.addEventListener('submit', (ev) => {
                if (manualToggle.checked) {
                    applyManualCoords();
                    // if destination text is empty, fill a readable fallback so server logs include something
                    const destEl = document.getElementById('destination');
                    if (destEl && (!destEl.value || destEl.value.trim() === '')) {
                        const hLat = hiddenLat ? hiddenLat.value : '';
                        const hLng = hiddenLng ? hiddenLng.value : '';
                        if (hLat && hLng) {
                            destEl.value = hLat + ', ' + hLng;
                        }
                    }
                }
            });
        }
    }
});
</script>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places&callback=initMap" async defer></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
