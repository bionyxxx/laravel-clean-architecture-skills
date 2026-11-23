# Testing per level

Read this file when you write tests for Actions, UseCases or handlers, or when you add architecture (arch) rules.

## Contents
1. What to test at each level
2. Folder layout
3. Action/UseCase tests (L1–L3)
4. Handler unit tests with in-memory adapters (L3/L4)
5. HTTP feature tests
6. Fakes for side effects
7. Architecture tests (Pest `arch()`, Deptrac)
8. PHPUnit equivalents
9. Running tests (Sail aware)

## 1. What to test at each level

| Level | Must have | Nice to have |
|---|---|---|
| L1 | A feature test per Action (DB): success, rule violations, idempotent re-run | HTTP tests for critical endpoints, arch rules |
| L2 | Same as L1 per domain Action, cross-domain listener tests | Enum transition unit tests |
| L3 | UseCase tests (DB or in-memory repo), gateway contract tests with `Http::fake()`, arch rules per module | Query tests, a contract test shared by the real and fake repo |
| L4 | Pure unit tests for entities/VOs (no Laravel boot), handler tests with in-memory adapters, mapper round-trip tests, repository integration tests | Deptrac in CI |

Every business Action/UseCase/handler gets tests for: **the happy path**, **each rejected rule**, and **a repeated call** (idempotency) when the operation can be retried.

## 2. Folder layout

