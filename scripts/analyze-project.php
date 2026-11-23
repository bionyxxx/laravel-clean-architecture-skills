<?php

declare(strict_types=1);

/**
 * Read-only Laravel project analyzer for the laravel-clean-architecture skill.
 *
 * Usage: php analyze-project.php [project-root] [--json]
 *
 * Prints: Laravel version, detected architecture level, metrics, ecosystem packages,
 * a score per signal, and a recommended level (L1–L4). It never writes files.
 */

$args = array_slice($argv, 1);
$asJson = in_array('--json', $args, true);
$positional = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '--')));
$root = rtrim($positional[0] ?? getcwd(), '/\\');

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, "Usage: php analyze-project.php [project-root] [--json]\n");
    exit(0);
}

if (! is_file("$root/composer.json")) {
    fwrite(STDERR, "No composer.json in [$root]. Pass the Laravel project root as the first argument.\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents("$root/composer.json"), true) ?: [];
$packages = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
$has = fn (string $pkg): bool => array_key_exists($pkg, $packages);

if (! $has('laravel/framework')) {
    fwrite(STDERR, "laravel/framework is not required in composer.json; this does not look like a Laravel app.\n");
    exit(1);
}

// ---------------------------------------------------------------- helpers

/** @return list<string> */
function phpFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    return $out;
}

function lineCount(string $file): int
{
    return substr_count((string) file_get_contents($file), "\n") + 1;
}

/** @param list<string> $files */
function filesMatching(array $files, string $regex): array
{
    return array_values(array_filter($files, fn ($f) => preg_match($regex, (string) file_get_contents($f)) === 1));
}

function dirHasPhp(string $dir): bool
{
    return phpFiles($dir) !== [];
}

function installedVersion(string $root, string $package): ?string
{
    $lock = "$root/composer.lock";
    if (! is_file($lock)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($lock), true) ?: [];
    foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $p) {
        if (($p['name'] ?? null) === $package) {
            return ltrim((string) $p['version'], 'v');
        }
    }

    return null;
}

// ---------------------------------------------------------------- inventory

$app = "$root/app";
$appFiles = phpFiles($app);
$modelFiles = filesMatching($appFiles, '/\bextends\s+(\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?(Model|Authenticatable|Pivot|MorphPivot)\b/');
$controllerFiles = array_values(array_filter($appFiles, fn ($f) => str_ends_with($f, 'Controller.php') && basename($f) !== 'Controller.php'));
$controllerLoc = array_map('lineCount', $controllerFiles);
$avgControllerLoc = $controllerLoc ? (int) round(array_sum($controllerLoc) / count($controllerLoc)) : 0;
$maxControllerLoc = $controllerLoc ? max($controllerLoc) : 0;

$countBy = function (string $pattern) use ($appFiles): int {
    return count(array_filter($appFiles, fn ($f) => preg_match($pattern, str_replace('\\', '/', $f)) === 1));
};

$metrics = [
    'php_files_in_app' => count($appFiles),
    'models' => count($modelFiles),
    'controllers' => count($controllerFiles),
    'avg_controller_loc' => $avgControllerLoc,
    'max_controller_loc' => $maxControllerLoc,
    'actions' => $countBy('#/(Actions|UseCases)/(?!Fortify/|Jetstream/)#'),
    'services' => $countBy('#/Services/#'),
    'repositories' => $countBy('#/Repositories/#'),
    'events' => $countBy('#/Events/#'),
    'listeners' => $countBy('#/Listeners/#'),
    'jobs' => $countBy('#/Jobs/#'),
    'console_commands' => $countBy('#/Console/Commands/#'),
    'test_files' => count(phpFiles("$root/tests")),
    'migrations' => count(phpFiles("$root/database/migrations")),
    'route_files' => array_values(array_map('basename', glob("$root/routes/*.php") ?: [])),
];

$httpClientFiles = filesMatching($appFiles, '/\bHttp::|GuzzleHttp\\\\Client/');
$metrics['files_using_http_client'] = count($httpClientFiles);

// ---------------------------------------------------------------- ecosystem

