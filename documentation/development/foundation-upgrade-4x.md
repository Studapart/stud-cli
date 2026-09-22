# SCI-195 — Symfony 8.1, Castor 1.6.1, PHP 8.4 foundation upgrade (stud-cli 4.x)

**Status:** Implementation in progress on the SCI-195 branch (composer, delivery, docs). Spike sections below remain the authoritative feasibility record.

**Verified spike:** 2026-09-22 against `develop` (stud-cli **3.22.1**). Diagram: [foundation-upgrade-4x-layers.mmd](foundation-upgrade-4x-layers.mmd).

## Outcome (one line)

**GO — phased.** stud-cli **4.x** on **PHP ≥ 8.4.1**, **Symfony 8.1.x**, **Castor 1.8.1** is feasible; the upgrade is **coupled** (Castor 1.6+ forces Symfony 8.1 + PHP 8.4). Composer production blockers: **none**. Delivery work (CI, PHAR, portable, docs) is the main effort band (**L**). ADF consume uses **`studapart/adf-tools`** via VCS (`^1.2.2`).

## Spike deliverable

This file is the authoritative technical input for follow-up implementation. Feasibility tables below were re-checked with `composer prohibits`, a `--dry-run` require, Castor 1.6.0/1.6.1 notes, Symfony `UPGRADE-8.0.md`, and HTTP HEAD/GET of StaticPHP 8.4 archives.

---

## Golden references

| Area | Path | Symbols / notes |
|------|------|-----------------|
| Dependency manifest | `composer.json` | `require.php` **`>=8.4.1`**, `config.platform.php` **`8.4.1`**, `repositories` VCS `Studapart/adf-tools`, `studapart/adf-tools` `^1.2.2`, direct `symfony/*` `^8.1`, `jolicode/castor` **`1.8.1`** |
| Locked baseline | `composer.lock` | Symfony **8.1.x**, Castor **v1.8.1** (spike baseline was 7.4 / 1.5.0; first 4.x bump used Castor 1.6.1) |
| Castor tasks + listeners | `castor.php` | `#[AsTask]`, `#[AsListener]`, PHAR bootstrap L5–8, `vendor/bin/castor repack` consumer |
| PHAR build | `scripts/build-phar` | `composer install --no-dev`, `castor repack` |
| Portable pipeline | `scripts/download-portable-runtime`, `scripts/build-portable`, `.github/workflows/release.yml` | StaticPHP PHP **8.4.23** URLs |
| CI quality gate | `.github/workflows/tests.yml` | `php-version: '8.4'` |
| Installer | `setup-stud.sh` | PHP **8.4.1** gate; tests in `tests/Service/SetupStudScriptTest.php` |
| GHA composite | `.github/actions/stud-cli-setup/action.yml` | default `php-version: '8.4'` |
| ADF conversion | `src/Service/MarkdownToAdfConverter.php` | `DH\Adf\*` |
| ADF consumers | `src/Handler/ConfluencePushHandler.php`, `src/Service/JiraApiClient.php` | inject / instantiate converter |
| Symfony Console | `src/Command/StudHelpCommand.php` | extends `HelpCommand`, `InputOption` |
| Symfony Process | `src/Service/GitRepository.php` | `Process`, `ProcessFailedException` |
| Symfony HttpClient | `castor.php`, git hosting adapters | `HttpClient::create*` |
| Symfony Yaml | `src/Service/FileSystem.php` | `Yaml::parse`, `Yaml::dump` |
| Symfony String | `src/Service/BranchNameGenerator.php` | `AsciiSlugger` |
| Symfony Translation | `src/Service/TranslationService.php` | YAML catalogs; not `TranslatableMessage::__toString()` |
| Castor introspection | `src/Service/UpdateFileService.php` | `ReflectionClass(\Castor\Console\Application::class)` — class still present in Castor 1.6.1 |
| Agent schema | `src/Service/AgentModeSchemaGenerator.php` | parses `#[AsTask]` / `#[AsOption]` |
| PHPUnit config | `phpunit.xml.dist` | schema **11.0** URL, `failOnDeprecation="true"` |
| PHP 8.4 prep | `src/Util/ReflectionAccessor.php` | `ensureAccessible()` (SCI-192) |
| Prior Castor PHAR fix | `CHANGELOG.md` | SCI-78 repack bootstrap |
| Portable docs | `documentation/stud-portable-prototype.md` | PHP **8.4** runtime |
| Migration guide | `documentation/setup/migrating-3x-to-4x.md` | consumer 3.x → 4.x |
| Security policy | `SECURITY.md` | **4.x** supported; 3.x frozen after GA |

