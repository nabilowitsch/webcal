<?php
// ── Public ICS proxy (no auth required) ──────────────────────────────────────
if (isset($_GET['c']) && $_GET['c'] !== '') {
    $proxyToken = $_GET['c'];
    $cfg = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
    $secret  = $cfg['proxy_secret'] ?? '';
    $caldav  = $cfg['caldav'];

    if (!$secret) { http_response_code(404); exit('Not found'); }

    // Discover calendars via PROPFIND
    $baseUrl    = rtrim($caldav['url'], '/');
    $parsed     = parse_url($baseUrl);
    $schemeHost = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $homeHref   = $caldav['calendar_home'] ?? '/';
    $homeUrl    = $schemeHost . $homeHref;

    $propXml = '<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop><d:displayname/><d:resourcetype/></d:prop></d:propfind>';

    $propResp = icsProxyCurl('PROPFIND', $homeUrl, $caldav['username'], $caldav['password'], $propXml, ['Depth: 1']);
    if (!$propResp) { http_response_code(503); exit; }

    // Find calendar matching the token
    $dom = new DOMDocument(); @$dom->loadXML($propResp);
    $xp  = new DOMXPath($dom);
    $xp->registerNamespace('d', 'DAV:');
    $xp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    $matchedHref = null;
    foreach ($xp->query('//d:response') as $node) {
        if ($xp->query('.//c:calendar', $node)->length === 0) continue;
        $href = trim($xp->query('d:href', $node)->item(0)?->textContent ?? '');
        if (!$href) continue;
        if (substr(hash_hmac('sha256', $href, $secret), 0, 32) === $proxyToken) {
            $matchedHref = $href;
            break;
        }
    }
    if (!$matchedHref) { http_response_code(404); exit('Calendar not found'); }

    // Fetch all events for that calendar via REPORT
    $calUrl   = str_starts_with($matchedHref, 'http') ? $matchedHref : $schemeHost . $matchedHref;
    $reportXml = '<?xml version="1.0" encoding="utf-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:prop><c:calendar-data/></d:prop>
    <c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"/></c:comp-filter></c:filter>
</c:calendar-query>';

    $evResp = icsProxyCurl('REPORT', $calUrl, $caldav['username'], $caldav['password'], $reportXml, ['Depth: 1']);
    if (!$evResp) { http_response_code(503); exit; }

    // Extract VEVENT blocks and assemble VCALENDAR
    $evDom = new DOMDocument(); @$evDom->loadXML($evResp);
    $evXp  = new DOMXPath($evDom);
    $evXp->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    $vevents = [];
    foreach ($evXp->query('//c:calendar-data') as $node) {
        if (preg_match_all('/BEGIN:VEVENT.+?END:VEVENT/ms', $node->textContent, $m)) {
            foreach ($m[0] as $block) {
                $vevents[] = rtrim($block);
            }
        }
    }
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//WebCal//EN\r\nCALSCALE:GREGORIAN\r\n"
         . implode("\r\n", $vevents)
         . "\r\nEND:VCALENDAR\r\n";

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="calendar.ics"');
    header('Cache-Control: no-cache');
    echo $ics;
    exit;
}

function icsProxyCurl(string $method, string $url, string $user, string $pass, string $body, array $extra = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_HTTPAUTH       => CURLAUTH_ANY,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/xml; charset=utf-8'], $extra),
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($result !== false && $status < 400) ? (string) $result : null;
}

// ─────────────────────────────────────────────────────────────────────────────
session_name('webcal');
session_start();

const CONFIG_FILE = __DIR__ . '/config.json';

// --- Config helpers ---

function loadConfig(): array {
    if (!file_exists(CONFIG_FILE)) {
        die('config.json not found. Please create it from the example.');
    }
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    if ($data === null) die('Invalid config.json.');
    return $data;
}

