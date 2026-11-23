# laravel-clean-architecture

A [Claude Code](https://claude.com/claude-code) skill that helps Claude write Laravel code in a clean architecture. It offers four levels, from simplest to most complex, recommends one based on your project, and knows the Laravel ecosystem.

| Level | Shape | Fits |
|---|---|---|
| **L1 Lean Actions** | `app/Actions` + `app/Data`, thin controllers | MVPs, CRUD apps, small teams |
| **L2 Domain-oriented** | `app/Domain/{Domain}/{Models,Actions,Data,Events,…}` | Growing products, several business areas |
| **L3 Modular Clean** | `app/Modules/{Module}/{Domain,Application,Infrastructure,Presentation}` | Medium–large apps, many integrations |
| **L4 Strict Hexagonal** | Framework-free domain, ports and adapters, mappers | Complex, long-lived domains, large teams |

## Features

- **Project analysis.** `scripts/analyze-project.php` detects the current structure, Sail and installed packages, scores the project, and recommends a level.
- **Ecosystem-aware.** It covers Laravel Sail, Spatie packages (laravel-data, permission, query-builder, model-states, …), Sanctum, Horizon, Octane, Cashier, Scout, Reverb, Pennant, Livewire, Inertia, Filament, Nova, laravel-actions, laravel-modules, Pest and Larastan.
- **Scaffolding.** `scripts/make-module.php` generates a module for the chosen level: model, DTO, action or use case, repository, controller, routes, migration, tests and arch tests. It adapts to the packages it finds (laravel-data, laravel-actions, Pest, Sail).
- **Guides** for every level, testing (including Pest `arch()` rules and Deptrac), and incremental migration from legacy code.

## Install

```bash
git clone git@github.com:bionyxxx/laravel-clean-architecture.git
ln -s "$PWD/laravel-clean-architecture" ~/.claude/skills/laravel-clean-architecture
```

Claude Code picks it up automatically when you work on Laravel backend code. For a single project, put it in that project's `.claude/skills/` instead.

## Usage

### How the skill is triggered

You don't need to mention the skill. Claude Code loads it automatically when a request involves Laravel backend work, such as adding a feature, endpoint or module, choosing a folder structure, or refactoring controllers. To force it, call it by name:

```
/laravel-clean-architecture add a "cancel order" feature
```

### What happens in a session

1. **Inspection.** Claude reads `composer.json`, your compose file, your existing folders, and the `Architecture:` line in `CLAUDE.md`. If Sail is installed, every command goes through `./vendor/bin/sail`.
2. **Level choice**, only when none is recorded yet. Claude runs the analyzer and shows the four levels with one marked **(Recommended)**, plus the evidence for it, for example "38 models, Stripe + S3, API and Filament → L3". You pick one.
3. **Recording.** Claude offers to add the chosen level to your project's `CLAUDE.md` so later sessions don't ask again:
   ```md
   ## Architecture
   Architecture: L3 Modular Clean (laravel-clean-architecture skill).
   ```
4. **Implementation.** Claude writes code following that level's folder tree, naming and rules, adds tests and arch rules, and runs Pint and the test suite.

### Example prompts

| Goal | Prompt |
|---|---|
| Set up a new project | "Set up the architecture for this Laravel app" |
| Get a recommendation only | "Which clean architecture level fits this project? Analyze it first" |
| Add a feature | "Add an endpoint to place an order with stock reservation and Stripe payment" |
| Scaffold a module | "Create a Billing module with an Invoice entity and an IssueInvoice use case" |
| Refactor legacy code | "Refactor OrderController@store into an action, it's 300 lines" |
| Move up a level | "We're at L2 and adding a partner API; migrate the Order domain to L3" |
| Review layering | "Check whether app/Modules respects the dependency rules" |
| Use the ecosystem correctly | "Add role-based access with spatie/laravel-permission to the admin approvals" |
| Write tests | "Write tests and arch rules for the PlaceOrder use case" |

### Tips

- **Existing projects.** Claude follows the structure you already have and won't restructure silently. To change levels, ask for it explicitly. Migrations are done one area at a time.
- **Mixed levels.** A simple module (for example a CMS) can stay at a lower level. Note that exception in `CLAUDE.md`.
- **Packages.** Claude uses packages you already have, for example laravel-data for DTOs or laravel-actions for actions, and asks before adding a new one.
- **Running the scripts yourself.** You can also run the scripts directly (see below), for example to see the analysis or generate a module without Claude.

## Scripts

Requires PHP 8.2+ and no other dependencies. Run them from the Laravel project root.

```bash
php ~/.claude/skills/laravel-clean-architecture/scripts/analyze-project.php [project-root] [--json]

php ~/.claude/skills/laravel-clean-architecture/scripts/make-module.php Order --level=3 \
    [--entity=Order] [--usecase=PlaceOrder] [--register] [--dry-run] [--force]
```

The scripts can't reach the skill folder from inside a Sail container. Under Sail, either run them with host PHP, or copy them into the project and run them with `./vendor/bin/sail php`.

## Layout

```
SKILL.md                         entry point: workflow, levels, universal rules, checklist
references/choosing-architecture.md
references/level-1-lean-actions.md … level-4-strict-hexagonal.md
references/ecosystem.md
references/testing.md
references/refactoring-legacy.md
scripts/analyze-project.php
scripts/make-module.php
```
