# Level 2: Domain-oriented

Code is grouped by **business domain** instead of by technical type, in the "Laravel Beyond CRUD" style. Each domain owns its models, actions, data, events and jobs. HTTP and admin stay thin and live outside the domains. Use it for growing products with several business areas and 2–5 devs.

## Folder tree

```
app/
├── Domain/
│   ├── Order/
│   │   ├── Actions/       PlaceOrderAction, CancelOrderAction
│   │   ├── Data/          OrderData
│   │   ├── Enums/         OrderStatus
│   │   ├── Events/        OrderPlaced
│   │   ├── Exceptions/    OrderCannotBeCancelled
│   │   ├── Jobs/          CancelStalePendingOrders
│   │   ├── Listeners/     ReleaseStockOnCancellation
│   │   ├── Models/        Order, OrderLine
│   │   ├── QueryBuilders/ OrderQueryBuilder (custom Eloquent builder)
│   │   └── Services/      OrderTotals (technical helpers for this domain)
│   ├── Catalog/  Customer/  Payment/ …
│   └── Shared/            cross-domain value objects, base exceptions, Money
├── Http/                  Controllers/{Domain}, Requests/{Domain}, Resources/{Domain}, Middleware
├── Filament/ | Livewire/  presentation adapters (thin)
└── Providers/DomainServiceProvider.php   listeners, policies, morph map
database/factories/{Domain}/OrderFactory.php
```

Namespace: `App\Domain\{Domain}\{Type}\ClassName`. The default `App\` PSR-4 mapping already covers it, so you don't need to change `composer.json`.

## Dependency rule

- `Http`, `Filament`, `Livewire` and `Console` → `Domain\*\Actions` (and read models for display).
- A domain may call **another domain's Actions or public Models (read-only)**. It must never write to another domain's tables directly; call that domain's Action instead.
- Domains never import `App\Http\*`, `Request`, `auth()` or `session()`.
- `Domain\Shared` depends on nothing else in `Domain`.

## Class types

These are the same as L1 (Action, Data, Service, Event, Listener, Job), plus:

| Type | Role | Naming |
|---|---|---|
| Custom QueryBuilder | Reusable query scopes per model | `{Model}QueryBuilder extends Builder` |
| Domain Exception | Business rule violation | Descriptive (`OrderCannotBeCancelled`) |
| Enum with behavior | Status plus transitions/labels | `OrderStatus` with `canTransitionTo()` |
| Shared Value Object | Small immutable concept | `Money`, `EmailAddress` |

## Rules

- Each model lives in exactly one domain. Factories live in `database/factories/{Domain}` and are wired with `#[UseFactory]` (Laravel 13+) or `newFactory()`.
- State changes go through Actions that check the enum transition: `$order->status->canTransitionTo(...)`, or `spatie/laravel-model-states`.
- Cross-domain workflows: domain A dispatches an event, and domain B's listener calls domain B's Action. Don't build an Action that writes to both domains' tables.
- Register the morph map in `DomainServiceProvider` (`Relation::enforceMorphMap`) so moving classes later doesn't break polymorphic rows.
- Policies live in `app/Policies` or `Domain/{Domain}/Policies`. Wire them with `#[UsePolicy]` on the model (Laravel 13+) or `Gate::policy()` when they aren't in the default discovery path.

## Templates

### Model with custom builder, factory and policy (Laravel 13+)
```php
<?php

namespace App\Domain\Order\Models;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Policies\OrderPolicy;
use App\Domain\Order\QueryBuilders\OrderQueryBuilder;
use Database\Factories\Order\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'status', 'total', 'cancel_reason'])]
#[UseFactory(OrderFactory::class)]
#[UsePolicy(OrderPolicy::class)]
#[UseEloquentBuilder(OrderQueryBuilder::class)]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'total' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
```
**Laravel ≤ 12:** use `protected $fillable = [...]`, `protected static function newFactory(): OrderFactory { return OrderFactory::new(); }`, `public function newEloquentBuilder($query): OrderQueryBuilder { return new OrderQueryBuilder($query); }`, and register the policy with `Gate::policy(Order::class, OrderPolicy::class)` in the provider.

