# Refactoring legacy code and moving up a level

Read this file when you work on an existing codebase with fat controllers or models, or when the user decides to move up a level (L1 → L2 → L3 → L4).

## Contents
1. Ground rules
2. Fat controller → L1 (before and after)
3. L1 → L2: group by domain
4. L2 → L3: split into layers, introduce contracts
5. L3 → L4: pure entities and mappers
6. Pitfalls when moving classes
7. Locking in progress

## 1. Ground rules

- **Strangler, not big bang.** Migrate one business area per PR. Old and new structures coexist, and new code goes in the new structure.
- **Tests first.** Before moving logic, write or confirm a feature test for the current behavior (HTTP-level characterization tests are fine). Refactor until it stays green.
- **Behavior-preserving moves are separate from behavior changes.** Don't fix bugs in the same commit as a move, unless the user asks.
- **Moving files without changing logic:** prefer IDE refactors or `git mv` plus namespace updates, then run `composer dump-autoload`, the tests, Pint and Larastan.
- **Under Sail**, every command runs via `./vendor/bin/sail`.
- **Agree on scope with the user** before touching more than one area. Record the target level and progress in `CLAUDE.md` (for example `Migration: Order (done), Billing (in progress) → L3`).

## 2. Fat controller → L1

**Before**
```php
public function store(Request $request)
{
    $request->validate(['lines' => 'required|array', 'lines.*.product_id' => 'exists:products,id']);

    DB::beginTransaction();
    $order = Order::create(['customer_id' => auth()->id(), 'status' => 'pending']);
    $total = 0;
    foreach ($request->lines as $line) {
        $product = Product::find($line['product_id']);
        if ($product->stock < $line['quantity']) {
            DB::rollBack();
            return back()->withErrors('Out of stock');
        }
        $product->stock -= $line['quantity'];
        $product->save();
        $order->lines()->create([...]);
        $total += $product->price * $line['quantity'];
    }
    $order->update(['total' => $total]);
    DB::commit();
    Mail::to(auth()->user())->send(new OrderConfirmation($order));

    return redirect()->route('orders.show', $order);
}
```

