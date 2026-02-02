# [ADR-000] Short Title of the Decision

* **Status:** `Proposed` | `Accepted` | `Rejected` | `Deprecated` | `Superseded by [ADR-00X]`
* **Date:** YYYY-MM-DD
* **Authors:** [Name]
* **Deciders:** [Name] (Who has the final sign-off?)
* **Technical Context:** Symfony 7.4, PHP 8.x, [Specific Bundle/Component]

## 1. Context and Problem Statement

What is the technical or business problem we are trying to solve?

* **The Pain Point:** (e.g., *“Our current controller-based export logic times out when processing >10k rows.”*)
* **The Goal:** (e.g., *“Offload heavy processing to background workers securely and reliably.”*)

## 2. Decision Drivers & Constraints

What forces are at play?

* **Symfony Best Practices:** Does this align with modern Symfony (e.g., using Attributes over Annotations, Autowiring)?
* **Ecosystem:** dependencies on third-party bundles (e.g., API Platform, Sonata).
* **Infrastructure:** Does this require new infra (Redis, RabbitMQ, Elasticsearch)?
* **Team Skills:** Is the team familiar with the proposed technology?

## 3. Considered Options

Briefly describe the alternatives analyzed.

* **Option 1:** [Description] (e.g., *Use native Symfony Messenger with Doctrine Transport*)
* **Option 2:** [Description] (e.g., *Use a custom shell script + Cron*)
* **Option 3:** [Description] (e.g., *Use a specialized SaaS solution*)

## 4. Decision Outcome

**Chosen Option:** `[Option 1]`

**Justification:**
Why is this the best choice?
*(e.g., "We chose Symfony Messenger because it is native to 7.4, offers built-in failure handling (retries/DLQ), and requires no new infrastructure since we already use PostgreSQL.")*

## 5. Consequences (Trade-offs)

Every architectural decision has a price.

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **Maintainability** | *(+) Standard component; easier for new hires to understand.* |
| **Performance** | *(-) Slight overhead compared to raw PHP scripts.* |
| **Complexity** | *(-) Requires running and monitoring a new `messenger:consume` worker process.* |
| **Dependency** | *(+) Removes dependency on the legacy `brianium/paratest` library.* |

## 6. Implementation Plan

* [ ] PoC branch: `feat/adr-00X-poc`
* [ ] Update `composer.json` (if new packages are needed).
* [ ] Configuration: Create `config/packages/xxx.yaml`.
* [ ] Documentation: Update `README.md` or internal wiki.

---

### Pro-Tip for Symfony 7.4 ADRs

When filling this out for Symfony 7.4, explicitly mention if you are favoring **Composition over Inheritance** or **Attributes over Configuration**, as these are key architectural shifts in recent Symfony versions.