### Enum with transitions
```php
<?php

namespace App\Domain\Order\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped, self::Cancelled => [],
        }, true);
    }
}
```

### Action (idempotent, locked)
```php
<?php

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Exceptions\OrderCannotBeCancelled;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;

final class CancelOrderAction
{
    public function execute(Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($order->status === OrderStatus::Cancelled) {
                return $order; // idempotent
            }
            if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw OrderCannotBeCancelled::because($order->status);
            }

            $order->update(['status' => OrderStatus::Cancelled, 'cancel_reason' => $reason]);
            OrderCancelled::dispatch($order, $reason);

            return $order;
        });
    }
}
```

### Domain exception
```php
<?php

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use DomainException;

final class OrderCannotBeCancelled extends DomainException
{
    public static function because(OrderStatus $status): self
    {
        return new self("An order in status [{$status->value}] cannot be cancelled.");
    }
}
```
Map domain exceptions to HTTP responses in `bootstrap/app.php` (`->withExceptions(fn ($e) => $e->render(fn (OrderCannotBeCancelled $ex) => response()->json(['message' => $ex->getMessage()], 409)))`). Don't use try/catch in every controller.

### Cross-domain listener
```php
<?php

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Actions\ReleaseStockAction;
use App\Domain\Order\Events\OrderCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(5)]
#[Backoff(10, 60, 300)]   // Laravel ≤ 12: public int $tries = 5; public array $backoff = [10, 60, 300];
final class ReleaseStockOnCancellation implements ShouldQueue
{
    public function __construct(private ReleaseStockAction $releaseStock) {}

    public function handle(OrderCancelled $event): void
    {
        $this->releaseStock->execute($event->order->lines);
    }
}
```

### DomainServiceProvider
```php
<?php

namespace App\Providers;

use App\Domain\Catalog\Listeners\ReleaseStockOnCancellation;
use App\Domain\Order\Events\OrderCancelled;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'order' => Order::class,
        ]);

        Event::listen(OrderCancelled::class, ReleaseStockOnCancellation::class);
    }
}
```
Register it in `bootstrap/providers.php`. Laravel's event auto-discovery only scans `app/Listeners` by default, so register domain listeners explicitly as above, or add the domain paths with `->withEvents(discover: [app_path('Domain/*/Listeners')])` in `bootstrap/app.php`.

### Thin controller
```php
<?php

namespace App\Http\Controllers\Order;

use App\Domain\Order\Actions\CancelOrderAction;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Http\Resources\Order\OrderResource;

final class CancelOrderController extends Controller
{
    public function __invoke(CancelOrderRequest $request, Order $order, CancelOrderAction $cancel): OrderResource
    {
        return OrderResource::make($cancel->execute($order, $request->validated('reason')));
    }
}
```

## Arch rules (Pest)
```php
arch('domains are HTTP-free')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request', 'request', 'auth', 'session']);

arch('shared kernel is independent')
    ->expect('App\Domain\Shared')
    ->not->toUse(['App\Domain\Order', 'App\Domain\Catalog' /* add each domain */]);

// Per-domain naming rules (loop over domain folders so new domains are covered automatically)
foreach (glob(dirname(__DIR__, 2).'/app/Domain/*', GLOB_ONLYDIR) as $dir) {
    $domain = basename($dir);
    if (is_dir("$dir/Actions")) {
        arch("$domain actions are named *Action")
            ->expect("App\\Domain\\$domain\\Actions")->toHaveSuffix('Action')->toBeFinal();
    }
}
```

## When to move up to L3

- You have 3 or more external integrations that need test seams (payment, shipping, messaging, search).
- You add multiple delivery channels (public API, partner API, CLI, Filament) and controllers start duplicating mapping logic.
- Domains grow large enough that you want to separate the "what" (Application) from the "how" (Infrastructure).

Migration path: `refactoring-legacy.md`, section L2 → L3.
