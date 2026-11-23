<?php

declare(strict_types=1);

/**
 * Scaffolding generator for the laravel-clean-architecture skill.
 *
 * Usage (from the Laravel project root):
 *   php make-module.php <Module> [--level=1|2|3|4] [--entity=Name] [--usecase=CreateName]
 *                       [--base=app/Modules] [--namespace=App\\Modules] [--root=.]
 *                       [--register] [--force] [--no-detect] [--dry-run]
 *
 * --level      Architecture level. Defaults to the level recorded in CLAUDE.md, else the detected structure.
 * --entity     Main entity/model name (default: the module name).
 * --usecase    First use case name (default: Create<Entity>).
 * --base       Module root for L3/L4 (default app/Modules). --namespace must match it.
 * --register   Add providers to bootstrap/providers.php and (L1/L2) the route to routes/api.php|web.php.
 * --force      Overwrite existing files (default: skip them).
 * --no-detect  Ignore composer.json (plain DTOs, plain actions, PHPUnit tests, `php` command prefix).
 * --dry-run    Only print what would be written.
 */

// ------------------------------------------------------------------ CLI parsing

$opts = ['level' => null, 'entity' => null, 'usecase' => null, 'base' => 'app/Modules', 'namespace' => 'App\\Modules',
    'root' => getcwd(), 'register' => false, 'force' => false, 'no-detect' => false, 'dry-run' => false, 'help' => false];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        if (! array_key_exists($m[1], $opts)) {
            fail("Unknown option --{$m[1]}");
        }
        $opts[$m[1]] = isset($m[2]) ? $m[2] : true;
    } elseif ($arg === '-h') {
        $opts['help'] = true;
    } else {
        $positional[] = $arg;
    }
}

if ($opts['help'] || ! $positional) {
    $doc = (string) file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    echo preg_replace('/^\s*\* ?/m', '', trim($m[1] ?? '')).PHP_EOL;
    exit($positional ? 0 : 1);
}

function fail(string $msg): never
{
    fwrite(STDERR, "Error: $msg\n");
    exit(1);
}

$root = rtrim((string) $opts['root'], '/\\');
if (! is_file("$root/composer.json") || ! is_file("$root/artisan")) {
    fail("[$root] is not a Laravel project root (composer.json + artisan expected). Use --root=PATH.");
}

$module = $positional[0];
$entity = $opts['entity'] ?: $module;
foreach (['Module' => $module, 'Entity' => $entity] as $label => $value) {
    if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $value)) {
        fail("$label name [$value] must be StudlyCase (e.g. Order, InvoiceLine).");
    }
}
$useCase = $opts['usecase'] ?: "Create{$entity}";
if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $useCase)) {
    fail("Use case name [$useCase] must be StudlyCase (e.g. PlaceOrder).");
}

// ------------------------------------------------------------------ detection

$composer = json_decode((string) file_get_contents("$root/composer.json"), true) ?: [];
$packages = $opts['no-detect'] ? [] : array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
$has = fn (string $p): bool => array_key_exists($p, $packages);

$composeFile = null;
foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'] as $f) {
    if (is_file("$root/$f")) {
        $composeFile = $f;
        break;
    }
}
$sail = $has('laravel/sail') && $composeFile !== null;
$cmd = $sail ? './vendor/bin/sail' : 'php';
$artisan = $sail ? './vendor/bin/sail artisan' : 'php artisan';
$testCmd = $sail ? './vendor/bin/sail test' : 'php artisan test';
$pint = $sail ? './vendor/bin/sail pint --dirty' : './vendor/bin/pint --dirty';
$laravelData = $has('spatie/laravel-data');
$laravelActions = $has('lorisleiva/laravel-actions');
$pest = $has('pestphp/pest');

$pestGlobalRefresh = false;
if ($pest && is_file("$root/tests/Pest.php")) {
    foreach (file("$root/tests/Pest.php") as $line) {
        // Only an active ->use(...)/uses(...) call counts, not the `use ...RefreshDatabase;` import.
        if (preg_match('/(->use|\buses)\s*\(.*RefreshDatabase/', $line) && ! str_starts_with(ltrim($line), '//')) {
            $pestGlobalRefresh = true;
        }
    }
}

$level = $opts['level'];
$levelSource = '--level';
if ($level === null || $level === true) {
    $level = null;
    foreach (['CLAUDE.md', '.claude/CLAUDE.md', 'AGENTS.md'] as $f) {
        if (is_file("$root/$f") && preg_match('/Architecture:\s*L([1-4])\b/i', (string) file_get_contents("$root/$f"), $m)) {
            [$level, $levelSource] = [(int) $m[1], $f];
            break;
        }
    }
    if ($level === null) {
        $baseDir = "$root/".trim((string) $opts['base'], '/');
        if (is_dir("$baseDir/Shared/Application/Ports")) {
            [$level, $levelSource] = [4, 'detected Shared/Application/Ports'];
        } elseif (is_dir($baseDir) && glob("$baseDir/*/Domain", GLOB_ONLYDIR)) {
            [$level, $levelSource] = [3, "detected {$opts['base']}/*/Domain"];
        } elseif (is_dir("$root/app/Domain")) {
            [$level, $levelSource] = [2, 'detected app/Domain'];
        } elseif (glob("$root/app/Actions/*", GLOB_ONLYDIR) && array_diff(array_map('basename', glob("$root/app/Actions/*", GLOB_ONLYDIR)), ['Fortify', 'Jetstream'])) {
            [$level, $levelSource] = [1, 'detected app/Actions'];
        }
    }
    if ($level === null) {
        fail('No --level given and none recorded/detected. Run analyze-project.php for a recommendation, then pass --level=1..4.');
    }
}
$level = (int) $level;
if ($level < 1 || $level > 4) {
    fail('--level must be 1, 2, 3 or 4.');
}

// ------------------------------------------------------------------ naming helpers

function snake(string $s): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $s));
}

