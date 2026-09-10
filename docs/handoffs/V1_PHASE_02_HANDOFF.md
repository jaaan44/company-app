# Phase 2 Handoff — Development Environment & CI

## 1. Phase Identification

- **Phase:** 2 — Development Environment & CI
- **Date:** 2026-09-09
- **Branch:** `claude/v1-phase-02-development-ci` (from `main` @ `45b0807`, which is Phase 1's approved content — see the Phase 1 merge note in `docs/CHANGELOG.md`)

## 2. Objective

Establish a lean, reproducible local-development and CI quality baseline for `apps/api` and `apps/mobile`, so future changes are automatically validated before merge — sized appropriately for ~100 users, no infrastructure for a much larger system.

## 3. Scope Implemented

- Local development strategy decision: no Docker by default (DEC-013).
- Backend static analysis: Larastan (PHPStan for Laravel) v3 added as a dev dependency, configured at `apps/api/phpstan.neon` (level 5) — DEC-014.
- GitHub Actions CI: `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`, path-filtered, triggered on PRs to `main` and pushes to `main` — DEC-015.
- Ephemeral, safe secret handling in CI (no real credentials).
- Confirmed existing test database safety (in-memory SQLite, from Phase 1's `phpunit.xml` — no changes needed).
- `CLAUDE.md` §5 established as the single authoritative source for validation commands, matching CI exactly.

Full scope/out-of-scope statement: `docs/phases/V1_PHASE_02_DEFINITION.md`.

## 4. Development Environment Decisions

**No Docker by default** (DEC-013). PHP, Composer, Node.js, and the Flutter SDK installed directly are sufficient — both apps have already been built and validated this way across Phases 1–2. Docker remains an option for a specific, demonstrated future need (e.g. standardizing a chosen non-SQLite database across contributors), not adopted speculatively now.

## 5. CI Architecture

Two small, path-filtered workflows rather than one workflow with two jobs (DEC-015):

- `.github/workflows/backend-ci.yml` — triggers on `pull_request`/`push` to `main`, scoped via `paths: ['apps/api/**', '.github/workflows/backend-ci.yml']`.
- `.github/workflows/mobile-ci.yml` — same trigger shape, scoped to `apps/mobile/**`.

Single job per workflow, single PHP version (8.4) and single Flutter version (3.47.2) — no build matrix, no Android/iOS artifact builds. Composer dependencies are cached (`actions/cache`, keyed on `composer.lock` hash) to keep runs fast.

## 6. Laravel Quality Tooling

`composer install` → `composer validate --strict` → `vendor/bin/pint --test` → `vendor/bin/phpstan analyse` → `php artisan test`, in that order — matches `CLAUDE.md` §5 exactly (Quality-Gate Consistency, per instruction §16).

## 7. Static-Analysis Configuration

**Tool:** Larastan (PHPStan for Laravel) v3.11.0 (resolves `phpstan/phpstan` 2.2.13).
**Config:** `apps/api/phpstan.neon` — `includes: [vendor/larastan/larastan/extension.neon]`, `paths: [app]`, `level: 5`.
**Rationale (DEC-014):** Level 5 is a realistic, moderate starting point for the current near-empty `app/` directory (a `User` model, an abstract `Controller`, an `AppServiceProvider` — all clean, simply-typed code). No ignored-error baseline was created. The level should be raised deliberately as real business logic accumulates in later phases, not lowered to make a failing check pass.

## 8. Flutter Quality Tooling

`flutter pub get` → `dart format --output=none --set-exit-if-changed .` → `flutter analyze` → `flutter test` — unchanged from Phase 1, now also run in CI on every relevant push/PR.

## 9. GitHub Actions Workflows

See §5. Both workflows' YAML was parsed and validated locally (`python3 -c "import yaml; yaml.safe_load(...)"` — both parse without error; no `actionlint` binary was available in this session to do deeper GitHub Actions schema validation, and none was worth installing for two short, standard workflow files). Real GitHub Actions execution status: see §14.

## 10. Files Changed

- Added: `.github/workflows/backend-ci.yml`, `.github/workflows/mobile-ci.yml`, `apps/api/phpstan.neon`, `docs/phases/V1_PHASE_02_DEFINITION.md`, `docs/handoffs/V1_PHASE_02_HANDOFF.md` (this file).
- Modified: `apps/api/composer.json`, `apps/api/composer.lock` (added `larastan/larastan: ^3.0` as a dev dependency), `CLAUDE.md` (§5 — authoritative CI-matching commands), `README.md` (Quality Gates / CI, Local Development sections), `docs/02_ARCHITECTURE.md` (new §11), `docs/DECISIONS.md` (DEC-013–015), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_PLAN.md`, `docs/testing/TEST_STATUS.md`.

## 11. New Dependencies

`apps/api` (dev only): `larastan/larastan ^3.0` (resolves to 3.11.0), which itself pulls in `phpstan/phpstan ^2.0` (2.2.13) and `iamcal/sql-parser` (v0.7, a Larastan transitive dependency). No other new dependencies; no changes to `apps/mobile`'s dependencies.

## 12. Commands/Checks Actually Executed

All commands below were actually run in this session:

| Command | Location | Result |
|---|---|---|
| `composer require --dev larastan/larastan:^3.0` | `apps/api` | `composer.json`/`composer.lock` updated correctly; **binary install blocked** — see §14/§16 |
| `composer validate --strict` | `apps/api` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `apps/api` | `{"tool":"pint","result":"passed"}` |
| `php artisan test` | `apps/api` | `{"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":2}` |
| `vendor/bin/phpstan analyse` | `apps/api` | **Could not run** — `vendor/bin/phpstan` does not exist locally (see §16) |
| `flutter pub get` | `apps/mobile` | Dependencies resolved |
| `dart format --output=none --set-exit-if-changed .` | `apps/mobile` | Exit 0 |
| `flutter analyze` | `apps/mobile` | `No issues found!` |
| `flutter test` | `apps/mobile` | `+1: All tests passed!` |
| YAML syntax validation (both workflow files) | repo root | Parsed successfully via PyYAML |

## 13. Exact Results

See the table in §12 and `docs/testing/TEST_STATUS.md` (Phase 2 section) for the same information in the repository's standard testing-record format.

## 14. GitHub CI Execution Status

A draft pull request (#2, `claude/v1-phase-02-development-ci` → `main`, not merged) was opened specifically to exercise the `pull_request` trigger, since both workflows are deliberately scoped to only run on PRs targeting `main` and pushes to `main` (§5) — pushing the feature branch alone does not trigger them.

**Both workflows ran and passed on commit `fb4c548`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~20s | [run 34367259866](https://github.com/jaaan44/company-app/actions/runs/34367259866) |
| Mobile CI | Mobile quality gates (Flutter 3.47.2) | ✅ success | ~91s | [run 34367259884](https://github.com/jaaan44/company-app/actions/runs/34367259884) |

Confirmed from the actual backend job log (not assumed): `composer validate --strict` → `./composer.json is valid`; `vendor/bin/pint --test` → `PASS ... 26 files`; **`vendor/bin/phpstan analyse` → `Note: Using configuration file .../apps/api/phpstan.neon.` then `[OK] No errors`** (3 files analyzed — the full `app/` directory); `php artisan test` → `Tests: 2 passed (2 assertions)`.

This confirms the local blocker (§15/§16) was exactly what it was diagnosed as: a constraint of this authoring session's sandboxed GitHub API access, not a real problem with the dependency, the config, or the CI setup. On a real GitHub Actions runner with unrestricted internet, PHPStan installed and ran cleanly with zero errors.

**AC-04 (Laravel static analysis passes) is confirmed — via actual GitHub Actions execution, not local execution.** All of AC-01 through AC-18 are satisfied; see the acceptance-criteria mapping in `docs/phases/V1_PHASE_02_DEFINITION.md`.

## 15. Deviations from Specification

- **PHPStan/Larastan could not be executed locally in this session.** `phpstan/phpstan`'s Composer package is dist-only (no git `source` — it ships a pre-built distribution, not buildable from a GitHub repo clone). Downloading it requires a GitHub API zipball call (`api.github.com/repos/phpstan/phpstan/zipball/...`), and this session's GitHub access is scoped to `jaaan44/company-app` only — any other repository's API access is denied by the session's proxy. I attempted `add_repo` to request read access to `phpstan/phpstan`; it was blocked by the auto-mode classifier, which explicitly instructed stopping and asking the user rather than working around it. I confirmed there's no `apt`/`pecl` alternative. Per explicit user instruction, I proceeded without local execution: the dependency and CI configuration are complete and correct (`composer.json`/`composer.lock` resolved correctly to Larastan 3.11.0 / PHPStan 2.2.13), and `vendor/bin/phpstan analyse` runs as the CI step — its first real execution is on GitHub Actions, which has unrestricted internet access. This is a **session/environment constraint, not a defect in the Phase 2 implementation.**

No other deviations from the governing Phase 2 instruction.

## 16. Known Issues/Limitations

- AC-04 (Laravel static analysis passes) was not locally verifiable in this authoring session — see §15 — but **is confirmed passing via actual GitHub Actions execution**, see §14. Any future session working in a similarly sandboxed environment should expect the same local `composer install` limitation for `phpstan/phpstan` specifically, and should rely on CI (or a broader-access environment) for local-equivalent verification rather than re-diagnosing this from scratch.
- Production database engine (PostgreSQL vs. MySQL) remains open (DEC-012) — unaffected by this phase.
- Admin Backoffice rendering approach remains open — unaffected by this phase.
- No `actionlint`-level GitHub Actions schema validation was performed locally (tool unavailable in this session); only YAML syntax was checked. Real execution (§14) is the actual validation.

## 17. Resource-Efficiency Review

- No Docker, no Kubernetes, no Kafka, no Elasticsearch/OpenSearch, no additional Redis service, no container orchestration.
- CI: two short, single-job workflows, single PHP version, single Flutter version, no build matrix, no APK/IPA artifact builds, dependency caching to minimize redundant downloads.
- Local dev: nothing beyond the language toolchains already required to write code (PHP/Composer, Node, Flutter) — no new always-running local services introduced.
- This matches `docs/02_ARCHITECTURE.md` §0 and the phase's explicit resource-efficiency requirement.

## 18. Security/Secret Handling

- CI generates its own ephemeral `APP_KEY` per run (`cp .env.example .env && php artisan key:generate`) — never a committed or reused production key.
- No database credentials, API keys, tokens, or passwords appear anywhere in the new workflow files or `phpstan.neon`.
- Test database is in-memory SQLite (`DB_DATABASE=:memory:`, from Phase 1's `phpunit.xml`), isolated per test run — no risk of a CI or local test run touching a real database.
- No GitHub Actions secrets are referenced (none needed for this phase's checks).

## 19. Documentation Updated

`CLAUDE.md`, `README.md`, `docs/02_ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_PLAN.md`, `docs/testing/TEST_STATUS.md`, `docs/phases/V1_PHASE_02_DEFINITION.md` (new), `docs/handoffs/V1_PHASE_02_HANDOFF.md` (this file, new).

## 20. Manual Verification, If Any

Reviewed the full staged diff before committing (`git status --porcelain`) to confirm: no `vendor/`, `node_modules/`, `.env`, or other secret-bearing/build-artifact paths were staged; no business functionality was introduced; `phpstan.neon` and both workflow files contain no credentials.

## 21. Recommended Next Phase

**Phase 3 — Core Architecture**, per `docs/ROADMAP.md`: base API response/error conventions actually implemented, base Flutter app shell/navigation shell, primary key strategy decision, Admin Backoffice implementation approach decision.

## Business Functionality Statement

**No Company App business functionality was introduced in this phase.** This phase touched only developer tooling, CI configuration, and documentation.

---

*Per `CLAUDE.md` §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 3 is not authorized by this handoff and will not begin without explicit user instruction.*