---

## Target vs baseline

| Dimension | 3.x baseline (verified lock) | 4.x target (confirmed) |
|-----------|------------------------------|-------------------------|
| PHP | `composer.json` `php: "8.2"` + platform `8.2`; CI 8.2 | **≥ 8.4.1** (Symfony 8.1.x); Castor 1.6 allows `>=8.4` but Symfony needs **8.4.1** |
| Symfony (direct) | `^7.3` → locked **7.4.13–7.4.14** | **8.1.x** (`^8.1`; Castor wants `^8.1.1`) |
| Castor | `^1.3` → locked **v1.5.0** | **1.8.1** (stable; still PHP ≥ 8.4 + Symfony ^8.1) |
| stud-cli version line | `3.22.1` | **`4.0.0`** first release |
| Maintenance | current | **3.x frozen** after 4.x GA |

**Castor version cliff:** `1.5.x` = PHP ≥ 8.2 + Symfony ^7.4; `1.6.0+` = PHP ≥ 8.4 + Symfony ^8.1.1. No incremental Castor 1.6 on PHP 8.2.

**Composer CLI vs library:** local Composer **2.8.12** is fine. Castor already pulls `composer/composer` **2.10.2** in the 3.x lock; 1.6.1 wants `^2.10.1` (dry-run moved lock to **2.10.3**). CI does not need a Composer **CLI** 2.10 bump unless a follow-up uses Composer as a binary.

---

## Dependency audit

### Direct `require` (production)

| Package | Locked | PHP 8.4 | Symfony 8 | Notes |
|---------|--------|---------|-----------|-------|
| `symfony/console` | 7.4.14 | ✅ (8.1 needs 8.4.1+) | bump to `^8.1` | Root + Castor |
| `symfony/http-client` | 7.4.13 | ✅ | bump to `^8.1` | |
| `symfony/process` | 7.4.13 | ✅ | bump to `^8.1` | |
| `symfony/string` | 7.4.13 | ✅ | bump to `^8.1` | |
| `symfony/yaml` | 7.4.13 | ✅ | bump to `^8.1` | Duplicate-null-key parse change in 8.0 |
| `symfony/translation` | 7.4.6 | ✅ | bump to `^8.1` | Dry-run → 8.1.5 |
| `jolicode/castor` | 1.5.0 | ❌ at 1.5 | ❌ at 1.5 | → **1.6.1** pulls Symfony 8.1 |
| `studapart/adf-tools` | 1.2.2 (VCS) | ✅ prod (`php >=7.4`) | ✅ **no prod Symfony dep**; `replace` of `damienharper/adf-tools` | Fork consume |
| `league/commonmark` | 2.8.2 | ✅ | n/a | Existing `composer audit` noise (10 advisories on 3.x lock) — unrelated to bump |
| `league/flysystem` + local | 3.32.0 / 3.31 | ✅ | n/a | |
| `league/html-to-markdown` | 5.1.1 | ✅ | n/a | |
| `stevebauman/hypertext` | 1.1.2 | ✅ | n/a | |

### Direct `require-dev`

| Package | Locked | PHP 8.4 | Symfony 8 | Notes |
|---------|--------|---------|-----------|-------|
| `phpunit/phpunit` | 11.5.55 | ✅ | n/a | Keep **11.x**. Castor 1.6.1 `require-dev` is PHPUnit 13 — **not** installed into stud-cli |
| `phpspec/prophecy-phpunit` | 2.5.0 | verify on 8.4 | n/a | Run full suite on 8.4 |
| `phpstan/phpstan` | 2.1.55 | ✅ | n/a | |
| `friendsofphp/php-cs-fixer` | 3.94.2 | ✅ | supports Symfony ^8 | |
| `league/flysystem-memory` | 3.31 | ✅ | n/a | |

### Composer prohibit + dry-run (evidence)

`config.platform.php = 8.2` makes Composer report “php 8.2 is installed” even on a PHP 8.4.17 host.

`composer prohibits symfony/console 8.1.1`:

- `studapart/stud-cli` requires `symfony/console ^7.3`
- `jolicode/castor` v1.5.0 requires `symfony/console ^7.4.11`
- `symfony/console` v8.1.1 requires `php >=8.4.1`
- conflicts `symfony/dependency-injection` / `event-dispatcher` `<8.1`

`composer require --dry-run --ignore-platform-req=php --with-all-dependencies` of Castor **1.6.1** + Symfony **^8.1** **succeeded**. Notable lock moves:

