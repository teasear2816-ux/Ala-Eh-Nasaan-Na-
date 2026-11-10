<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('expose_php', '0');

// Prevent account pages and authenticated responses from being retained by shared caches.
header_remove('X-Powered-By');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

function load_local_environment(): void
{
    $environmentFile = dirname(__DIR__) . '/.env';
    if (!is_file($environmentFile)) {
        return;
    }

    $values = parse_ini_file($environmentFile, false, INI_SCANNER_RAW);
    if ($values === false) {
        throw new RuntimeException('Unable to read the local environment configuration.');
    }

    $allowedNames = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'DB_SSL_CA',
        'DB_SSL_CA_BASE64',
        'SESSION_COOKIE_SECURE',
        'SESSION_DRIVER',
        'PROFILE_PHOTO_STORAGE',
    ];
    foreach ($values as $name => $value) {
        if (function_exists('putenv') && in_array($name, $allowedNames, true) && is_string($value) && getenv($name) === false) {
            putenv($name . '=' . $value);
        }
    }
}

load_local_environment();

function environment_value(string $name, string $default = ''): string
{
    $environmentValue = getenv($name);
    if ($environmentValue !== false && $environmentValue !== '') {
        return $environmentValue;
    }

    static $values = null;
    if ($values === null) {
        $environmentFile = dirname(__DIR__) . '/.env';
        $parsedValues = is_file($environmentFile)
            ? parse_ini_file($environmentFile, false, INI_SCANNER_RAW)
            : [];
        $values = is_array($parsedValues) ? $parsedValues : [];
    }

    return isset($values[$name]) && is_string($values[$name]) && $values[$name] !== ''
        ? $values[$name]
        : $default;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log((string) $exception);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'An unexpected server error occurred.';
});

