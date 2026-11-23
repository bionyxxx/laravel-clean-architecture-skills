# Laravel ecosystem: detection and placement

Read this file when a project uses (or is about to use) a package listed here, or before you hand-roll something a package might already provide. Always check `composer.json` first. **Use what's installed and ask before adding anything.**

## Contents
1. Detection cheat-sheet
2. Development environment (Sail, Herd, Valet, Docker)
3. Spatie packages
4. First-party Laravel packages
5. UI stacks (Livewire, Volt, Inertia, Filament, Nova)
6. Structure-overlapping packages (laravel-actions, laravel-modules, modular)
7. Quality tooling (Pint, Larastan, Rector, Pest)
8. Other common packages
9. Laravel 13 PHP attributes: what to use in which layer
10. Laravel Boost and the generated CLAUDE.md/AGENTS.md

## 1. Detection cheat-sheet

```bash
# installed packages (prod + dev)
jq -r '(.require + ."require-dev") | keys[]' composer.json
# or without jq:
grep -E '"(laravel|spatie|livewire|filament|inertiajs|lorisleiva|nwidart|internachi|pestphp|larastan|nunomaduro)/' composer.json
```
`scripts/analyze-project.php` prints all of this in one go.

## 2. Development environment

### Laravel Sail (`laravel/sail` + `compose.yaml`/`docker-compose.yml`)
- **Run every command through Sail:** `./vendor/bin/sail artisan …`, `sail composer …`, `sail php …`, `sail npm …`, `sail test`/`sail pest`, `sail pint`, `sail tinker`. Running `php artisan` on the host uses the wrong PHP version and extensions and can't reach the DB.
- The `sail` alias (`alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'`) is fine in docs. In tool calls, use `./vendor/bin/sail` so it works without the alias.
- `.env` uses service hostnames: `DB_HOST=mysql` (or `pgsql`/`mariadb`), `REDIS_HOST=redis`, `MAIL_HOST=mailpit`, `MEILISEARCH_HOST=http://meilisearch:7700`, MinIO endpoint `http://minio:9000` for S3 disks (`AWS_USE_PATH_STYLE_ENDPOINT=true`).
- Add services with `./vendor/bin/sail artisan sail:add redis,meilisearch` or by editing the compose file. Don't ask the user to install them on the host.
- Running skill scripts: Sail mounts only the project directory, so `./vendor/bin/sail php ~/.claude/skills/...` fails inside the container. Either run the skill script with host PHP (if available), or copy it into the project (for example `cp <skill>/scripts/analyze-project.php ./analyze-project.php && ./vendor/bin/sail php analyze-project.php . && rm analyze-project.php`).
- Tests run against the `testing` DB that Sail creates. Check `phpunit.xml` for `DB_DATABASE=testing`.

### Herd / Valet / plain Docker
- **Herd/Valet** (no Sail, a `*.test` domain): use host `php artisan` and `composer`.
- **Custom Docker** (compose without Sail): find the PHP service name and use `docker compose exec <service> php artisan …`.
- **Laravel Octane** (`laravel/octane`): see section 4.

## 3. Spatie packages

### `spatie/laravel-data`: DTOs
- **L1/L2/L3:** DTOs extend `Spatie\LaravelData\Data`. They can replace FormRequests: when injected into a controller method, the Data object is validated (rules inferred from types and attributes such as `#[Max(255)]`, `#[Exists('products','id')]`).
- **L4:** Data objects stay in **Presentation** only. Map them to plain `final readonly` Commands, because Application must be framework-free.
- Data classes are **not** `readonly` classes. Don't apply a `toBeReadonly()` arch rule to them.
```php
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

final class PlaceOrderData extends Data
{
    public function __construct(
        public int $customerId,
        /** @var array<int, OrderLineData> */
        public array $lines,
        #[Max(500)]
        public ?string $note = null,
    ) {}
}

// controller: the payload is validated and mapped automatically
public function store(PlaceOrderData $data, PlaceOrderAction $action) { … }
// elsewhere: PlaceOrderData::from($array) / ::validateAndCreate($array)
```
Use `#[MapInputName(SnakeCaseMapper::class)]` (or the global config) when request keys are snake_case. It can also act as an API resource (`$data->toArray()`, `Data::collect()`).