function plural(string $s): string
{
    if (preg_match('/[^aeiou]y$/', $s)) {
        return substr($s, 0, -1).'ies';
    }
    if (preg_match('/(s|x|z|ch|sh)$/', $s)) {
        return $s.'es';
    }

    return $s.'s';
}

$table = plural(snake($entity));
$uri = str_replace('_', '-', $table);
$baseNs = trim(str_replace('/', '\\', (string) $opts['namespace']), '\\');
$basePath = trim(str_replace('\\', '/', (string) $opts['base']), '/');
$modNs = "$baseNs\\$module";
$modPath = "$basePath/$module";

$exec = $laravelActions && $level <= 2 ? 'handle' : 'execute';

$vars = [
    '{{Module}}' => $module,
    '{{Entity}}' => $entity,
    '{{entity}}' => lcfirst($entity),
    '{{entityWords}}' => str_replace('_', ' ', snake($entity)),
    '{{a}}' => preg_match('/^[AEIOU]/', $entity) ? 'an' : 'a',
    '{{UseCase}}' => $useCase,
    '{{table}}' => $table,
    '{{uri}}' => $uri,
    '{{ModNs}}' => $modNs,
    '{{BaseNs}}' => $baseNs,
    '{{basePath}}' => $basePath,
    '{{exec}}' => $exec,
    '{{SharedNs}}' => "$baseNs\\Shared",
];

function tpl(string $template, array $extra = []): string
{
    global $vars;

    return strtr($template, $extra + $vars);
}

// ------------------------------------------------------------------ shared templates

$tplModel = <<<'PHP'
<?php

namespace {{ns}};

use {{factoryFqcn}};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class {{Entity}} extends Model
{
    /** @use HasFactory<{{factoryClass}}> */
    use HasFactory;

    protected $table = '{{table}}';

    protected $fillable = ['name'];

    protected static function newFactory(): {{factoryClass}}
    {
        return {{factoryClass}}::new();
    }
}

PHP;

$tplFactory = <<<'PHP'
<?php

namespace {{ns}};

use {{modelFqcn}};
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<{{Entity}}> */
class {{Entity}}Factory extends Factory
{
    protected $model = {{Entity}}::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
        ];
    }
}

PHP;

$tplMigration = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{table}}', function (Blueprint $table) {
            {{idColumn}}
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{table}}');
    }
};

PHP;

$tplDtoPlain = <<<'PHP'
<?php

namespace {{ns}};

final readonly class {{Entity}}Data
{
    public function __construct(
        public string $name,
    ) {}

    /** @param array{name: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
        );
    }
}

PHP;

$tplDtoSpatie = <<<'PHP'
<?php

namespace {{ns}};

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

final class {{Entity}}Data extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
    ) {}
}

PHP;

$dtoTemplate = $laravelData ? $tplDtoSpatie : $tplDtoPlain;
$dtoFromRequest = $laravelData ? '{{Entity}}Data::from($request->validated())' : '{{Entity}}Data::fromArray($request->validated())';

$tplRequest = <<<'PHP'
<?php

namespace {{ns}};

use Illuminate\Foundation\Http\FormRequest;

final class Store{{Entity}}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TODO: policy / permission check
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

PHP;

$tplResource = <<<'PHP'
<?php

namespace {{ns}};

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \{{modelFqcn}} */
final class {{Entity}}Resource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
        ];
    }
}

PHP;

$tplEventEloquent = <<<'PHP'
<?php

namespace {{ns}};

use {{modelFqcn}};
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class {{Entity}}Created implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly {{Entity}} ${{entity}}) {}
}

PHP;

$tplAction = <<<'PHP'
<?php

namespace {{ns}};

use {{dtoFqcn}};
use {{eventFqcn}};
use {{modelFqcn}};
use Illuminate\Support\Facades\DB;{{actionsImport}}

final class {{UseCase}}Action
{{{actionsTrait}}
    public function {{exec}}({{Entity}}Data $data): {{Entity}}
    {
        return DB::transaction(function () use ($data) {
            ${{entity}} = {{Entity}}::create([
                'name' => $data->name,
            ]);

            {{Entity}}Created::dispatch(${{entity}});

            return ${{entity}};
        });
    }
}

PHP;

$actionsVars = $laravelActions
    ? ['{{actionsImport}}' => "\nuse Lorisleiva\\Actions\\Concerns\\AsAction;", '{{actionsTrait}}' => "\n    use AsAction;\n"]
    : ['{{actionsImport}}' => '', '{{actionsTrait}}' => ''];

$tplControllerAction = <<<'PHP'
<?php

namespace {{ns}};

use {{actionFqcn}};
use {{dtoFqcn}};
use {{requestFqcn}};
use {{resourceFqcn}};
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class {{Entity}}Controller extends Controller
{
    public function store(Store{{Entity}}Request $request, {{actionClass}} $action): JsonResponse
    {
        ${{entity}} = $action->{{exec}}({{dtoFromRequest}});

        return {{Entity}}Resource::make(${{entity}})->response()->setStatusCode(201);
    }
}

PHP;

