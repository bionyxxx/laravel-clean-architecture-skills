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