$ecosystemGroups = [
    'dev_env' => ['laravel/sail', 'laravel/octane'],
    'spatie' => array_values(array_filter(array_keys($packages), fn ($p) => str_starts_with($p, 'spatie/'))),
    'first_party' => ['laravel/sanctum', 'laravel/passport', 'laravel/fortify', 'laravel/breeze', 'laravel/jetstream',
        'laravel/horizon', 'laravel/scout', 'laravel/cashier', 'laravel/cashier-paddle', 'laravel/socialite',
        'laravel/reverb', 'laravel/pennant', 'laravel/telescope', 'laravel/pulse', 'laravel/folio', 'laravel/nova'],
    'ui' => ['livewire/livewire', 'livewire/volt', 'inertiajs/inertia-laravel', 'filament/filament', 'laravel/nova'],
    'structure' => ['lorisleiva/laravel-actions', 'nwidart/laravel-modules', 'internachi/modular'],
    'quality' => ['pestphp/pest', 'phpunit/phpunit', 'laravel/pint', 'larastan/larastan', 'nunomaduro/larastan',
        'rector/rector', 'deptrac/deptrac', 'qossmic/deptrac', 'phpat/phpat'],
];
$ecosystem = [];
foreach ($ecosystemGroups as $group => $list) {
    $ecosystem[$group] = array_values(array_filter(array_unique($list), $has));
}

$composeFile = null;
foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $f) {
    if (is_file("$root/$f")) {
        $composeFile = $f;
        break;
    }
}
$usesSail = $has('laravel/sail') && $composeFile !== null
    && str_contains((string) file_get_contents("$root/$composeFile"), 'sail');

$integrationPackages = ['stripe/stripe-php', 'laravel/cashier', 'laravel/cashier-paddle', 'aws/aws-sdk-php',
    'league/flysystem-aws-s3-v3', 'twilio/sdk', 'google/apiclient', 'midtrans/midtrans-php', 'xendit/xendit-php',
    'paypal/paypal-server-sdk', 'srmklive/paypal', 'algolia/algoliasearch-client-php', 'meilisearch/meilisearch-php',
    'pusher/pusher-php-server', 'kreait/laravel-firebase', 'mailgun/mailgun-php', 'symfony/mailgun-mailer',
    'symfony/postmark-mailer', 'resend/resend-laravel', 'openai-php/laravel', 'anthropic-ai/sdk',
    'elasticsearch/elasticsearch', 'laravel/socialite', 'vonage/client', 'sentry/sentry-laravel'];
$integrationsFound = array_values(array_filter($integrationPackages, $has));

$moneyPackages = array_values(array_filter(['laravel/cashier', 'laravel/cashier-paddle', 'stripe/stripe-php',
    'brick/money', 'moneyphp/money', 'midtrans/midtrans-php', 'xendit/xendit-php', 'paypal/paypal-server-sdk',
    'srmklive/paypal', 'akaunting/laravel-money', 'cknow/laravel-money'], $has));
$eventSourcing = $has('spatie/laravel-event-sourcing') || $has('eventsauce/eventsauce');

// delivery channels
$routes = $metrics['route_files'];
$webRoutes = is_file("$root/routes/web.php") ? (int) preg_match_all('/Route::/', (string) file_get_contents("$root/routes/web.php")) : 0;
$channels = [];
if ($webRoutes > 1 || $has('livewire/livewire') || $has('inertiajs/inertia-laravel')) {
    $channels[] = 'web UI';
}
if (in_array('api.php', $routes, true)) {
    $channels[] = 'API';
}
if ($has('filament/filament') || $has('laravel/nova')) {
    $channels[] = 'admin panel';
}
if ($metrics['console_commands'] >= 3) {
    $channels[] = 'console commands';
}
if ($metrics['jobs'] >= 3) {
    $channels[] = 'queued jobs';
}
if (in_array('channels.php', $routes, true) || $has('laravel/reverb') || $has('pusher/pusher-php-server')) {
    $channels[] = 'broadcasting';
}

// ---------------------------------------------------------------- detected structure

$detected = ['level' => null, 'label' => 'Default Laravel layout (no architecture pattern detected)', 'evidence' => []];

$claudeMd = null;
foreach (['CLAUDE.md', '.claude/CLAUDE.md', 'AGENTS.md'] as $f) {
    if (is_file("$root/$f") && preg_match('/Architecture:\s*L([1-4])\b[^\n]*/i', (string) file_get_contents("$root/$f"), $m)) {
        $claudeMd = ['file' => $f, 'level' => (int) $m[1], 'line' => trim($m[0])];
        break;
    }
}

