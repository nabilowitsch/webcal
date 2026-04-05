<?php
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
$csrf     = csrfToken();
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WebCal</title>
    <link rel="stylesheet" href="public/styles.css">
    <link rel="stylesheet" href="public/custom.css">
</head>
<body class="bg-gray-50 min-h-screen font-sans antialiased text-gray-900">

<?php if (!$loggedIn): ?>
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
            <p class="text-[10px] font-bold tracking-widest text-gray-400 uppercase px-3 mb-2">Calendars</p>
            <div id="calendar-list">
                <div class="px-3 py-2 text-sm text-gray-400">Loading…</div>
            </div>
        </div>

        <!-- User / sign out -->
        <div class="shrink-0 px-4 py-3 border-t border-gray-100">
            <div class="flex items-center justify-between text-sm gap-2">
                <span class="text-gray-600 font-medium truncate"><?= htmlspecialchars($_SESSION['display_name']) ?></span>
                <a href="index.php?logout=1" class="text-xs text-gray-400 hover:text-red-500 transition-colors shrink-0">Sign out</a>
            </div>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <!-- Topbar -->
        <header class="flex items-center gap-3 px-4 h-14 bg-white border-b border-gray-100 shrink-0">
            <button onclick="openSidebar()"
                    class="lg:hidden p-2 -ml-1 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="flex items-center gap-1 flex-1 min-w-0">
                <h1 id="view-heading" class="text-sm font-semibold text-gray-800"></h1>
                <div id="month-nav" class="hidden items-center gap-1">
                    <button onclick="changeMonth(-1)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <span id="month-label" class="text-sm font-semibold text-gray-800 min-w-32 text-center select-none"></span>
                    <button onclick="changeMonth(1)"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
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
        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100 shrink-0">
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

<script>window.__CSRF = <?= json_encode($csrf) ?>; window.__WEEK_START = <?= $weekStartDay ?>;</script>
<script src="public/app.js"></script>
<?php endif; ?>

</body>
</html>
