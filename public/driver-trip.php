<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin.php';
require_once __DIR__ . '/../app/driver-routes.php';
$driver = require_driver();
$driverId = (int) $driver['id'];
$routeAssignment = driver_route_assignment($driverId);
$routeSaved = $routeAssignment !== null
    && !empty($routeAssignment['route_key'])
    && (int) ($routeAssignment['route_revision'] ?? 0) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'start') {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM drivers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $driverId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Driver account was not found.');
            }

            $activeTrip = $pdo->prepare(
                "SELECT id FROM trips WHERE driver_id = :driver_id AND trip_status = 'active' LIMIT 1"
            );
            $activeTrip->execute(['driver_id' => $driverId]);
            if ($activeTrip->fetchColumn() !== false) {
                $pdo->rollBack();
                $_SESSION['driver_trip_flash'] = 'You already have an active trip.';
                redirect('driver-trip.php');
            }

            if (!$routeSaved) {
                $pdo->rollBack();
                $_SESSION['driver_trip_flash'] = 'Save your route before starting a trip.';
                redirect('driver-route.php');
            }

            $vehicle = $pdo->prepare(
                "SELECT j.id AS jeepney_profile_id, r.id AS route_id
                 FROM jeepney_profiles j
                 INNER JOIN jeepney_admin_status js ON js.jeepney_profile_id = j.id
                 INNER JOIN driver_pickup_assignments dpa
                    ON dpa.driver_id = j.driver_id
                   AND dpa.route_key <> ''
                   AND dpa.route_revision > 0
                 INNER JOIN routes r ON BINARY r.route_name = BINARY dpa.route_name AND r.is_active = 1
                 INNER JOIN drivers d ON d.id = j.driver_id AND d.verification_status = 'verified'
                 LEFT JOIN driver_admin_status ds ON ds.driver_id = d.id
                 WHERE j.driver_id = :driver_id
                   AND j.verification_status = 'verified'
                   AND js.operational_status = 'active'
                   AND COALESCE(ds.account_status, 'active') = 'active'
                 LIMIT 1"
            );
            $vehicle->execute(['driver_id' => $driverId]);
            $assignment = $vehicle->fetch();
            if ($assignment === false) {
                $pdo->rollBack();
                $_SESSION['driver_trip_flash'] = 'An approved, active jeepney and route assignment are required before you can start a trip.';
                redirect('driver-trip.php');
            }

            $create = $pdo->prepare(
                "INSERT INTO trips (driver_id, jeepney_profile_id, route_id, trip_status)
                 VALUES (:driver_id, :jeepney_profile_id, :route_id, 'active')"
            );
            $create->execute([
                'driver_id' => $driverId,
                'jeepney_profile_id' => $assignment['jeepney_profile_id'],
                'route_id' => $assignment['route_id'],
            ]);
            $pdo->prepare("INSERT INTO driver_trip_status (driver_id, status) VALUES (?, 'in_transit') ON DUPLICATE KEY UPDATE status = 'in_transit', revision = revision + 1")->execute([$driverId]);
            $pdo->prepare("UPDATE driver_pickup_assignments SET availability = 'on_trip', revision = revision + 1 WHERE driver_id = ?")->execute([$driverId]);
            $pdo->commit();
            $_SESSION['driver_trip_flash'] = 'Trip started. Your browser will request location permission to share your position with administrators while this trip is active.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        redirect('driver-trip.php');
    }

    if ($action === 'finish' || $action === 'cancel') {
        $tripId = validated_positive_id($_POST['trip_id'] ?? null);
        if ($tripId === null) {
            http_response_code(400);
            exit('Invalid trip.');
        }
        $finish = $pdo->prepare(
            "UPDATE trips SET trip_status = :status, ended_at = CURRENT_TIMESTAMP
             WHERE id = :trip_id AND driver_id = :driver_id AND trip_status = 'active'"
        );
        $finish->execute([
            'status' => $action === 'finish' ? 'completed' : 'cancelled',
            'trip_id' => $tripId,
            'driver_id' => $driverId,
        ]);
        $changed = $finish->rowCount() === 1;
        if ($changed) {
            $pdo->prepare("UPDATE driver_trip_status SET status = 'available', revision = revision + 1 WHERE driver_id = ?")->execute([$driverId]);
            $pdo->prepare("UPDATE driver_pickup_assignments SET availability = 'available', revision = revision + 1 WHERE driver_id = ?")->execute([$driverId]);
        }
        $_SESSION['driver_trip_flash'] = $changed
            ? ($action === 'finish' ? 'Trip completed.' : 'Trip cancelled.')
            : 'No active trip was found.';
        redirect('driver-trip.php');
    }

    if ($action === 'location') {
        header('Content-Type: application/json; charset=UTF-8');
        $tripId = validated_positive_id($_POST['trip_id'] ?? null);
        $latitude = validated_coordinate($_POST['latitude'] ?? null, -90, 90);
        $longitude = validated_coordinate($_POST['longitude'] ?? null, -180, 180);
        $accuracy = validated_coordinate($_POST['accuracy'] ?? null, 0, 100000);
        if ($tripId === null || $latitude === null || $longitude === null) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid location update.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $active = $pdo->prepare(
                "SELECT id FROM trips
                 WHERE id = :trip_id AND driver_id = :driver_id AND trip_status = 'active'
                 FOR UPDATE"
            );
            $active->execute(['trip_id' => $tripId, 'driver_id' => $driverId]);
            if ($active->fetchColumn() === false) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['ok' => false, 'message' => 'This trip is no longer active.']);
                exit;
            }
            $recentCount = $pdo->prepare(
                'SELECT COUNT(*) FROM trip_locations
                 WHERE trip_id = :trip_id
                   AND recorded_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 SECOND)'
            );
            $recentCount->execute(['trip_id' => $tripId]);
            if ((int) $recentCount->fetchColumn() > 0) {
                $pdo->rollBack();
                http_response_code(429);
                echo json_encode(['ok' => false, 'message' => 'A location update was received too recently.']);
                exit;
            }
            $insert = $pdo->prepare(
                'INSERT INTO trip_locations (trip_id, latitude, longitude, accuracy_m)
                 VALUES (:trip_id, :latitude, :longitude, :accuracy)'
            );
            $insert->execute([
                'trip_id' => $tripId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    exit('Invalid trip action.');
}

if (!$routeSaved) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $cancelTrips = $pdo->prepare(
            "UPDATE trips
             SET trip_status = 'cancelled', ended_at = CURRENT_TIMESTAMP
             WHERE driver_id = ? AND trip_status = 'active'"
        );
        $cancelTrips->execute([$driverId]);
        $pdo->prepare(
            "UPDATE driver_trip_status
             SET status = 'available', revision = revision + 1
             WHERE driver_id = ? AND status <> 'available'"
        )->execute([$driverId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

$tripQuery = db()->prepare(
    "SELECT t.id, t.started_at, dpa.route_name, j.plate_number
     FROM trips t
     INNER JOIN jeepney_profiles j ON j.id = t.jeepney_profile_id
     INNER JOIN driver_pickup_assignments dpa
        ON dpa.driver_id = t.driver_id
       AND dpa.route_key <> ''
       AND dpa.route_revision > 0
     WHERE t.driver_id = :driver_id AND t.trip_status = 'active'
     ORDER BY t.started_at DESC LIMIT 1"
);
$tripQuery->execute(['driver_id' => $driverId]);
$activeTrip = $tripQuery->fetch();
$driverStatusQuery = db()->prepare(
    'SELECT verification_status FROM drivers WHERE id = :driver_id LIMIT 1'
);
$driverStatusQuery->execute(['driver_id' => $driverId]);
$driverVerified = $driverStatusQuery->fetchColumn() === 'verified';
$flash = (string) ($_SESSION['driver_trip_flash'] ?? '');
unset($_SESSION['driver_trip_flash']);

page_start('Trip tracking', 'driver-trip');
?>
<section class="admin-dashboard">
  <div class="admin-page-heading">
    <div>
      <span class="eyebrow"><span class="eyebrow-dot"></span> DRIVER TRIP</span>
      <h1>Trip tracking</h1>
      <p>Start location sharing only when you are ready to begin an authorized route trip.</p>
    </div>
    <span class="admin-access-badge"><span aria-hidden="true">◈</span> Location sharing is trip-based</span>
  </div>
  <?php if ($flash !== ''): ?><p class="message message-success admin-flash" role="status"><?= h($flash) ?></p><?php endif; ?>
  <section class="admin-queue">
    <div class="admin-queue-heading">
      <div>
        <h2><?= $activeTrip === false ? 'Ready to start?' : 'Trip in progress' ?></h2>
        <p>Your precise location is visible to authorized administrators while this trip remains active.</p>
      </div>
    </div>
    <div class="admin-queue-content driver-trip-content">
      <?php if (!$driverVerified): ?>
        <p class="driver-trip-notice">Driver verification is required before starting a trip.</p>
      <?php elseif ($activeTrip !== false): ?>
        <div class="driver-trip-details">
          <div class="driver-trip-detail">
            <span>Route</span>
            <strong><?= h((string) $activeTrip['route_name']) ?></strong>
          </div>
          <div class="driver-trip-detail">
            <span>Jeepney</span>
            <strong><?= h((string) ($activeTrip['plate_number'] ?? 'Plate pending')) ?></strong>
          </div>
          <div class="driver-trip-detail">
            <span>Started</span>
            <strong><?= h(date('M j, Y  -  g:i A', strtotime((string) $activeTrip['started_at']))) ?></strong>
          </div>
        </div>
        <p class="driver-trip-gps-status" id="gps-status" role="status" aria-live="polite">
          <span class="driver-trip-gps-dot" aria-hidden="true"></span>
          <span>Requesting browser location permission…</span>
        </p>
        <form class="driver-trip-actions" method="post" action="driver-trip.php">
          <?= csrf_field() ?>
          <input type="hidden" name="trip_id" value="<?= h((string) $activeTrip['id']) ?>">
          <button class="button button-outline" name="action" value="finish">Complete trip</button>
          <button class="button button-reject" name="action" value="cancel">Cancel trip</button>
        </form>
        <script>
        (() => {
          const status = document.getElementById('gps-status');
          const tripId = <?= json_encode((int) $activeTrip['id']) ?>;
          const token = <?= json_encode(csrf_token()) ?>;
          let lastSent = 0;
          if (!navigator.geolocation) {
            status.lastElementChild.textContent = 'This browser does not support location sharing.';
            status.classList.add('is-warning');
            return;
          }
          let watchId = null;
          watchId = navigator.geolocation.watchPosition(async (position) => {
            if (Date.now() - lastSent < 15000) return;
            lastSent = Date.now();
            const body = new URLSearchParams({
              csrf_token: token,
              action: 'location',
              trip_id: String(tripId),
              latitude: String(position.coords.latitude),
              longitude: String(position.coords.longitude),
              accuracy: String(position.coords.accuracy)
            });
            try {
              const response = await fetch('driver-trip.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body
              });
              const result = await response.json();
              if (!result.ok) {
                if (response.status === 409 && watchId !== null) {
                  navigator.geolocation.clearWatch(watchId);
                }
                status.lastElementChild.textContent = result.message;
                status.classList.add('is-warning');
                return;
              }
              status.lastElementChild.textContent = `Location shared at ${new Date().toLocaleTimeString()}.`;
              status.classList.remove('is-warning');
              status.classList.add('is-sharing');
            } catch {
              status.lastElementChild.textContent = 'Location could not be sent. Check your connection.';
              status.classList.add('is-warning');
            }
          }, () => {
            status.lastElementChild.textContent = 'Location permission was not granted; the trip is active but no position is being shared.';
            status.classList.add('is-warning');
          }, {enableHighAccuracy: true, maximumAge: 10000, timeout: 20000});
        })();
        </script>
      <?php elseif (!$routeSaved): ?>
        <p class="driver-trip-notice">Save your route before starting a trip.</p>
        <div class="driver-trip-actions">
          <button class="button button-primary" type="button" disabled>Start trip &amp; share location</button>
          <a class="button button-outline" href="map.php#map-pickup-area">Save route</a>
        </div>
      <?php else: ?>
        <p class="driver-trip-notice">Starting a trip activates location sharing in this browser. You can stop sharing by completing or cancelling the trip. A secure HTTPS connection is required on most mobile browsers.</p>
        <form class="driver-trip-actions" method="post" action="driver-trip.php">
          <?= csrf_field() ?>
          <button class="button button-primary" name="action" value="start" <?= $driverVerified ? '' : 'disabled' ?>>Start trip & share location</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</section>
<?php page_end(); ?>
