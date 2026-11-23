# Level 3: Modular Clean (pragmatic)

Each module is split into four layers: **Domain**, **Application**, **Infrastructure** and **Presentation**. Eloquent models are allowed in Domain (that's the pragmatic part). Interfaces (contracts) are added where a real seam exists: external services always, and persistence when queries are complex or you want DB-free unit tests. Use it for medium to large apps, many integrations, several delivery channels, and teams.

## Contents
1. Folder tree
2. Dependency rule
3. Class types
4. Rules
5. Templates (Domain → Application → Infrastructure → Presentation)
6. Module provider and registration
7. Arch rules
8. When to move up

## 1. Folder tree

```
app/Modules/
├── Order/
│   ├── Domain/
│   │   ├── Models/          Order, OrderLine (Eloquent: relations, casts, simple invariants)
│   │   ├── Enums/           OrderStatus
│   │   ├── ValueObjects/    OrderNumber
│   │   ├── Events/          OrderPlaced
│   │   ├── Exceptions/      OutOfStock
│   │   ├── Contracts/       OrderRepository, PaymentGateway   (interfaces)
│   │   └── Services/        pure domain logic (PricingPolicy)
│   ├── Application/
│   │   ├── UseCases/        PlaceOrder, CancelOrder           (one public execute())
│   │   ├── DTOs/            PlaceOrderData, OrderSummary
│   │   └── Queries/         OrderListQuery (read side, returns DTOs/paginators)
│   ├── Infrastructure/
│   │   ├── Repositories/    EloquentOrderRepository
│   │   ├── Gateways/        StripePaymentGateway
│   │   ├── Listeners/       SendOrderConfirmation
│   │   ├── Jobs/            CancelStalePendingOrders
│   │   └── Providers/       OrderServiceProvider
│   └── Presentation/
│       ├── Http/Controllers/  Http/Requests/  Http/Resources/
│       ├── Console/         Commands
│       ├── Livewire/ | Filament/   (optional UI adapters)
│       └── routes.php
└── Shared/                  Money, base exceptions, shared contracts (Clock), traits
database/migrations/         (or Order/Infrastructure/Database/migrations loaded by the provider)
database/factories/Order/    OrderFactory
tests/{Unit,Feature}/Modules/Order/
```

If `nwidart/laravel-modules` or `internachi/modular` is installed, keep the same four layer folders inside *their* module root and namespace (for example `Modules\Order\Domain\…`).

## 2. Dependency rule

```
Presentation ──► Application ──► Domain ◄── Infrastructure
                      │                         ▲
                      └──── via Contracts ──────┘ (bound in the module provider)
```

- **Domain** imports only Domain, `Shared`, Eloquent (`Illuminate\Database\Eloquent\*`), and small framework utilities (`Illuminate\Support\Collection`, `Carbon`). No HTTP, no facades except inside models' Eloquent APIs, no Infrastructure.
- **Application** imports Domain, Shared, `DB::transaction`, event dispatch and its own DTOs. No `Request`, no controllers, no concrete Infrastructure classes (depend on Contracts).
- **Infrastructure** implements Domain Contracts using Eloquent, HTTP clients, SDKs and queues.
- **Presentation** maps HTTP/CLI/UI input to DTOs, calls UseCases and maps the results to responses.
- **Cross-module:** call the other module's **Application UseCases/Queries** or its **Domain Contracts**. Never use another module's Infrastructure, and never write to another module's tables. Prefer events for loose coupling.

## 3. Class types

| Type | Layer | Naming | Notes |
|---|---|---|---|
| Model | Domain | Noun | Eloquent; relations, casts, small invariants (`canBeCancelled()`) |
| Enum / ValueObject | Domain | Noun | Immutable; VOs validate in the constructor |
| Contract | Domain | Role noun (`OrderRepository`, `PaymentGateway`) | Interface, no `Interface` suffix |
| Domain Event | Domain | Past tense | `ShouldDispatchAfterCommit` |
| Domain Exception | Domain | Descriptive | Extends `DomainException` |
| UseCase | Application | Verb + noun (`PlaceOrder`) | One public `execute()`, transaction boundary |
| DTO | Application | Noun + `Data` (input), noun + `Summary`/`View` (output) | `final readonly` or `spatie/laravel-data` |
| Query | Application | Noun + `Query` | Read side; may use Eloquent directly; returns DTOs/paginators |
| Repository impl | Infrastructure | `Eloquent` + contract name | |
| Gateway impl | Infrastructure | Vendor + contract name (`StripePaymentGateway`) | Wraps the SDK/HTTP client and maps errors to Domain exceptions |
| Listener / Job | Infrastructure | Verb phrase | Calls UseCases; `ShouldQueue` |
| ServiceProvider | Infrastructure | Module + `ServiceProvider` | Bindings, routes, migrations, events |
| Controller / Request / Resource | Presentation | Laravel standard | Thin |

## 4. Rules

- **When to add a repository contract.** Add one when (a) queries are complex or reused across UseCases, (b) you want UseCase unit tests without the DB, or (c) storage could change. Otherwise a UseCase may use the Domain model's Eloquent API directly. Be consistent within a module.
- **External services always go behind a Domain contract** with an Infrastructure gateway, so tests can swap in a fake via `$this->app->instance()` or `swap()`.
- A UseCase returns a Domain model or an output DTO. Presentation turns that into an API Resource, Inertia props or a view.
- **Read side:** list/search screens use `Application/Queries` (they may use Eloquent directly with eager loading) instead of bloating repositories.
- Each module owns its migrations. Prefix table names only if the modules share a DB and names collide.

## 5. Templates

### Domain: contract
```php
<?php

namespace App\Modules\Order\Domain\Contracts;

use App\Modules\Order\Domain\Models\Order;

interface OrderRepository
{
    public function findForUpdate(int $id): ?Order;

    public function save(Order $order): void;

    public function nextOrderNumber(): string;
}
```

```php
<?php

namespace App\Modules\Order\Domain\Contracts;

use App\Modules\Shared\Domain\Money;

interface PaymentGateway
{
    /** @return string provider charge id */
    public function charge(int $customerId, Money $amount, string $idempotencyKey): string;
}
```

### Shared: Money value object
```php
<?php

namespace App\Modules\Shared\Domain;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(public int $minor, public string $currency)
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }
    }

    public static function idr(int $minor): self
    {
        return new self($minor, 'IDR');
    }

    public function add(self $other): self
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException('Currency mismatch.');
        }

        return new self($this->minor + $other->minor, $this->currency);
    }
}
```
(Use `brick/money` or `moneyphp/money` instead if the project already has one.)

### Domain: model with an invariant
```php
<?php

namespace App\Modules\Order\Domain\Models;

use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Exceptions\OrderCannotBeCancelled;
use Database\Factories\Order\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'customer_id', 'status', 'total', 'charge_id', 'cancel_reason'])]
#[UseFactory(OrderFactory::class)]      // Laravel 13+; ≤ 12: $fillable + newFactory()
final class Order extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function cancel(string $reason): void
    {
        if (! $this->status->canTransitionTo(OrderStatus::Cancelled)) {
            throw OrderCannotBeCancelled::because($this->status);
        }
        $this->status = OrderStatus::Cancelled;
        $this->cancel_reason = $reason;
    }
}
```

### Application: DTO and UseCase
```php
<?php

namespace App\Modules\Order\Application\DTOs;

final readonly class PlaceOrderData
{
    /** @param list<array{product_id:int, quantity:int}> $lines */
    public function __construct(
        public int $customerId,
        public array $lines,
        public string $idempotencyKey,
    ) {}
}
```

```php
<?php

namespace App\Modules\Order\Application\UseCases;

use App\Modules\Catalog\Application\UseCases\ReserveStock;
use App\Modules\Order\Application\DTOs\PlaceOrderData;
use App\Modules\Order\Domain\Contracts\OrderRepository;
use App\Modules\Order\Domain\Contracts\PaymentGateway;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;

final class PlaceOrder
{
    public function __construct(
        private OrderRepository $orders,
        private PaymentGateway $payments,
        private ReserveStock $reserveStock,   // another module's UseCase, not its tables
    ) {}

    public function execute(PlaceOrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            $priced = $this->reserveStock->execute($data->lines); // returns line totals

            $order = new Order([
                'number' => $this->orders->nextOrderNumber(),
                'customer_id' => $data->customerId,
                'status' => OrderStatus::Pending,
                'total' => $priced->total,
            ]);
            $this->orders->save($order);

            $chargeId = $this->payments->charge(
                $data->customerId,
                Money::idr($priced->total),
                $data->idempotencyKey,
            );
            $order->status = OrderStatus::Paid;
            $order->charge_id = $chargeId;
            $this->orders->save($order);

            OrderPlaced::dispatch($order);

            return $order;
        });
    }
}
```
**Production note:** a remote charge inside a DB transaction can succeed while the transaction later rolls back. For real payment flows, split it: `PlaceOrder` persists the order as `Pending` and commits; a queued job (or the payment webhook) then calls a `ConfirmOrderPayment` UseCase that is idempotent on the order and charge id. The single-transaction version above is only acceptable for fast, idempotent, compensatable calls.

### Application: read-side query
```php
<?php

namespace App\Modules\Order\Application\Queries;

use App\Modules\Order\Domain\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CustomerOrdersQuery
{
    public function get(int $customerId, int $perPage = 20): LengthAwarePaginator
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->with('lines')
            ->latest()
            ->paginate($perPage);
    }
}
```

### Infrastructure: repository and gateway
```php
<?php

namespace App\Modules\Order\Infrastructure\Repositories;

use App\Modules\Order\Domain\Contracts\OrderRepository;
use App\Modules\Order\Domain\Models\Order;
use Illuminate\Support\Str;

final class EloquentOrderRepository implements OrderRepository
{
    public function findForUpdate(int $id): ?Order
    {
        return Order::whereKey($id)->lockForUpdate()->first();
    }

    public function save(Order $order): void
    {
        $order->save();
    }

    public function nextOrderNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
```

```php
<?php

namespace App\Modules\Order\Infrastructure\Gateways;

use App\Modules\Order\Domain\Contracts\PaymentGateway;
use App\Modules\Order\Domain\Exceptions\PaymentFailed;
use App\Modules\Shared\Domain\Money;
use Illuminate\Support\Facades\Http;

final class StripePaymentGateway implements PaymentGateway
{
    public function charge(int $customerId, Money $amount, string $idempotencyKey): string
    {
        $response = Http::withToken(config('services.stripe.secret'))
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount' => $amount->minor,
                'currency' => strtolower($amount->currency),
                'customer' => $customerId,
                'confirm' => 'true',
            ]);

        if ($response->failed()) {
            throw PaymentFailed::fromProvider($response->json('error.message', 'unknown'));
        }

        return $response->json('id');
    }
}
```
(If `laravel/cashier` or `stripe/stripe-php` is installed, use it inside the gateway instead of the raw HTTP client.)

### Presentation: request, controller, resource, routes
```php
<?php

namespace App\Modules\Order\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Application\DTOs\PlaceOrderData;
use App\Modules\Order\Application\UseCases\PlaceOrder;
use App\Modules\Order\Presentation\Http\Requests\PlaceOrderRequest;
use App\Modules\Order\Presentation\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

final class PlaceOrderController extends Controller
{
    public function __invoke(PlaceOrderRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $order = $placeOrder->execute(new PlaceOrderData(
            customerId: $request->user()->id,
            lines: $request->validated('lines'),
            idempotencyKey: $request->header('Idempotency-Key') ?? (string) str()->uuid(),
        ));

        return OrderResource::make($order)->response()->setStatusCode(201);
    }
}
```

```php
<?php
// app/Modules/Order/Presentation/routes.php

use App\Modules\Order\Presentation\Http\Controllers\PlaceOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])->prefix('api/orders')->name('orders.')->group(function () {
    Route::post('/', PlaceOrderController::class)->name('store');
});
```

## 6. Module provider and registration

```php
<?php

namespace App\Modules\Order\Infrastructure\Providers;

use App\Modules\Order\Domain\Contracts\OrderRepository;
use App\Modules\Order\Domain\Contracts\PaymentGateway;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Models\Order;
use App\Modules\Order\Infrastructure\Gateways\StripePaymentGateway;
use App\Modules\Order\Infrastructure\Listeners\SendOrderConfirmation;
use App\Modules\Order\Infrastructure\Repositories\EloquentOrderRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class OrderServiceProvider extends ServiceProvider
{
    public array $bindings = [
        OrderRepository::class => EloquentOrderRepository::class,
        PaymentGateway::class => StripePaymentGateway::class,
    ];

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/routes.php');
        // $this->loadMigrationsFrom(__DIR__.'/../Database/migrations'); // if the module owns its migrations

        Relation::enforceMorphMap(['order' => Order::class]); // or merge into one central morph map

        Event::listen(OrderPlaced::class, SendOrderConfirmation::class);
    }
}
```

**Keep bindings in the provider, not on the contract.** Laravel 12+/13 lets you write `#[Bind(EloquentOrderRepository::class)]` on an interface. At L3 that would make `Domain\Contracts` import an Infrastructure class and break the dependency rule (the arch test catches it). Attributes on the Infrastructure side are fine: `#[Singleton]`/`#[Scoped]` on a gateway, `#[Config('services.stripe.secret')]` in its constructor.

Register each module provider in `bootstrap/providers.php` (Laravel 11+):
```php
return [
    App\Providers\AppServiceProvider::class,
    App\Modules\Order\Infrastructure\Providers\OrderServiceProvider::class,
];
```

## 7. Arch rules (Pest)

The loop picks up new modules automatically. Place it in `tests/Arch/ModulesTest.php` (or `tests/ArchTest.php`).

```php
<?php

$modules = array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR));

foreach ($modules as $m) {
    $ns = "App\\Modules\\$m";

    arch("$m: domain has no HTTP/infrastructure deps")
        ->expect("$ns\\Domain")
        ->not->toUse(["$ns\\Application", "$ns\\Infrastructure", "$ns\\Presentation",
            'Illuminate\Http', 'Illuminate\Support\Facades\Http', 'request', 'auth', 'session']);

    arch("$m: application does not depend on infrastructure/presentation")
        ->expect("$ns\\Application")
        ->not->toUse(["$ns\\Infrastructure", "$ns\\Presentation", 'Illuminate\Http', 'request', 'auth']);

    arch("$m: contracts are interfaces")
        ->expect("$ns\\Domain\\Contracts")->toBeInterfaces();

    foreach (array_diff($modules, [$m, 'Shared']) as $other) {
        arch("$m does not use $other internals")
            ->expect($ns)
            ->not->toUse(["App\\Modules\\$other\\Infrastructure", "App\\Modules\\$other\\Presentation"]);
    }
}
```

## 8. When to move up to L4

- Domain rules are complex enough that Eloquent-coupled models make the invariants hard to see and test.
- Unit-test speed or coverage of the domain is a hard requirement (regulated, financial), or the domain must be reused outside Laravel.
- Multiple persistence models exist for the same concept (event store, read replicas, external system of record).

Migration path: `refactoring-legacy.md`, section L3 → L4.
