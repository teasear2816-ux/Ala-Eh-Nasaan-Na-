<?php
declare(strict_types=1);

function trip_options(): array
{
    return [
        'available' => ['label' => 'Available', 'description' => 'Ready to pick up passengers.', 'icon' => 'check'],
        'in_transit' => ['label' => 'In transit', 'description' => 'Currently traveling along your route.', 'icon' => 'bus'],
        'full' => ['label' => 'Full', 'description' => 'Not accepting more passengers right now.', 'icon' => 'seat'],
        'out' => ['label' => 'Out', 'description' => 'Off duty or not operating.', 'icon' => 'clock'],
    ];
}

function driver_trip(int $driverId): ?array
{
    $query = db()->prepare('SELECT status, revision, updated_at FROM driver_trip_status WHERE driver_id = ?');
    $query->execute([$driverId]);
    return $query->fetch() ?: null;
}

function active_driver_trip(int $driverId): ?array
{
    $query = db()->prepare(
        "SELECT t.id, t.started_at, dpa.route_name
         FROM trips t
         INNER JOIN driver_pickup_assignments dpa
            ON dpa.driver_id = t.driver_id
           AND dpa.route_key <> ''
           AND dpa.route_revision > 0
         WHERE t.driver_id = :driver_id AND t.trip_status = 'active'
         ORDER BY t.started_at DESC LIMIT 1"
    );
    $query->execute(['driver_id' => $driverId]);
    return $query->fetch() ?: null;
}

function save_driver_trip(int $driverId, mixed $status, mixed $revision): array
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $driver = driver_seats($driverId, true);
        $current = driver_trip($driverId);
        if (!can_update_seats($driver)) {
            $error = [403, 'A verified driver account and approved jeepney are required to update trip status.'];
        } elseif (!is_string($status) || !array_key_exists($status, trip_options())) {
            $error = [422, 'Choose Available, In transit, Full, or Out.'];
        } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10 || (int) $revision !== (int) ($current['revision'] ?? 0)) {
            $error = [409, 'Trip status changed in another window. Review the latest status below and save again.'];
        } else {
            $query = $pdo->prepare('INSERT INTO driver_trip_status (driver_id, status) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), revision = revision + 1, updated_at = CURRENT_TIMESTAMP');
            $query->execute([$driverId, $status]);
            if (in_array($status, ['available', 'out'], true)) {
                $finish = $pdo->prepare("UPDATE trips SET trip_status = ?, ended_at = CURRENT_TIMESTAMP WHERE driver_id = ? AND trip_status = 'active'");
                $finish->execute([$status === 'out' ? 'cancelled' : 'completed', $driverId]);
            }
            $pdo->commit();
            return [200, 'Trip status saved.'];
        }
        $pdo->rollBack();
        return $error;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log((string) $exception);
        return [500, 'Trip status could not be saved. Please try again.'];
    }
}
