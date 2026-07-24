<?php
/**
 * PHP Environment Console — a standalone, dependency-free replacement for phpinfo().
 *
 * One self-contained file. No CDN, no framework, no build step. Works on any
 * PHP 8.0+ install regardless of OS or SAPI, online or fully offline.
 *
 * Access: localhost only by default. To view remotely, set the environment
 * variable PHPINFO_TOKEN and append ?token=... to the URL. Delete this file
 * when you no longer need it — it exposes configuration detail.
 */

declare(strict_types=1);

// ===================================================================
//  ACCESS CONTROL
// ===================================================================
(function (): void {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $isLocal = $remote === ''
        || in_array($remote, ['127.0.0.1', '::1'], true)
        || php_sapi_name() === 'cli-server';

    $token = getenv('PHPINFO_TOKEN') ?: '';
    $given = (string) ($_GET['token'] ?? '');
    $tokenOk = $token !== '' && hash_equals($token, $given);

    if (!$isLocal && !$tokenOk) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Forbidden.\nThis console is restricted to localhost. "
            . "Set PHPINFO_TOKEN and pass ?token=... to view remotely.");
    }
})();

// ===================================================================
//  HELPERS
// ===================================================================
function bytes(string|int|false|null $val): int
{
    $val = (string) $val;
    if ($val === '-1') {
        return PHP_INT_MAX;
    }
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMGT]?)/i', $val, $m)) {
        $n = (float) $m[1];
        $mult = match (strtoupper($m[2])) {
            'T' => 1024 ** 4, 'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };
        return (int) ($n * $mult);
    }
    return 0;
}

function fmtBytes(int $b): string
{
    if ($b === PHP_INT_MAX) {
        return 'unlimited';
    }
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $b;
    while ($v >= 1024 && $i < count($u) - 1) {
        $v /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($v, 1), '0'), '.') . ' ' . $u[$i];
}

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function iniDisplay(mixed $v): string
{
    return match (true) {
        is_array($v) => 'Array',
        $v === false => 'off',
        $v === true => 'on',
        $v === null || $v === '' => '—',
        default => (string) $v,
    };
}

/**
 * Parse phpinfo() into structured [section => [key => value|[local,master]]].
 * Handles BOTH the HTML output (web SAPI) and the plain-text output (CLI),
 * so it behaves identically everywhere. This avoids embedding phpinfo's own
 * markup, whose styling and structure drift between PHP versions.
 */
function parsePhpinfo(): array
{
    ob_start();
    phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
    $raw = (string) ob_get_clean();

    $sections = [];

    if (stripos($raw, '<table') !== false || stripos($raw, '<h2') !== false) {
        // ---- HTML form (web SAPI) ----
        $raw = preg_replace('/<a[^>]*>|<\/a>/i', '', $raw);
        $parts = preg_split('/<h2[^>]*>(.*?)<\/h2>/is', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i < count($parts); $i += 2) {
            $title = trim(html_entity_decode(strip_tags($parts[$i]), ENT_QUOTES));
            $body = $parts[$i + 1] ?? '';
            if ($title === '' || strcasecmp($title, 'Credits') === 0) {
                continue;
            }
            $rows = [];
            if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $body, $trs)) {
                foreach ($trs[1] as $tr) {
                    if (!preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $cells)) {
                        continue;
                    }
                    $vals = array_map(
                        fn($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES)),
                        $cells[1]
                    );
                    if (count($vals) === 2) {
                        $rows[$vals[0]] = $vals[1];
                    } elseif (count($vals) >= 3) {
                        $rows[$vals[0]] = ['local' => $vals[1], 'master' => $vals[2]];
                    }
                }
            }
            if ($rows) {
                $sections[$title] = $rows;
            }
        }
    } else {
        // ---- Plain-text form (CLI) ----
        $lines = preg_split('/\R/', $raw);
        $current = 'General';
        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || $line === 'phpinfo()') {
                continue;
            }
            if (str_contains($line, ' => ')) {
                $bits = array_map('trim', explode(' => ', $line));
                if ($bits[0] === 'Directive' || $bits[0] === '') {
                    continue;
                }
                if (count($bits) >= 3) {
                    $sections[$current][$bits[0]] = ['local' => $bits[1], 'master' => $bits[2]];
                } else {
                    $sections[$current][$bits[0]] = $bits[1] ?? '';
                }
            } else {
                $current = $line;
            }
        }
    }

    ksort($sections);
    return $sections;
}

// ===================================================================
//  DATA COLLECTION
// ===================================================================
$phpVersion = PHP_VERSION;
$sapi = php_sapi_name();
$osFamily = PHP_OS_FAMILY;
$os = $osFamily . ' · ' . php_uname('s') . ' ' . php_uname('r');
$arch = php_uname('m');
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'CLI / not served over HTTP';
$loadedIni = php_ini_loaded_file() ?: 'none (built-in defaults)';
$scannedIni = php_ini_scanned_files() ?: '—';
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? (getcwd() ?: '—');
$serverTime = date('Y-m-d H:i:s');
$timezone = ini_get('date.timezone') ?: '(not set)';
$zendVersion = zend_version();

$limits = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_vars' => ini_get('max_input_vars'),
];
$recommended = [
    'memory_limit' => '256M', 'max_execution_time' => '30', 'max_input_time' => '60',
    'upload_max_filesize' => '20M', 'post_max_size' => '20M', 'max_input_vars' => '1000',
];
$limitGroups = [
    'Execution & time' => [
        'max_execution_time' => ['risky' => fn($v) => (int) $v === 0, 'hint' => 'Max seconds a script may run. 0 means unlimited.'],
        'max_input_time' => ['risky' => fn($v) => (int) $v !== -1 && (int) $v < 30, 'hint' => 'Max seconds to parse request input.'],
    ],
    'Memory' => [
        'memory_limit' => ['risky' => fn($v) => bytes((string) $v) < bytes('128M'), 'hint' => 'Low memory causes fatal errors under load.'],
    ],
    'Uploads' => [
        'upload_max_filesize' => ['risky' => fn($v) => bytes((string) $v) < bytes('8M'), 'hint' => 'Largest single file a client can upload.'],
        'post_max_size' => ['risky' => fn($v) => bytes((string) $v) < bytes((string) $limits['upload_max_filesize']), 'hint' => 'Must be at least upload_max_filesize or uploads fail silently.'],
    ],
    'Input' => [
        'max_input_vars' => ['risky' => fn($v) => (int) $v < 1000, 'hint' => 'Too low truncates large forms without warning.'],
    ],
];

$extensions = get_loaded_extensions();
sort($extensions, SORT_STRING | SORT_FLAG_CASE);
$requiredExtensions = ['curl', 'mbstring', 'openssl', 'json', 'pdo', 'gd', 'zip'];
$loadedLower = array_map('strtolower', $extensions);
$missingExtensions = array_values(array_filter($requiredExtensions, fn($x) => !in_array(strtolower($x), $loadedLower, true)));

// Per-extension version (many report false; that's fine — shown as "—").
$extVersions = [];
foreach ($extensions as $ext) {
    $v = @phpversion($ext);
    $extVersions[$ext] = ($v && $v !== '') ? $v : null;
}

// Map parsed phpinfo sections to extensions (case-insensitive) so the detail
// panel can show each extension's own directives without re-scanning.
// (Built after parsePhpinfo() below.)

// Database & PDO capability.
$pdoDrivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
$dbInfo = [];
if (function_exists('mysqli_get_client_info')) {
    $dbInfo['mysqli client'] = mysqli_get_client_info();
}
if (defined('PGSQL_LIBPQ_VERSION')) {
    $dbInfo['libpq'] = PGSQL_LIBPQ_VERSION;
}
if (extension_loaded('pdo_sqlite') && class_exists('PDO')) {
    $dbInfo['SQLite (PDO)'] = 'available';
}

// Runtime capabilities — cheap one-liners, high "can this box do X" value.
$capabilities = [
    'Stream wrappers' => stream_get_wrappers(),
    'Stream transports' => stream_get_transports(),
    'Stream filters' => stream_get_filters(),
    'Hash algorithms' => function_exists('hash_algos') ? hash_algos() : [],
];

$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$opcacheConf = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : null;
$opcacheOn = is_array($opcache) && ($opcache['opcache_enabled'] ?? false);
$opPct = 0;
$opHitRate = null;
$opKeyPct = null;
if ($opcacheOn) {
    $used = $opcache['memory_usage']['used_memory'] ?? 0;
    $free = $opcache['memory_usage']['free_memory'] ?? 0;
    $tot = $used + $free;
    $opPct = $tot ? (int) round($used / $tot * 100) : 0;
    $st = $opcache['opcache_statistics'] ?? [];
    $h = (int) ($st['hits'] ?? 0);
    $m = (int) ($st['misses'] ?? 0);
    $opHitRate = ($h + $m) ? round($h / ($h + $m) * 100, 1) : 0.0;
    $numKeys = (int) ($st['num_cached_keys'] ?? 0);
    $maxKeys = (int) ($st['max_cached_keys'] ?? 0);
    $opKeyPct = $maxKeys ? (int) round($numKeys / $maxKeys * 100) : null;
}

// Live memory of THIS request (a real "is the runtime healthy now" signal).
$memNow = memory_get_usage(true);
$memPeak = memory_get_peak_usage(true);
$memCap = bytes((string) $limits['memory_limit']);
$memPct = ($memCap > 0 && $memCap !== PHP_INT_MAX) ? min(100, (int) round($memPeak / $memCap * 100)) : null;

