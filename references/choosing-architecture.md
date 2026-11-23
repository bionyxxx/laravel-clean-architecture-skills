# Choosing an architecture level

Read this file when you're setting up a new project, when the user asks which architecture to use, when you're planning a large refactor, or when the project has no clear pattern yet.

## Contents
1. Principles
2. The levels compared
3. Scoring heuristic (what `analyze-project.php` computes)
4. Questions for borderline cases
5. Presenting the options (AskUserQuestion template)
6. Anti-patterns
7. Recording the decision

## 1. Principles

- **The simplest level that fits wins.** Every layer adds files, indirection and onboarding cost to every feature.
- **Follow what exists.** For an existing codebase, the detected level is the default. Suggest a change only when the scoring points at least one level higher **and** you can see pain: fat controllers, duplicated logic, hard-to-test integrations.
- **Levels can be mixed per module only on purpose.** For example, an L3 app may keep a simple `Cms` module at L2. Record such exceptions in `CLAUDE.md`.
- **Upgrades are incremental.** You can always move up later (`refactoring-legacy.md`). Over-engineering early is harder to undo than moving up.

## 2. The levels compared

| | L1 Lean Actions | L2 Domain-oriented | L3 Modular Clean | L4 Strict Hexagonal |
|---|---|---|---|---|
| Unit of organisation | Technical type (`app/Actions`, `app/Models`) | Business domain folder | Module × layer | Bounded context × layer, ports/adapters |
| Business logic in | Actions | Domain Actions | Application UseCases + Domain | Domain entities + Application handlers |
| Eloquent | Everywhere (except controllers' logic) | Domain models | Domain models (pragmatic) | Infrastructure only, mapped to entities |
| Interfaces/contracts | Rare | For external services only | For persistence that needs a seam, all external services | Every outbound dependency (repo, clock, tx, events, gateways) |
| Files per feature (typical) | 3–4 | 4–6 | 6–9 | 9–14 |
| Unit tests without DB | Some | Some | Most UseCases | All Domain + Application |
| Onboarding cost | Very low | Low | Medium | High |
| Typical team | 1–2 | 2–5 | 4–15 | 10+ or regulated domain |

## 3. Scoring heuristic

`scripts/analyze-project.php` computes these points. You can also compute them by hand.

| Signal | 0 | 1 | 2 | 3 |
|---|---|---|---|---|
| Eloquent models | ≤ 15 | 16–40 | 41–100 | > 100 |
| External integrations (SDK packages + `Http::` used in ≥ 3 files) | 0 | 1–2 | ≥ 3 | |
| Delivery channels (web UI, API, admin panel, ≥ 3 console commands, ≥ 3 jobs, broadcasting) | ≤ 2 | 3–4 | ≥ 5 | |
| Async/eventing (events + listeners + jobs) | < 5 | 5–20 | > 20 | |
| Money/payment packages (cashier, stripe, brick/money, moneyphp, midtrans, xendit, paypal…) | none | any | | |
| Event sourcing (`spatie/laravel-event-sourcing`, EventSauce) | none | | present | |
| Test files | ≤ 100 | > 100 | | |
| PHP files in `app/` | ≤ 300 | > 300 | | |

**Total → level:** 0–2 → **L1**, 3–5 → **L2**, 6–8 → **L3**, ≥ 9 → **L4**.
**Borderline:** a total of 2, 3, 5, 6, 8 or 9 (within one point of a boundary). In that case, ask the questions in section 4 before you recommend.

**Detected structure** (it overrides the score as the *default*):
- `CLAUDE.md` names a level: use it.
- `app/Modules/*/Domain` exists. If the Domain folders import `Illuminate\Database\Eloquent`, it's L3. If they're framework-free and `Application/Ports` or `Persistence` mappers exist, it's L4.
- `nwidart/laravel-modules` or `internachi/modular` is installed: modular, treat it as L3 inside their module roots.
- `app/Domain/*` exists: L2.
- `app/Actions` contains business actions (not only Fortify/Jetstream's `app/Actions/Fortify`): L1.
- Otherwise it's a default Laravel layout ("L0"). Recommend by score, usually L1.

**Fat-controller flag:** an average controller longer than 120 lines, or any controller longer than 300, means the code needs refactoring. The fix is extracting Actions (L1), whatever the target level is.

## 4. Questions for borderline cases

Ask only what the score can't tell you, in one AskUserQuestion call with at most 3 questions:
- **Team size**: solo or 2 devs / 3–6 / 7+?
- **Lifetime and stakes**: prototype or MVP / product for several years / regulated or financial-critical?
- **Domain complexity**: mostly CRUD / some workflows (status transitions, approvals) / complex rules (pricing engines, ledgers, scheduling, multi-tenant policies)?
- **Delivery channels planned**: web only / web + API / web + API + mobile + integrations?

Adjust the score: +1 for the 3rd option of each question, −1 for the 1st option of the first three (don't go below L1).

## 5. Presenting the options

Use one AskUserQuestion question with four options, ordered from simplest to most complex. Put "(Recommended)" on the recommended label. Put the evidence in the question text and keep the descriptions short. Example (recommended L3):

```
question: "Which architecture level should this project use? Analysis: 38 models, 4 integrations (Stripe, S3, Twilio, Algolia), web + API + Filament + 12 jobs → score 7 (L3)."
header: "Architecture"
options:
  - label: "L1 Lean Actions"
    description: "app/Actions + app/Data, thin controllers. Fastest to write; gets crowded past ~15 models."
  - label: "L2 Domain-oriented"
    description: "app/Domain/{Domain}/{Models,Actions,Data,Events}. Groups code by business area; low ceremony."
  - label: "L3 Modular Clean (Recommended)"
    description: "app/Modules/{Module}/{Domain,Application,Infrastructure,Presentation}. Contracts for integrations; Eloquent stays in Domain."
  - label: "L4 Strict Hexagonal"
    description: "Framework-free domain, ports & adapters, mappers. Most testable, roughly 2× the files per feature."
```

If the project already has a level, put it first as "Keep current L2 (Recommended)" and offer only the realistic alternatives.

## 6. Anti-patterns

- **L4 for a CRUD admin panel.** Mappers and ports around 30 forms is pure overhead. Use L1 with Filament.
- **L1 for a payments or ledger platform with a large team.** Actions grow huge and integrations get hard-wired. Use L3 at least.
- **A repository interface for every model at L3.** Add a contract only when there is a real seam: complex queries, an alternative storage, or a need to unit-test without the DB.
- **Changing levels mid-feature.** Finish the feature in the current level, then plan the migration separately.
- **Generic `Service` dumping grounds** (`OrderService` with 40 methods). Split it into one Action/UseCase per operation. Keep Services for reusable technical capabilities such as calculators, gateways and formatters.

## 7. Recording the decision

After the user confirms, offer to append this to the project's `CLAUDE.md`:

```md
## Architecture
Architecture: L3 Modular Clean (laravel-clean-architecture skill).
- Modules live in app/Modules/{Module}/{Domain,Application,Infrastructure,Presentation}.
- Exceptions: none.
- Dev env: Laravel Sail (run all commands via ./vendor/bin/sail).
```