$tplFeatureTestPest = <<<'PHP'
<?php
{{pestUses}}
it('creates {{a}} {{entityWords}} via HTTP', function () {
    $this->postJson('/{{routePrefix}}{{uri}}', ['name' => 'Example'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Example');

    $this->assertDatabaseHas('{{table}}', ['name' => 'Example']);
});

it('validates the {{entityWords}} payload', function () {
    $this->postJson('/{{routePrefix}}{{uri}}', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

PHP;

$tplFeatureTestPhpunit = <<<'PHP'
<?php

namespace {{ns}};

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class {{UseCase}}Test extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_{{a}}_{{entitySnake}}_via_http(): void
    {
        $this->postJson('/{{routePrefix}}{{uri}}', ['name' => 'Example'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Example');

        $this->assertDatabaseHas('{{table}}', ['name' => 'Example']);
    }

    public function test_it_validates_the_payload(): void
    {
        $this->postJson('/{{routePrefix}}{{uri}}', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
}

PHP;

$pestUses = $pestGlobalRefresh ? '' : "\nuses(Illuminate\\Foundation\\Testing\\RefreshDatabase::class);\n";

// ------------------------------------------------------------------ build file map

/** @var array<string, string> $files  relative path => contents */
$files = [];
$providers = [];
$routeSnippet = null;
$notes = [];

$migrationExists = (bool) glob("$root/database/migrations/*_create_{$table}_table.php");
$migrationPath = 'database/migrations/'.date('Y_m_d_His')."_create_{$table}_table.php";

$featureTest = function (string $testNs, string $testPath, string $routePrefix) use ($pest, $pestUses, $tplFeatureTestPest, $tplFeatureTestPhpunit, $useCase, $entity): array {
    $extra = ['{{ns}}' => $testNs, '{{routePrefix}}' => $routePrefix, '{{pestUses}}' => $pestUses, '{{entitySnake}}' => snake($entity)];

    return ["$testPath/{$useCase}Test.php" => tpl($pest ? $tplFeatureTestPest : $tplFeatureTestPhpunit, $extra)];
};

$routesTarget = is_file("$root/routes/api.php") ? 'routes/api.php' : 'routes/web.php';
$routePrefixL12 = $routesTarget === 'routes/api.php' ? 'api/' : '';

if ($level === 1) {
    $modelFqcn = "App\\Models\\$entity";
    $factoryFqcn = "Database\\Factories\\{$entity}Factory";
    $f = [
        "app/Models/$entity.php" => tpl($tplModel, ['{{ns}}' => 'App\\Models', '{{factoryFqcn}}' => $factoryFqcn, '{{factoryClass}}' => "{$entity}Factory"]),
        "database/factories/{$entity}Factory.php" => tpl($tplFactory, ['{{ns}}' => 'Database\\Factories', '{{modelFqcn}}' => $modelFqcn]),
        "app/Data/{$entity}Data.php" => tpl($dtoTemplate, ['{{ns}}' => 'App\\Data']),
        "app/Events/{$entity}Created.php" => tpl($tplEventEloquent, ['{{ns}}' => 'App\\Events', '{{modelFqcn}}' => $modelFqcn]),
        "app/Actions/$entity/{$useCase}Action.php" => tpl($tplAction, $actionsVars + [
            '{{ns}}' => "App\\Actions\\$entity", '{{dtoFqcn}}' => "App\\Data\\{$entity}Data",
            '{{eventFqcn}}' => "App\\Events\\{$entity}Created", '{{modelFqcn}}' => $modelFqcn]),
        "app/Http/Requests/$entity/Store{$entity}Request.php" => tpl($tplRequest, ['{{ns}}' => "App\\Http\\Requests\\$entity"]),
        "app/Http/Resources/{$entity}Resource.php" => tpl($tplResource, ['{{ns}}' => 'App\\Http\\Resources', '{{modelFqcn}}' => $modelFqcn]),
        "app/Http/Controllers/{$entity}Controller.php" => tpl(strtr($tplControllerAction, ['{{dtoFromRequest}}' => $dtoFromRequest]), [
            '{{ns}}' => 'App\\Http\\Controllers', '{{actionFqcn}}' => "App\\Actions\\$entity\\{$useCase}Action",
            '{{actionClass}}' => "{$useCase}Action", '{{dtoFqcn}}' => "App\\Data\\{$entity}Data",
            '{{requestFqcn}}' => "App\\Http\\Requests\\$entity\\Store{$entity}Request",
            '{{resourceFqcn}}' => "App\\Http\\Resources\\{$entity}Resource"]),
    ];
    if (is_file("$root/app/Models/$entity.php")) {
        unset($f["app/Models/$entity.php"]);
        $notes[] = "app/Models/$entity.php already exists: kept it. Make sure it has a 'name' attribute or adjust the generated code.";
    }
    $files += $f;
    $files += $featureTest($pest ? '' : "Tests\\Feature\\$entity", "tests/Feature/$entity", $routePrefixL12);
    $routeSnippet = "Route::post('/$uri', [\\App\\Http\\Controllers\\{$entity}Controller::class, 'store'])->name('$uri.store');";
}

if ($level === 2) {
    $dns = "App\\Domain\\$module";
    $modelFqcn = "$dns\\Models\\$entity";
    $factoryFqcn = "Database\\Factories\\$module\\{$entity}Factory";
    $files += [
        "app/Domain/$module/Models/$entity.php" => tpl($tplModel, ['{{ns}}' => "$dns\\Models", '{{factoryFqcn}}' => $factoryFqcn, '{{factoryClass}}' => "{$entity}Factory"]),
        "database/factories/$module/{$entity}Factory.php" => tpl($tplFactory, ['{{ns}}' => "Database\\Factories\\$module", '{{modelFqcn}}' => $modelFqcn]),
        "app/Domain/$module/Data/{$entity}Data.php" => tpl($dtoTemplate, ['{{ns}}' => "$dns\\Data"]),
        "app/Domain/$module/Events/{$entity}Created.php" => tpl($tplEventEloquent, ['{{ns}}' => "$dns\\Events", '{{modelFqcn}}' => $modelFqcn]),
        "app/Domain/$module/Actions/{$useCase}Action.php" => tpl($tplAction, $actionsVars + [
            '{{ns}}' => "$dns\\Actions", '{{dtoFqcn}}' => "$dns\\Data\\{$entity}Data",
            '{{eventFqcn}}' => "$dns\\Events\\{$entity}Created", '{{modelFqcn}}' => $modelFqcn]),
        "app/Domain/$module/Enums/.gitkeep" => '',
        "app/Domain/$module/Exceptions/.gitkeep" => '',
        "app/Domain/$module/Listeners/.gitkeep" => '',
        "app/Domain/$module/Jobs/.gitkeep" => '',
        "app/Domain/$module/Services/.gitkeep" => '',
        "app/Http/Requests/$module/Store{$entity}Request.php" => tpl($tplRequest, ['{{ns}}' => "App\\Http\\Requests\\$module"]),
        "app/Http/Resources/$module/{$entity}Resource.php" => tpl($tplResource, ['{{ns}}' => "App\\Http\\Resources\\$module", '{{modelFqcn}}' => $modelFqcn]),
        "app/Http/Controllers/$module/{$entity}Controller.php" => tpl(strtr($tplControllerAction, ['{{dtoFromRequest}}' => $dtoFromRequest]), [
            '{{ns}}' => "App\\Http\\Controllers\\$module", '{{actionFqcn}}' => "$dns\\Actions\\{$useCase}Action",
            '{{actionClass}}' => "{$useCase}Action", '{{dtoFqcn}}' => "$dns\\Data\\{$entity}Data",
            '{{requestFqcn}}' => "App\\Http\\Requests\\$module\\Store{$entity}Request",
            '{{resourceFqcn}}' => "App\\Http\\Resources\\$module\\{$entity}Resource"]),
    ];
    if (! is_file("$root/app/Providers/DomainServiceProvider.php")) {
        $files['app/Providers/DomainServiceProvider.php'] = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Relation::enforceMorphMap(['order' => \App\Domain\Order\Models\Order::class]);
        // Event::listen(\App\Domain\Order\Events\OrderPlaced::class, \App\Domain\Order\Listeners\SendOrderConfirmation::class);
    }
}

PHP;
    }
    $providers[] = 'App\\Providers\\DomainServiceProvider';
    $files += $featureTest($pest ? '' : "Tests\\Feature\\Domain\\$module", "tests/Feature/Domain/$module", $routePrefixL12);
    $routeSnippet = "Route::post('/$uri', [\\App\\Http\\Controllers\\$module\\{$entity}Controller::class, 'store'])->name('$uri.store');";
}

if ($level === 3) {
    $modelFqcn = "$modNs\\Domain\\Models\\$entity";
    $factoryFqcn = "Database\\Factories\\$module\\{$entity}Factory";
    $files += [
        "$modPath/Domain/Models/$entity.php" => tpl($tplModel, ['{{ns}}' => "$modNs\\Domain\\Models", '{{factoryFqcn}}' => $factoryFqcn, '{{factoryClass}}' => "{$entity}Factory"]),
        "database/factories/$module/{$entity}Factory.php" => tpl($tplFactory, ['{{ns}}' => "Database\\Factories\\$module", '{{modelFqcn}}' => $modelFqcn]),
        "$modPath/Domain/Events/{$entity}Created.php" => tpl($tplEventEloquent, ['{{ns}}' => "$modNs\\Domain\\Events", '{{modelFqcn}}' => $modelFqcn]),
        "$modPath/Domain/Contracts/{$entity}Repository.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\Contracts;

use {{ModNs}}\Domain\Models\{{Entity}};

interface {{Entity}}Repository
{
    public function find(int $id): ?{{Entity}};

    public function save({{Entity}} ${{entity}}): void;
}

PHP),
        "$modPath/Domain/Enums/.gitkeep" => '',
        "$modPath/Domain/ValueObjects/.gitkeep" => '',
        "$modPath/Domain/Exceptions/.gitkeep" => '',
        "$modPath/Domain/Services/.gitkeep" => '',
        "$modPath/Application/DTOs/{$entity}Data.php" => tpl($dtoTemplate, ['{{ns}}' => "$modNs\\Application\\DTOs"]),
        "$modPath/Application/UseCases/$useCase.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Application\UseCases;

use {{ModNs}}\Application\DTOs\{{Entity}}Data;
use {{ModNs}}\Domain\Contracts\{{Entity}}Repository;
use {{ModNs}}\Domain\Events\{{Entity}}Created;
use {{ModNs}}\Domain\Models\{{Entity}};
use Illuminate\Support\Facades\DB;

final class {{UseCase}}
{
    public function __construct(private {{Entity}}Repository $repository) {}

    public function execute({{Entity}}Data $data): {{Entity}}
    {
        return DB::transaction(function () use ($data) {
            ${{entity}} = new {{Entity}}(['name' => $data->name]);
            $this->repository->save(${{entity}});

            {{Entity}}Created::dispatch(${{entity}});

            return ${{entity}};
        });
    }
}

PHP),
        "$modPath/Application/Queries/.gitkeep" => '',
        "$modPath/Infrastructure/Repositories/Eloquent{$entity}Repository.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Repositories;

use {{ModNs}}\Domain\Contracts\{{Entity}}Repository;
use {{ModNs}}\Domain\Models\{{Entity}};

final class Eloquent{{Entity}}Repository implements {{Entity}}Repository
{
    public function find(int $id): ?{{Entity}}
    {
        return {{Entity}}::find($id);
    }

    public function save({{Entity}} ${{entity}}): void
    {
        ${{entity}}->save();
    }
}

PHP),
        "$modPath/Infrastructure/Providers/{$module}ServiceProvider.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Providers;

use {{ModNs}}\Domain\Contracts\{{Entity}}Repository;
use {{ModNs}}\Infrastructure\Repositories\Eloquent{{Entity}}Repository;
use Illuminate\Support\ServiceProvider;

final class {{Module}}ServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        {{Entity}}Repository::class => Eloquent{{Entity}}Repository::class,
    ];

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/routes.php');

        // Event::listen(\{{ModNs}}\Domain\Events\{{Entity}}Created::class, \{{ModNs}}\Infrastructure\Listeners\...::class);
    }
}

PHP),
        "$modPath/Infrastructure/Listeners/.gitkeep" => '',
        "$modPath/Infrastructure/Jobs/.gitkeep" => '',
        "$modPath/Infrastructure/Gateways/.gitkeep" => '',
        "$modPath/Presentation/Http/Requests/Store{$entity}Request.php" => tpl($tplRequest, ['{{ns}}' => "$modNs\\Presentation\\Http\\Requests"]),
        "$modPath/Presentation/Http/Resources/{$entity}Resource.php" => tpl($tplResource, ['{{ns}}' => "$modNs\\Presentation\\Http\\Resources", '{{modelFqcn}}' => $modelFqcn]),
        "$modPath/Presentation/Http/Controllers/{$entity}Controller.php" => tpl(strtr($tplControllerAction, ['{{dtoFromRequest}}' => $dtoFromRequest, '{{exec}}' => 'execute']), [
            '{{ns}}' => "$modNs\\Presentation\\Http\\Controllers", '{{actionFqcn}}' => "$modNs\\Application\\UseCases\\$useCase",
            '{{actionClass}}' => $useCase, '{{dtoFqcn}}' => "$modNs\\Application\\DTOs\\{$entity}Data",
            '{{requestFqcn}}' => "$modNs\\Presentation\\Http\\Requests\\Store{$entity}Request",
            '{{resourceFqcn}}' => "$modNs\\Presentation\\Http\\Resources\\{$entity}Resource"]),
        "$modPath/Presentation/routes.php" => tpl(<<<'PHP'
<?php

use {{ModNs}}\Presentation\Http\Controllers\{{Entity}}Controller;
use Illuminate\Support\Facades\Route;

// Add auth middleware as needed, e.g. ['api', 'auth:sanctum'].
Route::middleware('api')->prefix('api/{{uri}}')->name('{{uri}}.')->group(function () {
    Route::post('/', [{{Entity}}Controller::class, 'store'])->name('store');
});

PHP),
    ];
    $providers[] = "$modNs\\Infrastructure\\Providers\\{$module}ServiceProvider";
    $files += $featureTest($pest ? '' : "Tests\\Feature\\Modules\\$module", "tests/Feature/Modules/$module", 'api/');
    if ($pest && ! is_file("$root/tests/Unit/ArchitectureTest.php")) {
        $files['tests/Unit/ArchitectureTest.php'] = tpl(<<<'PHP'
<?php

// Level 3 (Modular Clean) dependency rules: see the laravel-clean-architecture skill.
$modules = array_values(array_diff(
    array_map('basename', glob(dirname(__DIR__, 2).'/{{basePath}}/*', GLOB_ONLYDIR) ?: []),
    ['Shared'],
));

foreach ($modules as $m) {
    $ns = "{{BaseNs}}\\$m";

    arch("$m: domain has no HTTP/application/infrastructure deps")
        ->expect("$ns\\Domain")
        ->not->toUse(["$ns\\Application", "$ns\\Infrastructure", "$ns\\Presentation",
            'Illuminate\Http', 'Illuminate\Support\Facades\Http', 'request', 'auth', 'session']);

    arch("$m: application does not depend on infrastructure/presentation")
        ->expect("$ns\\Application")
        ->not->toUse(["$ns\\Infrastructure", "$ns\\Presentation", 'Illuminate\Http', 'request', 'auth', 'session']);

    arch("$m: contracts are interfaces")
        ->expect("$ns\\Domain\\Contracts")
        ->toBeInterfaces();

    foreach (array_diff($modules, [$m]) as $other) {
        arch("$m does not use $other internals")
            ->expect($ns)
            ->not->toUse(["{{BaseNs}}\\$other\\Infrastructure", "{{BaseNs}}\\$other\\Presentation"]);
    }
}

PHP);
    }
}

