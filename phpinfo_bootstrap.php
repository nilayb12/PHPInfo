<?php
/**
 * PHP Environment Dashboard
 * Admin Sidebar + Theme Toggle + Bootstrap Icons
 */

// ================= SECURITY =================
if (php_sapi_name() !== 'cli-server' && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

// ================= HELPERS =================
function bytes($val)
{
    if ($val === '-1')
        return PHP_INT_MAX;
    if (preg_match('/^(\d+)([KMG])?/i', (string) $val, $m)) {
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
function badge(bool $ok): string
{
    return $ok ? 'bg-success' : 'bg-danger';
}

function renderIniValue($v): string
{
    if (is_array($v))
        return 'Array';
    if ($v === false)
        return 'Off';
    if ($v === true)
        return 'On';
    if ($v === null)
        return 'null';
    return htmlspecialchars((string) $v);
}

function sizeStatus(string $upload, string $post): array
{
    $u = bytes($upload);
    $p = bytes($post);

    if ($p < $u) {
        return ['Mismatch', 'bg-danger', 'post_max_size is smaller than upload_max_filesize'];
    }

    if ($u < bytes('8M')) {
        return ['Low', 'bg-warning text-dark', 'Upload limit is very low for modern apps'];
    }

    return ['Aligned', 'bg-success', 'Upload limits are properly configured'];
}

// ================= DATA =================
$expectedPath = 'C:\\php';
$runtimeWarning = stripos(PHP_BINARY, $expectedPath) === false;

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
$limitGroups = [
    'Execution & Time' => [
        'max_execution_time' => [
            'value' => $limits['max_execution_time'],
            'risky' => fn($v) => $v == 0,
            'hint' => 'Unlimited execution time is dangerous in production',
        ],
    ],
    'Memory' => [
        'memory_limit' => [
            'value' => $limits['memory_limit'],
            'risky' => fn($v) => bytes($v) < bytes('256M'),
            'hint' => 'Low memory can cause fatal errors under load',
        ],
    ],
    'Uploads' => [
        'upload_max_filesize' => [
            'value' => $limits['upload_max_filesize'],
            'risky' => fn($v) => bytes($v) < bytes('8M'),
            'hint' => 'Maximum size of a single uploaded file',
        ],
        'post_max_size' => [
            'value' => $limits['post_max_size'],
            'risky' => fn($v) => bytes($v) < bytes($limits['upload_max_filesize']),
            'hint' => 'Must be greater than or equal to upload_max_filesize',
        ],
    ],
    'Input / Security' => [
        'max_input_vars' => [
            'value' => $limits['max_input_vars'],
            'risky' => fn($v) => $v < 1000,
            'hint' => 'Too low may break complex forms',
        ],
    ],
];

$extensions = get_loaded_extensions();
sort($extensions);
$requiredExtensions = ['curl', 'mbstring', 'openssl', 'json', 'pdo'];
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

$composer = ['installed' => false, 'path' => null, 'version' => null];
$whichCmd = stripos(PHP_OS_FAMILY, 'Windows') !== false ? 'where composer' : 'which composer';
$pathOut = trim((string) @shell_exec($whichCmd));
if ($pathOut !== '') {
    $composer['installed'] = true;
    $composer['path'] = strtok($pathOut, PHP_EOL);
    $composer['version'] = trim((string) @shell_exec('composer --version'));
}

$healthChecks = [
    'Memory limit ≥ 128M' => bytes($limits['memory_limit']) >= bytes('128M'),
    'OPcache enabled' => $opcache && ($opcache['opcache_enabled'] ?? false),
    'Timezone set' => ini_get('date.timezone') !== '',
    'Temp dir writable' => is_writable(sys_get_temp_dir()),
    'Required extensions present' => empty(array_diff($requiredExtensions, $extensions)),
];
$healthScore = array_sum(array_map(fn($v) => $v ? 1 : 0, $healthChecks));
$healthTotal = count($healthChecks);

$iniAll = ini_get_all(null, false);
ksort($iniAll);
?>
<!doctype html>
<html lang="en" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>PHP Environment Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        html,
        body {
            height: 100%
        }

        body {
            overflow: hidden
        }

        .sidebar {
            width: 260px;
            flex-shrink: 0
        }

        .sidebar .nav-link {
            color: #adb5bd
        }

        .sidebar .nav-link.active,
        .sidebar .nav-link:hover {
            color: #fff;
            background: #495057
        }

        .main {
            overflow: auto;
            height: 100vh
        }

        .scroll-panel {
            max-height: 65vh;
            overflow: auto
        }

        .code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .9rem
        }

        .stat-label {
            font-size: 0.75rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--bs-secondary-color);
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 600;
            line-height: 1.2;
        }

        /* Compact / Dense mode */
        body.compact .card-body {
            padding: .75rem;
        }

        body.compact .list-group-item {
            padding: .4rem .6rem;
        }

        body.compact .table> :not(caption)>*>* {
            padding: .25rem .5rem;
        }

        body.compact .row.g-4 {
            --bs-gutter-y: 1rem;
        }

        /* Collapsible sidebar */
        body.sidebar-collapsed .sidebar {
            width: 72px;
        }

        body.sidebar-collapsed .sidebar h5,
        body.sidebar-collapsed .sidebar .nav-link span,
        body.sidebar-collapsed .sidebar hr {
            display: none;
        }

        body.sidebar-collapsed .sidebar .nav-link {
            text-align: center;
        }

        body.sidebar-collapsed .sidebar .nav-link i {
            margin-right: 0;
            font-size: 1.2rem;
        }

        /* Hide button text in collapsed sidebar */
        body.sidebar-collapsed .sidebar button span {
            display: none;
        }

        /* Center icons inside buttons when collapsed */
        body.sidebar-collapsed .sidebar button {
            justify-content: center;
        }
    </style>
</head>

<body>

    <div class="d-flex h-100">

        <!-- SIDEBAR -->
        <aside class="sidebar bg-dark p-3">
            <button class="btn btn-sm btn-outline-light w-100 mb-3" onclick="toggleSidebar()">
                <i id="sidebarIcon" class="bi bi-layout-sidebar-inset"></i>
            </button>
            <h5 class="text-white mb-4"><i class="bi bi-speedometer2 me-2"></i>PHP Dashboard</h5>
            <ul class="nav nav-pills flex-column gap-1">
                <li><a class="nav-link active" data-bs-toggle="tab" href="#overview"><i class="bi bi-grid me-2"></i>
                        <span>Overview</span></a>
                </li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#health"><i class="bi bi-heart-pulse me-2"></i>
                        <span>Health</span></a>
                </li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#limits"><i class="bi bi-sliders me-2"></i>
                        <span>Limits</span></a>
                </li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#extensions"><i class="bi bi-boxes me-2"></i>
                        <span>Extensions</span></a>
                </li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#opcache"><i class="bi bi-lightning-charge me-2"></i>
                        <span>OPcache</span></a></li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#ini"><i class="bi bi-file-earmark-text me-2"></i>
                        <span>INI Explorer</span></a></li>
                <li><a class="nav-link" data-bs-toggle="tab" href="#composer"><i
                            class="bi bi-wrench-adjustable-circle me-2"></i>
                        <span>Composer</span></a></li>
            </ul>
            <hr class="border-secondary">
            <button class="btn btn-outline-light w-100" onclick="toggleTheme()">
                <i id="themeIcon" class="bi bi-moon-stars me-2"></i>
                <span id="themeLabel">Dark mode</span>
            </button>
            <button class="btn btn-outline-light w-100" onclick="toggleCompact()">
                <i id="compactIcon" class="bi bi-arrows-collapse me-2"></i>
                <span id="compactLabel">Compact mode</span>
            </button>
        </aside>

        <!-- MAIN -->
        <div class="flex-fill main">
            <nav class="navbar navbar-expand bg-body-tertiary border-bottom">
                <div class="container-fluid">
                    <span class="navbar-text"><i class="bi bi-code-slash me-2"></i>PHP <?= PHP_VERSION ?></span>
                    <span class="badge <?= $runtimeWarning ? 'bg-danger' : 'bg-success' ?> ms-auto">
                        <?= $runtimeWarning ? 'Unexpected runtime' : 'C:\\php runtime' ?>
                    </span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <div class="tab-content">

                    <?php
                    $opPct = 0;
                    if ($opcache && ($opcache['opcache_enabled'] ?? false)) {
                        $used = $opcache['memory_usage']['used_memory'];
                        $free = $opcache['memory_usage']['free_memory'];
                        $total = $used + $free;
                        $opPct = $total ? round($used / $total * 100) : 0;
                    }
                    ?>

                    <!-- OVERVIEW -->
                    <div class="tab-pane fade show active" id="overview">

                        <!-- STATUS CARDS -->
                        <div class="row g-4 mb-4">

                            <div class="col-md-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="stat-label">Environment</div>
                                        <div class="stat-value">PHP <?= PHP_VERSION ?></div>
                                        <div class="text-muted"><?= php_sapi_name() ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="stat-label">Health</div>
                                        <div class="stat-value"><?= $healthScore ?> / <?= $healthTotal ?></div>
                                        <span class="badge <?= badge($healthScore === $healthTotal) ?>">
                                            <?= $healthScore === $healthTotal ? 'Healthy' : 'Attention needed' ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="stat-label">Runtime</div>
                                        <div class="stat-value <?= $runtimeWarning ? 'text-danger' : '' ?>">
                                            <?= $runtimeWarning ? 'Unexpected' : 'C:\\php' ?>
                                        </div>
                                        <div class="code text-muted small"><?= PHP_BINARY ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="stat-label">Memory / Upload</div>
                                        <div class="stat-value"><?= $limits['memory_limit'] ?></div>
                                        <div class="text-muted">
                                            Upload: <?= $limits['upload_max_filesize'] ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="card h-100">
                                    <div class="card-body text-center">
                                        <div class="stat-label mb-2">OPcache Usage</div>

                                        <svg width="90" height="90" viewBox="0 0 36 36" class="mx-auto">
                                            <path
                                                d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                                fill="none" stroke="#e9ecef" stroke-width="3" />
                                            <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831" fill="none"
                                                stroke="var(--bs-primary)" stroke-width="3"
                                                stroke-dasharray="<?= $opPct ?>,100" />
                                            <text x="18" y="20.35" font-size="8" text-anchor="middle"
                                                fill="currentColor">
                                                <?= $opPct ?>%
                                            </text>
                                        </svg>

                                        <?php if (!$opcache || !($opcache['opcache_enabled'] ?? false)): ?>
                                            <div class="text-muted small mt-2">OPcache disabled</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div>

                        <!-- INSIGHTS -->
                        <div class="row g-4">

                            <!-- NEEDS ATTENTION -->
                            <div class="col-lg-6">
                                <div class="card h-100">
                                    <div class="card-header fw-semibold">
                                        <i class="bi bi-exclamation-circle me-2"></i>Needs Attention
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $issues = [];
                                        foreach ($healthChecks as $label => $ok) {
                                            if (!$ok)
                                                $issues[] = $label;
                                        }
                                        ?>
                                        <?php if (empty($issues)): ?>
                                            <div class="text-success">
                                                <i class="bi bi-check-circle me-2"></i>All checks passed 🎉
                                            </div>
                                        <?php else: ?>
                                            <ul class="list-unstyled mb-0">
                                                <?php foreach ($issues as $i): ?>
                                                    <li class="mb-1">
                                                        <i class="bi bi-dot me-2"></i><?= $i ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- QUICK ACTIONS -->
                            <div class="col-lg-6">
                                <div class="card h-100">
                                    <div class="card-header fw-semibold">
                                        <i class="bi bi-lightning-charge me-2"></i>Quick Actions
                                    </div>
                                    <div class="card-body d-flex flex-wrap gap-2">
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tab"
                                            data-bs-target="#limits">
                                            <i class="bi bi-sliders me-1"></i>Limits
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tab"
                                            data-bs-target="#extensions">
                                            <i class="bi bi-boxes me-1"></i>Extensions
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tab"
                                            data-bs-target="#ini">
                                            <i class="bi bi-file-earmark-text me-1"></i>INI Explorer
                                        </button>
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="tab"
                                            data-bs-target="#opcache">
                                            <i class="bi bi-lightning me-1"></i>OPcache
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- HEALTH -->
                    <div class="tab-pane fade" id="health">
                        <div class="card">
                            <div class="card-body">
                                <?php foreach ($healthChecks as $label => $ok): ?>
                                    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                                        <span><?= $label ?></span>
                                        <span class="badge <?= badge($ok) ?>">
                                            <i
                                                class="bi <?= $ok ? 'bi-check-circle' : 'bi-exclamation-triangle' ?> me-1"></i>
                                            <?= $ok ? 'OK' : 'Issue' ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- LIMITS -->
                    <div class="tab-pane fade" id="limits">
                        <div class="row g-4">
                            <?php foreach ($limitGroups as $group => $items): ?>
                                <div class="col-12 col-lg-6">
                                    <div class="card h-100">
                                        <div class="card-header fw-semibold">
                                            <i class="bi bi-sliders me-2"></i><?= $group ?>
                                        </div>
                                        <ul class="list-group list-group-flush">
                                            <?php foreach ($items as $name => $meta):
                                                $value = $meta['value'];
                                                $risky = $meta['risky']($value);
                                                $label = $risky ? 'Risky' : 'OK';
                                                $badgeClass = $risky ? 'bg-danger' : 'bg-success';

                                                /* Special handling for upload limits */
                                                if (in_array($name, ['upload_max_filesize', 'post_max_size'], true)) {
                                                    [$label, $badgeClass, $meta['hint']] =
                                                        sizeStatus($limits['upload_max_filesize'], $limits['post_max_size']);
                                                }
                                                ?>
                                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <div class="fw-medium"><?= $name ?></div>
                                                        <small class="text-muted"><?= $meta['hint'] ?></small>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="fw-semibold"><?= renderIniValue($value) ?></div>
                                                        <span class="badge <?= $badgeClass ?>">
                                                            <?= $label ?>
                                                        </span>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- EXTENSIONS -->
                    <div class="tab-pane fade" id="extensions">
                        <div class="card">
                            <div class="card-body">
                                <div class="input-group mb-3">
                                    <span class="input-group-text"><i class="bi bi-funnel"></i></span>
                                    <input class="form-control" placeholder="Filter extensions…"
                                        oninput="filterExtensions(this.value)">
                                    <span class="input-group-text"><span
                                            id="extCount"><?= count($extensions) ?></span></span>
                                </div>

                                <!-- Grid layout: no internal scroll, fills width, avoids one-long-column -->
                                <div class="row g-2" id="extGrid">
                                    <?php foreach ($extensions as $ext): ?>
                                        <div class="col-6 col-sm-4 col-md-3 col-xl-2 ext-item">
                                            <span class="badge bg-secondary w-100 text-start"><?= $ext ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- OPCACHE -->
                    <div class="tab-pane fade" id="opcache">
                        <div class="card">
                            <div class="card-body">
                                <?php if ($opcache && $opcache['opcache_enabled']):
                                    $used = $opcache['memory_usage']['used_memory'];
                                    $free = $opcache['memory_usage']['free_memory'];
                                    $total = $used + $free;
                                    $pct = $total ? round($used / $total * 100) : 0; ?>
                                    <h6><i class="bi bi-cpu me-2"></i>Memory usage</h6>
                                    <div class="progress mb-3">
                                        <div class="progress-bar" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
                                    </div>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item">Cached scripts:
                                            <?= $opcache['opcache_statistics']['num_cached_scripts'] ?>
                                        </li>
                                        <li class="list-group-item">Hits: <?= $opcache['opcache_statistics']['hits'] ?></li>
                                        <li class="list-group-item">Misses: <?= $opcache['opcache_statistics']['misses'] ?>
                                        </li>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted">OPcache not enabled</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- INI -->
                    <div class="tab-pane fade" id="ini">
                        <div class="card">
                            <div class="card-body">
                                <div class="input-group mb-3">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input class="form-control" placeholder="Search php.ini directives…"
                                        oninput="filterIni(this.value)">
                                </div>
                                <div class="scroll-panel">
                                    <table class="table table-sm table-hover" id="iniTable">
                                        <?php foreach ($iniAll as $k => $v): ?>
                                            <tr>
                                                <td class="code"><?= $k ?></td>
                                                <td class="code"><?= htmlspecialchars((string) $v) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COMPOSER -->
                    <div class="tab-pane fade" id="composer">
                        <div class="card">
                            <div class="card-body">
                                <table class="table table-sm">
                                    <tr>
                                        <th>Installed</th>
                                        <td><span
                                                class="badge <?= badge($composer['installed']) ?>"><?= $composer['installed'] ? 'Yes' : 'No' ?></span>
                                        </td>
                                    </tr>
                                    <?php if ($composer['installed']): ?>
                                        <tr>
                                            <th>Path</th>
                                            <td class="code"><?= $composer['path'] ?></td>
                                        </tr>
                                        <tr>
                                            <th>Version</th>
                                            <td class="code"><?= $composer['version'] ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function filterIni(q) {
            q = q.toLowerCase();
            document.querySelectorAll('#iniTable tr').forEach(r => { r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none'; });
        }

        function filterExtensions(q) {
            q = q.toLowerCase();
            let c = 0;
            document.querySelectorAll('.ext-item').forEach(e => {
                const ok = e.innerText.toLowerCase().includes(q);
                e.style.display = ok ? '' : 'none';
                if (ok) c++;
            }); document.getElementById('extCount').innerText = c;
        }

        function toggleTheme() {
            const html = document.documentElement;
            const dark = html.getAttribute('data-bs-theme') === 'dark';
            const t = dark ? 'light' : 'dark'; html.setAttribute('data-bs-theme', t);
            localStorage.setItem('theme', t); updateThemeUI(t);
        }

        function updateThemeUI(t) {
            document.getElementById('themeIcon').className = 'bi ' + (t === 'dark' ? 'bi-moon-stars me-2' : 'bi-sun me-2');
            document.getElementById('themeLabel').innerText = t === 'dark' ? 'Dark mode' : 'Light mode';
        }

        (function () {
            const t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', t); updateThemeUI(t);
        })();
        function toggleCompact() {
            document.body.classList.toggle('compact');
            const on = document.body.classList.contains('compact');
            localStorage.setItem('compact', on ? '1' : '0');
            document.getElementById('compactLabel').innerText = on ? 'Comfort mode' : 'Compact mode';
            document.getElementById('compactIcon').className =
                'bi ' + (on ? 'bi-arrows-expand me-2' : 'bi-arrows-collapse me-2');
        }

        (function () {
            if (localStorage.getItem('compact') === '1') {
                document.body.classList.add('compact');
                document.getElementById('compactLabel').innerText = 'Comfort mode';
                document.getElementById('compactIcon').className = 'bi bi-arrows-expand';
            }
        })();

        function toggleSidebar() {
            document.body.classList.toggle('sidebar-collapsed');
            const collapsed = document.body.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebar', collapsed ? 'collapsed' : 'expanded');
            document.getElementById('sidebarIcon').className =
                'bi ' + (collapsed
                    ? 'bi-layout-sidebar-inset-reverse'
                    : 'bi-layout-sidebar-inset');
        }

        (function () {
            if (localStorage.getItem('sidebar') === 'collapsed') {
                document.body.classList.add('sidebar-collapsed');
                document.getElementById('sidebarIcon').className =
                    'bi bi-layout-sidebar-inset-reverse';
            }
        })();

    </script>
</body>

</html>