<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/seats.php';
require_once dirname(__DIR__, 2) . '/app/trips.php';
require_once dirname(__DIR__, 2) . '/app/driver-routes.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Only POST requests are supported.']);
    exit;
}

try {
    if (!csrf_value_is_valid($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your driver session expired. Reload the dashboard and try again.']);
        exit;
    }
    $driver = current_driver();
    if ($driver === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Please sign in to the driver dashboard.']);
        exit;
    }
    $occupiedInput = $_POST['occupied'] ?? null;
    if (!is_string($occupiedInput) || !ctype_digit($occupiedInput) || strlen($occupiedInput) > 10) {
        throw new InvalidArgumentException('Occupied seats must be a whole number.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $currentSeats = driver_seats((int) $driver['id'], true);
    $occupied = (int) $occupiedInput;
    $capacity = (int) ($currentSeats['capacity'] ?? 0);
    if (!can_update_seats($currentSeats)) {
        throw new RuntimeException('A verified driver account and approved jeepney capacity are required.');
    }
    $routeSetup = driver_route_assignment((int) $driver['id']);
if ($routeSetup === null || empty($routeSetup['route_key']) || (int) ($routeSetup['route_revision'] ?? 0) < 1) {
        throw new RuntimeException('Save your route before updating available seats.');
    }
    if ($occupied < 0 || $occupied > $capacity) {
        throw new InvalidArgumentException("Occupied seats must be from 0 to {$capacity}.");
    }

    $save = $pdo->prepare('INSERT INTO driver_seat_availability (driver_id, occupied, capacity) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE occupied = VALUES(occupied), capacity = VALUES(capacity), revision = revision + 1, updated_at = CURRENT_TIMESTAMP');
    $save->execute([(int) $driver['id'], $occupied, $capacity]);
    $pdo->commit();

    $savedSeats = driver_seats((int) $driver['id']);
    echo json_encode([
        'ok' => true,
        'message' => 'Seat availability updated.',
        'occupied' => (int) ($savedSeats['occupied'] ?? $occupied),
        'capacity' => (int) ($savedSeats['capacity'] ?? $capacity),
        'revision' => (int) ($savedSeats['revision'] ?? 0),
    ]);
} catch (InvalidArgumentException|RuntimeException $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code($error instanceof InvalidArgumentException ? 422 : 403);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log((string) $error);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Seat availability could not be updated. Please try again.']);
}