if ($level === 4) {
    $sharedNs = "$baseNs\\Shared";
    $sharedPath = "$basePath/Shared";
    $sv = ['{{SharedNs}}' => $sharedNs];
    if (! is_dir("$root/$sharedPath/Application/Ports")) {
        $files += [
            "$sharedPath/Domain/AggregateRoot.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Domain;

abstract class AggregateRoot
{
    /** @var list<object> */
    private array $recordedEvents = [];

    protected function record(object $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<object> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}

PHP, $sv),
            "$sharedPath/Application/Ports/TransactionManager.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Application\Ports;

interface TransactionManager
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed;
}

PHP, $sv),
            "$sharedPath/Application/Ports/EventPublisher.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Application\Ports;

interface EventPublisher
{
    public function publish(object ...$events): void;
}

PHP, $sv),
            "$sharedPath/Application/Ports/Clock.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Application\Ports;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}

PHP, $sv),
            "$sharedPath/Infrastructure/LaravelTransactionManager.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Infrastructure;

use {{SharedNs}}\Application\Ports\TransactionManager;
use Illuminate\Database\ConnectionInterface;

final class LaravelTransactionManager implements TransactionManager
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}

PHP, $sv),
            "$sharedPath/Infrastructure/LaravelEventPublisher.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Infrastructure;

