# ATS PoC — Codex Instructions

## Goal and scope

Build the Viterbit technical exercise described at https://github.com/viterbit/tech-exercise.

This file contains the agreed project decisions. The prompt for each task defines the scope to implement during that stage. Do not implement the entire product when the active task only concerns infrastructure.

The product lets candidates submit applications, enriches them asynchronously, and lets recruiters browse them through a list, filters, search, and a detail view.

Exercise constraints:

- The CV must be pasted as plain text into a textarea. Do not add file uploads or OCR.
- Mock LLM requests. Do not call a real AI API or require credentials.
- Messaging and asynchronous processing must work for real.
- Include unit and integration tests and straightforward local instructions.
- Deliver a simple, usable web interface.

## Project language

Use English throughout the repository, including:

- documentation and Markdown files;
- source file and directory names;
- namespaces, classes, interfaces, functions, methods, variables, properties, constants, enums, commands, events, DTOs, and test names;
- code comments and PHPDoc;
- commit messages, branch names, fixtures, log messages, validation messages, UI copy, and sample data.

Use established technical names as written by their projects. Do not mix Spanish identifiers or prose into repository content.

## Technology and local execution

- PHP and Symfony.
- MongoDB with Doctrine MongoDB ODM for persistence. Do not add PostgreSQL.
- Symfony Messenger and RabbitMQ for asynchronous processing.
- Twig, CSS, and lightweight JavaScript for the UI.
- Docker Compose for the web application, worker, MongoDB, and RabbitMQ.
- The web application and worker share the same image and codebase and run as separate processes.
- PHPUnit, PHPStan level 8, and PHP-CS-Fixer. PHPMD is outside the initial scope.

When bootstrapping the project, select stable, supported, mutually compatible versions of PHP, Symfony, PHP extensions, and packages. Pin container image versions, commit `composer.lock`, and do not use `latest` image tags. The setup must work on Apple Silicon macOS and Linux; do not force the `amd64` platform.

Run PHP, Composer, tests, and quality tools inside containers. The host should only require Docker with Compose. Make is a convenience layer; document the equivalent Docker Compose commands. Persist demo data in named volumes and do not delete it when stopping the environment.

## Architecture

Use a modular monolith with proportionate DDD, hexagonal architecture, and lightweight CQRS. Build one Applications module separated into:

- Domain: model, invariants, states, and domain events. It must not depend on Symfony, Doctrine, MongoDB, or RabbitMQ.
- Application: commands, queries, handlers, DTOs, and required ports.
- Infrastructure: persistence, messaging, job catalogue, and mock AI adapters.
- Presentation: controllers, forms, view models, and Twig templates.

Organize code by module first and layer second. Keep controllers thin. Configure Doctrine mappings outside domain classes. Commands and queries may share MongoDB. Queries should return DTOs tailored to views and do not need to reconstitute aggregates.

Do not add Event Sourcing, microservices, separate read/write databases, or a generic command-bus/repository framework. Introduce small abstractions only when required by a real use case.

Expected ports:

- `ApplicationRepository`: load and save applications for write use cases.
- `ApplicationReadRepository`: return application lists and detail read models.
- `JobCatalog`: provide the predefined jobs.
- `CvEnricher`: produce a CV summary and relevance score.
- `EventPublisher`: publish domain events.
- `EnrichmentDispatcher`: enqueue enrichment requests.

## Jobs and application data

Provide a version-controlled catalogue of three fictional jobs. Do not build job administration:

- Backend Developer: PHP, Symfony, databases, and REST APIs.
- Frontend Developer: JavaScript/TypeScript, React, HTML, and CSS.
- Fullstack Developer: backend and frontend skills.

Each job has a stable identifier, title, short description, and expected skills. The candidate selects a job and can read its description. Persist the stable job identifier instead of accepting free-form position text.

Fields and limits:

| Field | Required | Validation |
| --- | --- | --- |
| Full name | Yes | Non-empty after trimming; maximum 150 characters. Do not require a specific number of names. |
| Email | Yes | Valid email; maximum 254 characters. |
| Phone | No | Maximum 30 characters; accept common prefixes and separators. |
| Job | Yes | Must reference an existing catalogue entry. |
| Notes | No | Maximum 2,000 characters. |
| CV text | Yes | Non-empty after trimming; maximum 30,000 characters. |

Validate on the server, display errors next to their fields, and retain submitted values after validation errors. Preserve the original CV text for the detail view, escape it on output, and protect the form with CSRF. Allow repeated applications with the same email, including applications for the same job.

The server creates the unique application identifier and `appliedAt` in UTC. The initial application status is `received`. The initial enrichment status is `pending`. Summary and score initially are `null`; a score of zero is a valid completed score and must not mean pending.

## States and asynchronous flow

Keep application status separate from enrichment status:

- Application status: `received`. Do not implement hiring decisions.
- Enrichment status: `pending`, `processing`, `completed`, `failed`.

Flow:

1. `SubmitApplication` validates, creates, and persists the application.
2. It publishes `ApplicationSubmitted` only after persistence succeeds.
3. An event handler dispatches `EnrichApplication` through Messenger.
4. The worker loads the application and runs enrichment.
5. It persists summary, score, `enrichedAt`, and `completed` together.

The event and asynchronous command carry only the application identifier. Do not copy the CV or contact data into messages. Submission must not wait for the worker. The confirmation page states that the application was received and its analysis is pending.