### `spatie/laravel-permission`: roles and permissions
- Check permissions at the **edge**: route middleware (`role:admin`, `permission:orders.cancel`), policies (`$user->can('orders.cancel')`), and Filament `canAccess()`.
- If a *business rule* depends on permissions (for example "a manager can approve above 10M"), give the UseCase an actor DTO with the resolved permissions, or a Domain/Application contract `Authorizer` implemented in Infrastructure with laravel-permission (L3/L4). Don't call `$user->hasRole()` deep inside domain code.
- Permission names: `resource.action` (`orders.cancel`). Seed them in a seeder, and cache-reset after seeding (`app(PermissionRegistrar::class)->forgetCachedPermissions()`).

### `spatie/laravel-query-builder`: filter/sort/include from the query string
- It reads the HTTP query string, so it's **Presentation-bound**:
  - L1/L2: a `Queries/` class (or the controller's index) may use it directly.
  - L3/L4: put it in `Presentation/Http/Queries/OrderIndexQuery`, or pass the parsed filters as a DTO into an Application Query.
```php
QueryBuilder::for(Order::class, $request)
    ->allowedFilters(['status', AllowedFilter::exact('customer_id')])
    ->allowedSorts(['created_at', 'total'])
    ->allowedIncludes(['lines'])
    ->paginate();
```

### `spatie/laravel-model-states`: state machines
- L1–L3: state classes live next to the model (`Domain/Models/States` at L3, `Domain/{D}/States` at L2). Transitions with side effects become `Transition` classes, but orchestration (events, other aggregates) stays in the Action/UseCase.
- L4: model states in the pure entity with an enum (as in the L4 template). The package may still be used on the Infrastructure `*Model` for persistence casting, but the entity owns the rules.

### `spatie/laravel-medialibrary`, `-activitylog`, `-sluggable`, `-translatable`, `-tags`
- Traits on Eloquent models: Domain models at L1–L3, Infrastructure `*Model` at L4.
- Uploads: the controller/Livewire component receives the file and passes an `UploadedFile` (L1–L3) or a path/stream DTO (L4) to the Action. The Action calls `addMedia()` (L1–L3) or a `MediaStorage` port (L4).
- Activity log: log in Actions/UseCases (`activity()->performedOn($order)->causedBy($actorId)->log('cancelled')`) or in a listener. At L4 use an `AuditLog` port.

### `spatie/laravel-settings`
Settings classes are config-like. Read them in Actions at L1/L2. At L3/L4, expose the values through a contract (`OrderSettings` interface) implemented by the settings class.

### `spatie/laravel-event-sourcing`
It's an architectural decision. Aggregates (`AggregateRoot`), projectors and reactors replace parts of the Action/UseCase layer. At L3 put aggregates in `Domain/Aggregates`, projectors and reactors in `Infrastructure`. At L4 it bends the "pure Domain" rule, so record that in `CLAUDE.md`.

### `spatie/laravel-backup`, `-health`, `-csp`, `-honeypot`, `-responsecache`
These are infra and ops only. Configure them in `config/` and schedule them in `routes/console.php`. They never touch business code.

## 4. First-party Laravel packages

| Package | Placement and rules |
|---|---|
| **Boost** (`laravel/boost`) | Dev-time MCP server and AI guidelines. Use its doc search for version-correct APIs. Keep your own notes outside its `CLAUDE.md`/`AGENTS.md` block (section 10). |
| **Sanctum / Passport** | Auth guards and middleware at the edge (`auth:sanctum`). Business code receives `$request->user()->id` or an actor DTO, never the guard. |
| **Fortify / Breeze / Jetstream** | They generate `app/Actions/Fortify` and `app/Actions/Jetstream`. Leave those as they are, since the packages own them. Your business Actions go elsewhere. Customise auth only through those action classes. |
| **Horizon / queues** | Jobs and queued listeners: `app/Jobs` (L1), `Domain/*/Jobs` (L2), `Infrastructure/Jobs` (L3/L4). Jobs call Actions/UseCases. Set `$tries`, `$backoff`, `$timeout`, and `ShouldBeUnique` where duplicates hurt. Name queues by priority (`high`, `default`, `low`) in `config/horizon.php`. |
| **Octane** | No per-request state in singletons, static properties or long-lived services. Inject `Request` only into controllers. Use `scoped()` bindings for request-scoped services. Avoid `app()->instance()` per request. |
| **Scout** | L1/L2: the `Searchable` trait on the model and `Model::search()` in Queries. L3/L4: a `ProductSearch` contract, with a Scout-backed adapter in Infrastructure. |
| **Cashier (Stripe/Paddle)** | L1/L2: the `Billable` trait on `User`; call Cashier inside Actions. L3/L4: a `Billing`/`PaymentGateway` contract with a Cashier adapter. Webhooks are handled by Cashier's controller plus listeners that call UseCases. |
| **Socialite** | Presentation: the OAuth controller calls a `LoginWithProvider` Action/UseCase with the provider user mapped to a DTO. |
| **Reverb / broadcasting** | Broadcast events are Presentation/Infrastructure concerns. Domain events are separate; a listener maps the domain event to a `ShouldBroadcast` event. Channel authorization goes in `routes/channels.php`. |
| **Pennant** | L1/L2: `Feature::active('new-checkout')` at the edge or in Actions. L3/L4: a `FeatureFlags` contract. Prefer deciding at the edge and passing a flag. |
| **Telescope / Pulse / Pail** | Dev and ops only; never referenced from business code. |
| **Laravel Prompts** | Console commands (Presentation). |
| **Precognition** | Works with FormRequests. Keep validation in FormRequests/Data so Precognition can reuse it. |
| **Folio / Volt** | Presentation (page and component files). Volt components call Actions. No queries with business rules inline. |

## 5. UI stacks

All of these are **Presentation adapters**. The same Action/UseCase must serve all of them.

- **Livewire / Volt:** component properties form the input. On submit, validate (`$this->validate()` or a Form object), build the DTO, call the Action, then update the UI. No `Model::create()` with business rules inside components. Livewire Form objects (`Livewire\Form`) live next to components and map to DTOs. At L3/L4 put components in `Presentation/Livewire` and register them in the module provider (`Livewire::component('order.checkout', Checkout::class)`).
- **Inertia (Vue/React/Svelte):** the controller returns `Inertia::render('Orders/Show', ['order' => OrderResource::make($order)])`. Shape props with Resources or Data objects, never raw models with hidden fields leaking. Use shared data (`HandleInertiaRequests`) only for global UI state.
- **Filament:** resources, pages and table/page actions are adapters. `->action(fn (Order $record, array $data) => app(CancelOrderAction::class)->execute($record, $data['reason']))`. Create/Edit pages that contain business rules override `handleRecordCreation()` / `handleRecordUpdate()` to call an Action. Plain CRUD fields with no rules may stay as Filament defaults. At L3, put them in `Presentation/Filament`, or keep `app/Filament` and register them via the panel provider's `discoverResources()` for each module path.
- **Nova:** the same idea. Nova actions call domain Actions/UseCases.
- **Blade + controllers:** controllers stay thin and redirect with flash messages. View composers are only for view data, never writes.

## 6. Structure-overlapping packages

### `lorisleiva/laravel-actions`
If it's installed, its classes **are** the Action/UseCase type:
- Use `use AsAction;` with a `handle(...)` method. Call it with `PlaceOrder::run(...)` or by injecting it and calling `->handle()`.
- It can also act as a controller (`asController`), job (`asJob`) or listener (`asListener`). Allowed at L1/L2. At L3, keep `asController` in a Presentation wrapper or accept it consciously. At L4 don't use it in Application (it's framework-bound); use plain handlers.
- Testing: `PlaceOrder::mock()->shouldReceive('handle')`.