// Decode error_reporting() into named constants.
$erLevel = error_reporting();
$erNames = [];
$erFlags = [
    'E_ERROR' => E_ERROR, 'E_WARNING' => E_WARNING, 'E_PARSE' => E_PARSE, 'E_NOTICE' => E_NOTICE,
    'E_CORE_ERROR' => E_CORE_ERROR, 'E_CORE_WARNING' => E_CORE_WARNING,
    'E_COMPILE_ERROR' => E_COMPILE_ERROR, 'E_COMPILE_WARNING' => E_COMPILE_WARNING,
    'E_USER_ERROR' => E_USER_ERROR, 'E_USER_WARNING' => E_USER_WARNING, 'E_USER_NOTICE' => E_USER_NOTICE,
    'E_RECOVERABLE_ERROR' => E_RECOVERABLE_ERROR, 'E_DEPRECATED' => E_DEPRECATED, 'E_USER_DEPRECATED' => E_USER_DEPRECATED,
];
if (($erLevel & E_ALL) === E_ALL) {
    $erSummary = 'E_ALL';
    $off = [];
    foreach ($erFlags as $name => $bit) {
        if (!($erLevel & $bit)) {
            $off[] = '~' . $name;
        }
    }
    if ($off) {
        $erSummary .= ' & ' . implode(' & ', $off);
    }
} else {
    foreach ($erFlags as $name => $bit) {
        if ($erLevel & $bit) {
            $erNames[] = $name;
        }
    }
    $erSummary = $erNames ? implode(' | ', $erNames) : ($erLevel === 0 ? 'off (0)' : (string) $erLevel);
}

// Session configuration.
$session = [
    'save_handler' => ini_get('session.save_handler') ?: '—',
    'save_path' => ini_get('session.save_path') ?: '(system default)',
    'gc_maxlifetime' => ini_get('session.gc_maxlifetime') . ' s',
    'cookie_lifetime' => ini_get('session.cookie_lifetime') . ' s',
    'name' => ini_get('session.name') ?: '—',
];

// Composer detection — guarded so it never hangs or errors.
$composer = ['state' => 'unknown', 'path' => null, 'version' => null];
$disabledList = array_map('trim', array_filter(explode(',', (string) ini_get('disable_functions'))));
$execOk = function_exists('shell_exec') && !in_array('shell_exec', $disabledList, true);
if ($execOk) {
    $lookup = $osFamily === 'Windows' ? 'where composer 2>NUL' : 'command -v composer 2>/dev/null';
    $path = trim((string) @shell_exec($lookup));
    if ($path !== '') {
        $composer['state'] = 'installed';
        $composer['path'] = strtok($path, "\r\n");
        $ver = trim((string) @shell_exec('composer --version 2>&1'));
        $composer['version'] = $ver !== '' ? preg_replace('/\s+/', ' ', $ver) : 'unknown';
    } else {
        $composer['state'] = 'absent';
    }
} else {
    $composer['state'] = 'blocked';
}

sort($disabledList);

// Security posture.
$securityChecks = [
    'display_errors' => ['want' => false, 'note' => 'Should be off in production.'],
    'expose_php' => ['want' => false, 'note' => 'Removes the version from response headers.'],
    'allow_url_fopen' => ['want' => false, 'note' => 'Remote file reads. Off unless you need them.'],
    'allow_url_include' => ['want' => false, 'note' => 'Must be off. Remote code inclusion risk.'],
    'session.cookie_httponly' => ['want' => true, 'note' => 'Blocks JavaScript from reading the session cookie.'],
    'session.cookie_secure' => ['want' => true, 'note' => 'Sends the session cookie over HTTPS only.'],
    'session.use_strict_mode' => ['want' => true, 'note' => 'Rejects attacker-supplied session IDs.'],
];

// PHP release lifecycle. Dates are the official php.net schedule.
// EOL data current as of July 2026 — refresh from https://www.php.net/supported-versions.php
$eolSchedule = [
    '8.5' => ['active' => '2027-11-20', 'eol' => '2029-12-31'],
    '8.4' => ['active' => '2026-12-31', 'eol' => '2028-12-31'],
    '8.3' => ['active' => '2025-11-23', 'eol' => '2027-12-31'],
    '8.2' => ['active' => '2024-12-31', 'eol' => '2026-12-31'],
    '8.1' => ['active' => '2023-11-25', 'eol' => '2025-12-31'],
    '8.0' => ['active' => '2022-11-26', 'eol' => '2023-11-26'],
];
$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$today = new DateTimeImmutable('today');
$eol = ['state' => 'unknown', 'label' => 'Unknown branch', 'detail' => 'No lifecycle data for ' . $branch . '.', 'daysToEol' => null, 'eolDate' => null];
if (isset($eolSchedule[$branch])) {
    $activeUntil = new DateTimeImmutable($eolSchedule[$branch]['active']);
    $eolDate = new DateTimeImmutable($eolSchedule[$branch]['eol']);
    $daysToEol = (int) $today->diff($eolDate)->format('%r%a');
    $eol['eolDate'] = $eolDate->format('M j, Y');
    $eol['daysToEol'] = $daysToEol;
    if ($daysToEol < 0) {
        $eol = ['state' => 'eol', 'label' => 'End of life', 'detail' => "Security support ended {$eol['eolDate']}. No further patches — upgrade.", 'daysToEol' => $daysToEol, 'eolDate' => $eol['eolDate']];
    } elseif ($daysToEol < 180) {
        $eol = ['state' => 'soon', 'label' => 'Approaching EOL', 'detail' => "Security support ends {$eol['eolDate']} — about " . round($daysToEol / 30) . " months. Plan an upgrade.", 'daysToEol' => $daysToEol, 'eolDate' => $eol['eolDate']];
    } elseif ($today < $activeUntil) {
        $eol = ['state' => 'active', 'label' => 'Actively supported', 'detail' => "Active (bug + security) support until {$activeUntil->format('M j, Y')}; EOL {$eol['eolDate']}.", 'daysToEol' => $daysToEol, 'eolDate' => $eol['eolDate']];
    } else {
        $eol = ['state' => 'security', 'label' => 'Security-only support', 'detail' => "Bug fixes ended {$activeUntil->format('M j, Y')}; security patches until {$eol['eolDate']}.", 'daysToEol' => $daysToEol, 'eolDate' => $eol['eolDate']];
    }
}

// Health signals.
$healthChecks = [
    'Supported PHP release' => in_array($eol['state'], ['active', 'security', 'soon'], true),
    'Memory ≥ 128M' => bytes((string) $limits['memory_limit']) >= bytes('128M'),
    'OPcache on' => $opcacheOn,
    'Timezone set' => $timezone !== '(not set)',
    'Temp dir writable' => is_writable(sys_get_temp_dir()),
    'Core extensions present' => empty($missingExtensions),
    'display_errors off' => !filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN),
];
$healthScore = count(array_filter($healthChecks));
$healthTotal = count($healthChecks);
$healthy = $healthScore === $healthTotal;

// Where each failing check should send the user.
$healthJump = [
    'Supported PHP release' => 'health',
    'Memory ≥ 128M' => 'limits',
    'OPcache on' => 'opcache',
    'Timezone set' => 'phpinfo',
    'Temp dir writable' => 'health',
    'Core extensions present' => 'extensions',
    'display_errors off' => 'security',
];

$phpinfoSections = parsePhpinfo();

// Match parsed phpinfo sections to loaded extensions (case-insensitive),
// so the extension detail panel can list each one's own directives.
$extDirectives = [];
$sectionLower = [];
foreach ($phpinfoSections as $sec => $rows) {
    $sectionLower[strtolower($sec)] = $sec;
}
foreach ($extensions as $ext) {
    $key = strtolower($ext);
    // phpinfo section names sometimes differ (e.g. "Zend OPcache" vs "opcache").
    $match = $sectionLower[$key] ?? null;
    if ($match === null) {
        foreach ($sectionLower as $lk => $orig) {
            if (str_contains($lk, $key) || str_contains($key, $lk)) {
                $match = $orig;
                break;
            }
        }
    }
    if ($match !== null) {
        $extDirectives[$ext] = $phpinfoSections[$match];
    }
}

$copyReport = [
    'generated' => date('c'),
    'php' => $phpVersion,
    'php_branch' => $branch,
    'lifecycle' => $eol['label'] . ($eol['eolDate'] ? " (EOL {$eol['eolDate']})" : ''),
    'zend' => $zendVersion,
    'sapi' => $sapi,
    'os' => $os,
    'arch' => $arch,
    'server' => $serverSoftware,
    'loaded_ini' => $loadedIni,
    'memory_limit' => $limits['memory_limit'],
    'memory_peak' => fmtBytes($memPeak),
    'upload_max_filesize' => $limits['upload_max_filesize'],
    'post_max_size' => $limits['post_max_size'],
    'error_reporting' => $erSummary,
    'opcache' => $opcacheOn ? "{$opPct}% used, {$opHitRate}% hit rate" : 'disabled',
    'extensions_loaded' => count($extensions),
    'missing_core_extensions' => $missingExtensions,
    'health' => "{$healthScore}/{$healthTotal}",
];
?>
<!doctype html>
<html lang="en" data-theme="dark">