use {{SharedNs}}\Application\Ports\EventPublisher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

final class LaravelEventPublisher implements EventPublisher
{
    public function __construct(private Dispatcher $events, private ConnectionInterface $db) {}

    public function publish(object ...$events): void
    {
        // Runs immediately when no transaction is open.
        $this->db->afterCommit(function () use ($events): void {
            foreach ($events as $event) {
                $this->events->dispatch($event);
            }
        });
    }
}

PHP, $sv),
            "$sharedPath/Infrastructure/SystemClock.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Infrastructure;

use {{SharedNs}}\Application\Ports\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

PHP, $sv),
            "$sharedPath/Infrastructure/SharedServiceProvider.php" => tpl(<<<'PHP'
<?php

namespace {{SharedNs}}\Infrastructure;

use {{SharedNs}}\Application\Ports\Clock;
use {{SharedNs}}\Application\Ports\EventPublisher;
use {{SharedNs}}\Application\Ports\TransactionManager;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        TransactionManager::class => LaravelTransactionManager::class,
        EventPublisher::class => LaravelEventPublisher::class,
        Clock::class => SystemClock::class,
    ];
}

PHP, $sv),
        ];
    }
    $providers[] = "$sharedNs\\Infrastructure\\SharedServiceProvider";

    $files += [
        "$modPath/Domain/ValueObjects/{$entity}Id.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class {{Entity}}Id
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidArgumentException("Invalid {{entityWords}} id [$value].");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

PHP),
        "$modPath/Domain/Entities/$entity.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\Entities;