### `nwidart/laravel-modules` / `internachi/modular`
- Modules live in `Modules/{Name}` (nwidart) or `app-modules/{name}` (internachi), with their own namespaces and composer autoload or merge plugin.
- Use their generators (`php artisan module:make Order`, `make:model --module=order`, …). Inside each module, apply the level's layer folders (L3: `Domain/Application/Infrastructure/Presentation`) and leave the package's provider and route conventions alone.
- `scripts/make-module.php` can target them with `--base=Modules/Order/app --namespace="Modules\\Order"` (adjust to the package's layout).

## 7. Quality tooling

- **Pint** (`laravel/pint`): run `./vendor/bin/pint --dirty` (or `sail pint --dirty`) after editing. Respect `pint.json`.
- **Larastan** (`larastan/larastan`): run `./vendor/bin/phpstan analyse` at the project's configured level. Add generics and `@return` PHPDoc to repositories and collections so the analysis stays clean.
- **Rector** (`rector/rector`, `driftingly/rector-laravel`): use it for mechanical upgrades. Don't run it unasked.
- **Pest** (`pestphp/pest`, `pest-plugin-laravel`, `pest-plugin-arch`): the preferred test framework. Use `arch()` rules to lock in the level (see `testing.md`). Without Pest, use PHPUnit plus Deptrac for architecture rules.
- **Deptrac** (`deptrac/deptrac` or `qossmic/deptrac`): an architecture linter for L3/L4 when Pest isn't used or the codebase is big.
- **IDE helper** (`barryvdh/laravel-ide-helper`): regenerate model docs after changing models (`ide-helper:models -N`) if the project uses it.

## 8. Other common packages

| Package | Notes |
|---|---|
| `brick/money`, `moneyphp/money` | Use instead of a custom `Money` VO. At L4 they're allowed in Domain (pure PHP libraries). |
| `ramsey/uuid`, `symfony/uid` | Pure PHP, allowed in the L4 Domain for IDs (or behind an `IdGenerator` port). |
| `guzzlehttp/guzzle` | Prefer the `Http` facade in Laravel adapters so tests can use `Http::fake()`. |
| `barryvdh/laravel-dompdf`, `maatwebsite/excel` | Infrastructure exporters behind a `ReportExporter`/`InvoiceRenderer` contract at L3/L4. At L1/L2 call them from an Action or Job. |
| `laravel-notification-channels/*`, `twilio/sdk` | Notifications are side effects, so trigger them from queued listeners. At L3/L4 SMS/WhatsApp go behind a `MessageSender` contract. |
| `aws/aws-sdk-php`, `league/flysystem-*` | Use `Storage::disk()` in Infrastructure. Business code receives paths or URLs. |
| Payment gateways (`midtrans/midtrans-php`, `xendit/xendit-php`, `stripe/stripe-php`) | Always behind a `PaymentGateway` contract from L2 up. Webhooks: verify signatures, deduplicate on the provider id, then call an idempotent UseCase. |

## 9. Laravel 13 PHP attributes

Laravel 13 (PHP 8.3+) configures many classes with PHP attributes instead of properties. The fresh skeleton's `User` model already uses `#[Fillable]` and `#[Hidden]`. **On Laravel 13+, prefer attributes in new code. Match the existing style when editing old classes. On Laravel 12 and older, use properties.** Check the version in `composer.lock` (`analyze-project.php` prints it and the recommended style).

### Eloquent models (`Illuminate\Database\Eloquent\Attributes\*`)
```php
#[Table('orders')]                                   // also: key:, keyType:, incrementing:, timestamps:, dateFormat:
#[Fillable(['customer_id', 'status', 'total'])]      // or #[Guarded([...])] / #[Unguarded]
#[Hidden(['internal_note'])]                         // #[Visible], #[Appends]
#[UseFactory(OrderFactory::class)]                   // replaces newFactory() for module models
#[UsePolicy(OrderPolicy::class)]                     // replaces Gate::policy() for module models
#[UseEloquentBuilder(OrderQueryBuilder::class)]      // replaces newEloquentBuilder()
#[ObservedBy(OrderObserver::class)] #[ScopedBy(TenantScope::class)] #[CollectedBy(OrderCollection::class)]
#[UseResource(OrderResource::class)]                 // $order->toResource()
class Order extends Model { use HasFactory; }
```
Other attributes in that namespace: `#[Connection]`, `#[RouteKey]`, `#[Touches]`, `#[WithoutTimestamps]`, `#[WithoutIncrementing]`, `#[DateFormat]`.

`#[UseFactory]` and `#[UsePolicy]` remove the two biggest pains of moving models out of `App\Models` (factory and policy discovery). Use them at L2 and L3.

### Queue jobs and listeners (`Illuminate\Queue\Attributes\*`)
`#[Tries(5)]`, `#[Backoff(10, 60, 300)]`, `#[Timeout(120)]`, `#[MaxExceptions(3)]`, `#[UniqueFor(3600)]`, `#[Queue('high')]`, `#[Connection('redis')]`, `#[Delay(30)]`, `#[RetryFor(...)]`, `#[FailOnTimeout]`, `#[DeleteWhenMissingModels]`, `#[WithoutRelations]`, `#[DebounceFor(...)]`.

### Console commands (`Illuminate\Console\Attributes\*`)
`#[Signature('orders:cancel-stale {--days=1}')]`, `#[Description('Cancel stale pending orders')]`, plus `#[Aliases]`, `#[Help]`, `#[Usage]`, `#[Hidden]`. The command stays in Presentation and calls an Action or UseCase.

### Controllers (`Illuminate\Routing\Attributes\Controllers\*`)
`#[Middleware('auth:sanctum')]` (class or method, with `only:`/`except:`), `#[WithoutMiddleware(...)]`, `#[Authorize('update', 'order')]`. Authorization at the edge is the right place in every level.

### Form requests (`Illuminate\Foundation\Http\Attributes\*`)
`#[StopOnFirstFailure]`, `#[FailOnUnknownFields]` (rejects unexpected input, which is good for API boundaries), `#[ErrorBag('checkout')]`, `#[RedirectTo('/checkout')]`, `#[RedirectToRoute('checkout')]`.

### Container (`Illuminate\Container\Attributes\*`): layer rules matter
- **Constructor injection** attributes like `#[Config('services.stripe.secret')]`, `#[Storage('s3')]`, `#[DB('reporting')]`, `#[Cache('redis')]`, `#[Log('payments')]` and `#[Context('tenant_id')]` are fine in **Infrastructure** adapters at every level, and in Actions at L1/L2.
- **`#[CurrentUser]`, `#[Authenticated]`, `#[Auth]`, `#[RouteParameter]` and `#[RequestAttribute]`** read ambient request state, so they belong in Presentation only. Never use them in Actions, UseCases or the Domain (universal rule: pass the actor in).
- **`#[Bind(Concrete::class)]`, `#[Singleton]` and `#[Scoped]` on an interface:**
  - L1/L2: allowed (`#[Bind(StripePaymentGateway::class)] interface PaymentGateway`).
  - **L3/L4: don't use them on Domain or Application contracts.** The attribute names the Infrastructure class, so the Domain would depend on Infrastructure. Keep `$bindings` in the module service provider. `#[Singleton]`/`#[Scoped]` on Infrastructure classes themselves are fine.
  - Octane: prefer `#[Scoped]` over `#[Singleton]` for anything that holds per-request state.

### Testing (`Illuminate\Foundation\Testing\Attributes\*`)
`#[Seed]`, `#[Seeder(OrderSeeder::class)]`, `#[SetUp]`/`#[TearDown]` hooks, `#[WithoutMigrationEvents]` and `#[UnitTest]` (PHPUnit test classes).

## 10. Laravel Boost and the generated CLAUDE.md/AGENTS.md

- New Laravel 13 apps ship with `CLAUDE.md` and `AGENTS.md` containing a `<laravel-boost-guidelines>` … `</laravel-boost-guidelines>` block. `php artisan boost:install` and `boost:update` **rewrite everything inside that block.**
- **Write the architecture decision (and any other project notes) outside the block**, below the closing tag, for example in a `## Architecture` section. Otherwise the next Boost update erases it. The analyzer reports which files contain the block.
- The bootstrap block tells agents to `composer require laravel/boost --dev`. Per this skill's rule, **ask the user first** instead of installing it silently. Under Sail: `./vendor/bin/sail composer require laravel/boost --dev && ./vendor/bin/sail artisan boost:install`.
- When Boost is installed (`laravel/boost`), its MCP server offers tools such as version-specific documentation search, `tinker`, database schema and queries, routes, and last errors. Use them to confirm framework APIs for the project's exact versions. The Boost guidelines cover framework conventions. This skill adds the architecture level on top. If they conflict, follow the level the project chose and mention the conflict.