function saveConfig(array $config): void {
    file_put_contents(
        CONFIG_FILE,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function findUser(array $config, string $username): ?array {
    foreach ($config['users'] as $user) {
        if ($user['username'] === $username) return $user;
    }
    return null;
}

// --- CSRF ---

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): bool {
    $token = $_POST['_csrf'] ?? '';
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// --- Bootstrap ---

$config = loadConfig();

// Ensure a stable proxy secret exists
if (empty($config['proxy_secret'])) {
    $config['proxy_secret'] = bin2hex(random_bytes(24));
    saveConfig($config);
}

$error = '';
$setupError = '';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login' && verifyCsrf()) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = findUser($config, $username);

        if (!$user) {
            $error = 'Invalid credentials.';
        } elseif ($user['password_hash'] === null) {
            $_SESSION['setup_user'] = $username;
            header('Location: index.php?setup=1');
            exit;
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            $_SESSION['user'] = $username;
            $_SESSION['display_name'] = $user['display_name'] ?? $username;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }
    }

    if ($action === 'setup' && verifyCsrf() && isset($_SESSION['setup_user'])) {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';

        if (strlen($password) < 8) {
            $setupError = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $setupError = 'Passwords do not match.';
        } else {
            $username = $_SESSION['setup_user'];
            foreach ($config['users'] as &$u) {
                if ($u['username'] === $username) {
                    $u['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                    break;
                }
            }
            unset($u);
            saveConfig($config);
            $user = findUser($config, $username);
            $_SESSION['user'] = $username;
            $_SESSION['display_name'] = $user['display_name'] ?? $username;
            unset($_SESSION['setup_user']);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: index.php');
            exit;
        }
    }
}

$loggedIn = isset($_SESSION['user']);
$doSetup  = isset($_GET['setup']) && isset($_SESSION['setup_user']);
$isPublic = !$loggedIn && !isset($_GET['admin']) && !$doSetup;
$showAuth = !$loggedIn && (isset($_GET['admin']) || $doSetup);
$csrf     = csrfToken();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebCal</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="custom.css">
</head>
<body class="bg-gray-50 min-h-screen font-sans antialiased text-gray-900">

<?php if ($showAuth): ?>
<!-- ── Auth ─────────────────────────────────────────────────────────────── -->
<div class="min-h-screen flex items-center justify-center p-4 bg-linear-to-br from-slate-50 to-blue-50">
    <div class="w-full max-w-sm">

        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-blue-600 shadow-lg mb-4">
                <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight">WebCal</h1>
            <p class="text-sm text-gray-500 mt-1">
                <?= $doSetup ? 'Choose your password to get started' : 'Sign in to your calendar' ?>
            </p>
        </div>

        <div class="bg-white rounded-2xl shadow-xs border border-gray-100 p-6">

            <?php if ($doSetup): ?>
            <!-- Password setup -->
            <?php if ($setupError): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700"><?= htmlspecialchars($setupError) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="setup">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">New password</label>
                    <input type="password" name="password" required minlength="8" autofocus
                           class="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                           placeholder="At least 8 characters">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Confirm password</label>
                    <input type="password" name="confirm" required
                           class="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                           placeholder="Repeat your password">
                </div>
                <button type="submit"
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold rounded-xl transition-colors shadow-xs">
                    Set Password &amp; Sign In
                </button>
            </form>

            <?php else: ?>
            <!-- Login -->
            <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-100 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Username</label>
                    <input type="text" name="username" required autofocus autocomplete="username"
                           class="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                           placeholder="Your username">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
                    <input type="password" name="password" required autocomplete="current-password"
                           class="w-full px-3.5 py-2.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                           placeholder="Your password">
                </div>
                <button type="submit"
                        class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white text-sm font-semibold rounded-xl transition-colors shadow-xs">
                    Sign In
                </button>
            </form>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php else: ?>
<!-- ── App ──────────────────────────────────────────────────────────────── -->
<div id="app" class="flex h-screen overflow-hidden">

    <!-- Mobile overlay -->
    <div id="backdrop" onclick="closeSidebar()"
         class="fixed inset-0 bg-black/40 z-20 hidden lg:hidden"></div>

    <!-- Sidebar -->
    <aside id="sidebar"
           class="fixed lg:static inset-y-0 left-0 z-30 w-[250px] shrink-0 bg-white border-r border-gray-100 flex flex-col -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-in-out">

        <!-- Logo -->
        <div class="flex items-center gap-2.5 px-4 h-14 border-b border-gray-100 shrink-0">
            <div class="w-7 h-7 rounded-lg bg-blue-600 flex items-center justify-center shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <span class="font-semibold text-sm">WebCal</span>
            <button onclick="closeSidebar()" class="ml-auto lg:hidden text-gray-400 hover:text-gray-600 p-1 rounded-lg">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Calendar list -->
        <div class="flex-1 overflow-y-auto py-3 px-2 sidebar-scroll">
            <div class="flex items-center justify-between px-3 mb-2">
                <button onclick="toggleAllCalendars()" class="flex items-center gap-2 text-left group">
                    <span id="toggle-all-cals-box"
                          class="w-4 h-4 rounded shrink-0 flex items-center justify-center transition-colors"></span>
                    <p class="text-[10px] font-bold tracking-widest text-gray-400 uppercase group-hover:text-gray-500 transition-colors">Calendars</p>
                </button>
                <?php if (!$isPublic): ?>
                <div class="flex items-center gap-0.5">
                    <button onclick="showPublicUrls()" title="Public calendar URLs"
                            class="p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-600 transition-colors rounded">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </button>
                    <button onclick="openNewCalendarModal()" title="Add calendar"
                            class="p-1 text-gray-400 hover:bg-gray-200 hover:text-gray-600 transition-colors rounded">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <div id="calendar-list">
                <div class="px-3 py-2 text-sm text-gray-400">Loading…</div>
            </div>
        </div>

        <?php if (!$isPublic): ?>
        <!-- User / sign out -->
        <div class="shrink-0 px-4 py-3 border-t border-gray-100">
            <div class="flex items-center justify-between text-sm gap-2">
                <span class="text-gray-600 font-medium truncate"><?= htmlspecialchars($_SESSION['display_name'] ?? '') ?></span>
                <a href="index.php?logout=1" class="text-xs text-gray-400 hover:text-red-500 transition-colors shrink-0">Sign out</a>
            </div>
        </div>
        <?php endif; ?>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <!-- Topbar -->
        <header class="flex items-center gap-3 px-4 min-h-14 py-2 bg-white border-b border-gray-100 shrink-0 flex-wrap">
            <button onclick="openSidebar()"
                    class="lg:hidden p-2 -ml-1 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="flex items-center gap-2 flex-1 min-w-0">
                <!-- navigation bar for list view -->
                <div id="list-nav" class="flex flex-wrap items-center gap-1 text-sm font-semibold text-gray-800">
                  <?php if (!$isPublic): ?>
                  <button onclick="openNewEventModal()" title="New event"
                    class="p-2 rounded-lg bg-gray-200 hover:bg-blue-700 text-gray-800 hover:text-white text-sm font-light transition-colors leading-none whitespace-nowrap">
                    + New Event
                  </button>
                  <button onclick="toggleImportMode()" title="Import .ics"
                    class="flex items-center justify-center gap-1 p-2 rounded-lg bg-gray-200 hover:bg-blue-700 text-gray-800 hover:text-white text-sm font-light transition-colors leading-none">
                      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="currentColor" viewBox="0 0 16 16">
                          <path fill-rule="evenodd" d="M3.5 6a.5.5 0 0 0-.5.5v8a.5.5 0 0 0 .5.5h9a.5.5 0 0 0 .5-.5v-8a.5.5 0 0 0-.5-.5h-2a.5.5 0 0 1 0-1h2A1.5 1.5 0 0 1 14 6.5v8a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 2 14.5v-8A1.5 1.5 0 0 1 3.5 5h2a.5.5 0 0 1 0 1z"/>
                          <path fill-rule="evenodd" d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/>
                      </svg>
                      Import
                  </button>
                  <?php endif; ?>
                  <div class="relative flex-1 min-w-48">
                    <div class="absolute inset-y-0 start-0 flex items-center ps-4 pointer-events-none">
                        <svg width="1em" height="1em" viewBox="0 0 20 20" class="text-gray-400 w-4">
                            <path d="M14.386 14.386l4.0877 4.0877-4.0877-4.0877c-2.9418 2.9419-7.7115 2.9419-10.6533 0-2.9419-2.9418-2.9419-7.7115 0-10.6533 2.9418-2.9419 7.7115-2.9419 10.6533 0 2.9419 2.9418 2.9419 7.7115 0 10.6533z" stroke="currentColor" fill="none" stroke-width="2" fill-rule="evenodd" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                    </div>
                    <input id="list-search" type="search" placeholder="Search events…"
                          oninput="onSearchInput(this.value)"
                          class="w-full ps-11 px-3 py-1.5 text-sm border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent bg-white">
                  </div>
                </div>
                <!-- navigation bar for month view -->
                <div id="month-nav" class="flex hidden items-center gap-1">
                    <button onclick="changeMonth(-1)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div id="month-label" class="text-sm font-semibold text-gray-800 min-w-20 text-center select-none"></div>
                    <button onclick="changeMonth(1)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                    <button class="ml-1 px-2 py-1 border border-blue-600 rounded-md hover:bg-gray-200 text-sm" onclick="changeMonth(0)">
                      Today
                    </button>
                </div>
            </div>

            <!-- View switcher -->
            <div class="flex items-center bg-gray-100 rounded-xl p-1 gap-0.5 shrink-0">
                <button id="btn-list"  onclick="setView('list')"  class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-list" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/>
                  </svg>
                </button>
                <button id="btn-month" onclick="setView('month')" class="px-3 py-1.5 text-xs font-semibold rounded-lg transition-all">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-calendar3" viewBox="0 0 16 16">
                    <path d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2M1 3.857C1 3.384 1.448 3 2 3h12c.552 0 1 .384 1 .857v10.286c0 .473-.448.857-1 .857H2c-.552 0-1-.384-1-.857z"/>
                    <path d="M6.5 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/>
                  </svg>
                </button>
            </div>
        </header>

        <!-- Content -->
        <main id="content" class="flex-1 overflow-y-auto">
            <div class="flex items-center justify-center h-full text-gray-400 text-sm gap-2">
                <svg class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 22 6.477 22 12h-4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
                Loading events…
            </div>
        </main>
    </div>
</div>
<?php
$weekStartMap = ['sunday'=>0,'monday'=>1,'tuesday'=>2,'wednesday'=>3,'thursday'=>4,'friday'=>5,'saturday'=>6];
$weekStartDay = $weekStartMap[strtolower($config['week_start'] ?? 'monday')] ?? 1;
?>
<!-- ── Edit modal ──────────────────────────────────────────────────────────── -->
<div id="ev-modal"
     onclick="if(event.target===this)closeEditModal()"
     class="fixed inset-0 z-50 items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md flex flex-col max-h-[90vh]"
         onclick="event.stopPropagation()">

        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h2 id="ev-modal-title" class="text-base font-semibold text-gray-900">Edit Event</h2>
            <button onclick="closeEditModal()" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Scrollable body -->
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">

            <!-- Recurring notice -->
            <div id="ev-recurring-notice"
                 class="hidden p-3 rounded-xl bg-amber-50 border border-amber-100 text-sm text-amber-700">
                Recurring events cannot be edited.
            </div>

            <!-- Title -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Title</label>
                <input id="ev-summary" type="text"
                       class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                       placeholder="Event title">
            </div>

            <!-- Calendar -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Calendar</label>
                <select id="ev-calendar"
                        class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow bg-white">
                </select>
            </div>

            <!-- All-day toggle -->
            <label class="flex items-center gap-2.5 cursor-pointer select-none">
                <input id="ev-allday" type="checkbox" onchange="onAlldayChange()"
                       class="w-4 h-4 accent-blue-600 rounded">
                <span class="text-sm font-medium text-gray-700">All day</span>
            </label>

            <!-- Start -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Start</label>
                <div class="flex gap-2">
                    <input id="ev-start-date" type="date"
                           class="flex-1 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <input id="ev-start-time" type="time"
                           class="ev-time w-28 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                </div>
            </div>

            <!-- End -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">End</label>
                <div class="flex gap-2">
                    <input id="ev-end-date" type="date"
                           class="flex-1 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                    <input id="ev-end-time" type="time"
                           class="ev-time w-28 px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow">
                </div>
            </div>

            <!-- Location -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Location</label>
                <input id="ev-location" type="text"
                       class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                       placeholder="Location (optional)">
            </div>

            <!-- Description -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Description</label>
                <textarea id="ev-description" rows="3"
                          class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow resize-none"
                          placeholder="Description (optional)"></textarea>
            </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center gap-2 px-6 py-4 border-t border-gray-100 shrink-0">
            <button id="ev-delete-btn" onclick="deleteEvent()"
                    class="px-4 py-2 text-sm font-medium text-red-500 hover:text-red-700 disabled:opacity-40 transition-colors hidden">
                Delete
            </button>
            <div class="flex items-center gap-2 ml-auto">
                <button onclick="closeEditModal()"
                        class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors">
                    Cancel
                </button>
                <button id="ev-save-btn" onclick="saveEvent()"
                        class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
                    Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── New calendar modal ──────────────────────────────────────────────────── -->
<div id="cal-modal"
     onclick="if(event.target===this)closeCalendarModal()"
     class="fixed inset-0 z-50 items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm flex flex-col"
         onclick="event.stopPropagation()">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h2 class="text-base font-semibold text-gray-900">New Calendar</h2>
            <button onclick="closeCalendarModal()" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="px-6 py-4 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Display Name</label>
                <input id="cal-name" type="text" oninput="autoFillCalId(this.value)"
                       class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                       placeholder="My Calendar">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Calendar ID</label>
                <input id="cal-id" type="text"
                       class="w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
                       placeholder="my-calendar">
                <p class="text-xs text-gray-400 mt-1.5">Used in the URL — lowercase letters, numbers and hyphens only.</p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100 shrink-0">
            <button onclick="closeCalendarModal()"
                    class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors">
                Cancel
            </button>
            <button id="cal-save-btn" onclick="saveCalendar()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors">
                Create
            </button>
        </div>
    </div>
</div>

<!-- ── Event detail modal (read-only, public view) ──────────────────────── -->
<div id="detail-modal"
     onclick="if(event.target===this)closeDetailModal()"
     class="fixed inset-0 z-50 items-center justify-center p-4 bg-black/50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md flex flex-col max-h-[90vh]"
         onclick="event.stopPropagation()">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h2 id="detail-title" class="text-base font-semibold text-gray-900 pr-4"></h2>
            <button onclick="closeDetailModal()" class="p-1 rounded-lg text-gray-400 hover:text-gray-600 transition-colors shrink-0">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div id="detail-body" class="flex-1 overflow-y-auto px-6 py-4 space-y-3">
        </div>
    </div>
</div>

<script>window.__CSRF = <?= json_encode($isPublic ? '' : $csrf) ?>; window.__WEEK_START = <?= $weekStartDay ?>; window.__IS_PUBLIC = <?= json_encode($isPublic) ?>;</script>
<script src="app.js"></script>
<?php endif; ?>

</body>
</html>
