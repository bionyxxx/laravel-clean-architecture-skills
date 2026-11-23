# Level 4: Strict Clean / Hexagonal

The **Domain and Application layers are framework-free PHP**. Laravel, Eloquent, queues and HTTP are adapters at the edge. Entities hold the business rules, and Application handlers orchestrate them through **ports** (interfaces). Infrastructure **adapters** implement those ports, and Eloquent models are mapped to and from entities. Use it for complex, long-lived domains, large teams, and compliance or test-heavy contexts. It costs roughly twice the files per feature compared with L3.

## Contents
1. Folder tree
2. Dependency rule
3. Class types
4. Rules
5. Templates (Domain → Application → Infrastructure → Presentation)
6. Provider and shared kernel
7. Arch rules
8. Notes on CQRS and event sourcing

## 1. Folder tree

```
app/Modules/
├── Order/
│   ├── Domain/                      ← pure PHP, no Illuminate\*
│   │   ├── Entities/                Order (aggregate root), OrderLine
│   │   ├── ValueObjects/            OrderId, CustomerId, Quantity
│   │   ├── Enums/                   OrderStatus
│   │   ├── Events/                  OrderPlaced (plain objects)
│   │   ├── Exceptions/              OrderCannotBeCancelled
│   │   ├── Repositories/            OrderRepository (port)
│   │   └── Services/                PricingPolicy (pure domain service)
│   ├── Application/                 ← pure PHP, depends on Domain + Shared ports
│   │   ├── Commands/PlaceOrder/     PlaceOrderCommand, PlaceOrderHandler
│   │   ├── Queries/GetOrder/        GetOrderQuery, GetOrderHandler, OrderView
│   │   └── Ports/                   PaymentGateway, ProductCatalog (outbound ports)
│   ├── Infrastructure/              ← Laravel lives here
│   │   ├── Persistence/             OrderModel (Eloquent), EloquentOrderRepository, OrderMapper
│   │   ├── Adapters/                StripePaymentGateway, CatalogModuleProductCatalog
│   │   ├── Listeners/  Jobs/
│   │   └── Providers/               OrderServiceProvider
│   └── Presentation/
│       ├── Http/Controllers/  Http/Requests/  Http/Resources/
│       └── routes.php
└── Shared/
    ├── Domain/                      Money, AggregateRoot (event recording), DomainEvent
    ├── Application/Ports/           TransactionManager, EventPublisher, Clock, IdGenerator
    └── Infrastructure/              LaravelTransactionManager, LaravelEventPublisher, SystemClock, UuidGenerator, SharedServiceProvider
```

Optional variant: put the pure core in `src/` with its own PSR-4 namespace (for example `"Core\\": "src/"`) and keep only adapters under `app/`. This makes framework independence physically visible. Use it if the team wants that hard boundary.

## 2. Dependency rule

```
Presentation ─► Application ─► Domain
      │              │ ports      ▲
      ▼              ▼            │
  Infrastructure (adapters implement ports, map Eloquent ⇄ entities)
```

- **Domain:** only PHP, Domain and `Shared\Domain`. No `Illuminate\*`, no `Carbon` (use `DateTimeImmutable`), no `Str`, no facades, no helpers (`now()`, `collect()`, `config()`).
- **Application:** Domain, Shared ports, own commands/queries/views. No `Illuminate\*`. It gets transactions, events, time and IDs through ports.
- **Infrastructure:** anything Laravel. It implements ports and contains the Eloquent models (`*Model`), which never leave this layer.
- **Presentation:** HTTP/CLI/UI. It builds Commands from requests, calls Handlers and renders Views.
- **Cross-module:** through an Application port in the calling module, implemented by an adapter that calls the other module's handler (an anti-corruption layer).

## 3. Class types

| Type | Layer | Naming |
|---|---|---|
| Aggregate root / Entity | Domain | Noun (`Order`); private constructor, named constructors (`place()`), behavior methods |
| Value Object | Domain | Noun (`OrderId`, `Money`); `final readonly`, validated, `equals()` |
| Domain Event | Domain | Past tense, plain `final readonly` class |
| Repository port | Domain | `OrderRepository` (`get`, `save`, `nextIdentity`) |
| Command / Query | Application | Imperative (`PlaceOrderCommand`) / `Get…Query`; primitive fields only |
| Handler | Application | `PlaceOrderHandler` with `handle(Command)` (or `__invoke`) |
| View (output DTO) | Application | `OrderView`; primitives/arrays only |
| Outbound port | Application/Ports | Role noun (`PaymentGateway`, `Clock`) |
| Eloquent model | Infrastructure/Persistence | `OrderModel` (`$table = 'orders'`) |
| Mapper | Infrastructure/Persistence | `OrderMapper` (`toEntity`, `toPersistence`) |
| Adapter | Infrastructure/Adapters | Vendor + port (`StripePaymentGateway`) |