Set enrichment to `processing` when work begins. For a transient failure, return it to `pending` and allow three retries in addition to the initial attempt. Once all retries are exhausted, mark it `failed` and retain the application. Integrate this transition with Messenger failure handling; do not mark the application as failed on every attempt. Show a clear user-facing status and log technical details without logging CV or contact data.

Ignore messages for already completed applications. Prevent concurrent message deliveries from producing incompatible writes by using an atomic processing claim or an equivalent mechanism. A read-check followed by save is insufficient under concurrency.

Do not implement an outbox for this PoC. Persistence and publication are not atomic. Document that limitation and retain the application when publication fails. Provide a manual recovery command that redispatches pending applications and stale processing applications using a configurable age threshold. Document that it must run while workers are stopped. Do not claim exactly-once delivery.

## Commands, queries, and events

- `SubmitApplication`: submit an application.
- `EnrichApplication`: perform and persist enrichment.
- `ListJobs`: retrieve the catalogue.
- `ListApplications`: search and filter applications.
- `GetApplicationDetail`: retrieve one application and handle missing IDs.
- `ApplicationSubmitted`: signal that an application was persisted.

## Mock enrichment provider

`CvEnricher` receives the CV text and job data and returns a short summary and an integer score from 0 to 100. The same inputs must produce the same output.

Each job defines skills with recognizable words or aliases. Match without case sensitivity while avoiding accidental substring matches. Account for terms such as PHP, TypeScript, and REST. Count each skill once. Calculate the score as the percentage of expected skills detected, rounded to an integer. Return zero when none are found. Every job must define at least one skill. Keep skill groupings and aliases explicit in data and tests.

Generate the summary from a template using detected skills and a short CV excerpt. Do not invent experience, education, or abilities. Label the result `Mock analysis` in the UI and explain the simulation in the README. Do not present the score as a real assessment of professional suitability.

Use controlled test doubles to exercise failures. Do not introduce magic words in CV text or artificial delays into domain logic.

## Queries and UI

- Sort applications by `appliedAt` descending, with a stable identifier tiebreaker.
- Search candidate name and email case-insensitively. Treat input as literal text and never as a user-provided regular expression.
- Combine filters for job, application status, and enrichment status.
- Display the score in the list and distinguish pending from a completed zero.
- Show candidate data, notes, job, original CV, summary, score, statuses, and relevant timestamps in the detail view.
- Update filters immediately and debounce search without a manual page reload.
- Poll for results only while enrichments remain pending or processing. Do not add WebSockets and do not poll indefinitely.
- Provide clear loading, error, empty, pending, processing, completed, and failed states.

Keep forms accessible, labels clear, and layouts usable on mobile. Do not add authentication, job management, or hiring workflow features. Use fictional data only in fixtures and demonstrations.

## Code quality and tests

Use `declare(strict_types=1)`, explicit types, and English names. Keep formatting consistent with PHP-CS-Fixer. Run PHPStan level 8 over `src` and `tests`, adding the Symfony integration required for accurate analysis. Do not globally suppress errors or generate a baseline to hide new problems.

Add tests proportionate to each stage:

- Unit tests for invariants, state transitions, mock scoring, and use cases.
- Integration tests for persistence, filters, forms, and messaging.
- When implemented, verify persistence before enrichment, successful results, retries, duplicate delivery, concurrent claims, and recovery.
- Isolate test databases and queues from demo data.
- Do not rely on arbitrary sleeps. When real asynchronous behavior is tested, use bounded polling for an observable condition.

The project must provide these commands:

- `make init`: build, install dependencies, and start the project; it must be repeatable and preserve data.
- `make test`: run PHPUnit.
- `make analyse`: run PHPStan.
- `make lint`: check formatting without modifying files.
- `make check`: run all checks and fail if any check fails.
- `make down`: stop services without deleting volumes.

These commands describe the agreed target and may not exist before Task 0. Update this file if their contract changes. Never report a check as passing when it could not run or when no meaningful tests were executed.

## Staged delivery

The detailed implementation plan, task boundaries, dependencies, and acceptance
criteria live in [`PLAN.md`](PLAN.md). Treat that file as the project roadmap.
Work on one task at a time and do not begin the next task until the current task
meets its acceptance criteria or the user explicitly changes the order.

0. Bootstrap the project, Docker services, dependencies, and quality tools.
1. Implement domain concepts and ports progressively with real use cases; avoid empty speculative classes.
2. Implement submission, validation, persistence, and event dispatch.
3. Implement asynchronous enrichment, mock scoring, states, and recovery.
4. Implement list, filters, search, and detail.
5. Complete UX states and result refresh behavior.
6. Run full verification, add demo fixtures, and prepare delivery documentation.

For Task 0, verify the HTTP response, MongoDB and RabbitMQ connectivity, and one technical message travelling through the queue to a consumer. Do not implement business behavior merely to demonstrate infrastructure. Add meaningful smoke tests.

Before editing, inspect the repository and present a short plan for the active task. Preserve existing user changes. Work in reviewable steps and run relevant checks. Do not add dependencies or features outside the active scope. When blocked, explain the issue and choose the simplest alternative compatible with the requirements.

At completion, report changes, commands run, results, and outstanding limits. Do not push, merge, or perform remote changes unless the user explicitly asks.

## Documentation and delivery

Keep the README concise and evaluator-focused: host requirements, clean-clone startup, application URL, tests, equivalent Compose commands, and a short demo flow. Record assumptions, the mock nature of AI, and message recovery limits. Avoid an exhaustive class-by-class explanation. Do not commit secrets, `vendor`, caches, volumes, or real personal data.
