<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();
require_once __DIR__ . '/../app/seats.php';
require_once __DIR__ . '/../app/trips.php';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isSeatAjax = $isPost && (
    ($_POST['ajax'] ?? '') === '1'
    || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
);
$action = $_POST['action'] ?? 'seats';
$tripError = '';
$tripFlash = $_SESSION['trip_flash'] ?? '';
unset($_SESSION['trip_flash']);
$tripOpen = ($isPost && $action === 'trip') || $tripFlash !== '';
if ($isPost) {
    if ($isSeatAjax && !csrf_value_is_valid($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'Your driver session expired. Reload the dashboard and try again.']);
        exit;
    }
    verify_csrf_token();
    if (!in_array($action, ['seats', 'trip'], true)) {
        http_response_code(400);
        exit('Unknown dashboard action.');
    }
    if ($action === 'trip') {
        [$code, $message] = save_driver_trip((int) $driver['id'], $_POST['trip_status'] ?? null, $_POST['trip_revision'] ?? null);
        if ($code === 200) {
            $_SESSION['trip_flash'] = $message;
            redirect('dashboard.php#current-trip-status');
        }
        http_response_code($code);
        $tripError = $message;
    }
}
$seatError = '';
$seatFlash = $_SESSION['seat_flash'] ?? '';
unset($_SESSION['seat_flash']);
$seatsOpen = !$tripOpen || ($isPost && $action === 'seats') || $seatFlash !== '';
if ($isPost && $action === 'seats') {
    verify_csrf_token();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $currentSeats = driver_seats((int) $driver['id'], true);
        $occupiedInput = $_POST['occupied'] ?? null;
        $revisionInput = $_POST['revision'] ?? null;
        $capacityInput = $_POST['capacity'] ?? null;
        if (!can_update_seats($currentSeats)) {
            http_response_code(403);
            $seatError = 'A verified driver account and approved jeepney capacity are required.';
        } elseif (!is_string($occupiedInput) || !ctype_digit($occupiedInput) || strlen($occupiedInput) > 10 || (int) $occupiedInput > (int) $currentSeats['capacity']) {
            http_response_code(422);
            $seatError = 'Occupied seats must be a whole number from 0 to your jeepney capacity.';
        } elseif (!$isSeatAjax && (!is_string($revisionInput) || !ctype_digit($revisionInput) || (int) $revisionInput !== (int) ($currentSeats['revision'] ?? 0)
            || !is_string($capacityInput) || !ctype_digit($capacityInput) || (int) $capacityInput !== (int) $currentSeats['capacity'])) {
            http_response_code(409);
            $seatError = 'Seat availability or capacity changed in another window. The latest values are shown below; check them and save again.';
        } else {
            $save = $pdo->prepare('INSERT INTO driver_seat_availability (driver_id, occupied, capacity) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE occupied = VALUES(occupied), capacity = VALUES(capacity), revision = revision + 1, updated_at = CURRENT_TIMESTAMP');
            $save->execute([(int) $driver['id'], (int) $occupiedInput, (int) $currentSeats['capacity']]);
        }
        if ($seatError !== '') {
            $pdo->rollBack();
            if ($isSeatAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => $seatError, 'revision' => (int) ($currentSeats['revision'] ?? 0)]);
                exit;
            }
        } else {
            $pdo->commit();
            if ($isSeatAjax) {
                $savedSeats = driver_seats((int) $driver['id']);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => true,
                    'message' => 'Seat availability updated.',
                    'occupied' => (int) ($savedSeats['occupied'] ?? $occupiedInput),
                    'capacity' => (int) ($savedSeats['capacity'] ?? $capacityInput),
                    'revision' => (int) ($savedSeats['revision'] ?? $revisionInput),
                ]);
                exit;
            }
            $_SESSION['seat_flash'] = 'Seat availability saved.';
            redirect('dashboard.php#available-seats');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log((string) $exception);
        http_response_code(500);
        $seatError = 'Seat availability could not be saved. Please try again.';
        if ($isSeatAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $seatError]);
            exit;
        }
    }
}
$seats = driver_seats((int) $driver['id']);
$seatEnabled = can_update_seats($seats);
$seatCapacity = $seatEnabled ? (int) $seats['capacity'] : 0;
$seatOccupied = $seatEnabled ? min($seatCapacity, (int) ($seats['occupied'] ?? 0)) : 0;
$seatStatus = $seatEnabled ? seat_status($seatOccupied, $seatCapacity) : null;
$seatCurrent = $seatEnabled && $seats['updated_at'] !== null && (int) $seats['reported_capacity'] === $seatCapacity;
$trip = driver_trip((int) $driver['id']);
$tripOptions = trip_options();
$tripStatus = $trip['status'] ?? 'unset';
$tripLabel = $tripOptions[$tripStatus]['label'] ?? 'Not set';