## 4. Rules

- Entities protect their invariants. There are no public setters and state changes only through methods (`$order->cancel($reason)`). Rebuild them from persistence with `Order::reconstitute(...)`, which doesn't record events.
- Aggregates record domain events (`$this->record(new OrderPlaced(...))`). Handlers publish them *after* saving through `EventPublisher` (the Laravel adapter dispatches them after commit).
- One aggregate per transaction. Coordinate across aggregates with events and eventual consistency.
- Handlers receive Commands built from primitives. They never receive Requests, Eloquent models or Laravel `Data` objects.
- IDs come from the repository (`nextIdentity()`) or the `IdGenerator` port, never from auto-increment, so entities have identity before they're persisted.
- Laravel-data, FormRequests and API Resources stay in Presentation. Eloquent, Scout, Cashier and medialibrary stay in Infrastructure.

## 5. Templates

### Shared kernel: aggregate root and ports
```php
<?php

namespace App\Modules\Shared\Domain;

abstract class AggregateRoot
{
    /** @var list<object> */
    private array $events = [];

    protected function record(object $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<object> */
    public function releaseEvents(): array
    {
        [$events, $this->events] = [$this->events, []];

        return $events;
    }
}
```

```php
<?php

namespace App\Modules\Shared\Application\Ports;

interface TransactionManager
{
    /** @template T @param callable(): T $work @return T */
    public function run(callable $work): mixed;
}
```

```php
<?php

namespace App\Modules\Shared\Application\Ports;

interface EventPublisher
{
    public function publish(object ...$events): void;
}
```

### Domain: value object and entity
```php
<?php

namespace App\Modules\Order\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class OrderId
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $value)) {
            throw new InvalidArgumentException("Invalid order id [$value].");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

```php
<?php

namespace App\Modules\Order\Domain\Entities;

use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\Events\OrderCancelled;
use App\Modules\Order\Domain\Events\OrderPlaced;
use App\Modules\Order\Domain\Exceptions\OrderCannotBeCancelled;
use App\Modules\Order\Domain\ValueObjects\OrderId;
use App\Modules\Shared\Domain\AggregateRoot;
use App\Modules\Shared\Domain\Money;
use DateTimeImmutable;

final class Order extends AggregateRoot
{
    /** @param list<OrderLine> $lines */
    private function __construct(
        private OrderId $id,
        private int $customerId,
        private array $lines,
        private OrderStatus $status,
        private DateTimeImmutable $placedAt,
    ) {}

    /** @param list<OrderLine> $lines */
    public static function place(OrderId $id, int $customerId, array $lines, DateTimeImmutable $now): self
    {
        if ($lines === []) {
            throw new \DomainException('An order needs at least one line.');
        }
        $order = new self($id, $customerId, $lines, OrderStatus::Pending, $now);
        $order->record(new OrderPlaced($id->value, $customerId, $order->total()->minor));

        return $order;
    }

    /** @param list<OrderLine> $lines */
    public static function reconstitute(OrderId $id, int $customerId, array $lines, OrderStatus $status, DateTimeImmutable $placedAt): self
    {
        return new self($id, $customerId, $lines, $status, $placedAt);
    }

    public function cancel(string $reason): void
    {
        if ($this->status === OrderStatus::Cancelled) {
            return; // idempotent
        }
        if (! $this->status->canTransitionTo(OrderStatus::Cancelled)) {
            throw OrderCannotBeCancelled::because($this->status);
        }
        $this->status = OrderStatus::Cancelled;
        $this->record(new OrderCancelled($this->id->value, $reason));
    }

    public function total(): Money
    {
        return array_reduce(
            $this->lines,
            fn (Money $sum, OrderLine $line) => $sum->add($line->subtotal()),
            Money::idr(0),
        );
    }

    public function id(): OrderId { return $this->id; }
    public function customerId(): int { return $this->customerId; }
    /** @return list<OrderLine> */
    public function lines(): array { return $this->lines; }
    public function status(): OrderStatus { return $this->status; }
    public function placedAt(): DateTimeImmutable { return $this->placedAt; }
}
```
`OrderLine` is a `final readonly class` with `productId`, `quantity` and `unitPrice: Money`, plus `subtotal(): Money`.

### Domain: repository port
```php
<?php