<head>
    <meta charset="utf-8">
    <title>PHP Console · <?= e($phpVersion) ?></title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* =========================================================
           DESIGN TOKENS — an instrument-panel aesthetic.
        ========================================================= */
        :root {
            --violet: #7c6af7;
            --violet-dim: #6455d6;
            --indigo: #4f46e5;
            --ok: #34d399;
            --warn: #fbbf24;
            --bad: #fb7185;
            --font-ui: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            --font-mono: ui-monospace, "SF Mono", "JetBrains Mono", "Cascadia Code", Menlo, Consolas, monospace;
            --radius: 10px;
            --radius-sm: 6px;
        }

        html[data-theme="dark"] {
            --bg: #0d131c;
            --bg-grid: #111a26;
            --panel: #151f2e;
            --panel-2: #1b2738;
            --edge: #263447;
            --edge-soft: #1e2a3a;
            --ink: #e6edf6;
            --ink-dim: #94a6bd;
            --ink-faint: #5f7288;
            --accent-glow: 0 0 0 1px rgba(124, 106, 247, .35), 0 4px 20px -6px rgba(124, 106, 247, .5);
        }

        html[data-theme="light"] {
            --bg: #eef1f6;
            --bg-grid: #e6eaf1;
            --panel: #ffffff;
            --panel-2: #f5f7fb;
            --edge: #d5dce6;
            --edge-soft: #e4e9f0;
            --ink: #17202e;
            --ink-dim: #4c5b70;
            --ink-faint: #8493a6;
            --violet: #6455d6;
            --accent-glow: 0 0 0 1px rgba(100, 85, 214, .25), 0 4px 18px -6px rgba(100, 85, 214, .35);
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }

        body {
            font-family: var(--font-ui);
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 78% -8%, color-mix(in srgb, var(--violet) 10%, transparent), transparent 60%),
                var(--bg);
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            display: flex;
            overflow: hidden;
        }

        /* ---------- SIDEBAR ---------- */
        .rail {
            width: 236px;
            flex: 0 0 236px;
            background: linear-gradient(180deg, var(--panel), var(--bg-grid));
            border-right: 1px solid var(--edge);
            display: flex;
            flex-direction: column;
            padding: 18px 14px;
            gap: 4px;
            transition: transform .22s ease;
        }

        .brand {
            display: flex; align-items: center; gap: 10px;
            padding: 4px 8px 14px;
            font-weight: 650; letter-spacing: .2px;
        }
        .brand .mark {
            width: 52px; height: auto; flex: 0 0 52px;
            padding: 5px 6px; border-radius: 8px;
            background: var(--panel-2);
            border: 1px solid var(--edge);
            box-shadow: var(--accent-glow);
        }
        .brand small { display: block; color: var(--ink-faint); font-weight: 500; font-size: 11px; }

        .navbtn {
            display: flex; align-items: center; gap: 11px;
            width: 100%; text-align: left;
            padding: 9px 11px;
            border: 1px solid transparent; border-radius: var(--radius-sm);
            background: none; color: var(--ink-dim);
            font: inherit; font-size: 13.5px; cursor: pointer;
            transition: background .12s, color .12s, border-color .12s;
        }
        .navbtn svg { width: 17px; height: 17px; flex: 0 0 17px; opacity: .85; }
        .navbtn:hover { background: var(--panel-2); color: var(--ink); }
        .navbtn.active {
            background: color-mix(in srgb, var(--violet) 16%, transparent);
            border-color: color-mix(in srgb, var(--violet) 40%, transparent);
            color: var(--ink);
        }
        .navbtn.active svg { opacity: 1; color: var(--violet); }

        .rail-spacer { flex: 1; }
        .rail-sep { height: 1px; background: var(--edge-soft); margin: 10px 4px; }

        .toolbtn {
            display: flex; align-items: center; gap: 9px;
            width: 100%; padding: 8px 11px;
            border: 1px solid var(--edge); border-radius: var(--radius-sm);
            background: var(--panel-2); color: var(--ink-dim);
            font: inherit; font-size: 12.5px; cursor: pointer;
        }
        .toolbtn:hover { color: var(--ink); border-color: var(--violet); }
        .toolbtn svg { width: 16px; height: 16px; }

        /* ---------- MAIN ---------- */
        .main { flex: 1; overflow-y: auto; height: 100vh; }

        .topbar {
            position: sticky; top: 0; z-index: 20;
            display: flex; align-items: center; gap: 14px;
            padding: 13px 22px;
            background: color-mix(in srgb, var(--bg) 82%, transparent);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--edge);
        }
        .hamburger { display: none; }
        .topbar .ver { font-family: var(--font-mono); font-size: 13px; color: var(--ink-dim); }
        .topbar .ver b { color: var(--ink); }

        .gauge { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .gauge-label { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-faint); }
        .gauge-strip { display: flex; gap: 3px; }
        .seg { width: 16px; height: 9px; border-radius: 2px; background: var(--edge); transition: background .3s; }
        .seg.lit { background: var(--ok); box-shadow: 0 0 8px -1px var(--ok); }
        .gauge.warn .seg.lit { background: var(--warn); box-shadow: 0 0 8px -1px var(--warn); }
        .gauge .count { font-family: var(--font-mono); font-size: 13px; color: var(--ink); }

        .view { padding: 24px 22px 60px; max-width: 1180px; }
        .view[hidden] { display: none; }

        .eyebrow { font-size: 11px; letter-spacing: .1em; text-transform: uppercase; color: var(--ink-faint); margin: 0 0 14px; }
        h2.section-title { margin: 30px 0 12px; font-size: 15px; font-weight: 600; }
        h2.section-title:first-of-type { margin-top: 4px; }

        /* ---------- CARDS / GRID ---------- */
        .grid { display: grid; gap: 14px; }
        .grid.stats { grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
        .grid.two { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

        .card { background: var(--panel); border: 1px solid var(--edge); border-radius: var(--radius); overflow: hidden; }
        .card-pad { padding: 16px 18px; }
        .card-head {
            padding: 12px 18px; border-bottom: 1px solid var(--edge-soft);
            font-weight: 600; font-size: 13px;
            display: flex; align-items: center; gap: 9px;
        }
        .card-head svg { width: 16px; height: 16px; color: var(--violet); }

        .stat .label { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-faint); }
        .stat .value { font-family: var(--font-mono); font-size: 22px; font-weight: 600; margin: 6px 0 3px; line-height: 1; }
        .stat .sub { font-size: 12px; color: var(--ink-dim); }

        .radial { display: grid; place-items: center; }
        .radial svg { width: 96px; height: 96px; }
        .radial .track { stroke: var(--edge); }
        .radial .fill { stroke: var(--violet); transition: stroke-dasharray .6s ease; }
        .radial .pct { font-family: var(--font-mono); font-size: 9px; font-weight: 600; fill: var(--ink); }

        /* ---------- KEY/VALUE ---------- */
        .kv { display: flex; flex-direction: column; }
        .kv .row { display: flex; justify-content: space-between; gap: 16px; padding: 10px 18px; border-top: 1px solid var(--edge-soft); }
        .kv .row:first-child { border-top: none; }
        .kv .k { color: var(--ink-dim); }
        .kv .v { font-family: var(--font-mono); text-align: right; word-break: break-all; }

        .mono { font-family: var(--font-mono); }
        .dim { color: var(--ink-dim); }
        .faint { color: var(--ink-faint); }

        /* ---------- PILLS ---------- */
        .pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; font-family: var(--font-mono); border: 1px solid transparent; }
        .pill.ok { color: var(--ok); background: color-mix(in srgb, var(--ok) 14%, transparent); border-color: color-mix(in srgb, var(--ok) 30%, transparent); }
        .pill.warn { color: var(--warn); background: color-mix(in srgb, var(--warn) 14%, transparent); border-color: color-mix(in srgb, var(--warn) 30%, transparent); }
        .pill.bad { color: var(--bad); background: color-mix(in srgb, var(--bad) 14%, transparent); border-color: color-mix(in srgb, var(--bad) 30%, transparent); }
        .pill.muted { color: var(--ink-dim); background: var(--panel-2); border-color: var(--edge); }

        /* ---------- LIMITS ---------- */
        .limit { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 13px 18px; border-top: 1px solid var(--edge-soft); }
        .limit:first-child { border-top: none; }
        .limit .name { font-family: var(--font-mono); font-weight: 600; font-size: 13px; }
        .limit .hint { color: var(--ink-faint); font-size: 12px; margin-top: 3px; }
        .limit .rec { color: var(--ink-faint); font-size: 12px; margin-top: 2px; }
        .limit .right { text-align: right; flex: 0 0 auto; }
        .limit .cur { font-family: var(--font-mono); font-weight: 600; margin-bottom: 5px; }

        /* ---------- EXTENSIONS ---------- */
        .ext-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 7px; }
        .ext { font-family: var(--font-mono); font-size: 12.5px; padding: 6px 10px; border-radius: var(--radius-sm); border: 1px solid var(--edge); background: var(--panel-2); color: var(--ink-dim); }
        .ext.req { color: var(--violet); border-color: color-mix(in srgb, var(--violet) 45%, transparent); background: color-mix(in srgb, var(--violet) 10%, transparent); }

        /* ---------- SEARCH + TABLES ---------- */
        .search { display: flex; align-items: center; gap: 9px; padding: 9px 13px; margin-bottom: 14px; border: 1px solid var(--edge); border-radius: var(--radius-sm); background: var(--panel); }
        .search:focus-within { border-color: var(--violet); box-shadow: var(--accent-glow); }
        .search svg { width: 16px; height: 16px; color: var(--ink-faint); }
        .search input { flex: 1; border: none; background: none; color: var(--ink); font: inherit; outline: none; }
        .search kbd { font-family: var(--font-mono); font-size: 11px; color: var(--ink-faint); border: 1px solid var(--edge); border-radius: 4px; padding: 1px 6px; background: var(--panel-2); }

        table.data { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.data th { text-align: left; font-weight: 600; font-size: 11px; letter-spacing: .05em; text-transform: uppercase; color: var(--ink-faint); padding: 8px 14px; border-bottom: 1px solid var(--edge); position: sticky; top: 0; background: var(--panel); z-index: 1; }
        table.data td { padding: 8px 14px; border-bottom: 1px solid var(--edge-soft); vertical-align: top; }
        table.data td.k { color: var(--ink-dim); font-family: var(--font-mono); white-space: nowrap; }
        table.data td.v { font-family: var(--font-mono); word-break: break-all; }
        table.data tr:hover td { background: var(--panel-2); }
        .table-wrap { border: 1px solid var(--edge); border-radius: var(--radius); overflow: hidden; }
        .table-scroll { max-height: 68vh; overflow: auto; }

        .empty { padding: 26px; text-align: center; color: var(--ink-faint); font-size: 13px; }
        .empty[hidden] { display: none; }

        .progress { height: 20px; border-radius: var(--radius-sm); background: var(--edge); overflow: hidden; border: 1px solid var(--edge); }
        .progress > i { display: grid; place-items: center; height: 100%; background: linear-gradient(90deg, var(--violet-dim), var(--violet)); color: #fff; font-family: var(--font-mono); font-size: 11px; font-style: normal; transition: width .6s ease; }

        .note { color: var(--ink-dim); font-size: 13px; }
        .note code, code.inline { font-family: var(--font-mono); font-size: 12.5px; background: var(--panel-2); border: 1px solid var(--edge); border-radius: 4px; padding: 1px 6px; }

        /* ---------- COMPACT MODE ---------- */
        body.compact { font-size: 12.5px; }
        body.compact .view { padding: 16px 18px 44px; }
        body.compact .card-pad { padding: 11px 13px; }
        body.compact .card-head { padding: 9px 13px; font-size: 12px; }
        body.compact .kv .row, body.compact .limit { padding: 7px 13px; }
        body.compact table.data td, body.compact table.data th { padding: 5px 11px; }
        body.compact .stat .value { font-size: 18px; }
        body.compact .grid { gap: 10px; }
        body.compact h2.section-title { margin: 18px 0 9px; }

        /* ---------- MOBILE ---------- */
        .scrim { display: none; }
        @media (max-width: 860px) {
            .rail { position: fixed; inset: 0 auto 0 0; z-index: 60; transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,.4); }
            body.nav-open .rail { transform: translateX(0); }
            .scrim { position: fixed; inset: 0; z-index: 50; background: rgba(0,0,0,.5); opacity: 0; pointer-events: none; transition: opacity .2s; }
            body.nav-open .scrim { display: block; opacity: 1; pointer-events: auto; }
            .hamburger { display: grid; place-items: center; width: 38px; height: 38px; flex: 0 0 38px; border: 1px solid var(--edge); border-radius: var(--radius-sm); background: var(--panel); color: var(--ink); cursor: pointer; }
            .hamburger svg { width: 18px; height: 18px; }
            .gauge-strip { display: none; }
            .view { padding: 16px 14px 44px; }
        }

        /* clickable rows in "needs attention" and health */
        .row-btn { font: inherit; text-align: left; background: none; cursor: pointer; align-items: center; border-radius: 0; }
        .row-btn:hover { background: var(--panel-2); }
        .row-btn:hover .pill.warn, .row-btn:hover .pill.bad { filter: brightness(1.15); }

        /* interactive extension buttons */
        .ext-btn { display: flex; align-items: center; justify-content: space-between; gap: 8px; cursor: pointer; text-align: left; }
        .ext-btn:hover { border-color: var(--violet); color: var(--ink); }
        .ext-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ext-ver { font-size: 10.5px; color: var(--ink-faint); flex: 0 0 auto; }
        .ext.req .ext-ver { color: color-mix(in srgb, var(--violet) 75%, var(--ink-faint)); }

        /* extension detail dialog */
        .ext-dialog { border: 1px solid var(--edge); border-radius: var(--radius); background: var(--panel); color: var(--ink); padding: 0; max-width: 640px; width: calc(100% - 32px); box-shadow: 0 20px 60px -12px rgba(0,0,0,.5); }
        .ext-dialog::backdrop { background: rgba(0,0,0,.55); backdrop-filter: blur(2px); }
        .ext-dialog-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--edge-soft); }
        #extDialogBody { padding: 6px 0 8px; max-height: 60vh; overflow: auto; }

        /* config diff */
        .drop-zone { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 26px; border: 1.5px dashed var(--edge); border-radius: var(--radius); transition: border-color .15s, background .15s; }
        .drop-zone.drag { border-color: var(--violet); background: color-mix(in srgb, var(--violet) 8%, transparent); }
        .link-btn { color: var(--violet); cursor: pointer; text-decoration: underline; }
        .diff-add { color: var(--ok); }
        .diff-del { color: var(--bad); }
        td.diff-val-a { color: var(--bad); }
        td.diff-val-b { color: var(--ok); }
        .diff-summary { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
        .diff-stat { font-family: var(--font-mono); font-size: 13px; }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { transition: none !important; } }
        :focus-visible { outline: 2px solid var(--violet); outline-offset: 2px; border-radius: 3px; }

        /* =========================================================
           PRINT — expand everything, drop chrome, force light ink.
           Turns the page into a single shareable / PDF-able report.
        ========================================================= */
        @media print {
            :root, html[data-theme="dark"], html[data-theme="light"] {
                --bg: #fff; --bg-grid: #fff; --panel: #fff; --panel-2: #fff;
                --edge: #ccc; --edge-soft: #e2e2e2;
                --ink: #000; --ink-dim: #333; --ink-faint: #666; --violet: #4f46e5;
            }
            body { display: block; overflow: visible; background: #fff; font-size: 11px; }
            .rail, .topbar, .scrim, .search, .toolbtn, .row-btn > .pill { display: none !important; }
            .main { height: auto; overflow: visible; }
            .view { display: block !important; max-width: none; padding: 0 0 24px; }
            .view[hidden] { display: block !important; }
            .view[data-view]::before {
                content: attr(data-view);
                display: block; text-transform: uppercase; letter-spacing: .08em;
                font-size: 13px; font-weight: 700; margin: 18px 0 8px;
                border-bottom: 2px solid #000; padding-bottom: 3px;
            }
            .card { break-inside: avoid; box-shadow: none; margin-bottom: 8px; }
            .table-scroll { max-height: none; overflow: visible; }
            table.data th { position: static; }
            .grid.stats, .grid.two { display: block; }
            .grid.stats > *, .grid.two > * { margin-bottom: 8px; }
            a[href]::after { content: ""; }
        }
    </style>
</head>

<body>
    <div class="scrim" onclick="closeNav()"></div>

    <!-- ================= SIDEBAR ================= -->
    <nav class="rail" id="rail" aria-label="Sections">
        <div class="brand">
            <svg class="mark" viewBox="0 0 711.20123 383.5975" role="img" aria-label="PHP" xmlns="http://www.w3.org/2000/svg"><defs id="defs3434"><clipPath clipPathUnits="userSpaceOnUse" id="clipPath3444"><path d="M 11.52,162 C 11.52,81.677 135.307,16.561 288,16.561 l 0,0 c 152.693,0 276.481,65.116 276.481,145.439 l 0,0 c 0,80.322 -123.788,145.439 -276.481,145.439 l 0,0 C 135.307,307.439 11.52,242.322 11.52,162" id="path3446"/></clipPath><radialGradient cx="0" cy="0" fx="0" fy="0" gradientTransform="matrix(363.05789,0,0,-363.05789,177.52002,256.30713)" gradientUnits="userSpaceOnUse" id="radialGradient3452" r="1" spreadMethod="pad"><stop id="stop3454" offset="0" style="stop-opacity:1;stop-color:#aeb2d5"/><stop id="stop3456" offset="0.3" style="stop-opacity:1;stop-color:#aeb2d5"/><stop id="stop3458" offset="0.75" style="stop-opacity:1;stop-color:#484c89"/><stop id="stop3460" offset="1" style="stop-opacity:1;stop-color:#484c89"/></radialGradient><clipPath clipPathUnits="userSpaceOnUse" id="clipPath3468"><path d="M 0,324 576,324 576,0 0,0 0,324 Z" id="path3470"/></clipPath><clipPath clipPathUnits="userSpaceOnUse" id="clipPath3480"><path d="M 0,324 576,324 576,0 0,0 0,324 Z" id="path3482"/></clipPath></defs><g id="g3438" transform="matrix(1.25,0,0,-1.25,-4.4,394.29875)"><g id="g3440"><g clip-path="url(#clipPath3444)" id="g3442"><g id="g3448"><g id="g3450"><path d="M 11.52,162 C 11.52,81.677 135.307,16.561 288,16.561 l 0,0 c 152.693,0 276.481,65.116 276.481,145.439 l 0,0 c 0,80.322 -123.788,145.439 -276.481,145.439 l 0,0 C 135.307,307.439 11.52,242.322 11.52,162" id="path3462" style="fill:url(#radialGradient3452);stroke:none"/></g></g></g></g><g id="g3464"><g clip-path="url(#clipPath3468)" id="g3466"><g id="g3472" transform="translate(288,27.3594)"><path d="M 0,0 C 146.729,0 265.68,60.281 265.68,134.641 265.68,209 146.729,269.282 0,269.282 -146.729,269.282 -265.68,209 -265.68,134.641 -265.68,60.281 -146.729,0 0,0" id="path3474" style="fill:#777bb3;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g></g></g><g id="g3476"><g clip-path="url(#clipPath3480)" id="g3478"><g id="g3484" transform="translate(161.7344,145.3066)"><path d="m 0,0 c 12.065,0 21.072,2.225 26.771,6.611 5.638,4.341 9.532,11.862 11.573,22.353 1.903,9.806 1.178,16.653 -2.154,20.348 C 32.783,53.086 25.417,55 14.297,55 L -4.984,55 -15.673,0 0,0 Z m -63.063,-67.75 c -0.895,0 -1.745,0.4 -2.314,1.092 -0.57,0.691 -0.801,1.601 -0.63,2.48 L -37.679,81.573 C -37.405,82.982 -36.17,84 -34.734,84 L 26.32,84 C 45.508,84 59.79,78.79 68.767,68.513 77.792,58.182 80.579,43.741 77.05,25.592 75.614,18.198 73.144,11.331 69.709,5.183 66.27,-0.972 61.725,-6.667 56.198,-11.747 49.582,-17.939 42.094,-22.429 33.962,-25.071 25.959,-27.678 15.681,-29 3.414,-29 l -24.722,0 -7.06,-36.322 c -0.274,-1.41 -1.508,-2.428 -2.944,-2.428 l -31.751,0 z" id="path3486" style="fill:#000000;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g><g id="g3488" transform="translate(159.2236,197.3071)"><path d="m 0,0 16.808,0 c 13.421,0 18.083,-2.945 19.667,-4.7 2.628,-2.914 3.124,-9.058 1.435,-17.767 C 36.012,-32.217 32.494,-39.13 27.452,-43.012 22.29,-46.986 13.898,-49 2.511,-49 L -9.523,-49 0,0 Z m 28.831,35 -61.055,0 c -2.872,0 -5.341,-2.036 -5.889,-4.855 l -28.328,-145.751 c -0.342,-1.759 0.12,-3.578 1.259,-4.961 1.14,-1.383 2.838,-2.183 4.63,-2.183 l 31.75,0 c 2.873,0 5.342,2.036 5.89,4.855 l 6.588,33.895 22.249,0 c 12.582,0 23.174,1.372 31.479,4.077 8.541,2.775 16.399,7.48 23.354,13.984 5.752,5.292 10.49,11.232 14.08,17.657 3.591,6.427 6.171,13.594 7.668,21.302 3.715,19.104 0.697,34.402 -8.969,45.466 C 63.965,29.444 48.923,35 28.831,35 m -45.633,-90 19.313,0 c 12.801,0 22.336,2.411 28.601,7.234 6.266,4.824 10.492,12.875 12.688,24.157 2.101,10.832 1.144,18.476 -2.871,22.929 C 36.909,3.773 28.87,6 16.808,6 L -4.946,6 -16.802,-55 M 28.831,29 C 47.198,29 60.597,24.18 69.019,14.539 77.44,4.898 79.976,-8.559 76.616,-25.836 75.233,-32.953 72.894,-39.46 69.601,-45.355 66.304,-51.254 61.999,-56.648 56.679,-61.539 50.339,-67.472 43.296,-71.7 35.546,-74.218 27.796,-76.743 17.925,-78 5.925,-78 l -27.196,0 -7.531,-38.75 -31.75,0 28.328,145.75 61.055,0" id="path3490" style="fill:#ffffff;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g><g id="g3492" transform="translate(311.583,116.3066)"><path d="m 0,0 c -0.896,0 -1.745,0.4 -2.314,1.092 -0.571,0.691 -0.802,1.6 -0.631,2.48 L 9.586,68.061 C 10.778,74.194 10.484,78.596 8.759,80.456 7.703,81.593 4.531,83.5 -4.848,83.5 L -27.55,83.5 -43.305,2.428 C -43.579,1.018 -44.814,0 -46.25,0 l -31.5,0 c -0.896,0 -1.745,0.4 -2.315,1.092 -0.57,0.691 -0.801,1.601 -0.63,2.48 l 28.328,145.751 c 0.274,1.409 1.509,2.427 2.945,2.427 l 31.5,0 c 0.896,0 1.745,-0.4 2.315,-1.091 0.57,-0.692 0.801,-1.601 0.63,-2.481 L -21.813,113 2.609,113 c 18.605,0 31.221,-3.28 38.569,-10.028 7.49,-6.884 9.827,-17.891 6.947,-32.719 L 34.945,2.428 C 34.671,1.018 33.437,0 32,0 L 0,0 Z" id="path3494" style="fill:#000000;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g><g id="g3496" transform="translate(293.6611,271.0571)"><path d="m 0,0 -31.5,0 c -2.873,0 -5.342,-2.036 -5.89,-4.855 l -28.328,-145.751 c -0.342,-1.759 0.12,-3.578 1.26,-4.961 1.14,-1.383 2.838,-2.183 4.63,-2.183 l 31.5,0 c 2.872,0 5.342,2.036 5.89,4.855 l 15.283,78.645 20.229,0 c 9.363,0 11.328,-2 11.407,-2.086 0.568,-0.611 1.315,-3.441 0.082,-9.781 l -12.531,-64.489 c -0.342,-1.759 0.12,-3.578 1.26,-4.961 1.14,-1.383 2.838,-2.183 4.63,-2.183 l 32,0 c 2.872,0 5.342,2.036 5.89,4.855 l 13.179,67.825 c 3.093,15.921 0.447,27.864 -7.861,35.5 -7.928,7.281 -21.208,10.82 -40.599,10.82 l -20.784,0 6.143,31.605 C 6.231,-5.386 5.77,-3.566 4.63,-2.184 3.49,-0.801 1.792,0 0,0 m 0,-6 -7.531,-38.75 28.062,0 c 17.657,0 29.836,-3.082 36.539,-9.238 6.703,-6.16 8.711,-16.141 6.032,-29.938 l -13.18,-67.824 -32,0 12.531,64.488 c 1.426,7.336 0.902,12.34 -1.574,15.008 -2.477,2.668 -7.746,4.004 -15.805,4.004 l -25.176,0 -16.226,-83.5 -31.5,0 L -31.5,-6 0,-6" id="path3498" style="fill:#ffffff;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g><g id="g3500" transform="translate(409.5498,145.3066)"><path d="m 0,0 c 12.065,0 21.072,2.225 26.771,6.611 5.638,4.34 9.532,11.861 11.574,22.353 1.903,9.806 1.178,16.653 -2.155,20.348 C 32.783,53.086 25.417,55 14.297,55 L -4.984,55 -15.673,0 0,0 Z m -63.062,-67.75 c -0.895,0 -1.745,0.4 -2.314,1.092 -0.57,0.691 -0.802,1.601 -0.631,2.48 L -37.679,81.573 C -37.404,82.982 -36.17,84 -34.733,84 L 26.32,84 C 45.509,84 59.79,78.79 68.768,68.513 77.793,58.183 80.579,43.742 77.051,25.592 75.613,18.198 73.144,11.331 69.709,5.183 66.27,-0.972 61.725,-6.667 56.198,-11.747 49.582,-17.939 42.094,-22.429 33.962,-25.071 25.959,-27.678 15.681,-29 3.414,-29 l -24.723,0 -7.057,-36.322 c -0.275,-1.41 -1.509,-2.428 -2.946,-2.428 l -31.75,0 z" id="path3502" style="fill:#000000;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g><g id="g3504" transform="translate(407.0391,197.3071)"><path d="M 0,0 16.808,0 C 30.229,0 34.891,-2.945 36.475,-4.7 39.104,-7.614 39.6,-13.758 37.91,-22.466 36.012,-32.217 32.493,-39.13 27.452,-43.012 22.29,-46.986 13.898,-49 2.511,-49 L -9.522,-49 0,0 Z m 28.831,35 -61.054,0 c -2.872,0 -5.341,-2.036 -5.889,-4.855 L -66.44,-115.606 c -0.342,-1.759 0.12,-3.578 1.259,-4.961 1.14,-1.383 2.838,-2.183 4.63,-2.183 l 31.75,0 c 2.872,0 5.342,2.036 5.89,4.855 l 6.587,33.895 22.249,0 c 12.582,0 23.174,1.372 31.479,4.077 8.541,2.775 16.401,7.481 23.356,13.986 5.752,5.291 10.488,11.23 14.078,17.655 3.591,6.427 6.171,13.594 7.668,21.302 3.715,19.105 0.697,34.403 -8.969,45.467 C 63.965,29.444 48.924,35 28.831,35 m -45.632,-90 19.312,0 c 12.801,0 22.336,2.411 28.601,7.234 6.267,4.824 10.492,12.875 12.688,24.157 2.102,10.832 1.145,18.476 -2.871,22.929 C 36.909,3.773 28.87,6 16.808,6 L -4.946,6 -16.801,-55 M 28.831,29 C 47.198,29 60.597,24.18 69.019,14.539 77.441,4.898 79.976,-8.559 76.616,-25.836 75.233,-32.953 72.894,-39.46 69.601,-45.355 66.304,-51.254 61.999,-56.648 56.679,-61.539 50.339,-67.472 43.296,-71.7 35.546,-74.218 27.796,-76.743 17.925,-78 5.925,-78 l -27.196,0 -7.53,-38.75 -31.75,0 28.328,145.75 61.054,0" id="path3506" style="fill:#ffffff;fill-opacity:1;fill-rule:nonzero;stroke:none"/></g></g></g></g></svg>
            <div>Console<small>environment status</small></div>
        </div>

        <button class="navbtn active" data-view="overview">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span>Overview</span>
        </button>
        <button class="navbtn" data-view="health">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h4l2 6 4-14 2 8h6"/></svg>
            <span>Health</span>
        </button>
        <button class="navbtn" data-view="limits">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="7" x2="20" y2="7"/><circle cx="9" cy="7" r="2.4" fill="var(--panel)"/><line x1="4" y1="17" x2="20" y2="17"/><circle cx="15" cy="17" r="2.4" fill="var(--panel)"/></svg>
            <span>Limits</span>
        </button>
        <button class="navbtn" data-view="extensions">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/></svg>
            <span>Extensions</span>
        </button>
        <button class="navbtn" data-view="opcache">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/></svg>
            <span>OPcache</span>
        </button>
        <button class="navbtn" data-view="security">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>
            <span>Security</span>
        </button>
        <button class="navbtn" data-view="capabilities">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4M12 18v4M2 12h4M18 12h4M5 5l2.5 2.5M16.5 16.5L19 19M19 5l-2.5 2.5M7.5 16.5L5 19"/><circle cx="12" cy="12" r="3"/></svg>
            <span>Capabilities</span>
        </button>
        <button class="navbtn" data-view="composer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a4 4 0 00-5.4 5.4l-6 6 2 2 6-6a4 4 0 005.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/></svg>
            <span>Composer</span>
        </button>
        <button class="navbtn" data-view="diff">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3v18M15 3v18"/><path d="M4 8l2 2-2 2M20 8l-2 2 2 2"/></svg>
            <span>Compare</span>
        </button>
        <button class="navbtn" data-view="phpinfo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6"/><path d="M9 13h6M9 17h6"/></svg>
            <span>All directives</span>
        </button>

        <div class="rail-spacer"></div>
        <div class="rail-sep"></div>
        <button class="toolbtn" id="themeBtn" onclick="toggleTheme()">
            <svg id="themeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
            <span id="themeLabel">Light theme</span>
        </button>
        <button class="toolbtn" id="compactBtn" onclick="toggleCompact()" style="margin-top:6px">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 9h16M4 15h16"/></svg>
            <span id="compactLabel">Compact density</span>
        </button>
    </nav>

    <!-- ================= MAIN ================= -->
    <main class="main">
        <div class="topbar">
            <button class="hamburger" onclick="openNav()" aria-label="Open menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>
            <span class="ver">PHP <b><?= e($phpVersion) ?></b> · <?= e($sapi) ?></span>
            <div class="gauge <?= $healthy ? '' : 'warn' ?>">
                <span class="gauge-label">Health</span>
                <span class="gauge-strip" aria-hidden="true">
                    <?php for ($i = 0; $i < $healthTotal; $i++): ?>
                        <span class="seg <?= $i < $healthScore ? 'lit' : '' ?>"></span>
                    <?php endfor; ?>
                </span>
                <span class="count"><?= $healthScore ?>/<?= $healthTotal ?></span>
            </div>
        </div>

        <!-- ---------- OVERVIEW ---------- -->
        <section class="view" data-view="overview">
            <p class="eyebrow">Runtime snapshot</p>
            <div class="grid stats">
                <div class="card card-pad stat">
                    <div class="label">Interpreter</div>
                    <div class="value">PHP <?= e($phpVersion) ?></div>
                    <div class="sub"><?= e($sapi) ?> · Zend <?= e($zendVersion) ?></div>
                </div>
                <div class="card card-pad stat">
                    <div class="label">Platform</div>
                    <div class="value" style="font-size:16px"><?= e($osFamily) ?></div>
                    <div class="sub"><?= e($arch) ?> · <?= e(php_uname('r')) ?></div>
                </div>
                <?php
                $eolPill = ['active' => 'ok', 'security' => 'ok', 'soon' => 'warn', 'eol' => 'bad', 'unknown' => 'muted'][$eol['state']] ?? 'muted';
                ?>
                <div class="card card-pad stat">
                    <div class="label">Release lifecycle</div>
                    <div class="value" style="font-size:15px;margin-bottom:6px"><span class="pill <?= $eolPill ?>"><?= e($eol['label']) ?></span></div>
                    <div class="sub"><?= $eol['eolDate'] ? 'EOL ' . e($eol['eolDate']) : e('branch ' . $branch) ?></div>
                </div>
                <div class="card card-pad">
                    <div class="radial">
                        <svg viewBox="0 0 36 36" role="img" aria-label="OPcache memory usage">
                            <path class="track" fill="none" stroke-width="3.2" d="M18 3.5a14.5 14.5 0 0 1 0 29 14.5 14.5 0 0 1 0-29"/>
                            <path class="fill" fill="none" stroke-width="3.2" stroke-linecap="round" stroke-dasharray="<?= $opcacheOn ? $opPct : 0 ?>,100" d="M18 3.5a14.5 14.5 0 0 1 0 29 14.5 14.5 0 0 1 0-29"/>
                            <text class="pct" x="18" y="19.5" text-anchor="middle"><?= $opcacheOn ? $opPct . '%' : 'off' ?></text>
                        </svg>
                    </div>
                    <div class="label" style="text-align:center">OPcache memory</div>
                </div>
            </div>

            <div class="grid stats" style="margin-top:14px">
                <div class="card card-pad stat">
                    <div class="label">Memory limit / upload</div>
                    <div class="value" style="font-size:17px"><?= e($limits['memory_limit']) ?></div>
                    <div class="sub">upload <?= e($limits['upload_max_filesize']) ?> · post <?= e($limits['post_max_size']) ?></div>
                </div>
                <div class="card card-pad stat">
                    <div class="label">This request · peak memory</div>
                    <div class="value" style="font-size:17px"><?= e(fmtBytes($memPeak)) ?></div>
                    <div class="sub"><?= $memPct !== null ? $memPct . '% of limit · ' : '' ?>now <?= e(fmtBytes($memNow)) ?></div>
                </div>
                <div class="card card-pad stat">
                    <div class="label">error_reporting</div>
                    <div class="value mono" style="font-size:13px;font-weight:600;word-break:break-word;line-height:1.35"><?= e($erSummary) ?></div>
                </div>
                <div class="card card-pad stat">
                    <div class="label">Extensions loaded</div>
                    <div class="value"><?= count($extensions) ?></div>
                    <div class="sub"><?= $missingExtensions ? count($missingExtensions) . ' core missing' : 'all core present' ?></div>
                </div>
            </div>

            <div class="grid two" style="margin-top:14px">
                <div class="card">
                    <div class="card-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/></svg>
                        Server
                    </div>
                    <div class="kv">
                        <div class="row"><span class="k">Server API</span><span class="v"><?= e($serverSoftware) ?></span></div>
                        <div class="row"><span class="k">Operating system</span><span class="v"><?= e(php_uname('s') . ' ' . php_uname('r')) ?></span></div>
                        <div class="row"><span class="k">Server time</span><span class="v"><?= e($serverTime) ?> · <?= e($timezone) ?></span></div>
                        <div class="row"><span class="k">Document root</span><span class="v"><?= e($docRoot) ?></span></div>
                        <div class="row"><span class="k">PHP binary</span><span class="v"><?= e(PHP_BINARY) ?></span></div>
                        <div class="row"><span class="k">Loaded php.ini</span><span class="v"><?= e($loadedIni) ?></span></div>
                        <div class="row"><span class="k">Extra .ini files</span><span class="v"><?= e($scannedIni) ?></span></div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/></svg>
                        Needs attention
                    </div>
                    <div class="card-pad">
                        <?php $issues = array_keys(array_filter($healthChecks, fn($ok) => !$ok)); ?>
                        <?php if (!$issues): ?>
                            <div class="pill ok" style="margin-bottom:14px">all checks pass</div>
                            <p class="note" style="margin:0">Nothing flagged. This runtime looks well-configured.</p>
                        <?php else: ?>
                            <div class="kv" style="margin:-6px -18px 0">
                                <?php foreach ($issues as $it): ?>
                                    <button class="row row-btn" data-jump="<?= e($healthJump[$it] ?? 'health') ?>">
                                        <span class="k" style="color:var(--ink)"><?= e($it) ?></span>
                                        <span class="pill warn">review →</span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px">
                            <button class="toolbtn" style="width:auto" onclick="copyReport(this)">Copy summary</button>
                            <button class="toolbtn" style="width:auto" onclick="downloadJson()">Download JSON</button>
                            <button class="toolbtn" style="width:auto" onclick="window.print()">Print / PDF</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ---------- HEALTH ---------- -->
        <section class="view" data-view="health" hidden>
            <p class="eyebrow">Configuration checks</p>
            <div class="card card-pad" style="margin-bottom:14px;display:flex;gap:14px;align-items:flex-start">
                <span class="pill <?= $eolPill ?>" style="flex:0 0 auto;margin-top:2px"><?= e($eol['label']) ?></span>
                <div>
                    <div style="font-weight:600;margin-bottom:2px">PHP <?= e($branch) ?> release line</div>
                    <div class="note"><?= e($eol['detail']) ?></div>
                </div>
            </div>
            <div class="card">
                <div class="kv">
                    <?php foreach ($healthChecks as $label => $ok): ?>
                        <button class="row row-btn" data-jump="<?= e($healthJump[$label] ?? 'health') ?>">
                            <span class="k" style="color:var(--ink)"><?= e($label) ?></span>
                            <span class="pill <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'pass' : 'fail →' ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ---------- LIMITS ---------- -->
        <section class="view" data-view="limits" hidden>
            <p class="eyebrow">Resource limits</p>
            <div class="grid two">
                <?php foreach ($limitGroups as $group => $items): ?>
                    <div class="card">
                        <div class="card-head">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="8" x2="20" y2="8"/><line x1="4" y1="16" x2="20" y2="16"/></svg>
                            <?= e($group) ?>
                        </div>
                        <?php foreach ($items as $name => $meta):
                            $val = $limits[$name];
                            $risky = ($meta['risky'])($val);
                            $rec = $recommended[$name] ?? null;
                        ?>
                            <div class="limit">
                                <div>
                                    <div class="name"><?= e($name) ?></div>
                                    <div class="hint"><?= e($meta['hint']) ?></div>
                                    <?php if ($rec !== null): ?><div class="rec">recommended <code class="inline"><?= e($rec) ?></code></div><?php endif; ?>
                                </div>
                                <div class="right">
                                    <div class="cur"><?= e(iniDisplay($val)) ?></div>
                                    <span class="pill <?= $risky ? 'warn' : 'ok' ?>"><?= $risky ? 'review' : 'ok' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ---------- EXTENSIONS ---------- -->
        <section class="view" data-view="extensions" hidden>
            <p class="eyebrow"><?= count($extensions) ?> extensions loaded</p>
            <?php if ($missingExtensions): ?>
                <div class="card card-pad" style="border-color:color-mix(in srgb,var(--warn) 40%,transparent);margin-bottom:14px">
                    <span class="pill warn">missing</span>
                    <span class="note">Commonly-required extensions not loaded: <b class="mono"><?= e(implode(', ', $missingExtensions)) ?></b></span>
                </div>
            <?php endif; ?>
            <div class="search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input id="extSearch" placeholder="Filter extensions…" oninput="filterExt(this.value)" autocomplete="off">
                <span class="faint mono" id="extCount"><?= count($extensions) ?></span>
            </div>
            <p class="note faint" style="margin:-4px 0 14px;font-size:12px">Click an extension to see its version and configuration directives.</p>
            <div class="ext-grid" id="extGrid">
                <?php foreach ($extensions as $ext):
                    $req = in_array(strtolower($ext), array_map('strtolower', $requiredExtensions), true);
                    $ver = $extVersions[$ext];
                    $hasDetail = isset($extDirectives[$ext]);
                ?>
                    <button class="ext ext-btn <?= $req ? 'req' : '' ?>" data-name="<?= e(strtolower($ext)) ?>" data-ext="<?= e($ext) ?>" title="<?= $req ? 'required extension' : 'loaded' ?><?= $hasDetail ? ' · click for directives' : '' ?>">
                        <span class="ext-name"><?= e($ext) ?></span>
                        <?php if ($ver !== null): ?><span class="ext-ver"><?= e($ver) ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="empty" id="extEmpty" hidden>No extensions match that filter.</div>
        </section>

        <dialog id="extDialog" class="ext-dialog">
            <div class="ext-dialog-head">
                <div>
                    <span class="eyebrow" style="margin:0">Extension</span>
                    <h2 id="extDialogTitle" style="margin:2px 0 0;font-size:17px"></h2>
                </div>
                <button class="hamburger" style="display:grid" onclick="document.getElementById('extDialog').close()" aria-label="Close">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>
            <div id="extDialogBody"></div>
        </dialog>

        <!-- ---------- OPCACHE ---------- -->
        <section class="view" data-view="opcache" hidden>
            <p class="eyebrow">Bytecode cache</p>
            <div class="card card-pad">
                <?php if ($opcacheOn):
                    $used = $opcache['memory_usage']['used_memory'] ?? 0;
                    $free = $opcache['memory_usage']['free_memory'] ?? 0;
                    $st = $opcache['opcache_statistics'] ?? [];
                ?>
                    <div class="note" style="margin-bottom:10px"><?= fmtBytes((int) $used) ?> used of <?= fmtBytes((int) ($used + $free)) ?></div>
                    <div class="progress"><i style="width:<?= $opPct ?>%"><?= $opPct ?>%</i></div>
                    <div class="kv" style="margin:16px -18px -16px">
                        <div class="row"><span class="k">Cached scripts</span><span class="v"><?= (int) ($st['num_cached_scripts'] ?? 0) ?></span></div>
                        <div class="row"><span class="k">Cache hits</span><span class="v"><?= number_format((int) ($st['hits'] ?? 0)) ?></span></div>
                        <div class="row"><span class="k">Cache misses</span><span class="v"><?= number_format((int) ($st['misses'] ?? 0)) ?></span></div>
                        <div class="row"><span class="k">Hit rate</span><span class="v"><?= $opHitRate ?>%</span></div>
                    </div>
                <?php else: ?>
                    <span class="pill muted">disabled</span>
                    <p class="note" style="margin:12px 0 0">OPcache is not enabled. Set <code>opcache.enable=1</code> in php.ini for a substantial performance gain on repeated requests.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- ---------- SECURITY ---------- -->
        <section class="view" data-view="security" hidden>
            <p class="eyebrow">Security posture</p>
            <div class="card">
                <table class="data">
                    <thead><tr><th>Directive</th><th>Current</th><th>Guidance</th><th style="text-align:right">Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($securityChecks as $key => $meta):
                            $on = filter_var(ini_get($key), FILTER_VALIDATE_BOOLEAN);
                            $ok = ($on === $meta['want']);
                        ?>
                            <tr>
                                <td class="k"><?= e($key) ?></td>
                                <td class="v"><?= $on ? 'on' : 'off' ?></td>
                                <td class="dim" style="font-family:var(--font-ui)"><?= e($meta['note']) ?></td>
                                <td style="text-align:right"><span class="pill <?= $ok ? 'ok' : 'warn' ?>"><?= $ok ? 'ok' : 'review' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h2 class="section-title">Error handling</h2>
            <div class="card">
                <div class="kv">
                    <div class="row"><span class="k">error_reporting</span><span class="v"><?= e($erSummary) ?></span></div>
                    <div class="row"><span class="k">display_errors</span><span class="v"><?= e(iniDisplay(ini_get('display_errors'))) ?></span></div>
                    <div class="row"><span class="k">log_errors</span><span class="v"><?= e(iniDisplay(ini_get('log_errors'))) ?></span></div>
                    <div class="row"><span class="k">error_log</span><span class="v"><?= e(ini_get('error_log') ?: '(server default)') ?></span></div>
                </div>
            </div>

            <h2 class="section-title">Sessions</h2>
            <div class="card">
                <div class="kv">
                    <?php foreach ($session as $k => $v): ?>
                        <div class="row"><span class="k">session.<?= e($k) ?></span><span class="v"><?= e($v) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <h2 class="section-title">Disabled functions</h2>
            <div class="card card-pad">
                <?php if ($disabledList): ?>
                    <div class="ext-grid">
                        <?php foreach ($disabledList as $fn): ?><div class="ext"><?= e($fn) ?></div><?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="note" style="margin:0">No functions disabled. In hardened environments, consider disabling <code>exec, shell_exec, system, passthru, proc_open</code>.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- ---------- COMPOSER ---------- -->
        <section class="view" data-view="composer" hidden>
            <p class="eyebrow">Dependency manager</p>
            <div class="card">
                <div class="kv">
                    <div class="row">
                        <span class="k">Status</span>
                        <?php [$pc, $pt] = match ($composer['state']) {
                            'installed' => ['ok', 'installed'],
                            'absent' => ['warn', 'not on PATH'],
                            'blocked' => ['muted', 'detection blocked'],
                            default => ['muted', 'unknown'],
                        }; ?>
                        <span class="pill <?= $pc ?>"><?= e($pt) ?></span>
                    </div>
                    <?php if ($composer['state'] === 'installed'): ?>
                        <div class="row"><span class="k">Path</span><span class="v"><?= e($composer['path']) ?></span></div>
                        <div class="row"><span class="k">Version</span><span class="v"><?= e($composer['version']) ?></span></div>
                    <?php elseif ($composer['state'] === 'blocked'): ?>
                        <div class="row"><span class="faint" style="font-family:var(--font-ui)">shell_exec is disabled, so Composer can't be detected from here.</span></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ---------- CAPABILITIES ---------- -->
        <section class="view" data-view="capabilities" hidden>
            <p class="eyebrow">Databases &amp; runtime capabilities</p>

            <div class="grid two">
                <div class="card">
                    <div class="card-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
                        PDO drivers
                    </div>
                    <div class="card-pad">
                        <?php if ($pdoDrivers): ?>
                            <div class="ext-grid">
                                <?php foreach ($pdoDrivers as $d): ?><div class="ext req"><?= e($d) ?></div><?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="note" style="margin:0">No PDO drivers available<?= class_exists('PDO') ? '.' : ' (PDO not loaded).' ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h.01M7 12h.01M7 16h.01M11 8h6M11 12h6M11 16h6"/></svg>
                        Database client libraries
                    </div>
                    <div class="kv">
                        <?php if ($dbInfo): ?>
                            <?php foreach ($dbInfo as $k => $v): ?>
                                <div class="row"><span class="k"><?= e($k) ?></span><span class="v"><?= e($v) ?></span></div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="row"><span class="faint" style="font-family:var(--font-ui)">No database client libraries detected.</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php foreach ($capabilities as $title => $items): ?>
                <h2 class="section-title"><?= e($title) ?> <span class="faint mono" style="font-weight:400">· <?= count($items) ?></span></h2>
                <div class="card card-pad">
                    <?php if ($items): ?>
                        <div class="ext-grid">
                            <?php foreach ($items as $it): ?><div class="ext"><?= e($it) ?></div><?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="note" style="margin:0">None available.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- ---------- COMPARE (config diff) ---------- -->
        <section class="view" data-view="diff" hidden>
            <p class="eyebrow">Compare against another server</p>
            <div class="card card-pad" style="margin-bottom:14px">
                <p class="note" style="margin:0 0 12px">
                    Load a JSON export from another server (the <b>Download JSON</b> button on its Overview) to see
                    exactly which directives differ. Everything runs in your browser — nothing is uploaded.
                </p>
                <div id="dropZone" class="drop-zone">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:26px;height:26px;opacity:.6"><path d="M12 16V4M8 8l4-4 4 4"/><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                    <div>Drop a <code class="inline">php-console-*.json</code> here, or <label class="link-btn">browse<input type="file" id="diffFile" accept="application/json,.json" hidden></label></div>
                    <div class="faint" style="font-size:12px">This server: <b class="mono"><?= e(gethostname() ?: 'php') ?></b> · PHP <?= e($phpVersion) ?></div>
                </div>
            </div>
            <div id="diffResult"></div>
        </section>

        <!-- ---------- ALL DIRECTIVES ---------- -->
        <section class="view" data-view="phpinfo" hidden>
            <p class="eyebrow">Complete phpinfo(), parsed into tables</p>
            <div class="search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                <input id="infoSearch" placeholder="Search every directive and section…" oninput="filterInfo(this.value)" autocomplete="off">
                <kbd>/</kbd>
            </div>
            <div id="infoWrap">
                <?php foreach ($phpinfoSections as $section => $rows): ?>
                    <div class="info-section" data-section="<?= e(strtolower($section)) ?>">
                        <h2 class="section-title"><?= e($section) ?></h2>
                        <div class="table-wrap">
                            <table class="data">
                                <?php
                                $hasMaster = false;
                                foreach ($rows as $rv) {
                                    if (is_array($rv)) { $hasMaster = true; break; }
                                }
                                ?>
                                <thead><tr>
                                    <th>Directive</th>
                                    <?php if ($hasMaster): ?><th>Local value</th><th>Master value</th><?php else: ?><th>Value</th><?php endif; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($rows as $k => $v): ?>
                                        <tr data-row="<?= e(strtolower($k . ' ' . (is_array($v) ? implode(' ', $v) : $v))) ?>">
                                            <td class="k"><?= e($k) ?></td>
                                            <?php if (is_array($v)): ?>
                                                <td class="v"><?= e($v['local']) ?></td>
                                                <td class="v"><?= e($v['master']) ?></td>
                                            <?php elseif ($hasMaster): ?>
                                                <td class="v" colspan="2"><?= e($v) ?></td>
                                            <?php else: ?>
                                                <td class="v"><?= e($v) ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="empty" id="infoEmpty" hidden>Nothing matches that search.</div>
        </section>
    </main>

    <script>
        const REPORT = <?= json_encode($copyReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>;
        const FULL_REPORT = <?= json_encode(['summary' => $copyReport, 'directives' => $phpinfoSections], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?>;
        const HOSTNAME = <?= json_encode(preg_replace('/[^a-z0-9_.-]/i', '_', (string) (gethostname() ?: 'php'))) ?>;
        const EXT_DIRECTIVES = <?= json_encode($extDirectives, JSON_UNESCAPED_SLASHES) ?>;
        const EXT_VERSIONS = <?= json_encode($extVersions, JSON_UNESCAPED_SLASHES) ?>;

        function showView(name, push = true) {
            document.querySelectorAll('.view').forEach(v => v.hidden = v.dataset.view !== name);
            document.querySelectorAll('.navbtn').forEach(b => b.classList.toggle('active', b.dataset.view === name));
            document.querySelector('.main').scrollTop = 0;
            if (push && location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
            closeNav();
        }
        document.querySelectorAll('.navbtn').forEach(b => b.addEventListener('click', () => showView(b.dataset.view)));
        document.querySelectorAll('[data-jump]').forEach(b => b.addEventListener('click', () => showView(b.dataset.jump)));

        function syncFromHash() {
            const h = location.hash.slice(1);
            if (h && document.querySelector('.view[data-view="' + h + '"]')) showView(h, false);
        }
        window.addEventListener('hashchange', syncFromHash);
        syncFromHash();

        function filterExt(q) {
            q = q.trim().toLowerCase();
            let n = 0;
            document.querySelectorAll('#extGrid .ext').forEach(el => {
                const hit = el.dataset.name.includes(q);
                el.style.display = hit ? '' : 'none';
                if (hit) n++;
            });
            document.getElementById('extCount').textContent = n;
            document.getElementById('extEmpty').hidden = n > 0;
        }
        function filterInfo(q) {
            q = q.trim().toLowerCase();
            let total = 0;
            document.querySelectorAll('.info-section').forEach(sec => {
                const secHit = sec.dataset.section.includes(q);
                let shown = 0;
                sec.querySelectorAll('tbody tr').forEach(tr => {
                    const hit = secHit || tr.dataset.row.includes(q);
                    tr.style.display = hit ? '' : 'none';
                    if (hit) shown++;
                });
                sec.style.display = shown ? '' : 'none';
                total += shown;
            });
            document.getElementById('infoEmpty').hidden = total > 0;
        }

        function copyReport(btn) {
            const label = btn.querySelector('span') || btn;
            const orig = label.textContent;
            navigator.clipboard.writeText(JSON.stringify(REPORT, null, 2)).then(() => {
                label.textContent = 'Copied';
                setTimeout(() => label.textContent = orig, 1400);
            }).catch(() => { label.textContent = 'Copy failed'; setTimeout(() => label.textContent = orig, 1400); });
        }

        function downloadJson() {
            const stamp = new Date().toISOString().slice(0, 10);
            const blob = new Blob([JSON.stringify(FULL_REPORT, null, 2)], { type: 'application/json' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'php-console-' + HOSTNAME + '-' + stamp + '.json';
            document.body.appendChild(a); a.click();
            document.body.removeChild(a); URL.revokeObjectURL(a.href);
        }

        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

        // Flatten a phpinfo section object into "key => local|value" pairs.
        function flattenSection(rows) {
            const out = {};
            for (const [k, v] of Object.entries(rows || {})) {
                out[k] = (v && typeof v === 'object') ? (v.local ?? '') : v;
            }
            return out;
        }

        // ---- extension detail dialog ----
        const extDialog = document.getElementById('extDialog');
        document.getElementById('extGrid').addEventListener('click', e => {
            const btn = e.target.closest('.ext-btn');
            if (!btn) return;
            const name = btn.dataset.ext;
            const ver = EXT_VERSIONS[name];
            const rows = EXT_DIRECTIVES[name];
            document.getElementById('extDialogTitle').textContent = name + (ver ? '  ·  v' + ver : '');
            const body = document.getElementById('extDialogBody');
            if (rows && Object.keys(rows).length) {
                let html = '<table class="data"><thead><tr><th>Directive</th><th>Local</th><th>Master</th></tr></thead><tbody>';
                for (const [k, v] of Object.entries(rows)) {
                    if (v && typeof v === 'object') {
                        html += `<tr><td class="k">${esc(k)}</td><td class="v">${esc(v.local ?? '')}</td><td class="v">${esc(v.master ?? '')}</td></tr>`;
                    } else {
                        html += `<tr><td class="k">${esc(k)}</td><td class="v" colspan="2">${esc(v)}</td></tr>`;
                    }
                }
                body.innerHTML = html + '</tbody></table>';
            } else {
                body.innerHTML = '<p class="note" style="padding:18px">This extension exposes no configuration directives.</p>';
            }
            extDialog.showModal();
        });
        extDialog.addEventListener('click', e => { if (e.target === extDialog) extDialog.close(); });

        // ---- config diff ----
        function runDiff(remote) {
            const result = document.getElementById('diffResult');
            let remoteDir, remoteSummary;
            try {
                remoteDir = remote.directives || {};
                remoteSummary = remote.summary || {};
                if (!remoteDir || typeof remoteDir !== 'object') throw new Error('no directives');
            } catch (err) {
                result.innerHTML = '<div class="card card-pad"><span class="pill bad">invalid</span> <span class="note">That file isn\'t a valid PHP Console export.</span></div>';
                return;
            }

            const localDir = FULL_REPORT.directives || {};
            const sections = Array.from(new Set([...Object.keys(localDir), ...Object.keys(remoteDir)])).sort();
            let changed = 0, onlyLocal = 0, onlyRemote = 0;
            let rowsHtml = '';

            for (const sec of sections) {
                const a = flattenSection(localDir[sec]);      // this server
                const b = flattenSection(remoteDir[sec]);     // loaded file
                const keys = Array.from(new Set([...Object.keys(a), ...Object.keys(b)])).sort();
                let secRows = '';
                for (const k of keys) {
                    const av = a[k], bv = b[k];
                    const hasA = k in a, hasB = k in b;
                    if (hasA && hasB && String(av) === String(bv)) continue;
                    let cls, aCell, bCell;
                    if (hasA && !hasB) { onlyLocal++; aCell = esc(av); bCell = '<span class="faint">—</span>'; }
                    else if (!hasA && hasB) { onlyRemote++; aCell = '<span class="faint">—</span>'; bCell = esc(bv); }
                    else { changed++; aCell = `<span class="diff-val-a">${esc(av)}</span>`; bCell = `<span class="diff-val-b">${esc(bv)}</span>`; }
                    secRows += `<tr><td class="k">${esc(k)}</td><td class="v">${aCell}</td><td class="v">${bCell}</td></tr>`;
                }
                if (secRows) {
                    rowsHtml += `<h2 class="section-title">${esc(sec)}</h2><div class="table-wrap"><table class="data"><thead><tr><th>Directive</th><th>This server</th><th>Loaded file</th></tr></thead><tbody>${secRows}</tbody></table></div>`;
                }
            }

            const total = changed + onlyLocal + onlyRemote;
            const remoteName = remoteSummary.php ? `PHP ${esc(remoteSummary.php)}` : 'loaded file';
            let head = `<div class="card card-pad"><div class="diff-summary">
                <span class="diff-stat"><b>This server:</b> PHP ${esc(FULL_REPORT.summary.php)}</span>
                <span class="diff-stat"><b>Loaded file:</b> ${remoteName}${remoteSummary.generated ? ' · ' + esc(remoteSummary.generated.slice(0,10)) : ''}</span>
                </div><div class="diff-summary">
                <span class="pill warn">${changed} changed</span>
                <span class="pill ok">${onlyRemote} only in file</span>
                <span class="pill bad">${onlyLocal} only here</span>
                </div></div>`;

            result.innerHTML = head + (total ? rowsHtml : '<div class="card card-pad"><span class="pill ok">identical</span> <span class="note">No directive differences found.</span></div>');
        }

        (function () {
            const zone = document.getElementById('dropZone');
            const input = document.getElementById('diffFile');
            const load = file => {
                const r = new FileReader();
                r.onload = () => { try { runDiff(JSON.parse(r.result)); } catch { document.getElementById('diffResult').innerHTML = '<div class="card card-pad"><span class="pill bad">error</span> <span class="note">Could not parse that file as JSON.</span></div>'; } };
                r.readAsText(file);
            };
            input.addEventListener('change', e => { if (e.target.files[0]) load(e.target.files[0]); });
            ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('drag'); }));
            ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('drag'); }));
            zone.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) load(f); });
        })();

        function applyTheme(t) {
            document.documentElement.setAttribute('data-theme', t);
            document.getElementById('themeLabel').textContent = t === 'dark' ? 'Light theme' : 'Dark theme';
            document.getElementById('themeIcon').innerHTML = t === 'dark'
                ? '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'
                : '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>';
        }
        function toggleTheme() {
            const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            localStorage.setItem('phpc-theme', t);
            applyTheme(t);
        }
        (function () {
            const saved = localStorage.getItem('phpc-theme')
                || (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
            applyTheme(saved);
        })();

        function toggleCompact() {
            const on = document.body.classList.toggle('compact');
            localStorage.setItem('phpc-compact', on ? '1' : '0');
            document.getElementById('compactLabel').textContent = on ? 'Comfort density' : 'Compact density';
        }
        (function () {
            if (localStorage.getItem('phpc-compact') === '1') {
                document.body.classList.add('compact');
                document.getElementById('compactLabel').textContent = 'Comfort density';
            }
        })();

        function openNav() { document.body.classList.add('nav-open'); }
        function closeNav() { document.body.classList.remove('nav-open'); }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeNav();
            const typing = /^(INPUT|TEXTAREA)$/.test(document.activeElement.tagName);
            if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
                const view = document.querySelector('.view:not([hidden])');
                const box = view && view.querySelector('.search input');
                if (box) { e.preventDefault(); box.focus(); box.select(); }
            }
        });
    </script>
</body>

</html>