$moduleRoots = [];
foreach (["$app/Modules", "$root/Modules", "$root/app-modules"] as $dir) {
    if (is_dir($dir)) {
        $moduleRoots[] = $dir;
    }
}
$layered = [];
foreach ($moduleRoots as $dir) {
    foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $mod) {
        foreach (["$mod/Domain", "$mod/app/Domain", "$mod/src/Domain"] as $domainDir) {
            if (is_dir($domainDir)) {
                $layered[] = $domainDir;
            }
        }
    }
}

if ($claudeMd) {
    $detected = ['level' => $claudeMd['level'], 'label' => "Recorded in {$claudeMd['file']}: {$claudeMd['line']}", 'evidence' => ['recorded decision']];
} elseif ($layered) {
    $domainFiles = array_merge(...array_map('phpFiles', $layered));
    $eloquentInDomain = filesMatching($domainFiles, '/use\s+Illuminate\\\\/');
    $hasPorts = (bool) array_filter($moduleRoots, fn ($d) => glob("$d/*/Application/Ports", GLOB_ONLYDIR) || glob("$d/Shared/Application/Ports", GLOB_ONLYDIR));
    if ($domainFiles && ! $eloquentInDomain && $hasPorts) {
        $detected = ['level' => 4, 'label' => 'L4 Strict Hexagonal (framework-free Domain + Application ports)', 'evidence' => array_map(fn ($d) => substr($d, strlen($root) + 1), $layered)];
    } else {
        $detected = ['level' => 3, 'label' => 'L3 Modular Clean (layered modules)', 'evidence' => array_map(fn ($d) => substr($d, strlen($root) + 1), $layered)];
    }
} elseif ($has('nwidart/laravel-modules') || $has('internachi/modular')) {
    $detected = ['level' => 3, 'label' => 'Modular package installed (treat as L3 inside module roots)', 'evidence' => $ecosystem['structure']];
} elseif (is_dir("$app/Domain") && dirHasPhp("$app/Domain")) {
    $detected = ['level' => 2, 'label' => 'L2 Domain-oriented (app/Domain)', 'evidence' => array_map('basename', glob("$app/Domain/*", GLOB_ONLYDIR) ?: [])];
} elseif ($metrics['actions'] > 0) {
    $detected = ['level' => 1, 'label' => 'L1 Lean Actions (app/Actions)', 'evidence' => ["{$metrics['actions']} action classes"]];
}

// ---------------------------------------------------------------- scoring

$score = [];
$m = $metrics['models'];
$score['models'] = $m > 100 ? 3 : ($m > 40 ? 2 : ($m > 15 ? 1 : 0));

$integrationCount = count($integrationsFound) + ($metrics['files_using_http_client'] >= 3 ? 1 : 0);
$score['integrations'] = $integrationCount >= 3 ? 2 : ($integrationCount >= 1 ? 1 : 0);

$c = count($channels);
$score['delivery_channels'] = $c >= 5 ? 2 : ($c >= 3 ? 1 : 0);

$async = $metrics['events'] + $metrics['listeners'] + $metrics['jobs'];
$score['async_eventing'] = $async > 20 ? 2 : ($async >= 5 ? 1 : 0);

$score['money_payments'] = $moneyPackages ? 1 : 0;
$score['event_sourcing'] = $eventSourcing ? 2 : 0;
$score['tests'] = $metrics['test_files'] > 100 ? 1 : 0;
$score['codebase_size'] = $metrics['php_files_in_app'] > 300 ? 1 : 0;

$total = array_sum($score);
$scoreLevel = $total >= 9 ? 4 : ($total >= 6 ? 3 : ($total >= 3 ? 2 : 1));
$borderline = in_array($total, [2, 3, 5, 6, 8, 9], true);

$levelNames = [1 => 'L1 Lean Actions', 2 => 'L2 Domain-oriented', 3 => 'L3 Modular Clean', 4 => 'L4 Strict Hexagonal'];

$reasons = [];
$reasons[] = "{$metrics['models']} models, {$metrics['controllers']} controllers, {$metrics['php_files_in_app']} PHP files in app/";
$reasons[] = $integrationsFound || $metrics['files_using_http_client']
    ? 'integrations: '.($integrationsFound ? implode(', ', $integrationsFound) : 'none via SDK').", Http client used in {$metrics['files_using_http_client']} files"
    : 'no external integrations detected';