use {{ModNs}}\Domain\Events\{{Entity}}Created;
use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;
use {{SharedNs}}\Domain\AggregateRoot;
use DateTimeImmutable;
use InvalidArgumentException;

final class {{Entity}} extends AggregateRoot
{
    private function __construct(
        private {{Entity}}Id $id,
        private string $name,
        private DateTimeImmutable $createdAt,
    ) {}

    public static function create({{Entity}}Id $id, string $name, DateTimeImmutable $now): self
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Name cannot be empty.');
        }

        ${{entity}} = new self($id, $name, $now);
        ${{entity}}->record(new {{Entity}}Created($id->value, $name));

        return ${{entity}};
    }

    /** Rebuild from persistence: no events recorded. */
    public static function reconstitute({{Entity}}Id $id, string $name, DateTimeImmutable $createdAt): self
    {
        return new self($id, $name, $createdAt);
    }

    public function id(): {{Entity}}Id
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}

PHP, $sv),
        "$modPath/Domain/Events/{$entity}Created.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\Events;

final readonly class {{Entity}}Created
{
    public function __construct(public string ${{entity}}Id, public string $name) {}
}

PHP),
        "$modPath/Domain/Exceptions/{$entity}NotFound.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\Exceptions;

use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;
use DomainException;

final class {{Entity}}NotFound extends DomainException
{
    public static function withId({{Entity}}Id $id): self
    {
        return new self("{{Entity}} [{$id->value}] not found.");
    }
}

PHP),
        "$modPath/Domain/Repositories/{$entity}Repository.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Domain\Repositories;

use {{ModNs}}\Domain\Entities\{{Entity}};
use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;

interface {{Entity}}Repository
{
    public function nextIdentity(): {{Entity}}Id;

    /** @throws \{{ModNs}}\Domain\Exceptions\{{Entity}}NotFound */
    public function get({{Entity}}Id $id): {{Entity}};

    public function save({{Entity}} ${{entity}}): void;
}

PHP),
        "$modPath/Application/Commands/$useCase/{$useCase}Command.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Application\Commands\{{UseCase}};

final readonly class {{UseCase}}Command
{
    public function __construct(public string $name) {}
}

PHP),
        "$modPath/Application/DTOs/{$entity}View.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Application\DTOs;

use {{ModNs}}\Domain\Entities\{{Entity}};

final readonly class {{Entity}}View
{
    public function __construct(public string $id, public string $name, public string $createdAt) {}

    public static function fromEntity({{Entity}} ${{entity}}): self
    {
        return new self(${{entity}}->id()->value, ${{entity}}->name(), ${{entity}}->createdAt()->format(DATE_ATOM));
    }

    /** @return array{id: string, name: string, created_at: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'created_at' => $this->createdAt];
    }
}

PHP),
        "$modPath/Application/Commands/$useCase/{$useCase}Handler.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Application\Commands\{{UseCase}};

use {{ModNs}}\Application\DTOs\{{Entity}}View;
use {{ModNs}}\Domain\Entities\{{Entity}};
use {{ModNs}}\Domain\Repositories\{{Entity}}Repository;
use {{SharedNs}}\Application\Ports\Clock;
use {{SharedNs}}\Application\Ports\EventPublisher;
use {{SharedNs}}\Application\Ports\TransactionManager;

final class {{UseCase}}Handler
{
    public function __construct(
        private {{Entity}}Repository $repository,
        private TransactionManager $transactions,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    public function handle({{UseCase}}Command $command): {{Entity}}View
    {
        return $this->transactions->run(function () use ($command): {{Entity}}View {
            ${{entity}} = {{Entity}}::create($this->repository->nextIdentity(), $command->name, $this->clock->now());
            $this->repository->save(${{entity}});
            $this->events->publish(...${{entity}}->releaseEvents());

            return {{Entity}}View::fromEntity(${{entity}});
        });
    }
}

PHP, $sv),
        "$modPath/Application/Ports/.gitkeep" => '',
        "$modPath/Infrastructure/Persistence/{$entity}Model.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class {{Entity}}Model extends Model
{
    use HasUuids;

    protected $table = '{{table}}';

    protected $fillable = ['id', 'name', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }
}

PHP),
        "$modPath/Infrastructure/Persistence/{$entity}Mapper.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Persistence;

use {{ModNs}}\Domain\Entities\{{Entity}};
use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;
use DateTimeImmutable;

final class {{Entity}}Mapper
{
    public function toEntity({{Entity}}Model $model): {{Entity}}
    {
        return {{Entity}}::reconstitute(
            new {{Entity}}Id($model->id),
            $model->name,
            DateTimeImmutable::createFromInterface($model->created_at),
        );
    }

    /** @return array<string, mixed> */
    public function toPersistence({{Entity}} ${{entity}}): array
    {
        return [
            'id' => ${{entity}}->id()->value,
            'name' => ${{entity}}->name(),
            'created_at' => ${{entity}}->createdAt(),
        ];
    }
}

PHP),
        "$modPath/Infrastructure/Persistence/Eloquent{$entity}Repository.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Persistence;

use {{ModNs}}\Domain\Entities\{{Entity}};
use {{ModNs}}\Domain\Exceptions\{{Entity}}NotFound;
use {{ModNs}}\Domain\Repositories\{{Entity}}Repository;
use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;
use Illuminate\Support\Str;

final class Eloquent{{Entity}}Repository implements {{Entity}}Repository
{
    public function __construct(private {{Entity}}Mapper $mapper) {}

    public function nextIdentity(): {{Entity}}Id
    {
        return new {{Entity}}Id((string) Str::orderedUuid());
    }

    public function get({{Entity}}Id $id): {{Entity}}
    {
        $model = {{Entity}}Model::query()->find($id->value) ?? throw {{Entity}}NotFound::withId($id);

        return $this->mapper->toEntity($model);
    }