- All direct Symfony components → **8.1.5–8.1.7**
- Castor **1.5.0 → 1.6.1**
- **Install** `laravel/agent-detector` **v2.0.2** (Castor AI-agent detection)
- `jolicode/jolinotif` **3.2.0 → 3.4.0**
- `symfony/polyfill-deepclone` **1.42.0** (new)
- `symfony/polyfill-php83` removed
- `composer/composer` **2.10.2 → 2.10.3**

**Conclusion:** bump must be **atomic**: `platform.php` / `require.php` **8.4** (prefer `>=8.4.1`) + Castor 1.6.1 + Symfony 8.1.x together. Use `--ignore-platform-req=php` only if the implementation machine still has `platform.php` 8.2; do not ship that.

### Transitive Symfony (via Castor)

Castor 1.5 pulls ~20 Symfony 7.4 components. Castor 1.6.1 replaces them with **^8.1.1**. stud-cli imports a subset directly; the rest are Castor runtime deps.

Castor 1.6.1 production extras vs 1.5: `laravel/agent-detector ^2.0.2`, `jolinotif ^3.3`, `composer/composer ^2.10.1` (already present).

---

## `damienharper/adf-tools` — fork recommendation

### Usage in stud-cli

- **Production:** `MarkdownToAdfConverter` builds `DH\Adf\Node\*` trees; used by Confluence push, Jira markdown descriptions, `items:create --description-format markdown`.
- **Tests:** `tests/Service/MarkdownToAdfConverterTest.php`.

### Composer reality

Upstream `composer.json` **production** requires only `php >=7.4` and `ext-json`.  
`symfony/var-dumper ^5\|^6` is **`require-dev` only** — it does **not** block installing Symfony 8 in stud-cli today. Ticket wording that treated this as a likely install blocker is **incorrect**.

### Why fork anyway (confirmed acceptable)

| Reason | Detail |
|--------|--------|
| Stale toolchain | Latest `1.2.1` (2025-11-07); PHPUnit 9; var-dumper ^5\|^6 in require-dev |
| Org control | `studapart/adf-tools` for patches without upstream wait |
| Future-proofing | If upstream adds Symfony to `require` or breaks PHP 8.4 |
| Attribution | MIT; preserve copyright; document fork lineage |

### Recommended fork scope (**S**)

1. Mirror `DamienHarper/adf-tools` @ `1.2.1` to `github.com/studapart/adf-tools`.
2. **Minimal changes:** modernize `require-dev` (`phpunit/phpunit ^11`, `symfony/var-dumper ^5\|^6\|^7\|^8`), add PHP 8.4 CI, tag `1.2.2` (prefer minor; no production API break).
3. **No production PHP changes expected** unless PHP 8.4 suite finds issues in `src/` (low risk — ADF builders).
4. stud-cli `composer.json`: `"studapart/adf-tools": "^1.2.2"` with `replace`/`canonical` as needed, or VCS repo until Packagist.
5. Keep namespace `DH\Adf\` unless a later major rewrite.

### Alternatives (rejected for now)

| Option | Verdict |
|--------|---------|
| Stay on `damienharper/adf-tools` | OK for first 4.x Composer PR; poor long-term |
| In-repo vendoring `DH\Adf` | High churn |
| Replace with different ADF lib | No drop-in; rewrite `MarkdownToAdfConverter` (**M**) |

**Sequence:** B1 fork + 1.2.2 tag is done (`Studapart/adf-tools`). stud-cli now requires `studapart/adf-tools` `^1.2.2` via Composer `repositories` VCS (Packagist later). Remaining B2 is PHP 8.4 + Symfony 8.1 + Castor 1.6.1.

---

## Code migration inventory

### Symfony 7.4 → 8.1

Source: [UPGRADE-8.0.md](https://github.com/symfony/symfony/blob/8.1/UPGRADE-8.0.md) (8.0 removes 7.4 deprecations; 8.1 is additive).

| Component | stud-cli touchpoints | Expected work |
|-----------|---------------------|---------------|
| **Console** | `StudHelpCommand` (`HelpCommand` + `execute(InputInterface, OutputInterface): int` — matches 8.0 closure typing), responders `SymfonyStyle`, `castor.php` `InputOption` | Low. We do not use `Command::getDefaultName()`. `Application::add()` → `addCommand()` is **Castor-owned**. `failOnDeprecation` will catch leftovers. |
| **HttpClient** | factories, `*GitHostingAdapter`, `VersionCheckService`, fetchers | Low — `create*` stable; caching client `StoreInterface` change unused |
| **Process** | `GitRepository`, `GitProjectConfigService` | Low — no Process section of concern in UPGRADE-8.0 |
| **Yaml** | `FileSystem` parse/dump | Low — **do not** emit duplicate mapping keys with `null` values |
| **String** | `AsciiSlugger` | Low — sleep/wakeup replace unused |
| **Translation** | `TranslationService`, YAML catalogs | Low — we do not call `TranslatableMessage::__toString()` |

**Process:** after composer bump on PHP 8.4, run PHPUnit, PHPStan, CS-Fixer; keep `failOnDeprecation="true"`.

### Castor 1.5 → 1.6.1

| Area | Risk | Action |
|------|------|--------|
| `#[AsTask]` / `#[AsOption]` / `#[AsListener]` | Low | Full CLI + agent integration tests |
| `castor repack` + PHAR bootstrap | **Medium** | Re-verify SCI-78 (`castor.php` L5–8); `scripts/build-phar` on PHP 8.4 |
| `Castor\Console\Application` | Low | Class exists in 1.6.1; `UpdateFileService` reflection still valid |
| Agent detection (`laravel/agent-detector`) | **Medium** | Castor hides logo when `PlatformHelper::isRunningInAgentContext()`. stud `--agent` JSON is our responder path. Regression-test agent JSON **and** human CLI under Cursor. Escape hatch: `CASTOR_DISABLE_AGENT_DETECTION`. |
| `.castor.context` file | Low | New optional Castor feature; ignore unless we adopt it |
| `composer/composer ^2.10.1` | Low | Already in 3.x lock at 2.10.2 |