$reasons[] = 'delivery channels: '.($channels ? implode(', ', $channels) : 'none detected');
$reasons[] = "events/listeners/jobs: $async";
if ($moneyPackages) {
    $reasons[] = 'money/payments: '.implode(', ', $moneyPackages);
}
if ($eventSourcing) {
    $reasons[] = 'event sourcing package installed';
}

$fatControllers = $avgControllerLoc > 120 || $maxControllerLoc > 300;

if ($detected['level'] !== null) {
    $recommended = $detected['level'];
    $advice = "Follow the existing {$levelNames[$detected['level']]} structure.";
    if ($scoreLevel >= $detected['level'] + 2) {
        $advice .= " The score suggests {$levelNames[$scoreLevel]}; consider moving up incrementally (see refactoring-legacy.md) if you see pain.";
    } elseif ($scoreLevel === $detected['level'] + 1) {
        $advice .= " Score is one level higher ({$levelNames[$scoreLevel]}); stay unless the 'when to move up' signals apply.";
    }
} else {
    $recommended = $scoreLevel;
    $advice = "No pattern detected. Recommended: {$levelNames[$scoreLevel]}.";
}
if ($fatControllers) {
    $advice .= " Fat controllers detected (avg {$avgControllerLoc} / max {$maxControllerLoc} lines): extract Actions first.";
}
if ($borderline && $detected['level'] === null) {
    $advice .= ' Borderline score: ask about team size, lifetime and domain complexity before deciding.';
}

$laravelVersion = installedVersion($root, 'laravel/framework') ?? ($packages['laravel/framework'] ?? 'unknown');

$result = [
    'project_root' => $root,
    'laravel' => $laravelVersion,
    'php_constraint' => $composer['require']['php'] ?? 'unknown',
    'providers_file' => is_file("$root/bootstrap/providers.php") ? 'bootstrap/providers.php' : 'config/app.php',
    'dev_environment' => [
        'sail' => $usesSail,
        'compose_file' => $composeFile,
        'command_prefix' => $usesSail ? './vendor/bin/sail' : 'php',
        'octane' => $has('laravel/octane'),
    ],
    'detected_structure' => $detected,
    'metrics' => $metrics,
    'ecosystem' => $ecosystem,
    'integrations' => $integrationsFound,
    'delivery_channels' => $channels,
    'score' => $score,
    'score_total' => $total,
    'score_level' => $levelNames[$scoreLevel],
    'borderline' => $borderline,
    'fat_controllers' => $fatControllers,
    'recommended_level' => $recommended,
    'recommended_name' => $levelNames[$recommended],
    'reasons' => $reasons,
    'advice' => $advice,
];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(0);
}

// ---------------------------------------------------------------- text output

$yn = fn (bool $b) => $b ? 'yes' : 'no';
$list = fn (array $a) => $a ? implode(', ', $a) : '-';

echo "Laravel architecture analysis\n";
echo str_repeat('=', 60)."\n";
echo "Project        : $root\n";
echo "Laravel        : {$result['laravel']} (PHP {$result['php_constraint']})\n";
echo "Providers file : {$result['providers_file']}\n";
echo 'Sail           : '.$yn($usesSail).($usesSail ? '  → run commands via ./vendor/bin/sail' : '')."\n";
echo 'Octane         : '.$yn($has('laravel/octane'))."\n\n";

echo "Detected structure\n  {$detected['label']}\n";
if ($detected['evidence']) {
    echo '  evidence: '.$list(array_slice($detected['evidence'], 0, 8))."\n";
}
echo "\nMetrics\n";
foreach ($metrics as $k => $v) {
    printf("  %-24s %s\n", $k, is_array($v) ? $list($v) : $v);
}
echo "\nEcosystem\n";
foreach ($ecosystem as $group => $pkgs) {
    printf("  %-12s %s\n", $group, $list($pkgs));
}
echo '  integrations '.$list($integrationsFound)."\n";
echo '  channels     '.$list($channels)."\n";

echo "\nScore\n";
foreach ($score as $k => $v) {
    printf("  %-20s %d\n", $k, $v);
}
printf("  %-20s %d → %s%s\n", 'TOTAL', $total, $levelNames[$scoreLevel], $borderline ? ' (borderline)' : '');

echo "\nRecommendation: {$levelNames[$recommended]}\n";
foreach ($reasons as $r) {
    echo "  - $r\n";
}
echo "  $advice\n";