    public function save({{Entity}} ${{entity}}): void
    {
        $data = $this->mapper->toPersistence(${{entity}});

        {{Entity}}Model::query()->updateOrCreate(['id' => $data['id']], $data);
    }
}

PHP),
        "$modPath/Infrastructure/Providers/{$module}ServiceProvider.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Infrastructure\Providers;

use {{ModNs}}\Domain\Repositories\{{Entity}}Repository;
use {{ModNs}}\Infrastructure\Persistence\Eloquent{{Entity}}Repository;
use Illuminate\Support\ServiceProvider;

final class {{Module}}ServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        {{Entity}}Repository::class => Eloquent{{Entity}}Repository::class,
    ];

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/routes.php');

        // Event::listen(\{{ModNs}}\Domain\Events\{{Entity}}Created::class, \{{ModNs}}\Infrastructure\Listeners\...::class);
    }
}

PHP),
        "$modPath/Infrastructure/Adapters/.gitkeep" => '',
        "$modPath/Presentation/Http/Requests/Store{$entity}Request.php" => tpl($tplRequest, ['{{ns}}' => "$modNs\\Presentation\\Http\\Requests"]),
        "$modPath/Presentation/Http/Controllers/{$entity}Controller.php" => tpl(<<<'PHP'
<?php

namespace {{ModNs}}\Presentation\Http\Controllers;

use {{ModNs}}\Application\Commands\{{UseCase}}\{{UseCase}}Command;
use {{ModNs}}\Application\Commands\{{UseCase}}\{{UseCase}}Handler;
use {{ModNs}}\Presentation\Http\Requests\Store{{Entity}}Request;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class {{Entity}}Controller extends Controller
{
    public function store(Store{{Entity}}Request $request, {{UseCase}}Handler $handler): JsonResponse
    {
        $view = $handler->handle(new {{UseCase}}Command(
            name: $request->validated('name'),
        ));

        return response()->json(['data' => $view->toArray()], 201);
    }
}

PHP),
        "$modPath/Presentation/routes.php" => tpl(<<<'PHP'
<?php

use {{ModNs}}\Presentation\Http\Controllers\{{Entity}}Controller;
use Illuminate\Support\Facades\Route;

// Add auth middleware as needed, e.g. ['api', 'auth:sanctum'].
Route::middleware('api')->prefix('api/{{uri}}')->name('{{uri}}.')->group(function () {
    Route::post('/', [{{Entity}}Controller::class, 'store'])->name('store');
});

PHP),
    ];
    $providers[] = "$modNs\\Infrastructure\\Providers\\{$module}ServiceProvider";

    // Pure unit test for the handler (no Laravel boot).
    $unitTestBody = <<<'PHP'
$repository = new class implements {{Entity}}Repository
{
    /** @var array<string, {{Entity}}> */
    public array $items = [];

    private int $sequence = 0;

    public function nextIdentity(): {{Entity}}Id
    {
        return new {{Entity}}Id(sprintf('00000000-0000-4000-8000-%012d', ++$this->sequence));
    }

    public function get({{Entity}}Id $id): {{Entity}}
    {
        return $this->items[$id->value] ?? throw {{Entity}}NotFound::withId($id);
    }

    public function save({{Entity}} ${{entity}}): void
    {
        $this->items[${{entity}}->id()->value] = ${{entity}};
    }
};
$events = new class implements EventPublisher
{
    /** @var list<object> */
    public array $published = [];

    public function publish(object ...$events): void
    {
        array_push($this->published, ...$events);
    }
};
$transactions = new class implements TransactionManager
{
    public function run(callable $work): mixed
    {
        return $work();
    }
};
$clock = new class implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T10:00:00+00:00');
    }
};

$view = (new {{UseCase}}Handler($repository, $transactions, $events, $clock))
    ->handle(new {{UseCase}}Command(name: 'Example'));
PHP;
    $unitUses = <<<'PHP'
