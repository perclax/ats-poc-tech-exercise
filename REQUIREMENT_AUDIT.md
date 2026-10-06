# Final requirement audit

Source: [Viterbit technical exercise](https://github.com/viterbit/tech-exercise).
Task 6 remains pending until the provisional commit passes isolated clean-clone,
restart-persistence, and final browser review checks.

| Requirement | Assessment | Evidence |
| --- | --- | --- |
| Candidate form, job description, plain-text CV, automatic date and status | Satisfied | Submission functional tests and live HTTP/form smoke |
| Persist before asynchronous enrichment | Satisfied | Submission use-case and messaging tests; real RabbitMQ smoke |
| Concise CV summary | Satisfied | Existing deterministic matched-skill summary and unchanged enrichment unit tests |
| Position relevance score | Satisfied as a mock | Explicit skill groups and aliases; deterministic rounded percentage; zero-score tests |
| Store summary and score after completion | Satisfied | Persistence, enrichment lifecycle tests, live enrichment smoke |
| Newest-first list, immediate filters, candidate search | Satisfied | Read repository and controller tests; browser harness available for manual verification |
| Detail with original CV, candidate data, results, statuses and timestamps | Satisfied | Detail functional tests and escaped-content live smoke |
| DDD, hexagonal architecture, CQRS/events | Satisfied | Module/layer separation, focused ports, domain independence test |
| Unit and integration tests, quality tools | Satisfied in current workspace | 170 tests / 1,130 assertions; PHPStan level 8 and formatting pass |
| Straightforward local startup and delivery | Awaiting final verification | README and Makefile; isolated clean-clone run still pending |
| Fictional repeatable demo data | Satisfied in current workspace | Four exact approved records; first live run created four and second created zero; command tests cover preflight conflicts and progressed state |

CV excerpts were deliberately excluded by explicit user decision: the exercise
does not require them, and the original CV remains available in the detail view.
No summary format, matching, aliases, scoring, smoke expected summaries, or
existing completed application results were changed.

## Security and dependency review

- `composer audit --locked`: no vulnerability advisories found on 2026-10-06.
- Container tags and PHP extension versions are pinned; `composer.lock` is tracked.
  No forced AMD64 platform or latest tag is used. Container operating-system
  vulnerability scanning and native Linux execution have not been performed.
- Tracked paths contain no vendor directory, generated caches, volumes, or key
  files. Committed credentials are explicitly fictional local defaults.
- Server validation, CSRF, escaped output, literal search, and private/no-store
  browsing responses are covered by existing tests. The app has no authentication
  and is intended for local fictional data only.
- Application enrichment logs contain identifiers, attempt metadata, and error
  classes, rather than CV/contact payloads. Local development HTTP access logs can
  contain GET search parameters, as documented in README.
- PHPUnit rejects a demo database before running tests; destructive collection
  cleanup additionally checks the configured database. A negative bootstrap
  check with `MONGODB_DB=ats` confirmed refusal.
- Real queued smoke checks passed with exact-ID/correlation cleanup. Cleanup
  errors now fail the smoke run. No collection or queue purge is used.
- The complete workspace check passed on 2026-10-06: 170 tests with 1,130
  assertions, PHPStan level 8, formatting, Compose topology, MongoDB and RabbitMQ
  probes, live HTTP, asynchronous enrichment, and browsing smoke checks.

Persistence/publication remains non-atomic, with no outbox or exactly-once claim.
Recovery must run while workers are stopped. Browser visual/accessibility review
and the JavaScript assertion harness remain manual; PHP checks do not execute it.
