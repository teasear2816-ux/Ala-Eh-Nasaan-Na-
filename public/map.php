<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/seats.php';
require_once __DIR__ . '/../app/pickup-areas.php';
require_once __DIR__ . '/../app/driver-routes.php';
$mapDriver = current_driver();
$routeSeatColor = 'unknown';
if ($mapDriver !== null) {
    $mapDriverSeats = driver_seats((int) $mapDriver['id']);
    if (can_update_seats($mapDriverSeats)
        && $mapDriverSeats['updated_at'] !== null
        && (int) $mapDriverSeats['reported_capacity'] === (int) $mapDriverSeats['capacity']) {
        $capacity = (int) $mapDriverSeats['capacity'];
        $occupied = min($capacity, max(0, (int) ($mapDriverSeats['occupied'] ?? 0)));
        $routeSeatColor = seat_status($occupied, $capacity)['color'];
    }
}
$mapPickupError = '';
$mapPickupFlash = $_SESSION['map_pickup_flash'] ?? '';
unset($_SESSION['map_pickup_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['pickup', 'clear'], true)) {
    verify_csrf_token();
    if ($mapDriver === null) {
        http_response_code(401);
        $mapPickupError = 'Sign in as a driver to save a pickup area.';
    } else {
        if ($_POST['action'] === 'clear') {
            [$code, $message] = clear_driver_route_and_pickup(
                (int) $mapDriver['id'],
                $_POST['pickup_revision'] ?? null
            );
        } else {
            [$code, $message] = save_driver_route_and_pickup(
                (int) $mapDriver['id'],
                $_POST['route_key'] ?? null,
                $_POST['location_key'] ?? null,
                $_POST['availability'] ?? null,
                $_POST['pickup_revision'] ?? null
            );
        }
        if ($code === 200) {
            $_SESSION['map_pickup_flash'] = $message;
            redirect('map.php#map-pickup-area');
        }
        http_response_code($code);
        $mapPickupError = $message;
    }
}
$mapPickupAreas = pickup_areas();
$mapPickupAssignment = $mapDriver !== null ? driver_pickup_assignment((int) $mapDriver['id']) : null;
$mapDriverSetup = $mapDriver !== null ? driver_route_assignment((int) $mapDriver['id']) : null;
$mapPickupLocationKey = $mapPickupAssignment['location_key'] ?? '';
$mapPickupAvailability = $mapPickupAssignment['availability'] ?? 'off_duty';
$mapPickupLocationLabel = $mapPickupAssignment['location_name'] ?? 'Not assigned';
$mapDriverRouteKey = $mapDriverSetup['route_key'] ?? '';
page_start('Jeepney map', 'commuter-map');
?>
<section class="route-page commuter-map-page">
  <div class="map-page-intro"><span class="form-kicker">FOR DRIVERS & COMMUTERS</span><h1>Find your next stop.</h1><p>Explore terminals, boarding points, and the roads your jeepney follows.</p></div>
  <?php require __DIR__ . '/../app/route-map.php'; ?>
</section>
<?php
require __DIR__ . '/../app/route-map-scripts.php';
page_end();
