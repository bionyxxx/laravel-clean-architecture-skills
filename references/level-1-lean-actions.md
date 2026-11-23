# Level 1: Lean Actions

This is the default Laravel layout with business logic pulled out of controllers into single-purpose **Action** classes and typed **DTOs**. Use it for MVPs, CRUD apps, internal tools and small teams.

## Folder tree

```
app/
├── Actions/{Area}/        PlaceOrderAction, CancelOrderAction   (one business operation each)
├── Data/                  OrderData, OrderLineData              (DTOs)
├── Enums/                 OrderStatus
├── Events/  Listeners/  Jobs/
├── Http/
│   ├── Controllers/       thin: validate → Action → response
│   ├── Requests/{Area}/   StoreOrderRequest
│   └── Resources/         OrderResource (API)
├── Models/                Eloquent models (relations, casts, scopes)
├── Policies/
└── Services/              reusable technical helpers only (PriceCalculator, StripeClient wrapper)
```

`app/Actions/Fortify` and `app/Actions/Jetstream`, if present, belong to those packages. Leave them alone and put your own actions in other subfolders.

## Dependency rule

`Http` → `Actions` → (`Models`, `Services`, `Data`, `Events`). Actions never use `Request`, `auth()` or `session()`. Models never call Actions.

## Class types

| Type | Role | Naming | Location |
|---|---|---|---|
| Action | One business operation, transaction, event dispatch | Verb + noun + `Action` | `app/Actions/{Area}` |
| DTO | Typed input/output | Noun + `Data` | `app/Data` |
| FormRequest | Validation and authorization | `Store`/`Update` + noun + `Request` | `app/Http/Requests/{Area}` |
| Service | Reusable technical capability, no workflow ownership | Noun (`PriceCalculator`) | `app/Services` |
| Event | Something happened | Past tense (`OrderPlaced`) | `app/Events` |
| Listener | Side effect | Verb phrase (`SendOrderConfirmation`) | `app/Listeners` |
| Job | Deferred or scheduled work that calls an Action | Verb phrase | `app/Jobs` |

## Rules

- Each Action has one public `execute()` method and gets its dependencies through the constructor (resolve it with DI or `app(PlaceOrderAction::class)`).
- Actions may call other Actions. Watch for deep chains (more than 2 levels): that's a signal to move up to L2.
- Eloquent use inside Actions is fine. Put complex reusable queries in model scopes or a dedicated `Queries/` class.
- Controllers stay under about 15 lines per method.
- **Laravel 13+:** configure models, jobs, commands and controllers with attributes (`#[Fillable]`, `#[Tries]`, `#[Signature]`, `#[Middleware]`, …), and use properties on ≤ 12. At L1 it's fine to put `#[Bind(StripeClient::class)]` on a service interface. See `ecosystem.md` §9.

## Templates

### DTO (plain PHP)
```php
<?php

namespace App\Data;

use App\Http\Requests\Order\StoreOrderRequest;

final readonly class OrderData
{
    /** @param list<OrderLineData> $lines */
    public function __construct(
        public int $customerId,
        public array $lines,
        public ?string $note = null,
    ) {}

    public static function fromRequest(StoreOrderRequest $request): self
    {
        return new self(
            customerId: $request->user()->id,
            lines: array_map(
                fn (array $line) => new OrderLineData($line['product_id'], (int) $line['quantity']),
                $request->validated('lines'),
            ),
            note: $request->validated('note'),
        );
    }
}
```
`OrderLineData` is a two-field `final readonly class` (`productId`, `quantity`).

With `spatie/laravel-data` installed, extend `Spatie\LaravelData\Data` instead. See `ecosystem.md`.

### Action
```php
<?php

namespace App\Actions\Order;

use App\Data\OrderData;
use App\Enums\OrderStatus;
use App\Events\OrderPlaced;
use App\Models\Order;
use App\Models\Product;
use App\Services\PriceCalculator;
use Illuminate\Support\Facades\DB;

final class PlaceOrderAction
{
    public function __construct(private PriceCalculator $prices) {}

    public function execute(OrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            $products = Product::whereKey(array_map(fn ($l) => $l->productId, $data->lines))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $order = Order::create([
                'customer_id' => $data->customerId,
                'status' => OrderStatus::Pending,
                'note' => $data->note,
                'total' => $this->prices->total($products, $data->lines),
            ]);

            foreach ($data->lines as $line) {
                $products[$line->productId]->decrement('stock', $line->quantity);
                $order->lines()->create([
                    'product_id' => $line->productId,
                    'quantity' => $line->quantity,
                    'unit_price' => $products[$line->productId]->price,
                ]);
            }

            OrderPlaced::dispatch($order); // implements ShouldDispatchAfterCommit

            return $order;
        });
    }
}
```

### FormRequest
```php
<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

### Controller (API, or redirect for Blade/Inertia)
```php
<?php

namespace App\Http\Controllers;

use App\Actions\Order\PlaceOrderAction;
use App\Data\OrderData;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Resources\OrderResource;

final class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, PlaceOrderAction $placeOrder): OrderResource
    {
        $order = $placeOrder->execute(OrderData::fromRequest($request));

        return OrderResource::make($order);
        // Blade/Inertia: return to_route('orders.show', $order)->with('status', 'Order placed');
    }
}
```

### Event
```php
<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderPlaced implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
```

### Job calling the Action
```php
final class CancelStalePendingOrders implements ShouldQueue
{
    use Queueable;

    public function handle(CancelOrderAction $cancel): void
    {
        Order::where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subDay())
            ->each(fn (Order $order) => $cancel->execute($order, reason: 'stale'));
    }
}
// routes/console.php: Schedule::job(new CancelStalePendingOrders)->hourly()->withoutOverlapping();
```

## Arch rules (Pest)
```php
arch('actions do not touch the HTTP layer')
    ->expect('App\Actions')
    ->not->toUse(['Illuminate\Http\Request', 'request', 'auth', 'session']);

arch('controllers do not run queries directly')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('dtos are immutable')->expect('App\Data')->toBeReadonly();
// With spatie/laravel-data, drop the readonly rule. Data classes are not readonly.
```

## When to move up to L2

- More than about 15 models, or `app/Actions` has more than about 5 subfolders that map to business areas.
- Changes routinely touch files in 5+ top-level folders for one business area.
- Several people work on different business areas and keep conflicting.

Migration path: `refactoring-legacy.md`, section L1 → L2.