namespace App\Modules\Order\Domain\Repositories;

use App\Modules\Order\Domain\Entities\Order;
use App\Modules\Order\Domain\ValueObjects\OrderId;

interface OrderRepository
{
    public function nextIdentity(): OrderId;

    /** @throws \App\Modules\Order\Domain\Exceptions\OrderNotFound */
    public function get(OrderId $id): Order;

    public function save(Order $order): void;
}
```

### Application: command and handler
```php
<?php

namespace App\Modules\Order\Application\Commands\PlaceOrder;

final readonly class PlaceOrderCommand
{
    /** @param list<array{productId:int, quantity:int}> $lines */
    public function __construct(public int $customerId, public array $lines) {}
}
```

```php
<?php

namespace App\Modules\Order\Application\Commands\PlaceOrder;

use App\Modules\Order\Application\Ports\ProductCatalog;
use App\Modules\Order\Domain\Entities\Order;
use App\Modules\Order\Domain\Entities\OrderLine;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use App\Modules\Shared\Application\Ports\Clock;
use App\Modules\Shared\Application\Ports\EventPublisher;
use App\Modules\Shared\Application\Ports\TransactionManager;

final class PlaceOrderHandler
{
    public function __construct(
        private OrderRepository $orders,
        private ProductCatalog $catalog,
        private TransactionManager $tx,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    public function handle(PlaceOrderCommand $command): string
    {
        return $this->tx->run(function () use ($command) {
            $lines = array_map(
                fn (array $l) => new OrderLine($l['productId'], $l['quantity'], $this->catalog->priceOf($l['productId'])),
                $command->lines,
            );

            $order = Order::place($this->orders->nextIdentity(), $command->customerId, $lines, $this->clock->now());
            $this->orders->save($order);
            $this->events->publish(...$order->releaseEvents());

            return $order->id()->value;
        });
    }
}
```

### Infrastructure: Eloquent model, mapper, repository
```php
<?php

namespace App\Modules\Order\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrderModel extends Model
{
    use HasUuids;

    protected $table = 'orders';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['placed_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLineModel::class, 'order_id');
    }
}
```

```php
<?php

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Order\Domain\Entities\Order;
use App\Modules\Order\Domain\Entities\OrderLine;
use App\Modules\Order\Domain\Enums\OrderStatus;
use App\Modules\Order\Domain\ValueObjects\OrderId;
use App\Modules\Shared\Domain\Money;

final class OrderMapper
{
    public function toEntity(OrderModel $m): Order
    {
        return Order::reconstitute(
            new OrderId($m->id),
            (int) $m->customer_id,
            $m->lines->map(fn ($l) => new OrderLine((int) $l->product_id, (int) $l->quantity, Money::idr((int) $l->unit_price)))->all(),
            OrderStatus::from($m->status),
            $m->placed_at,
        );
    }

    /** @return array{order: array<string, mixed>, lines: list<array<string, mixed>>} */
    public function toPersistence(Order $order): array
    {
        return [
            'order' => [
                'id' => $order->id()->value,
                'customer_id' => $order->customerId(),
                'status' => $order->status()->value,
                'total' => $order->total()->minor,
                'placed_at' => $order->placedAt(),
            ],
            'lines' => array_map(fn (OrderLine $l) => [
                'product_id' => $l->productId,
                'quantity' => $l->quantity,
                'unit_price' => $l->unitPrice->minor,
            ], $order->lines()),
        ];
    }
}
```

```php
<?php

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Order\Domain\Entities\Order;
use App\Modules\Order\Domain\Exceptions\OrderNotFound;
use App\Modules\Order\Domain\Repositories\OrderRepository;
use App\Modules\Order\Domain\ValueObjects\OrderId;
use Illuminate\Support\Str;

final class EloquentOrderRepository implements OrderRepository
{
    public function __construct(private OrderMapper $mapper) {}

    public function nextIdentity(): OrderId
    {
        return new OrderId((string) Str::orderedUuid());
    }

    public function get(OrderId $id): Order
    {
        $model = OrderModel::with('lines')->lockForUpdate()->find($id->value)
            ?? throw OrderNotFound::withId($id);

        return $this->mapper->toEntity($model);
    }

