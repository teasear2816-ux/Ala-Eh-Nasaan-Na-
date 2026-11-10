<?php
declare(strict_types=1);

function driver_route_options(): array
{
    return [
        '12000001' => [
            'name' => 'Mabini → Batangas City',
            'ref' => 'MAB-01',
            'start' => 'Mabini',
            'destination' => 'Batangas City',
        ],
        '12000002' => [
            'name' => 'Batangas City → Mabini',
            'ref' => 'MAB-01',
            'start' => 'Batangas City',
            'destination' => 'Mabini',
        ],
    ];
}

function driver_route_assignment(int $driverId): ?array
{
    $query = db()->prepare('SELECT route_key, route_name, route_ref, route_start, route_destination, route_revision, updated_at
        FROM driver_pickup_assignments WHERE driver_id = ? LIMIT 1');
    $query->execute([$driverId]);
    return $query->fetch() ?: null;
}

function save_driver_route_and_pickup(int $driverId, mixed $routeKey, mixed $locationKey, mixed $availability, mixed $revision): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $driver = driver_seats($driverId, true);
        $current = driver_route_assignment($driverId);
        $routes = driver_route_options();
        $areas = pickup_area_keys();
        if (!can_update_seats($driver)) {
            $error = [403, 'A verified driver account and approved jeepney are required.'];
        } elseif (!is_string($routeKey) || !array_key_exists($routeKey, $routes)) {
            $error = [422, 'Choose one of the available jeepney routes.'];
        } elseif (!is_string($locationKey) || !in_array($locationKey, $areas, true)) {
            $error = [422, 'Choose one of the six approved starting areas.'];
        } elseif (pickup_area_route_key($locationKey) !== $routeKey) {
            $error = [422, 'Choose a starting area in the municipality where your intended route begins.'];
        } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10 || (int) $revision !== (int) ($current['route_revision'] ?? 0)) {
            $error = [409, 'Your driver setup changed in another window. Review it and save again.'];
        } else {
            $route = $routes[$routeKey];
            $availability = 'waiting';
            $query = $pdo->prepare('INSERT INTO driver_pickup_assignments
                (driver_id, location_key, availability, route_key, route_name, route_ref, route_start, route_destination, route_revision)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE location_key = VALUES(location_key), availability = VALUES(availability),
                    route_key = VALUES(route_key), route_name = VALUES(route_name), route_ref = VALUES(route_ref),
                    route_start = VALUES(route_start), route_destination = VALUES(route_destination),
                    route_revision = route_revision + 1, revision = revision + 1, updated_at = CURRENT_TIMESTAMP');
            $query->execute([$driverId, $locationKey, $availability, $routeKey, $route['name'], $route['ref'], $route['start'], $route['destination']]);
            $pdo->commit();
            return [200, 'Starting area saved. You are now on duty and waiting for passengers.'];
        }
        $pdo->rollBack();
        return $error;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log((string) $exception);
        return [500, 'Driver setup could not be saved. Please try again.'];
    }
}

function clear_driver_route_and_pickup(int $driverId, mixed $revision): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $current = driver_route_assignment($driverId);
        if ($current === null) {
            $pdo->commit();
            return [200, 'There is no saved route to clear.'];
        }
        if (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10
            || (int) $revision !== (int) ($current['route_revision'] ?? 0)) {
            $pdo->rollBack();
            return [409, 'Your saved route changed in another window. Reload the map and try again.'];
        }

        $delete = $pdo->prepare('DELETE FROM driver_pickup_assignments WHERE driver_id = ? AND route_revision = ?');
        $delete->execute([$driverId, (int) $current['route_revision']]);
        if ($delete->rowCount() !== 1) {
            $pdo->rollBack();
            return [409, 'Your saved route changed in another window. Reload the map and try again.'];
        }

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
        return [200, 'Saved route cleared. Set up and save a route before updating available seats.'];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log((string) $exception);
        return [500, 'Saved route could not be cleared. Please try again.'];
    }
}
