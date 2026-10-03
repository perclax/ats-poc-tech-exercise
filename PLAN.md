# ATS PoC — Implementation Plan

## How to use this plan

This roadmap defines the implementation tasks for the Viterbit ATS proof of
concept. `AGENTS.md` remains the authoritative source for architecture,
technical constraints, coding standards, and product decisions.

Work on one task at a time. Before editing, inspect the repository and produce a
short plan for the active task. Do not start later tasks merely because their
requirements are already known. A task is complete only when its acceptance
criteria pass and the relevant checks have been executed.

Update the status table after the user accepts a task result.

| Task | Title | Status | Depends on |
| --- | --- | --- | --- |
| 0 | Bootstrap project and infrastructure | Done | — |
| 1 | Establish the application domain and ports | Done | Task 0 |
| 2 | Submit and persist applications | Done | Task 1 |
| 3 | Enrich applications asynchronously | Pending | Task 2 |
| 4 | Browse and inspect applications | Pending | Task 2; Task 3 for enrichment fields |
| 5 | Complete the user experience | Pending | Tasks 2–4 |
| 6 | Verify and prepare the submission | Pending | Tasks 0–5 |

Allowed status values: `Pending`, `In progress`, `Blocked`, and `Done`.

## Task 0 — Bootstrap project and infrastructure

### Objective

Create a clean Symfony project that runs entirely through Docker Compose and
provides the infrastructure and quality tools required by later tasks.

### Scope

- Bootstrap a supported Symfony application with Twig and the packages required
  for Doctrine MongoDB ODM and Symfony Messenger.
- Build one reusable PHP application image.
- Define Docker Compose services for the web application, worker, MongoDB, and
  RabbitMQ.
- Add health checks and startup dependencies that avoid brittle fixed sleeps.
- Configure named volumes for MongoDB and RabbitMQ data.
- Configure environment variables without committing secrets.
- Install and configure PHPUnit, PHPStan level 8, Symfony-aware PHPStan support,
  and PHP-CS-Fixer.
- Provide `make init`, `make test`, `make analyse`, `make lint`, `make check`,
  and `make down`, plus documented Compose equivalents.
- Add a minimal HTTP health endpoint.
- Prove MongoDB connectivity without introducing application business models.
- Send one infrastructure-only message through RabbitMQ and consume it with the
  worker. Keep this probe clearly separate from the Applications module.
- Add meaningful smoke tests for the HTTP endpoint and container configuration.
- Add an initial evaluator-focused README with startup and verification steps.

### Acceptance criteria

- From a clean clone, a developer with Docker Compose can run `make init`
  without host PHP or Composer.
- All services become healthy and the documented URL returns a successful HTTP
  response.
- The application can connect to MongoDB.
- A technical probe message is published to RabbitMQ and consumed by the worker.
- `make test`, `make analyse`, `make lint`, and `make check` pass inside Docker.
- `make down` stops services without deleting persisted data.
- Re-running `make init` is safe and does not reset volumes.
- The setup works without forcing an `amd64` platform.

### Out of scope

- Application forms, jobs, candidate data, domain entities, and mock AI logic.
- Production deployment, TLS, authentication, CI, and an outbox.

## Task 1 — Establish the application domain and ports

### Objective

Implement the minimum domain model and application contracts required by the
submission use case while preserving the agreed architectural boundaries.

### Scope

- Create the Applications module with Domain, Application, Infrastructure, and
  Presentation boundaries.
- Model stable identifiers, candidate contact data, job references, application
  status, enrichment status, timestamps, and enrichment results.
- Implement domain invariants and valid state transitions.
- Define `ApplicationSubmitted` carrying only the application identifier.
- Define the ports listed in `AGENTS.md` as focused interfaces.
- Implement the version-controlled three-job catalogue and explicit skill alias
  groups.
- Define command/query DTOs needed by the next tasks without creating unused
  speculative handlers.
- Add unit tests for invariants, initial state, transitions, and catalogue data.
- Keep domain code free of Symfony, Doctrine, MongoDB, and RabbitMQ imports.

### Acceptance criteria

- A valid application can be created in memory with `received` and `pending`
  initial states and a UTC application timestamp.
- Invalid required data and invalid transitions are rejected by domain rules.
- A completed enrichment requires a summary, score from 0 to 100, and timestamp.
- Domain tests pass and PHPStan confirms the domain boundary is correctly typed.
- No persistence, controller, form, or asynchronous worker behavior is added.

## Task 2 — Submit and persist applications

### Objective

Deliver the complete application-submission path from job selection and form
validation to MongoDB persistence and enrichment dispatch.

### Scope

- Implement the `SubmitApplication` command and handler.
- Implement MongoDB ODM mappings and the write repository adapter outside the
  domain model.
- Show the predefined jobs and their descriptions.
- Build the application form with the fields and limits defined in `AGENTS.md`.
- Add server-side validation, CSRF protection, escaped output, and preservation
  of submitted values after errors.
- Persist the application before publishing `ApplicationSubmitted`.
- Handle publication failure without losing the stored application.
- Dispatch `EnrichApplication` to the asynchronous transport through the event
  flow, while leaving enrichment handling for Task 3.
- Show an English confirmation page stating that analysis is pending.
- Add unit and integration tests for valid submission, validation failures,
  persistence, allowed duplicate emails, and event ordering.

### Acceptance criteria

- A valid form creates exactly one MongoDB document with server-generated ID,
  UTC `appliedAt`, `received`, `pending`, and null enrichment fields.
- An invalid form stores nothing and displays field-level errors.
- A job ID outside the catalogue is rejected.
- Repeated applications using the same email remain allowed.
- The event is published only after persistence succeeds and contains no CV or
  contact data.