**Steps**
1. Move validation to `StoreOrderRequest`.
2. Create `OrderData` (customerId, lines) with `fromRequest()`. `auth()->id()` is read **here**, in the request mapping, not in the Action.
3. Create `PlaceOrderAction::execute(OrderData)`. Move the loop there, use `DB::transaction()`, `lockForUpdate()` the products, and throw an `OutOfStock` exception instead of returning an HTTP response.
4. Replace the inline mail with an `OrderPlaced` event (`ShouldDispatchAfterCommit`) and a queued `SendOrderConfirmation` listener.
5. Convert the status string to an `OrderStatus` enum cast.
6. Render `OutOfStock` as a validation-style error in `bootstrap/app.php` (or catch it in the controller if it's only used there).

**After**
```php
public function store(StoreOrderRequest $request, PlaceOrderAction $placeOrder): RedirectResponse
{
    $order = $placeOrder->execute(OrderData::fromRequest($request));

    return to_route('orders.show', $order);
}
```

**Fat models:** move multi-step methods (`$order->checkout()`, which charges, mails and updates stock) into Actions. Keep relations, casts, scopes and small invariants (`isCancellable()`) on the model. Move logic out of `boot()`/observers that causes side effects (mails, API calls) into event listeners.

**`app/Services` god classes:** split `OrderService::create/update/cancel/refund` into one Action each. A Service stays only if it's a reusable technical capability (calculator, client wrapper).

## 3. L1 → L2: group by domain

1. List business areas (Order, Catalog, Billing, Identity…) and map every model, Action, event, job and listener to exactly one area. Resolve shared ones by choosing an owner. Put truly generic pieces in `Domain/Shared`.
2. For one area at a time: `git mv app/Actions/Order app/Domain/Order/Actions`, the models to `app/Domain/Order/Models`, and so on. Then update the namespaces and imports.
3. Add `newFactory()` to the moved models and move their factories to `database/factories/Order/`.
4. Create `DomainServiceProvider` and move listener registrations and the morph map into it. Policies outside `app/Policies` need `Gate::policy()`.
5. Search the codebase for the old class names (`grep -rn 'App\\Models\\Order\b'` plus string references in config, morph maps and serialized jobs). See Pitfalls.
6. Add the L2 arch rules. Repeat for the next area.

## 4. L2 → L3: split into layers, introduce contracts

1. Create `app/Modules/{Module}/{Domain,Application,Infrastructure,Presentation}`.
2. Move `Domain/{D}/Models`, `Enums`, `Events`, `Exceptions` → `Modules/{M}/Domain/...`.
3. Move `Actions` → `Application/UseCases` (rename `PlaceOrderAction` → `PlaceOrder`, keeping `execute()`). Move `Data` → `Application/DTOs`.
4. For every external service the Actions call (HTTP, SDK, mail-as-business-step), introduce a Domain `Contracts\X` interface, move the concrete implementation to `Infrastructure/Gateways`, and bind it in a new `{Module}ServiceProvider`.
5. Move listeners and jobs to `Infrastructure/`, and controllers, requests and resources from `app/Http` to `Presentation/Http`. Move the module's routes to `Presentation/routes.php`, loaded by the provider. Remove them from `routes/web.php`/`api.php`, and check route names stay identical.
6. Introduce repository contracts **only** where the L3 rules say so (complex or reused queries, a test seam).
7. Register the provider in `bootstrap/providers.php`. Add the L3 arch rules.
8. Cross-module calls: replace direct writes to another module's models with calls to its UseCases, or with events.

## 5. L3 → L4: pure entities and mappers

Do this for the **core** modules only. Supporting modules (CMS, settings) may stay at L3. Record that exception.

1. Create `Shared/Domain/AggregateRoot` and the `Shared/Application/Ports` (`TransactionManager`, `EventPublisher`, `Clock`, `IdGenerator`) with Laravel adapters and `SharedServiceProvider`.
2. Rename the Eloquent model `Order` → `Infrastructure/Persistence/OrderModel` (set `$table`).
3. Write the pure `Domain/Entities/Order` with named constructors and behavior methods. Move the invariants out of the model and UseCases into the entity.
4. Write `OrderMapper` and `EloquentOrderRepository` (implementing a Domain `Repositories\OrderRepository` port). Add mapper round-trip tests.
5. Convert UseCases into Command and Handler pairs that use ports only. Presentation maps requests or laravel-data objects to Commands.
6. Domain events become plain objects recorded by the aggregate and published via `EventPublisher` after save.
7. IDs: if tables use auto-increment, either keep `int` IDs assigned on save (`nextIdentity()` is impossible, so the repository returns the ID after `save()`), or migrate to UUID/ULID columns. Decide with the user, since it's a data migration.
8. Update the arch rules to the L4 set and make the unit tests pure (no app boot).

## 6. Pitfalls when moving classes

| Pitfall | Fix |
|---|---|
| Polymorphic relations store class names (`commentable_type = 'App\Models\Order'`) | Use `Relation::enforceMorphMap()` with stable aliases **before** moving, plus a migration that updates existing `*_type` values |
| Queued jobs and serialized models in Redis/DB reference old class names | Drain the queues before deploying, or keep a `class_alias()` shim for one release |
| Cached and serialized data (cache, sessions, notifications `data`, `failed_jobs`) | Clear the caches. Keep aliases for notification classes stored in `notifications.type` |
| `config/auth.php` provider model (`App\Models\User`) | Update it, or leave `User` in `App\Models` (a fine pragmatic choice) |
| Policy auto-discovery expects `App\Models\X` → `App\Policies\XPolicy` | Register with `Gate::policy()` or `Gate::guessPolicyNamesUsing()` |
| Factories resolved by model name | `newFactory()` on each moved model |
| Event auto-discovery only scans `app/Listeners` | Register explicitly in providers or configure `withEvents(discover: [...])` |
| Filament/Nova resources reference model classes and paths | Update `$model` and the panel's `discoverResources()` paths |
| Broadcast channel names and `broadcastAs()` derived from class names | Set `broadcastAs()` explicitly before moving |
| Route caching and `route:list` changes | Compare `php artisan route:list --json` before and after |
| Larastan baselines | Regenerate the baseline after moves, and only for moved files |
| `ide-helper` model docs | Regenerate |

## 7. Locking in progress

- Add the arch rules of the target level as soon as the first area is migrated. Use `->ignoring([...])` for areas not migrated yet, and remove entries as you go. This becomes a visible to-do list.
- Keep a short migration log in `CLAUDE.md` so future sessions know which areas are done.
- After each area: run the full test suite, Pint, Larastan, and `route:list` diff, all through Sail if present.
