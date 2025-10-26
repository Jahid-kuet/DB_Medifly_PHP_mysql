<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['operator']);

$userId = currentUserId();
$allowedStatuses = ['in-transit', 'delivered'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'status';
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if (!$requestId) {
        setFlash('Request reference missing.', 'danger');
        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    $stmt = $conn->prepare('SELECT status, drone_id, payment_status FROM DeliveryRequests WHERE request_id = ? AND operator_id = ?');
    $stmt->bind_param('ii', $requestId, $userId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();

    if (!$current) {
        setFlash('Request not found or not assigned to you.', 'danger');
        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    if ($action === 'location') {
        $latitude = isset($_POST['latitude']) ? (float) $_POST['latitude'] : null;
        $longitude = isset($_POST['longitude']) ? (float) $_POST['longitude'] : null;
        $altitude = isset($_POST['altitude']) && $_POST['altitude'] !== '' ? (int) $_POST['altitude'] : null;
        $speed = isset($_POST['speed']) && $_POST['speed'] !== '' ? (float) $_POST['speed'] : null;
        $note = trim($_POST['location_note'] ?? '');

        if ($latitude === null || $longitude === null) {
            setFlash('Latitude and longitude are required for tracking updates.', 'danger');
            header('Location: ' . BASE_PATH . '/operator/requests.php');
            exit;
        }

        try {
            $stmt = $conn->prepare('INSERT INTO DeliveryTracking (request_id, latitude, longitude, altitude, speed) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iddid', $requestId, $latitude, $longitude, $altitude, $speed);
            $stmt->execute();

            $timestamp = date('Y-m-d H:i:s');
            $logNote = sprintf('Operator reported drone position at %.5f, %.5f', $latitude, $longitude);
            if ($note !== '') {
                $logNote .= ' | Note: ' . $note;
            }
            $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
            $stmt->bind_param('iss', $requestId, $timestamp, $logNote);
            $stmt->execute();

            setFlash('Location update submitted.');
        } catch (Exception $e) {
            setFlash('Failed to submit location update: ' . $e->getMessage(), 'danger');
        }

        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (!in_array($status, $allowedStatuses, true)) {
        setFlash('Invalid update payload.', 'danger');
        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    // Check if payment is confirmed before allowing delivery status changes
    if ($current['payment_status'] !== 'paid') {
        setFlash('Cannot update delivery status until payment is confirmed.', 'danger');
        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    $oldStatus = $current['status'];
    $droneId = $current['drone_id'] ? (int) $current['drone_id'] : null;

    if ($status === 'in-transit' && $oldStatus === 'delivered') {
        setFlash('Delivered requests cannot return to in-transit.', 'danger');
        header('Location: ' . BASE_PATH . '/operator/requests.php');
        exit;
    }

    $stmt = $conn->prepare('UPDATE DeliveryRequests SET status = ? WHERE request_id = ?');
    $stmt->bind_param('si', $status, $requestId);
    $stmt->execute();

    if ($status === 'delivered' && $droneId) {
        $stmt = $conn->prepare("UPDATE Drones SET status = 'available' WHERE drone_id = ?");
        $stmt->bind_param('i', $droneId);
        $stmt->execute();
    } elseif ($status === 'in-transit' && $droneId) {
        $stmt = $conn->prepare("UPDATE Drones SET status = 'assigned' WHERE drone_id = ?");
        $stmt->bind_param('i', $droneId);
        $stmt->execute();
    }

    $logParts = ["Operator updated status from {$oldStatus} to {$status}"];
    if ($note !== '') {
        $logParts[] = "Note: {$note}";
    }
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = implode('; ', $logParts);
    $stmt = $conn->prepare('INSERT INTO DeliveryLogs (request_id, timestamp, notes) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $requestId, $timestamp, $logEntry);
    $stmt->execute();

    setFlash('Delivery request updated.');
    header('Location: ' . BASE_PATH . '/operator/requests.php');
    exit;
}

$requests = [];
$stmt = $conn->prepare("SELECT dr.request_id, dr.destination, dr.status, dr.payment_status, dr.payment_amount, dr.quantity, dr.latitude, dr.longitude, s.name AS supply_name, d.model AS drone_model, u.name AS hospital_name, h.name AS hospital_facility
    FROM DeliveryRequests dr
    LEFT JOIN Supplies s ON dr.supply_id = s.supply_id
    LEFT JOIN Drones d ON dr.drone_id = d.drone_id
    LEFT JOIN Users u ON dr.user_id = u.user_id
    LEFT JOIN Hospitals h ON u.hospital_id = h.hospital_id
    WHERE dr.operator_id = ?
    ORDER BY 
        CASE dr.status 
            WHEN 'approved' THEN 1
            WHEN 'in-transit' THEN 2
            WHEN 'delivered' THEN 3
        END,
        dr.request_id DESC");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$pageTitle = 'Assigned Deliveries | ' . APP_NAME;
include __DIR__ . '/../includes/header.php';
?>
<div class="row mb-3">
    <div class="col">
        <h1 class="text-gradient"><i class="bi bi-truck"></i> Assigned Deliveries</h1>
        <p class="text-muted">Manage your assigned delivery tasks</p>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Hospital</th>
                        <th>Supply</th>
                        <th>Qty</th>
                        <th>Destination</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Drone</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$requests): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5">
                                <i class="bi bi-inbox" style="font-size: 3rem; opacity: 0.3;"></i>
                                <p class="text-muted mt-2">No deliveries assigned yet.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $locationModals = []; ?>
                        <?php foreach ($requests as $request): ?>
                            <?php
                            $statusColors = [
                                'approved' => 'primary',
                                'in-transit' => 'info',
                                'delivered' => 'success'
                            ];
                            $statusColor = $statusColors[$request['status']] ?? 'secondary';
                            
                            $paymentColors = [
                                'unpaid' => 'danger',
                                'pending' => 'warning',
                                'paid' => 'success'
                            ];
                            $paymentColor = $paymentColors[$request['payment_status'] ?? 'unpaid'] ?? 'secondary';
                            $canUpdate = ($request['payment_status'] === 'paid');
                            $allowLocationUpdate = ($request['status'] === 'in-transit');
                            ?>
                            <tr class="<?php echo !$canUpdate ? 'table-secondary' : ''; ?>">
                                <td><span class="badge bg-secondary">#<?php echo (int) $request['request_id']; ?></span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($request['hospital_facility'] ?? 'N/A'); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($request['hospital_name'] ?? ''); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($request['supply_name'] ?? 'N/A'); ?></td>
                                <td><?php echo (int) ($request['quantity'] ?? 1); ?></td>
                                <td><small><?php echo htmlspecialchars($request['destination'] ?? ''); ?></small></td>
                                <td><strong>৳<?php echo number_format($request['payment_amount'] ?? 0, 2); ?></strong></td>
                                <td><span class="badge bg-<?php echo $paymentColor; ?>"><?php echo ucfirst($request['payment_status'] ?? 'unpaid'); ?></span></td>
                                <td><span class="badge bg-<?php echo $statusColor; ?>"><?php echo ucfirst($request['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($request['drone_model'] ?? '-'); ?></td>
                                <td class="d-flex flex-wrap gap-2">
                                    <?php if ($request['latitude'] && $request['longitude']): ?>
                                        <button class="btn btn-sm btn-info" type="button" onclick="showLocationModal(<?php echo $request['latitude']; ?>, <?php echo $request['longitude']; ?>, '<?php echo htmlspecialchars($request['destination']); ?>')">
                                            <i class="bi bi-geo-alt"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo BASE_PATH; ?>/track_delivery.php?id=<?php echo (int) $request['request_id']; ?>">
                                        <i class="bi bi-map"></i> Track
                                    </a>
                                    <?php if ($allowLocationUpdate): ?>
                                        <?php ob_start(); ?>
                                        <div class="modal fade" id="locationModal-<?php echo (int) $request['request_id']; ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="location">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title"><i class="bi bi-broadcast"></i> Update Drone Location</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="row g-3">
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Latitude</label>
                                                                    <input type="number" step="0.000001" class="form-control" name="latitude" required placeholder="22.845600">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Longitude</label>
                                                                    <input type="number" step="0.000001" class="form-control" name="longitude" required placeholder="89.540300">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Altitude (m)</label>
                                                                    <input type="number" class="form-control" name="altitude" min="0" placeholder="Optional">
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <label class="form-label">Speed (km/h)</label>
                                                                    <input type="number" step="0.1" class="form-control" name="speed" min="0" placeholder="Optional">
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label">Note to Admin (optional)</label>
                                                                    <textarea class="form-control" name="location_note" rows="2" placeholder="e.g., Entering hospital airspace"></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="alert alert-secondary mt-3">
                                                                <i class="bi bi-lightning-charge"></i> Tip: Use the drone controller GPS coordinates for accuracy.
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success">
                                                                <i class="bi bi-send"></i> Submit Update
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        <?php $locationModals[] = ob_get_clean(); ?>
                                        <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#locationModal-<?php echo (int) $request['request_id']; ?>">
                                            <i class="bi bi-broadcast"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canUpdate && $request['status'] !== 'delivered'): ?>
                                        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#update-<?php echo (int) $request['request_id']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php elseif (!$canUpdate): ?>
                                        <span class="badge bg-warning text-dark align-self-center">Awaiting Payment</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($canUpdate && $request['status'] !== 'delivered'): ?>
                            <tr class="collapse" id="update-<?php echo (int) $request['request_id']; ?>">
                                <td colspan="10">
                                    <form method="post" class="row g-3 border rounded p-3 bg-light">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                        <div class="col-md-4">
                                            <label class="form-label">Status</label>
                                            <select class="form-select" name="status" required>
                                                <?php foreach ($allowedStatuses as $state): ?>
                                                    <option value="<?php echo $state; ?>" <?php echo ($request['status'] === $state) ? 'selected' : ''; ?>><?php echo ucfirst($state); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Note (optional)</label>
                                            <input type="text" class="form-control" name="note" placeholder="Add a quick delivery note">
                                        </div>
                                        <div class="col-12 text-end">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> Update Status
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if (isset($locationModals) && !empty($locationModals)) { echo implode('', $locationModals); } ?>

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

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>"></script>
<script>
let viewMap, viewMarker;

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
            title: address,
            animation: google.maps.Animation.DROP
        });
    }, 300);
}
</script>

<script>
// Move any modals created inside the table to document.body to avoid backdrop/focus issues
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.modal').forEach(function (m) {
        if (!m.dataset.movedToBody) {
            document.body.appendChild(m);
            m.dataset.movedToBody = 'true';
        }
    });

    // Auto-focus the first input (latitude) when any location modal is shown
    document.querySelectorAll('[id^="locationModal-"]').forEach(function (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () {
            const lat = modalEl.querySelector('input[name="latitude"]');
            if (lat) lat.focus();
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