use {{ModNs}}\Application\Commands\{{UseCase}}\{{UseCase}}Command;
use {{ModNs}}\Application\Commands\{{UseCase}}\{{UseCase}}Handler;
use {{ModNs}}\Domain\Entities\{{Entity}};
use {{ModNs}}\Domain\Events\{{Entity}}Created;
use {{ModNs}}\Domain\Exceptions\{{Entity}}NotFound;
use {{ModNs}}\Domain\Repositories\{{Entity}}Repository;
use {{ModNs}}\Domain\ValueObjects\{{Entity}}Id;
use {{SharedNs}}\Application\Ports\Clock;
use {{SharedNs}}\Application\Ports\EventPublisher;
use {{SharedNs}}\Application\Ports\TransactionManager;
PHP;
    $indent = fn (string $s, int $n) => preg_replace('/^(?=.)/m', str_repeat(' ', $n), $s);

    if ($pest) {
        $files["tests/Unit/Modules/$module/{$useCase}HandlerTest.php"] = tpl("<?php\n\n$unitUses\n\nit('handles {{UseCase}} and publishes {{Entity}}Created', function () {\n"
            .$indent($unitTestBody, 4)
            ."\n\n    expect(\$view->name)->toBe('Example')\n        ->and(\$repository->items)->toHaveCount(1)\n        ->and(\$events->published)->toHaveCount(1)\n        ->and(\$events->published[0])->toBeInstanceOf({{Entity}}Created::class);\n});\n", $sv);
    } else {
        $files["tests/Unit/Modules/$module/{$useCase}HandlerTest.php"] = tpl("<?php\n\nnamespace Tests\\Unit\\Modules\\{{Module}};\n\n$unitUses\nuse DateTimeImmutable;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {{UseCase}}HandlerTest extends TestCase\n{\n    public function test_it_handles_the_command(): void\n    {\n"
            .$indent($unitTestBody, 8)
            ."\n\n        \$this->assertSame('Example', \$view->name);\n        \$this->assertCount(1, \$repository->items);\n        \$this->assertCount(1, \$events->published);\n        \$this->assertInstanceOf({{Entity}}Created::class, \$events->published[0]);\n    }\n}\n", $sv);
    }
    $files += $featureTest($pest ? '' : "Tests\\Feature\\Modules\\$module", "tests/Feature/Modules/$module", 'api/');

    if ($pest && ! is_file("$root/tests/Unit/ArchitectureTest.php")) {
        $files['tests/Unit/ArchitectureTest.php'] = tpl(<<<'PHP'
<?php

// Level 4 (Strict Hexagonal) dependency rules: see the laravel-clean-architecture skill.
$modules = array_values(array_diff(
    array_map('basename', glob(dirname(__DIR__, 2).'/{{basePath}}/*', GLOB_ONLYDIR) ?: []),
    ['Shared'],
));
$framework = ['Illuminate', 'Carbon', 'now', 'config', 'collect', 'dispatch', 'request', 'auth', 'session'];

arch('shared domain and application are framework-free')
    ->expect(['{{BaseNs}}\Shared\Domain', '{{BaseNs}}\Shared\Application'])
    ->not->toUse($framework);

foreach ($modules as $m) {
    $ns = "{{BaseNs}}\\$m";

    arch("$m: domain is pure PHP")
        ->expect("$ns\\Domain")
        ->not->toUse([...$framework, "$ns\\Application", "$ns\\Infrastructure", "$ns\\Presentation"]);

    arch("$m: application is framework-free")
        ->expect("$ns\\Application")
        ->not->toUse([...$framework, "$ns\\Infrastructure", "$ns\\Presentation"]);

    foreach (array_diff($modules, [$m]) as $other) {
        arch("$m does not use $other internals")
            ->expect($ns)
            ->not->toUse(["{{BaseNs}}\\$other\\Infrastructure", "{{BaseNs}}\\$other\\Presentation"]);
    }
}

PHP);
    }
}

// Migration (all levels)
if ($migrationExists) {
    $notes[] = "A create_{$table}_table migration already exists: no migration generated.";
} else {
    $files[$migrationPath] = tpl($tplMigration, ['{{idColumn}}' => $level === 4 ? "\$table->uuid('id')->primary();" : '$table->id();']);
}

// ------------------------------------------------------------------ write

$created = $skipped = [];
foreach ($files as $rel => $contents) {
    $abs = "$root/$rel";
    if (is_file($abs) && ! $opts['force']) {
        $skipped[] = $rel;
        continue;
    }
    if (! $opts['dry-run']) {
        if (! is_dir(dirname($abs)) && ! mkdir(dirname($abs), 0775, true) && ! is_dir(dirname($abs))) {
            fail('Cannot create directory '.dirname($abs));
        }
        file_put_contents($abs, $contents);
    }
    $created[] = $rel;
}

$registered = [];
if ($opts['register'] && ! $opts['dry-run']) {
    $providersFile = "$root/bootstrap/providers.php";
    if (is_file($providersFile) && $providers) {
        $src = (string) file_get_contents($providersFile);
        foreach ($providers as $p) {
            if (! str_contains($src, $p.'::class')) {
                $src = (string) preg_replace_callback('/\n\];\s*$/', fn () => "\n    $p::class,\n];\n", $src, 1, $count);
                if ($count) {
                    $registered[] = "bootstrap/providers.php ← $p";
                }
            }
        }
        file_put_contents($providersFile, $src);
    }
    if ($routeSnippet && is_file("$root/$routesTarget")) {
        $src = (string) file_get_contents("$root/$routesTarget");
        if (! str_contains($src, $routeSnippet)) {
            file_put_contents("$root/$routesTarget", rtrim($src)."\n\n$routeSnippet\n");
            $registered[] = "$routesTarget ← POST /$uri";
        }
    }
}

// ------------------------------------------------------------------ report

$levelNames = [1 => 'L1 Lean Actions', 2 => 'L2 Domain-oriented', 3 => 'L3 Modular Clean', 4 => 'L4 Strict Hexagonal'];
echo ($opts['dry-run'] ? '[dry-run] ' : '')."{$levelNames[$level]} scaffold for $module ($entity, use case $useCase), level from $levelSource\n";
echo 'Detected: '.implode(', ', array_filter([
    $sail ? 'Sail' : null, $laravelData ? 'spatie/laravel-data' : null,
    $laravelActions ? 'lorisleiva/laravel-actions' : null, $pest ? 'Pest' : 'PHPUnit',
]))."\n\n";
echo 'Created ('.count($created)."):\n";
foreach ($created as $f) {
    echo "  + $f\n";
}
if ($skipped) {
    echo "\nSkipped, already exist (use --force to overwrite):\n";
    foreach ($skipped as $f) {
        echo "  = $f\n";
    }
}
if ($registered) {
    echo "\nRegistered:\n";
    foreach ($registered as $r) {
        echo "  ✓ $r\n";
    }
}
foreach ($notes as $n) {
    echo "\nNote: $n\n";
}

echo "\nNext steps:\n";
$step = 1;
if ($providers && ! $opts['register']) {
    $where = is_file("$root/bootstrap/providers.php") ? 'bootstrap/providers.php' : "config/app.php 'providers'";
    foreach ($providers as $p) {
        echo '  '.$step++.". Register $p::class in $where\n";
    }
}
if ($routeSnippet && ! $opts['register']) {
    echo '  '.$step++.". Add to $routesTarget:\n       $routeSnippet\n";
}
if ($routesTarget === 'routes/web.php' && $level <= 2) {
    echo '  '.$step++.". No routes/api.php: the route goes to web.php. For an API run `$artisan install:api` first.\n";
}
echo '  '.$step++.". $artisan migrate\n";
echo '  '.$step++.". Replace the placeholder 'name' field with the real attributes (model, DTO, request, migration, tests).\n";
echo '  '.$step++.". $testCmd && $pint\n";