    public function save(Order $order): void
    {
        $data = $this->mapper->toPersistence($order);
        OrderModel::updateOrCreate(['id' => $data['order']['id']], $data['order']);
        OrderLineModel::where('order_id', $data['order']['id'])->delete();
        OrderLineModel::insert(array_map(
            fn (array $line) => $line + ['order_id' => $data['order']['id']],
            $data['lines'],
        ));
    }
}
```

### Infrastructure: Laravel adapters for the shared ports
```php
<?php

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Application\Ports\EventPublisher;
use App\Modules\Shared\Application\Ports\TransactionManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

final class LaravelTransactionManager implements TransactionManager
{
    public function __construct(private ConnectionInterface $db) {}

    public function run(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}

final class LaravelEventPublisher implements EventPublisher
{
    public function __construct(private Dispatcher $events, private ConnectionInterface $db) {}

    public function publish(object ...$events): void
    {
        // Domain events are plain objects, so defer dispatch until the transaction commits.
        $this->db->afterCommit(function () use ($events) {
            foreach ($events as $event) {
                $this->events->dispatch($event);
            }
        });
    }
}
```
(Each class goes in its own file.) If no transaction is open, `afterCommit` runs the callback immediately.

### Presentation: controller
```php
<?php

namespace App\Modules\Order\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Order\Application\Commands\PlaceOrder\PlaceOrderCommand;
use App\Modules\Order\Application\Commands\PlaceOrder\PlaceOrderHandler;
use App\Modules\Order\Presentation\Http\Requests\PlaceOrderRequest;
use Illuminate\Http\JsonResponse;

final class PlaceOrderController extends Controller
{
    public function __invoke(PlaceOrderRequest $request, PlaceOrderHandler $handler): JsonResponse
    {
        $id = $handler->handle(new PlaceOrderCommand(
            customerId: $request->user()->id,
            lines: array_map(
                fn (array $l) => ['productId' => (int) $l['product_id'], 'quantity' => (int) $l['quantity']],
                $request->validated('lines'),
            ),
        ));

        return response()->json(['id' => $id], 201);
    }
}
```

## 6. Provider and shared kernel

```php
<?php

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Application\Ports\Clock;
use App\Modules\Shared\Application\Ports\EventPublisher;
use App\Modules\Shared\Application\Ports\TransactionManager;
use Illuminate\Support\ServiceProvider;

final class SharedServiceProvider extends ServiceProvider
{
    public array $bindings = [
        TransactionManager::class => LaravelTransactionManager::class,
        EventPublisher::class => LaravelEventPublisher::class,
        Clock::class => SystemClock::class,
    ];
}
```

The module provider binds `OrderRepository => EloquentOrderRepository`, its outbound ports to adapters, and loads routes and listeners (same shape as L3). Register `SharedServiceProvider` and each module provider in `bootstrap/providers.php`.

## 7. Arch rules (Pest)

```php
<?php

$modules = array_values(array_diff(
    array_map('basename', glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR)),
    ['Shared'],
));
$framework = ['Illuminate', 'Carbon', 'now', 'config', 'collect', 'dispatch', 'request', 'auth', 'session'];

arch('shared domain and application are framework-free')
    ->expect(['App\Modules\Shared\Domain', 'App\Modules\Shared\Application'])
    ->not->toUse($framework);

foreach ($modules as $m) {
    $ns = "App\\Modules\\$m";

    arch("$m: domain is pure PHP")
        ->expect("$ns\\Domain")
        ->not->toUse([...$framework, "$ns\\Application", "$ns\\Infrastructure", "$ns\\Presentation"]);

    arch("$m: application is framework-free")
        ->expect("$ns\\Application")
        ->not->toUse([...$framework, "$ns\\Infrastructure", "$ns\\Presentation"]);
}

arch('eloquent stays in infrastructure')
    ->expect('Illuminate\Database\Eloquent')
    ->toOnlyBeUsedIn([
        ...array_map(fn ($m) => "App\\Modules\\$m\\Infrastructure", [...$modules, 'Shared']),
        'App\Models',   // e.g. User, if still in the default location
        'Database',      // factories and seeders
    ]);
```
A Deptrac config (see `testing.md`) is a good complement for large codebases.

## 8. CQRS and event sourcing

- **Queries** can bypass the domain completely: a `GetOrderHandler` adapter may read with Eloquent or the query builder and return `OrderView`. Define the read port in Application and implement it in Infrastructure.
- **Command bus:** optional. If many cross-cutting concerns are needed (logging, retries, authorization per command), add a small bus port. Otherwise inject handlers directly.
- **Event sourcing:** with `spatie/laravel-event-sourcing` or EventSauce, aggregates extend the library's root in an Infrastructure-adjacent layer, or you wrap them. Decide explicitly and record the decision in `CLAUDE.md`, because it bends the "Domain is pure" rule.