1.6.1 itself: nicer error when PHP 8.2/8.3 runs Castor — relevant only for source checkouts that bump Castor without bumping PHP (must not happen).

### PHPUnit / test harness

| Item | Action |
|------|--------|
| `phpunit.xml.dist` schema | Update XSD URL from 10.x to 11.x during 4.x work |
| `failOnDeprecation="true"` | Keep — catches PHP 8.4 + Symfony 8 deprecations |
| Prophecy | Full suite on 8.4 |
| Architecture tests | No Symfony version coupling expected |
| Agent integration | `tests/Integration/*Agent*` — **mandatory** |
| `SetupStudScriptTest` | Update expected PHP **8.4** strings |

### PHP 8.4 language / runtime

| Topic | stud-cli status |
|-------|-----------------|
| `ReflectionProperty::setAccessible()` on public | Mitigated via `ReflectionAccessor` (SCI-192) |
| New syntax (property hooks, etc.) | **Optional** — not required for bump |
| `composer.json` `require.php` + `platform.php` | `8.2` → **`>=8.4.1`** (or `8.4` + platform `8.4.1`) |
| `ext-*` | unchanged: `xml`; runtime also `curl`, `mbstring` |

---

## Delivery / consumer impact (3.x vs 4.x)

| Surface | 3.x | 4.x change |
|---------|-----|------------|
| Minimum PHP | 8.2 | **8.4.1** |
| Install script | `setup-stud.sh` checks 8.2 | Messages + apt packages `php8.4-*`; update `SetupStudScriptTest` |
| PHAR | built on PHP 8.2 CI | PHP **8.4** `setup-php` |
| Portable | StaticPHP **8.2.31** | **Confirmed 2026-09-22:** `https://dl.static-php.dev/static-php-cli/gnu-bulk/php-8.4.23-cli-linux-x86_64.tar.gz` (HTTP 200, gzip ~39 MB); `https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-macos-aarch64.tar.gz` (HTTP 200, gzip ~14 MB). Pin a current 8.4.x patch at implementation time. |
| `stud update` | 3.x artifacts | Document major upgrade; optional version-check warning when PHP &lt; 8.4 |
| GHA `stud-cli-setup` | default PHP 8.2 | Default **8.4**; document input |
| Docs | setup, README, ADRs | Migration guide + version matrix |
| `SECURITY.md` | 3.4.x supported | **4.x** supported; 3.x EOL after GA |

**3.x users who cannot move to PHP 8.4:** pin last **3.x** release; no security patches after 4.x GA.

**What they lose:** new features, dependency/security updates on the CLI and issue-tracker clients, PHP 8.4-built PHAR/portable. Install **channels** (PHAR, portable, Composer) stay; **runtime** requirement changes.

---

## Risk register

