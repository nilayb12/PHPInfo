<?php
// ================= SECURITY =================
$allowed = ['127.0.0.1', '::1'];
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

// ================= HELPERS =================
function bytes($val)
{
    if ($val === '-1')
        return PHP_INT_MAX;
    if (preg_match('/^(\d+)([KMG])?/i', $val, $m)) {
        $n = (int) $m[1];
        return match (strtoupper($m[2] ?? '')) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
    return 0;
}

function badge(bool $ok)
{
    return $ok ? 'ok' : 'bad';
}

// ================= RUNTIME IDENTITY =================
$runtime = [
    'PHP Version' => PHP_VERSION,
    'Binary' => PHP_BINARY,
    'SAPI' => php_sapi_name(),
    'OS' => PHP_OS_FAMILY,
    'Architecture' => PHP_INT_SIZE * 8 . '-bit',
    'Thread Safety' => ZEND_THREAD_SAFE ? 'Enabled' : 'Disabled',
    'Loaded php.ini' => php_ini_loaded_file() ?: 'None',
];

$expectedPath = 'C:\\php';
$runtimeWarning = stripos(PHP_BINARY, $expectedPath) === false;

// ================= LIMITS =================
$limits = [
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_input_vars' => ini_get('max_input_vars'),
];

$recommended = [
    'memory_limit' => '256M',
    'max_execution_time' => '30',
    'upload_max_filesize' => '20M',
    'post_max_size' => '20M',
    'max_input_vars' => '1000',
];

// ================= EXTENSIONS =================
$extensions = get_loaded_extensions();
sort($extensions);

$requiredExtensions = ['curl', 'mbstring', 'openssl', 'json', 'pdo'];

// ================= OPCACHE =================
$opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : null;

// ================= XDEBUG =================
$xdebugLoaded = extension_loaded('xdebug');
$xdebugMode = $xdebugLoaded ? ini_get('xdebug.mode') : null;

// ================= COMPOSER =================
$composer = [
    'installed' => false,
    'version' => null,
    'path' => null,
];

$composerCmd = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where composer' : 'which composer';
$composerPath = trim((string) @shell_exec($composerCmd));
if ($composerPath) {
    $composer['installed'] = true;
    $composer['path'] = strtok($composerPath, PHP_EOL);
    $composer['version'] = trim((string) @shell_exec('composer --version'));
}

// ================= HEALTH CHECK =================
$healthChecks = [
    'Memory limit >= 128M' => bytes($limits['memory_limit']) >= bytes('128M'),
    'OPcache enabled' => $opcache && $opcache['opcache_enabled'],
    'Timezone set' => ini_get('date.timezone') !== '',
    'Temp dir writable' => is_writable(sys_get_temp_dir()),
    'Required extensions loaded' => empty(array_diff($requiredExtensions, $extensions)),
];

$healthScore = array_sum(array_map(fn($v) => $v ? 1 : 0, $healthChecks));
$healthTotal = count($healthChecks);

// ================= INI EXPLORER =================
$iniAll = ini_get_all(null, false);
ksort($iniAll);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>PHP Environment Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #020617;
            --card: #0b1220;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --ok: #22c55e;
            --bad: #ef4444
        }

        body {
            margin: 0;
            font-family: system-ui;
            background: linear-gradient(180deg, #020617, #020617);
            color: var(--text)
        }

        .container {
            max-width: 1300px;
            margin: 30px auto;
            padding: 0 20px
        }

        h1 {
            margin-bottom: 6px
        }

        .warning {
            color: #facc15;
            font-size: .85rem;
            margin-bottom: 14px
        }

        nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px
        }

        nav button {
            background: #020617;
            border: 1px solid #1e293b;
            color: var(--muted);
            padding: 8px 14px;
            border-radius: 999px;
            cursor: pointer
        }

        nav button.active {
            color: var(--text);
            border-color: var(--accent)
        }

        .card {
            background: var(--card);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .4)
        }

        .kv {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px 14px;
            font-size: .9rem
        }

        .kv span:first-child {
            color: var(--muted)
        }

        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: .75rem
        }

        .ok {
            background: rgba(34, 197, 94, .15);
            color: var(--ok)
        }

        .bad {
            background: rgba(239, 68, 68, .15);
            color: var(--bad)
        }

        .extensions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 8px;
            font-size: .8rem
        }

        .ext {
            background: #020617;
            border-radius: 8px;
            padding: 6px 10px;
            text-align: center;
            color: var(--muted)
        }

        .bar {
            height: 8px;
            border-radius: 999px;
            background: #020617;
            overflow: hidden;
            margin-top: 6px
        }

        .fill {
            height: 100%;
            background: var(--accent)
        }

        .hidden {
            display: none
        }

        input[type=search] {
            width: 100%;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #1e293b;
            background: #020617;
            color: var(--text);
            margin-bottom: 12px
        }

        footer {
            text-align: center;
            color: var(--muted);
            font-size: .75rem;
            margin-top: 30px
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>PHP Environment Dashboard</h1>
        <?php if ($runtimeWarning): ?>
            <div class="warning">⚠ Running PHP is not from
                <?= htmlspecialchars($expectedPath) ?>
            </div>
        <?php endif; ?>

        <nav>
            <button data-tab="identity" class="active">Identity</button>
            <button data-tab="health">Health</button>
            <button data-tab="limits">Limits</button>
            <button data-tab="extensions">Extensions</button>
            <button data-tab="opcache">OPcache</button>
            <button data-tab="ini">INI Explorer</button>
            <button data-tab="composer">Composer</button>
        </nav>

        <section id="identity" class="tab">
            <div class="card">
                <div class="kv">
                    <?php foreach ($runtime as $k => $v): ?>
                        <span>
                            <?= htmlspecialchars($k) ?>
                        </span><span>
                            <?= htmlspecialchars($v) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="health" class="tab hidden">
            <div class="card">
                <div><strong>Overall:</strong>
                    <?= $healthScore ?> / <?= $healthTotal ?>
                </div>
                <div class="kv" style="margin-top:12px">
                    <?php foreach ($healthChecks as $k => $v): ?>
                        <span>
                            <?= htmlspecialchars($k) ?>
                        </span><span class="badge <?= badge($v) ?>">
                            <?= $v ? 'OK' : 'Issue' ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="limits" class="tab hidden">
            <div class="card">
                <div class="kv">
                    <?php foreach ($limits as $k => $v):
                        $ok = bytes($v) >= bytes($recommended[$k]); ?>
                        <span>
                            <?= htmlspecialchars($k) ?>
                        </span>
                        <span class="badge <?= badge($ok) ?>">
                            <?= htmlspecialchars($v) ?> (rec
                            <?= $recommended[$k] ?>)
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="extensions" class="tab hidden">
            <div class="card">
                <div class="extensions">
                    <?php foreach ($extensions as $e): ?>
                        <div class="ext">
                            <?= htmlspecialchars($e) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="opcache" class="tab hidden">
            <div class="card">
                <?php if ($opcache && $opcache['opcache_enabled']):
                    $used = $opcache['memory_usage']['used_memory'];
                    $free = $opcache['memory_usage']['free_memory'];
                    $total = $used + $free;
                    $pct = $total ? round($used / $total * 100) : 0; ?>
                    <div>Memory Usage:
                        <?= $pct ?>%
                    </div>
                    <div class="bar">
                        <div class="fill" style="width:<?= $pct ?>%"></div>
                    </div>
                    <div class="kv" style="margin-top:12px">
                        <span>Cached Scripts</span><span>
                            <?= $opcache['opcache_statistics']['num_cached_scripts'] ?>
                        </span>
                        <span>Hits</span><span>
                            <?= $opcache['opcache_statistics']['hits'] ?>
                        </span>
                        <span>Misses</span><span>
                            <?= $opcache['opcache_statistics']['misses'] ?>
                        </span>
                        <span>Restarts</span><span>
                            <?= $opcache['opcache_statistics']['oom_restarts'] + $opcache['opcache_statistics']['hash_restarts'] ?>
                        </span>
                    </div>
                <?php else: ?>OPcache not enabled
                <?php endif; ?>
            </div>
        </section>

        <section id="ini" class="tab hidden">
            <div class="card">
                <input type="search" placeholder="Search ini setting" oninput="filterIni(this.value)">
                <div class="kv" id="iniList">
                    <?php foreach ($iniAll as $k => $v): ?>
                        <span data-key="<?= htmlspecialchars($k) ?>">
                            <?= htmlspecialchars($k) ?>
                        </span>
                        <span>
                            <?= htmlspecialchars((string) $v) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section id="composer" class="tab hidden">
            <div class="card">
                <div class="kv">
                    <span>Installed</span><span class="badge <?= badge($composer['installed']) ?>">
                        <?= $composer['installed'] ? 'Yes' : 'No' ?>
                    </span>
                    <?php if ($composer['installed']): ?>
                        <span>Path</span><span>
                            <?= htmlspecialchars($composer['path']) ?>
                        </span>
                        <span>Version</span><span>
                            <?= htmlspecialchars($composer['version']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <footer>Local-only · PHP
            <?= PHP_VERSION ?>
        </footer>
    </div>
    <script>
        document.querySelectorAll('nav button').forEach(b => b.onclick = () => { document.querySelectorAll('nav button').forEach(x => x.classList.remove('active')); document.querySelectorAll('.tab').forEach(t => t.classList.add('hidden')); b.classList.add('active'); document.getElementById(b.dataset.tab).classList.remove('hidden'); });
        function filterIni(q) { q = q.toLowerCase(); document.querySelectorAll('#iniList span[data-key]').forEach(k => { const v = k.nextElementSibling; const show = k.dataset.key.toLowerCase().includes(q); k.style.display = v.style.display = show ? '' : 'none'; }); }
    </script>
</body>

</html>