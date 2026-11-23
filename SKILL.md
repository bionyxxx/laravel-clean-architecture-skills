---
name: laravel-clean-architecture
description: Clean architecture for Laravel apps in four levels, from Lean Actions (simplest) to Strict Clean/Hexagonal (most complex). It analyzes the project to recommend a level, and it knows the ecosystem (Sail, Spatie packages, Livewire/Inertia/Filament, Horizon, Octane, Pest and more). Use this skill whenever you write or change Laravel backend code, add a feature, module, endpoint, action, use case, DTO, repository or service, choose or set up a project's architecture or folder structure, review Laravel code for layering, or refactor fat controllers/models, even when the user doesn't say "clean architecture". Skip it for frontend-only work and for PHP projects that aren't Laravel.
---

# Laravel Clean Architecture

This skill gives you four architecture levels, from simplest to most complex. It tells you how to pick the right level for a project and how to write code that follows it. **Prefer the simplest level that fits.** Extra layers are a cost you pay on every feature.

## Step 0: Inspect the project before writing code

Do this once per session, before the first backend change:

1. **Recorded decision.** Look in the project's `CLAUDE.md` (or `AGENTS.md`/`README`) for a line like `Architecture: L3 Modular Clean`. If one exists, follow that level and skip ahead to the level file.
2. **Existing structure.** Check for `app/Modules`, `Modules/` (nwidart), `app-modules/` (internachi), `app/Domain`, `app/Actions`, `app/Services`, `app/Repositories`. Follow the pattern you find. If it conflicts with this skill, say so before you write code. Don't silently restructure.
3. **Ecosystem.** Read `composer.json` (`require` and `require-dev`) and `compose.yaml`/`docker-compose.yml`. If a package already solves the problem, use it instead of hand-rolling. Don't add a package without asking. See `references/ecosystem.md`.
4. **Sail.** If `laravel/sail` is installed and the project has a compose file, run **every** `artisan`, `composer`, `php`, `npm` and test command as `./vendor/bin/sail …` (for example `./vendor/bin/sail artisan migrate` or `./vendor/bin/sail test`), never on the host.
5. **Quick analysis.** `php <skill-dir>/scripts/analyze-project.php [project-root]` prints the detected level, ecosystem, metrics and a recommended level. It is read-only. Under Sail, copy the script into the project first (the container only sees the project directory) and run it there with `./vendor/bin/sail php`. Delete the copy afterwards.

## The four levels

| Level | Name | Shape | Fits |
|---|---|---|---|
| **L1** | Lean Actions | Default Laravel layout plus `app/Actions`, `app/Data`, thin controllers and FormRequests | MVPs, CRUD apps, admin tools, 1–2 devs, roughly 15 models or fewer |
| **L2** | Domain-oriented | `app/Domain/{Domain}/{Models,Actions,Data,Events,Listeners,Jobs,Enums,Services}` plus a thin `app/Http` | Growing products with several business areas, 2–5 devs |
| **L3** | Modular Clean (pragmatic) | `app/Modules/{Module}/{Domain,Application,Infrastructure,Presentation}`; contracts only where they earn their place; Eloquent allowed in Domain | Medium to large apps, many integrations, web + API + queue/CLI, teams |
| **L4** | Strict Clean / Hexagonal | Framework-free Domain entities, ports and adapters, Eloquent only in Infrastructure behind mappers, command handlers | Complex, long-lived domains (finance, logistics, insurance, healthcare), big teams, compliance or heavy unit testing |

Each level adds to the one below it. Before you write code at a level, read its file:
`references/level-1-lean-actions.md`, `references/level-2-domain-oriented.md`, `references/level-3-modular-clean.md`, `references/level-4-strict-hexagonal.md`.

### Choosing a level (new project, "set up the architecture", a refactor, or no clear pattern yet)

1. Run `scripts/analyze-project.php` and read `references/choosing-architecture.md`.
2. Present all four levels to the user with **AskUserQuestion**, simplest first. Put "(Recommended)" on the recommended one and give 1–2 lines of evidence from the analysis, for example "38 models, Stripe + S3 + WhatsApp, API and Filament admin → L3". If the analyzer marks the result as borderline, also ask about team size, the project's expected lifetime and how complex the domain is.
3. After the user picks a level, offer to record it in the project's `CLAUDE.md` (ask before you edit it):
   `Architecture: L3 Modular Clean (laravel-clean-architecture skill). Modules live in app/Modules.`
4. For an existing project, mention moving up a level only when the "when to move up" signals in the level file appear. Never migrate as a side effect of an unrelated task.

## Universal rules (all levels)