| Risk | Severity | Mitigation |
|------|----------|------------|
| Coupled PHP + Symfony + Castor bump | High | Single `4.x` / `4.0.0` branch; no partial upgrades |
| PHAR classloading regression | Medium | SCI-78 bootstrap + `scripts/build-phar` smoke on 8.4 |
| Portable runtime availability | Low (verified 8.4.23 exists) | Re-pin URLs at release; smoke `scripts/download-portable-runtime` |
| Agent-mode JSON parity vs Castor agent detection | Medium | Integration tests; `CASTOR_DISABLE_AGENT_DETECTION` if Castor wraps stdout |
| PHP 8.2-only CI consumers | Medium | Keep 3.x artifacts published; document EOL |
| adf-tools upstream drift | Low | Fork in B1; bump can use upstream until then |
| Symfony 8 Console `StudHelpCommand` | Low | Help + `--agent` integration tests |
| Existing `composer audit` (commonmark + composer/composer) | Low | Unrelated to 4.x; optional hygiene follow-up |

---

## Non-negotiables (follow-up implementation)

- **ADR-005** Handler → Responder; no presentation in handlers
- **ADR-009** service locators stay in `castor.php`
- **ADR-012/013/014** agent mode JSON via responders
- **100% PHPUnit coverage** on changed code
- `CHANGELOG.md` under `## [Unreleased]` for behavior changes
- No raw `git commit` / `gh pr create` — use `stud`
- Agent JSON field names from `stud help --agent`

---

## Suggested implementation order

### Phase A — Complete spike (SCI-195, this issue)

1. ~~PHP 8.4 host / dry-run~~ done (PHP 8.4.17 + dry-run resolve).
2. ~~Record conflicts~~ none beyond known coupling + platform.php 8.2.
3. ~~Symfony 8.0/8.1 notes~~ skimmed; inventory above.
4. ~~Castor 1.6.0/1.6.1 notes~~ skimmed; agent detection called out.
5. ~~StaticPHP 8.4 URLs~~ confirmed for linux-amd64 + darwin-arm64.
6. Requester sign-off on this PR.

### Phase B — Follow-up issues (recommended titles)

| Order | Suggested issue | Effort | Status |
|-------|-----------------|--------|--------|
| B1 | Fork `studapart/adf-tools` from 1.2.1 + PHP 8.4 CI | **S** | Done (SCI-207 / VCS consume) |
| B2 | stud-cli **4.x** branch: `composer.json` / lock bump (PHP ≥ 8.4.1, Symfony 8.1, Castor 1.8.1) | **M** | Done (this PR) |
| B3 | Fix Symfony 8 / PHP 8.4 deprecations; green PHPUnit + PHPStan + CS-Fixer | **M** | Done (this PR) |
| B4 | CI + `setup-stud.sh` + GHA composite → PHP 8.4 | **S** | Done (this PR) |
| B5 | Release pipeline: PHAR + portable PHP 8.4 runtimes (pin StaticPHP 8.4.x) | **M** | Done (pipeline pins; no publish/tag) |
| B6 | Docs: 3.x→4.x migration, `SECURITY.md`, ADR tech-context headers | **S** | Done (this PR) |
| B7 | stud-cli **4.0.0** release + announce 3.x EOL | **S** | Out of scope for this PR |

**Total implementation (B1–B7): effort band L** (about 2–4 weeks engineering, assuming no surprise BC).

---

## Feasibility report (spike conclusion)

| Question | Answer |
|----------|--------|
| Go / no-go? | **GO (phased)** |
| Hard blockers? | **None** in Composer prod deps; coupling is PHP 8.4.1 + atomic bump |
| adf-tools fork? | **Yes** — maintenance fork recommended; **not** an install blocker |
| 3.x path? | Freeze at last 3.x; document EOL |
| Effort | Spike **S**; implementation **L** |

---

## Acceptance criteria (Jira parity)

- [x] Feasibility report targets **stud-cli 4.x** with **PHP ≥ 8.4**, **Symfony 8.1.x**, **Castor 1.6.1** — **GO phased**
- [x] Direct and transitive dependencies audited; blockers + mitigations documented
- [x] **`damienharper/adf-tools`** assessed; **fork `studapart/adf-tools` recommended**
- [x] **3.x vs 4.x** consumer impact documented
- [x] Delivery surfaces assessed (CI, PHAR, portable, setup, GHA composite, docs)
- [x] Migration inventory: Symfony 8.1, Castor 1.6, PHPUnit, PHP 8.4, PHAR/repack
- [x] Risk register: agent parity, PHP 8.2 environments, fork maintenance
- [x] Ordered follow-up issues named (Phase B table)
- [ ] Spike deliverable reviewed by requester (Pierre-Emmanuel MANTEAU)

---

## Contract sketch

**N/A for spike.** No new CLI commands or agent JSON shapes. Follow-up work preserves existing `stud help --agent` contracts unless Castor agent detection forces a documented workaround.

---

## Inputs

| Channel | This issue |
|---------|------------|
| CLI | None |
| Agent JSON | None |
| Jira | `SCI-195` description + AC + attachments |