$secureCookieSetting = strtolower(environment_value('SESSION_COOKIE_SECURE', 'true'));
$secureCookie = !in_array($secureCookieSetting, ['false', '0', 'off'], true);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
require_once __DIR__ . '/database.php';
$sessionDriver = strtolower((string) (getenv('SESSION_DRIVER') ?: 'files'));
if ($sessionDriver === 'mysql') {
    require_once __DIR__ . '/database-session-handler.php';
    if (!session_set_save_handler(new DatabaseSessionHandler(), true)) {
        throw new RuntimeException('Unable to configure the shared database session store.');
    }
} elseif ($sessionDriver !== 'files') {
    throw new RuntimeException('SESSION_DRIVER must be either files or mysql.');
}
session_name('driver_portal_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vehicles.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function account_initial(string $name): string
{
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        return mb_strtoupper(mb_substr($name, 0, 1));
    }

    return strtoupper(substr($name, 0, 1));
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function current_driver(): ?array
{
    return isset($_SESSION['driver']) && is_array($_SESSION['driver'])
        ? $_SESSION['driver']
        : null;
}

function current_admin(): ?array
{
    return isset($_SESSION['admin']) && is_array($_SESSION['admin'])
        ? $_SESSION['admin']
        : null;
}

function driver_language(): string
{
    $language = $_COOKIE['driver_language'] ?? $_SESSION['driver_language'] ?? 'en';
    return is_string($language) && in_array($language, ['en', 'fil'], true) ? $language : 'en';
}

function driver_theme(): string
{
    $theme = $_COOKIE['driver_theme'] ?? $_SESSION['driver_theme'] ?? 'light';
    return is_string($theme) && in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
}

function driver_photo_file(int $driverId): ?string
{
    if ($driverId < 1) {
        return null;
    }

    $directory = dirname(__DIR__) . '/storage/driver-photos';
    $files = glob($directory . DIRECTORY_SEPARATOR . $driverId . '-*');
    if ($files === false) {
        return null;
    }

    $photos = array_values(array_filter(
        $files,
        static fn (string $file): bool => is_file($file)
            && preg_match('/\A' . $driverId . '-[a-f0-9]{32}\.(?:jpg|png|webp)\z/', basename($file)) === 1
    ));
    usort($photos, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

    return $photos[0] ?? null;
}

function driver_photo_database_version(int $driverId): ?string
{
    $query = db()->prepare(
        'SELECT UNIX_TIMESTAMP(updated_at) FROM profile_photos WHERE driver_id = :driver_id LIMIT 1'
    );
    $query->execute(['driver_id' => $driverId]);
    $version = $query->fetchColumn();

    return $version === false ? null : (string) $version;
}

function require_driver(): array
{
    $driver = current_driver();
    if ($driver === null) {
        redirect('login.php');
    }

    $access = db()->prepare('SELECT d.full_name, d.email, d.verification_status,
        COALESCE(s.account_status, \'active\') AS account_status
        FROM drivers d LEFT JOIN driver_admin_status s ON s.driver_id = d.id WHERE d.id = ?');
    $access->execute([(int) $driver['id']]);
    $record = $access->fetch();
    if (!$record || $record['account_status'] !== 'active') {
        unset($_SESSION['driver']);
        redirect((str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') ? '../' : '') . 'login.php?account=disabled');
    }
    $_SESSION['driver'] = $driver = array_merge($driver, array_intersect_key($record, array_flip(['full_name', 'email', 'verification_status'])));

    return $driver;
}

function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        if (function_exists('remember_auth_return_path')) remember_auth_return_path('admin_return_to');
        redirect((str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') ? '../' : '') . 'admin-login.php');
    }

    $access = db()->prepare(
        'SELECT role, account_status FROM admin_roles WHERE admin_id = :admin_id LIMIT 1'
    );
    $access->execute(['admin_id' => $admin['id']]);
    $role = $access->fetch();
    if ($role === false || $role['account_status'] !== 'active') {
        unset($_SESSION['admin']);
        redirect((str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') ? '../' : '') . 'admin-login.php?account=disabled');
    }
    $_SESSION['admin']['role'] = $role['role'];

    return $_SESSION['admin'];
}

function page_start(string $title, string $page = ''): void
{
    if (defined('ADMIN_PORTAL')) { admin_page_start($title, $page); return; }
    $safeTitle = h($title);
    $safePage = h($page);
    $language = current_driver() !== null ? driver_language() : 'en';
    $theme = current_driver() !== null ? driver_theme() : 'light';
    // InfinityFree serves the public directory as its document root, while local
    // development keeps it under /public. Support both layouts for cache busting.
    $stylesPath = __DIR__ . '/../public/assets/styles.css';
    if (!is_file($stylesPath)) {
        $stylesPath = __DIR__ . '/../assets/styles.css';
    }
    $stylesVersion = (string) filemtime($stylesPath);
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    $layoutClass = $isAuthenticated ? 'has-sidebar' : '';
    $headerProfile = '';
    $mapStyles = in_array($page, ['driver-route', 'commuter-map', 'admin-locations'], true)
        ? '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">'
        : '';
    if (current_admin() === null && current_driver() !== null) {
        $profileName = h((string) current_driver()['full_name']);
        $profileRole = current_driver()['verification_status'] === 'verified' ? 'Verified driver' : 'Driver';
        $profileInitial = h(account_initial((string) current_driver()['full_name']));
        $driverId = (int) current_driver()['id'];
        if (strtolower(environment_value('PROFILE_PHOTO_STORAGE', 'filesystem')) === 'database') {
            $photoVersion = driver_photo_database_version($driverId);
            $profileAvatar = $photoVersion === null
                ? $profileInitial
                : '<img src="admin-profile-photo.php?v=' . rawurlencode($photoVersion) . '" alt="" loading="lazy">';
        } else {
            $profilePhoto = driver_photo_file($driverId);
            $profileAvatar = $profilePhoto === null
                ? $profileInitial
                : '<img src="driver-photo.php?v=' . rawurlencode((string) filemtime($profilePhoto)) . '" alt="" loading="lazy">';
        }
        $logoutToken = csrf_field();
        $headerProfile = <<<HTML
      <div class="header-account">
        <button class="header-user" id="account-menu-toggle" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="account-menu">
          <span class="sidebar-user-avatar" aria-hidden="true">{$profileAvatar}</span>
          <span class="sidebar-user-info">
            <span class="sidebar-user-name">{$profileName}</span>
            <span class="sidebar-user-role">{$profileRole}</span>
          </span>
          <svg class="ui-icon account-menu-chevron" aria-hidden="true"><use href="assets/icons.svg#chevron-down"></use></svg>
        </button>
        <div class="account-menu" id="account-menu" hidden>
          <a href="driver-profile.php"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#user"></use></svg><span>Profile</span></a>
          <a href="driver-settings.php"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#settings"></use></svg><span>Settings</span></a>
          <form method="post" action="logout.php">
            {$logoutToken}
            <button type="submit"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#logout"></use></svg><span>Logout</span></button>
          </form>
        </div>
      </div>
HTML;
    } elseif (current_admin() !== null) {
        $adminAccountName = h((string) (current_admin()['full_name'] ?? current_admin()['email'] ?? 'Administrator'));
        $adminInitial = h(account_initial($adminAccountName));
        $logoutToken = csrf_field();
        $headerProfile = <<<HTML
      <div class="header-account">
        <button class="header-user" id="account-menu-toggle" type="button" aria-label="Open account menu" aria-expanded="false" aria-controls="account-menu">
          <span class="sidebar-user-avatar" aria-hidden="true">{$adminInitial}</span>
          <span class="sidebar-user-info">
            <span class="sidebar-user-name">{$adminAccountName}</span>
            <span class="sidebar-user-role">Administrator</span>
          </span>
          <svg class="ui-icon account-menu-chevron" aria-hidden="true"><use href="assets/icons.svg#chevron-down"></use></svg>
        </button>
        <div class="account-menu" id="account-menu" hidden>
          <a href="admin-dashboard.php"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#user"></use></svg><span>Account</span></a>
          <form method="post" action="admin-logout.php">
            {$logoutToken}
            <button type="submit"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#logout"></use></svg><span>Logout</span></button>
          </form>
        </div>
      </div>
HTML;
    }
    
    echo <<<HTML
<!doctype html>
<html lang="{$language}" data-theme="{$theme}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>{$safeTitle} | Ala-Eh Nasaan Na? Jeepney Locator</title>
  <meta name="theme-color" content="#f5f8ff">
  <link rel="icon" type="image/png" href="assets/ala-eh-logo.png">
  <link rel="apple-touch-icon" href="assets/ala-eh-logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
{$mapStyles}
  <link rel="stylesheet" href="assets/styles.css?v={$stylesVersion}">
</head>
<body class="page-{$safePage} {$layoutClass}">
HTML;
    
    if ($isAuthenticated) {
        // Sidebar layout for authenticated users
        echo <<<HTML
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <a class="sidebar-brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
        <img class="sidebar-brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
        <span class="sidebar-brand-name">Ala-Eh Nasaan Na?<span>Jeepney Locator</span></span>
      </a>
      <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
        <span class="toggle-icon"></span>
      </button>
      <button class="mobile-menu-close" id="mobile-menu-close" type="button" aria-label="Close menu">&times;</button>
    </div>
    <nav class="sidebar-nav" aria-label="Main navigation">
HTML;
        
        if (current_admin() !== null) {
            $adminName = h((string) (current_admin()['full_name'] ?? current_admin()['email'] ?? 'Administrator'));
            echo <<<HTML
      <div class="sidebar-user">
        <div class="sidebar-user-avatar">A</div>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name">{$adminName}</span>
          <span class="sidebar-user-role">Administrator</span>
        </div>
      </div>
      <div class="sidebar-menu">
        <a href="index.php" class="sidebar-link">
          <span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#home"></use></svg></span>
          <span class="sidebar-link-text">Home</span>
        </a>
        <a href="admin-dashboard.php" class="sidebar-link" aria-label="Admin Dashboard">
          <span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#chart"></use></svg></span>
          <span class="sidebar-link-text" data-mobile-label="Dashboard">Admin Dashboard</span>
        </a>
        <a href="admin-locations.php" class="sidebar-link" aria-label="Map locations"><span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#pin"></use></svg></span><span class="sidebar-link-text" data-mobile-label="Locations">Map locations</span></a>
        <a href="map.php" class="sidebar-link" aria-label="Public map"><span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#map"></use></svg></span><span class="sidebar-link-text" data-mobile-label="Map">Public map</span></a>
      </div>
HTML;
        } elseif (current_driver() !== null) {
            $driver = current_driver();
            echo <<<HTML
      <div class="sidebar-menu">
        <a href="dashboard.php" class="sidebar-link">
          <span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#home"></use></svg></span>
          <span class="sidebar-link-text">Home</span>
        </a>
        <a href="map.php" class="sidebar-link" aria-label="Jeepney map">
          <span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#map"></use></svg></span>
          <span class="sidebar-link-text" data-mobile-label="Map">Jeepney map</span>
        </a>
        <a href="jeepney-profile.php" class="sidebar-link" aria-label="My jeepney">
          <span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true" focusable="false" width="24" height="24"><use href="assets/icons.svg#bus"></use></svg></span>
          <span class="sidebar-link-text" data-mobile-label="Jeepney">My jeepney</span>
        </a>
        <a href="driver-trip.php" class="sidebar-link" aria-label="Trip tracking"><span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#map"></use></svg></span><span class="sidebar-link-text">Trip tracking</span></a>
        <a href="driver-documents.php" class="sidebar-link" aria-label="My documents"><span class="sidebar-link-icon"><svg class="ui-icon" aria-hidden="true"><use href="assets/icons.svg#user"></use></svg></span><span class="sidebar-link-text">My documents</span></a>
      </div>
HTML;
        }
        
        echo <<<HTML
    </nav>
  </aside>
  <div class="app-content">
    <header class="top-header">
      <button class="mobile-menu-toggle" id="mobile-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
      </button>
      <h1 class="page-title">{$safeTitle}</h1>
{$headerProfile}
    </header>
    <main class="site-main" id="main-content">
HTML;
    } elseif (defined('HIDE_SITE_FOOTER') && HIDE_SITE_FOOTER) {
        echo "</main>\n</body>\n</html>\n";
    } else {
        // Regular header for non-authenticated pages
        echo <<<HTML
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
      <img class="brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
      <span class="brand-name">Ala-Eh Nasaan Na?<span>Jeepney Locator</span></span>
    </a>
  </div>
</header>
<main class="site-main" id="main-content">
HTML;
    }
}

function page_end(): void
{
    if (defined('ADMIN_PORTAL')) { admin_page_end(); return; }
    $isAuthenticated = current_driver() !== null || current_admin() !== null;
    
    if ($isAuthenticated) {
        $navigationVersion = (string) filemtime(__DIR__ . '/../public/assets/navigation.js');
        $liveRefreshVersion = (string) filemtime(__DIR__ . '/../public/assets/live-refresh.js');
        $driverLanguageScript = '';
        if (current_driver() !== null) {
            $languageVersion = (string) filemtime(__DIR__ . '/../public/assets/driver-language.js');
            $driverLanguageScript = '<script src="assets/driver-language.js?v=' . h($languageVersion) . '" defer></script>';
        }
        echo <<<HTML
    </main>
  </div>
</div>
<script src="assets/navigation.js?v={$navigationVersion}" defer></script>
<script src="assets/live-refresh.js?v={$liveRefreshVersion}" defer></script>
{$driverLanguageScript}
</body>
</html>
HTML;
    } else {
        echo <<<HTML
</main>
<footer class="site-footer">
  <div class="footer-inner">
    <a class="footer-brand" href="index.php" aria-label="Ala-Eh Nasaan Na? Jeepney Locator home">
      <img class="footer-brand-mark" src="assets/ala-eh-logo.png" alt="" aria-hidden="true">
      <span>Ala-Eh Nasaan Na?<small>Jeepney Locator</small></span>
    </a>
    <span>Built for the road ahead.</span>
  </div>
</footer>
</body>
</html>
HTML;
    }
}