- **Thin entry points.** Controllers, Livewire/Volt components, Filament/Nova actions, console commands and jobs only do three things: validate or authorize, call **one** Action or UseCase, and shape the response. No business logic and no multi-step writes.
- **One business operation means one class** (Action, UseCase or Handler) with one public method: `execute()`, or `handle()` with `lorisleiva/laravel-actions`. Inject dependencies through the constructor. Every caller reuses that class: HTTP, jobs, commands, admin panels and tests.
- **No ambient request state in business code.** Pass the actor, user ID and input as arguments or DTOs. Never call `auth()`, `request()`, `session()` or `Auth::user()` inside Actions/UseCases/Domain.
- **DTOs at the boundary.** Map a validated request to a typed DTO (`final readonly class`, or `Spatie\LaravelData\Data` if installed). Don't pass `$request->all()` arrays into business code.
- **Consistency.** Wrap multi-row or money/state changes in `DB::transaction()` (or a `TransactionManager` port at L4). Use `lockForUpdate()` on rows you read and then modify. Make retried operations idempotent (check the current state first).
- **Events.** Name them in the past tense (`OrderPlaced`). Business events implement `ShouldDispatchAfterCommit`. Side-effect listeners implement `ShouldQueue` and never change core state directly; if they need to, they call an Action.
- **Jobs** select data and call an Action. They must be idempotent and use `withoutOverlapping()` when scheduled.
- **No logic in model events/observers** beyond trivial derived fields. Hidden side effects belong in Actions or listeners.
- **Money** is stored as integer minor units (or `brick/money`/`moneyphp` if installed), never as a float.
- **Config.** Call `env()` only inside `config/*.php`, and read values elsewhere with `config()`. Credentials never appear in code.
- **Octane-safe.** No request or user state in singletons or static properties.
- **Enums.** Backed PHP enums for statuses, with transition rules on the enum or in a state class (`spatie/laravel-model-states` if installed).
- **Tests** follow the level. See `references/testing.md`, including the Pest `arch()` rules that lock in the dependency direction.

## Ecosystem quick map

| Package / tool | Use it for | Where it lives |
|---|---|---|
| `laravel/sail` | Local Docker env | Prefix all commands with `./vendor/bin/sail` |
| `spatie/laravel-data` | DTOs (+ validation, resources) | `Data` classes: L1 `app/Data`, L2 `Domain/*/Data`, L3 `Application/DTOs`; L4 only in Presentation, mapped to plain commands |
| `spatie/laravel-permission` | Roles and permissions | Policies/middleware in Presentation; an `Authorizer` contract if business rules need it (L3/L4) |
| `spatie/laravel-query-builder` | Filter/sort/include from the query string | Query classes; HTTP-bound, so in Presentation at L3/L4 |
| `spatie/laravel-model-states` | State machines | Model states in Domain (L1–L3); L4 keeps states in the pure entity |
| `spatie/laravel-medialibrary`, `-activitylog`, `-sluggable`, `-translatable` | Model capabilities | Traits on Eloquent models (L1–L3); L4 puts them on the Infrastructure model |
| `lorisleiva/laravel-actions` | Action classes | They *are* your Actions/UseCases (`handle()`, `::run()`) |
| `nwidart/laravel-modules`, `internachi/modular` | Module packaging | Use their module root and generators instead of `app/Modules` |
| Livewire, Inertia, Filament, Nova, Volt | UI | Presentation only. They call Actions/UseCases, never `$model->update()` with business rules |
| Sanctum/Passport/Fortify/Breeze/Jetstream | Auth | Presentation and infra. Business code receives an actor ID |
| Horizon / queues, Reverb, Scout, Cashier, Socialite, Pennant | Infra capabilities | L1/L2: use directly inside Actions/Services. L3/L4: behind a Domain/Application contract with an Infrastructure adapter |
| Pint, Larastan/PHPStan, Rector, Pest | Quality | Run Pint (and Larastan) on files you touched; write Pest tests and `arch()` rules |

Details, snippets and per-level placement: `references/ecosystem.md`.

## Workflow for a new feature

1. Step 0, done once per session: know the level, the ecosystem and Sail.
2. Read the level file and follow its folder tree and naming exactly.
3. Scaffold if it helps: `php <skill-dir>/scripts/make-module.php <Module> --level=N [--entity=Name] [--usecase=PlaceOrder]` from the project root. It detects Sail, laravel-data, laravel-actions and Pest, and it never overwrites without `--force`. Under Sail, copy it into the project and run it with `./vendor/bin/sail php` as in Step 0.
4. Write the code inside out: Domain (model/entity, enum, VO, event, contract), then DTO, then Action/UseCase, then Infrastructure (repository/gateway and binding), then Presentation (FormRequest or Data, controller or component, route, resource).
5. Register any new provider in `bootstrap/providers.php` (Laravel 11+) or `config/app.php` (Laravel 10 and older), along with listeners, policies and routes.
6. Write tests: Action/UseCase behavior, the HTTP feature, and arch rules (see `references/testing.md`).
7. Run Pint, Larastan (if installed) and the test suite, through Sail if the project uses it.

Refactoring an existing fat-controller codebase, or moving up a level: `references/refactoring-legacy.md`.

## Checklist before finishing

- [ ] The level matches the project's recorded or detected level, with no silent restructuring
- [ ] Commands ran through Sail when Sail is installed
- [ ] Files are in the right folders and namespaces match the paths (PSR-4)
- [ ] Entry points are thin; each business operation is one Action/UseCase reused by every caller
- [ ] No `auth()`/`request()` inside business code; input arrives as DTOs
- [ ] State and money changes run in a transaction with locks and are idempotent
- [ ] Events dispatch after commit; side-effect listeners are queued
- [ ] Dependency direction respected (L3/L4: no Domain → Infrastructure/HTTP imports), and contracts are bound in the provider
- [ ] Installed packages were used instead of hand-rolled duplicates, and no new package was added without asking
- [ ] Tests (including arch rules) are written and passing; Pint is clean