page_start('Driver dashboard', 'dashboard');
?>
<section class="dashboard-wrap">
  <div class="dashboard-heading">
    <h1>Sa kalsada, disiplina ang bida! Ingat!</h1>
    <p>Manage your jeepney, check your route, and keep your profile up to date.</p>
  </div>
  <nav class="dashboard-navigation" aria-label="Seat availability">
    <details class="dashboard-dropdown seats-dropdown" id="available-seats" <?= $seatsOpen ? 'open' : '' ?>>
      <summary><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#seat"></use></svg><span>Available seats<?php if ($seatEnabled): ?><small class="seat-summary seat-color-<?= $seatCurrent ? $seatStatus['color'] : 'unknown' ?>" id="seat-summary"><?= $seatCurrent ? $seatStatus['available'] . ' / ' . $seatCapacity . ' available' : 'Set availability' ?></small><?php endif; ?></span><svg class="ui-icon dropdown-chevron" aria-hidden="true"><use href="assets/icons.svg#chevron-down"></use></svg></summary>
      <div class="dashboard-dropdown-content">
        <?php if ($seatError !== ''): ?><p class="message message-error" role="alert"><?= h($seatError) ?></p><?php endif; ?>
        <?php if ($seatFlash !== ''): ?><p class="seat-save-message" role="status"><?= h($seatFlash) ?></p><?php endif; ?>
        <?php if (!$seatEnabled): ?>
          <p><?= $seats && $seats['verification_status'] === 'verified' ? 'Add your jeepney and have its passenger capacity approved before updating seats.' : 'Seat updates become available after your driver account and jeepney are approved.' ?></p>
          <a href="jeepney-profile.php">View my jeepney</a>
        <?php else: ?>
          <form method="post" action="dashboard.php#available-seats" id="seat-form" data-seat-endpoint="api/seat-availability.php" data-capacity="<?= $seatCapacity ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="seats">
            <input type="hidden" name="capacity" value="<?= $seatCapacity ?>">
            <input type="hidden" name="revision" value="<?= (int) ($seats['revision'] ?? 0) ?>">
            <div class="seat-readout seat-color-<?= $seatStatus['color'] ?>" id="seat-readout" aria-live="polite" aria-atomic="true">
              <span class="seat-state-label" id="seat-state-label"><?= h($seatStatus['label']) ?></span>
              <strong><span id="seat-available"><?= $seatStatus['available'] ?></span><small> / <?= $seatCapacity ?> seats available</small></strong>
              <span id="seat-available-percent"><?= $seatStatus['percentAvailable'] ?>% available</span>
              <div class="seat-meter" role="meter" aria-label="Occupied seats" aria-valuemin="0" aria-valuemax="<?= $seatCapacity ?>" aria-valuenow="<?= $seatOccupied ?>" id="seat-meter"><span style="width:<?= $seatStatus['percentOccupied'] ?>%"></span></div>
              <span class="seat-occupied-note" id="seat-occupied-note"><?= $seatOccupied ?> occupied  -  <?= $seatStatus['percentOccupied'] ?>% occupied</span>
            </div>
            <div class="seat-controls">
            <input id="seat-occupied" name="occupied" type="hidden" value="<?= $seatOccupied ?>">
            <fieldset class="seat-availability-options" aria-describedby="seat-help">
              <legend>Set available seats</legend>
              <div class="seat-percentage-options">
                <?php foreach ([100, 75, 50, 25, 0] as $availablePercent): ?>
                  <button type="button" class="seat-percentage-option" data-available-percent="<?= $availablePercent ?>" aria-pressed="false"><?= $availablePercent ?>% <span>available</span></button>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <p id="seat-help">Choose the percentage of seats currently available. Your selection updates availability automatically.</p>
            <p id="seat-update-status" role="status"><?= $seatCurrent ? 'Choose an availability percentage to update seats.' : 'Set the current availability percentage.' ?></p>
            <div class="seat-color-guide" aria-label="Occupancy color guide"><span class="seat-color-green">0–49% occupied</span><span class="seat-color-yellow">50–69%</span><span class="seat-color-orange">70–89%</span><span class="seat-color-red">90–100%</span></div>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </details>
  </nav>
  <?php if ($driver['verification_status'] === 'pending_verification'): ?>
    <div class="application-card status-pending">
      <div class="application-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#clock"></use></svg></div>
      <div class="application-copy">
        <span class="form-kicker">APPLICATION STATUS</span>
        <h2>Under review</h2>
        <p>Your application is awaiting driver validation. You can check back here for updates.</p>
      </div>
      <span class="status-pill"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
    </div>
    <div class="next-step-card">
      <span class="next-step-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#info"></use></svg></span>
      <div><h2>What happens next?</h2><p>Your details will be reviewed. Access to driver services remains restricted until your account is verified.</p></div>
    </div>
  <?php elseif ($driver['verification_status'] === 'rejected'): ?>
    <div class="application-card status-rejected">
      <div class="application-icon" aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#alert"></use></svg></div>
      <div class="application-copy">
        <span class="form-kicker">APPLICATION STATUS</span>
        <h2>More information needed</h2>
        <p>Your application was not approved. Please contact the service team for next steps.</p>
      </div>
      <span class="status-pill"><?= h(str_replace('_', ' ', (string) $driver['verification_status'])) ?></span>
    </div>
  <?php endif; ?>
  <div class="dashboard-footer-note"><span aria-hidden="true"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#shield"></use></svg></span> Your account information is private and protected.</div>
</section>
<script src="assets/dashboard-panels.js?v=<?= h((string) filemtime(__DIR__ . '/assets/dashboard-panels.js')) ?>" defer></script>
<script src="assets/seats.js?v=<?= h((string) filemtime(__DIR__ . '/assets/seats.js')) ?>" defer></script>
<script src="assets/trips.js?v=<?= h((string) filemtime(__DIR__ . '/assets/trips.js')) ?>" defer></script>
<?php page_end(); ?>