- A publication failure leaves a recoverable pending application.
- Relevant tests and all quality checks pass.

## Task 3 — Enrich applications asynchronously

### Objective

Process queued applications through a deterministic mock enrichment provider
and persist reliable enrichment states and results.

### Scope

- Implement the `EnrichApplication` asynchronous handler.
- Implement `CvEnricher` using explicit job skills and aliases.
- Generate a deterministic score and short evidence-based summary.
- Implement an atomic claim from `pending` to `processing`.
- Persist summary, score, `enrichedAt`, and `completed` together.
- Configure the initial attempt plus three retries and a failure transport.
- Return transient failures to `pending` between attempts and mark `failed` only
  after retries are exhausted.
- Make completed-message delivery idempotent.
- Implement the manual recovery command for pending and stale processing items,
  with a configurable age threshold and clear operational documentation.
- Prevent CV and contact data from appearing in messages or logs.
- Add unit and integration tests for scoring, summaries, success, retries,
  exhausted retries, duplicate delivery, concurrent claims, and recovery.

### Acceptance criteria

- A queued pending application is eventually enriched by the worker.
- Identical inputs always return the same score and summary.
- Scores use distinct expected skill groups rather than repeated keyword counts.
- A completed score of zero is retained and distinguishable from pending.
- Duplicate or concurrent deliveries cannot overwrite a completed result or run
  incompatible enrichments.
- Exhausted retries result in `failed` while preserving the application.
- Recovery redispatches only eligible records.
- Relevant tests and all quality checks pass.

## Task 4 — Browse and inspect applications

### Objective

Provide recruiter-facing list and detail views with server-backed search,
filtering, ordering, and complete enrichment information.

### Scope

- Implement `ApplicationReadRepository` using MongoDB queries and read DTOs.
- Implement `ListApplications` and `GetApplicationDetail`.
- List applications newest first with a stable ID tiebreaker.
- Support combinable filters for job, application status, and enrichment status.
- Support case-insensitive literal search by candidate name or email.
- Escape user search input and avoid executing it as a raw regular expression.
- Display candidate identity, job, statuses, applied date, and score in the list.
- Add a detail view with contact data, notes, original CV, mock summary, score,
  states, and timestamps.
- Return an appropriate not-found response for unknown IDs.
- Add repository and HTTP integration tests for ordering, filters, search,
  score-zero display, detail, and missing records.

### Acceptance criteria

- Results are ordered by `appliedAt` descending with deterministic ties.
- Every filter works alone and in combination.
- Search is case-insensitive and treats regex characters literally.
- Pending enrichment and a completed zero score are visibly different.
- Detail output contains all relevant fields and safely escapes user content.
- Missing IDs return a clear 404 response.
- Relevant tests and all quality checks pass.

## Task 5 — Complete the user experience

### Objective

Make all required flows clear, responsive, accessible, and demonstrable across
normal, empty, pending, and failed states.

### Scope

- Apply a coherent responsive design to application, confirmation, list, and
  detail pages.
- Add clear navigation and accessible labels, focus states, validation summaries,
  and semantic status text.
- Update list filters immediately and debounce text search.
- Poll only while visible items or the current detail remain `pending` or
  `processing`, and stop after completion, failure, navigation, or a bounded
  timeout.
- Show useful loading, empty, network-error, pending, processing, completed, and
  failed states.
- Label enrichment as `Mock analysis` and include suitable explanatory copy.
- Verify keyboard and mobile usability without adding a frontend framework.
- Add focused behavior tests where they provide meaningful protection.

### Acceptance criteria

- The full flow is usable on desktop and mobile widths.
- Filters and search update without a manual full-page reload.
- Polling stops whenever no active enrichment remains and cannot run forever.
- Users can distinguish technical failure, pending work, and a valid zero score.
- The UI contains English copy and does not expose internal implementation terms
  that do not help the user.
- Relevant tests and all quality checks pass.

## Task 6 — Verify and prepare the submission

### Objective

Produce a clean, reproducible repository that a Viterbit reviewer can run,
understand, test, and demonstrate locally.

### Scope

- Review every exercise requirement and acceptance criterion against the final
  implementation.
- Add a small idempotent fictional demo-data command or fixture mechanism.
- Test the clean-clone path and every documented command.
- Run the full unit, integration, static-analysis, and formatting suite.
- Review logs and committed files for secrets or personal data.
- Finalize README sections for prerequisites, startup, URLs, demo flow, tests,
  architecture summary, assumptions, mock AI, and known limitations.
- Document the non-atomic persist/publish limitation and manual recovery command.
- Remove obsolete probes or clearly retain only those that remain useful.
- Review dependency and container version pinning and ensure `composer.lock` is
  committed.
- Ensure the Git working tree is clean after the final checks.

### Acceptance criteria

- A reviewer can clone the repository and reach a working application by
  following the README exactly.
- The documented demo covers submission, pending analysis, completed enrichment,
  list filtering/search, and detail inspection.
- `make check` passes from the documented environment.
- Demo fixtures are fictional, repeatable, and do not create uncontrolled
  duplicates.
- The repository contains no secrets, real candidate data, generated caches,
  container volumes, or `vendor`.
- Known limitations are concise and accurate.

## Cross-task completion report

At the end of every task, report:

1. files and behavior changed;
2. architecture decisions made within the permitted scope;
3. commands and tests executed, with their results;
4. any acceptance criterion that could not be verified;
5. remaining risks or limitations;
6. the recommended next task.

Do not mark the task `Done`, create a commit, push, merge, or begin the next task
unless the user's instruction authorizes that action.