```
tests/
├── Arch/                       ArchitectureTest.php (or tests/ArchTest.php)
├── Unit/                       pure tests (no DB, no app boot where possible)
│   └── Modules/Order/Domain/   (L3/L4)
├── Feature/
│   ├── Actions/Order/          (L1)
│   ├── Domain/Order/           (L2)
│   └── Modules/Order/          (L3/L4: UseCases, HTTP, repositories)
└── Support/                    InMemoryOrderRepository, FakePaymentGateway, builders
```
Add `"Tests\\": "tests/"` to `autoload-dev` (Laravel's default) so the `Tests\Support` classes autoload.

## 3. Action/UseCase tests (Pest)

```php
<?php

use App\Domain\Order\Actions\CancelOrderAction;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Exceptions\OrderCannotBeCancelled;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => Event::fake([OrderCancelled::class]));

it('cancels a pending order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Pending]);

    app(CancelOrderAction::class)->execute($order, 'customer request');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
    Event::assertDispatched(OrderCancelled::class);
});

it('is idempotent', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

    app(CancelOrderAction::class)->execute($order, 'again');

    Event::assertNotDispatched(OrderCancelled::class);
});

it('refuses to cancel a shipped order', function () {
    $order = Order::factory()->create(['status' => OrderStatus::Shipped]);

    app(CancelOrderAction::class)->execute($order, 'too late');
})->throws(OrderCannotBeCancelled::class);
```
`tests/Pest.php` must apply `RefreshDatabase` (or `LazilyRefreshDatabase`) to `Feature`:
```php
pest()->extend(Tests\TestCase::class)->use(Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
```
(Pest 2 syntax: `uses(TestCase::class, RefreshDatabase::class)->in('Feature');`)

### Factories for module models (L2/L3)
Module models aren't in `App\Models`, so wire factories explicitly:
```php
// in the model, Laravel 13+
#[UseFactory(OrderFactory::class)]
final class Order extends Model { use HasFactory; }

// Laravel ≤ 12
protected static function newFactory(): OrderFactory { return OrderFactory::new(); }

// database/factories/Order/OrderFactory.php
namespace Database\Factories\Order;

final class OrderFactory extends Factory
{
    protected $model = \App\Modules\Order\Domain\Models\Order::class;
    public function definition(): array { return [/* … */]; }
}
```
At L4, factories build `*Model` rows for integration tests. For unit tests, use entity builders in `tests/Support` (`OrderBuilder::pending()->withLine(...)->build()`).

## 4. Handler unit tests with in-memory adapters (L3/L4)

```php
<?php

namespace Tests\Support;

use App\Modules\Order\Domain\Entities\Order;
use App\Modules\Order\Domain\Exceptions\OrderNotFound;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use App\Modules\Order\Domain\ValueObjects\OrderId;

final class InMemoryOrderRepository implements OrderRepository
{
    /** @var array<string, Order> */
    public array $orders = [];
    private int $seq = 0;

    public function nextIdentity(): OrderId
    {
        return new OrderId(sprintf('00000000-0000-0000-0000-%012d', ++$this->seq));
    }

    public function get(OrderId $id): Order
    {
        return $this->orders[$id->value] ?? throw OrderNotFound::withId($id);
    }

    public function save(Order $order): void
    {
        $this->orders[$order->id()->value] = $order;
    }
}
```

```php
<?php
// tests/Unit/Modules/Order/PlaceOrderHandlerTest.php: pure PHP, runs in milliseconds

use App\Modules\Order\Application\Commands\PlaceOrder\PlaceOrderCommand;
use App\Modules\Order\Application\Commands\PlaceOrder\PlaceOrderHandler;
use App\Modules\Order\Domain\Events\OrderPlaced;
use Tests\Support\{FakeClock, FakeProductCatalog, ImmediateTransactionManager, InMemoryOrderRepository, RecordingEventPublisher};

it('places an order and publishes OrderPlaced', function () {
    $repo = new InMemoryOrderRepository();
    $events = new RecordingEventPublisher();
    $handler = new PlaceOrderHandler($repo, new FakeProductCatalog([7 => 10_000]),
        new ImmediateTransactionManager(), $events, new FakeClock('2026-01-01 10:00'));

    $id = $handler->handle(new PlaceOrderCommand(customerId: 1, lines: [['productId' => 7, 'quantity' => 2]]));

    expect($repo->orders[$id]->total()->minor)->toBe(20_000)
        ->and($events->published)->toHaveCount(1)
        ->and($events->published[0])->toBeInstanceOf(OrderPlaced::class);
});
```
In Pest, unit tests under `tests/Unit` don't boot Laravel unless `Pest.php` extends `TestCase` there. Keep it that way for L4 Domain/Application tests.

**Contract tests:** write one test suite that runs against both `InMemoryOrderRepository` and `EloquentOrderRepository` (a Pest dataset of factories) so the fake can't drift from the real implementation.

**At L3 with Eloquent domain models**, prefer DB-backed UseCase tests (fast with SQLite in-memory or a MySQL testing DB) and swap only the external gateways:
```php
$this->app->instance(PaymentGateway::class, $fake = new FakePaymentGateway());
```

## 5. HTTP feature tests

```php
it('places an order via the API', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['price' => 10_000, 'stock' => 5]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/orders', ['lines' => [['product_id' => $product->id, 'quantity' => 2]]])
        ->assertCreated()
        ->assertJsonPath('data.total', 20_000);

    expect($product->fresh()->stock)->toBe(3);
});

it('validates input', function () {
    $this->actingAs(User::factory()->create(), 'sanctum')
        ->postJson('/api/orders', ['lines' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lines');
});
```
Livewire: `Livewire::test(Checkout::class)->set('lines', …)->call('submit')->assertHasNoErrors()`. Filament: `livewire(ListOrders::class)->callTableAction('cancel', $order, data: ['reason' => 'x'])`. Inertia: `->assertInertia(fn ($page) => $page->component('Orders/Show')->where('order.id', $order->id))`.

## 6. Fakes for side effects

| Facade | Use |
|---|---|
| `Event::fake([OrderPlaced::class])` | Assert dispatch. Fake only the listed events so model events still fire |
| `Queue::fake()` / `Bus::fake()` | Assert jobs pushed and chained |
| `Http::fake(['api.stripe.com/*' => Http::response([...], 200)])` + `Http::preventStrayRequests()` | Gateway adapters |
| `Notification::fake()`, `Mail::fake()` | Notifications |
| `Storage::fake('s3')` | Uploads, medialibrary |
| `$this->travelTo(...)` / `Carbon::setTestNow()` | Time (L1–L3). At L4 use the `FakeClock` port |
| `PlaceOrder::mock()` | `lorisleiva/laravel-actions` |

## 7. Architecture tests

### Pest `arch()`
- Built in since Pest 2. `arch()->preset()->laravel()` and `->preset()->security()` exist in Pest 3. Use them as a baseline plus the level rules.
- Level-specific rule sets are in each level file (section "Arch rules"). Copy the matching one into `tests/Arch/ArchitectureTest.php`.
- The level files build rules dynamically from `glob(app/Modules/*)` so new modules are covered without edits.
- Baseline for every level:
```php
arch()->preset()->php();
arch()->preset()->security()->ignoring('md5'); // adjust as needed
arch('no debugging leftovers')->expect(['dd', 'dump', 'ray', 'var_dump'])->not->toBeUsed();
arch('no env() outside config')->expect('env')->not->toBeUsedIn('App');
```
(Larastan has a matching check, `noEnvCallsOutsideOfConfig: true`, in `phpstan.neon`.)

### Deptrac (no Pest, or very large L3/L4 codebases)
```yaml
# deptrac.yaml
deptrac:
  paths: [./app]
  layers:
    - name: Domain
      collectors: [{ type: directory, value: app/Modules/.*/Domain/.* }]
    - name: Application
      collectors: [{ type: directory, value: app/Modules/.*/Application/.* }]
    - name: Infrastructure
      collectors: [{ type: directory, value: app/Modules/.*/Infrastructure/.* }]
    - name: Presentation
      collectors: [{ type: directory, value: app/Modules/.*/Presentation/.* }]
    - name: Laravel
      collectors: [{ type: classLike, value: ^Illuminate\\.* }]
  ruleset:
    Domain: [Laravel]            # L3 (Eloquent). For L4 use: Domain: []
    Application: [Domain]        # L4: Application: [Domain]. L3 may add Laravel
    Infrastructure: [Domain, Application, Laravel]
    Presentation: [Application, Domain, Laravel]
```
Run it with `vendor/bin/deptrac analyse` (through Sail if present).

## 8. PHPUnit equivalents

- Test classes extend `Tests\TestCase` and use `RefreshDatabase`. Methods are named `test_it_cancels_a_pending_order()` or use `#[Test]`.
- Exceptions: `$this->expectException(OrderCannotBeCancelled::class);`.
- Pure unit tests (L4) extend `PHPUnit\Framework\TestCase`, not Laravel's, so the app doesn't boot.
- Without Pest, enforce the architecture with Deptrac. `phpat/phpat` (a PHPStan extension) is another option if Larastan is installed.

### Laravel 13 notes
- Laravel 13 ships Pest 4/5 and PHPUnit 12. The generated tests and the `arch()` rules in the level files were verified on Laravel 13 with Pest 5 and PHPUnit 12.
- The fresh `tests/Pest.php` imports `RefreshDatabase` but leaves `->use(RefreshDatabase::class)` **commented out**. Either enable it for `Feature`, or add `uses(RefreshDatabase::class);` per file (the generator does this automatically when needed).
- Testing attributes are available: `#[Seed]`, `#[Seeder(...)]`, `#[SetUp]`/`#[TearDown]`, `#[WithoutMigrationEvents]`.

## 9. Running tests

| Setup | Command |
|---|---|
| Sail | `./vendor/bin/sail test` (or `sail pest`, `sail artisan test --filter=PlaceOrder`) |
| Host PHP | `php artisan test` / `./vendor/bin/pest` |
| Parallel | `php artisan test --parallel` (needs `brianium/paratest`) |
| Coverage | `--coverage --min=80` (needs Xdebug or PCOV in the container) |

Run Pint after tests pass: `./vendor/bin/pint --dirty` (or `sail pint --dirty`).
