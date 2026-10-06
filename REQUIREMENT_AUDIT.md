# Final requirement audit

Source: [Viterbit technical exercise](https://github.com/viterbit/tech-exercise).
Task 6 completed after isolated clean-clone verification at provisional commit
`a059fd4549000f8a951d505e67799b1d72e4cc9b`, successful `make init` and
`make check` runs, two demo-seeding executions, shutdown/restart persistence
verification, and the final browser review.

| Requirement | Assessment | Evidence |
| --- | --- | --- |
| Candidate form, job description, plain-text CV, automatic date and status | Satisfied | Submission functional tests and live HTTP/form smoke |
| Persist before asynchronous enrichment | Satisfied | Submission use-case and messaging tests; real RabbitMQ smoke |
| Concise CV summary | Satisfied | Existing deterministic matched-skill summary and unchanged enrichment unit tests |
| Position relevance score | Satisfied as a mock | Explicit skill groups and aliases; deterministic rounded percentage; zero-score tests |
| Store summary and score after completion | Satisfied | Persistence, enrichment lifecycle tests, live enrichment smoke |
| Newest-first list, immediate filters, candidate search | Satisfied | Read repository and controller tests; manually browser-launched harness executed with 31/31 scenarios passing |
| Detail with original CV, candidate data, results, statuses and timestamps | Satisfied | Detail functional tests and escaped-content live smoke |
| DDD, hexagonal architecture, CQRS/events | Satisfied | Module/layer separation, focused ports, domain independence test |
| Unit and integration tests, quality tools | Satisfied in current workspace | 192 tests / 1,252 assertions; PHPStan level 8 and formatting pass |
| Straightforward local startup and delivery | Satisfied | Isolated clean-clone startup and full checks passed at provisional SHA `a059fd4549000f8a951d505e67799b1d72e4cc9b`; demo data persisted across shutdown and restart |
| Fictional repeatable demo data | Satisfied in current workspace | Four exact approved records; first live run created four and second created zero; command tests cover preflight conflicts and progressed state |

CV excerpts were deliberately excluded by explicit user decision: the exercise
does not require them, and the original CV remains available in the detail view.
At Task 6, no summary format, scoring algorithm, smoke expected summaries, or
existing completed application results were changed. The later REST alias
correction described below changes results for CVs containing the newly
supported `REST APIs` phrase; the scoring algorithm remains unchanged.

## Post-audit corrections

An independent final audit found and corrected four issues on 2026-10-06:

- The app and Messenger worker had shared `/app/var/cache/dev`. Existing runtime
  evidence showed `RestartCount=3` and a worker failure caused by a missing
  generated container file under that directory. The worker now uses the stable
  `/app/var/cache/worker` override, while the app remains on
  `/app/var/cache/dev` and tests remain on `/app/var/cache/test`. The override is
  lexically restricted to a child of the project cache directory and does not
  depend on the target already existing.
- The Backend Developer REST skill group now recognizes the explicit `rest api`,
  `rest apis`, and `restful` aliases. Boundary-aware matching remains unchanged:
  mixed case and punctuation match, all aliases still count the group once, and
  `the rest of the project` does not match.
- README now explains why the seeded pending application has no queued message,
  how to advance it through recovery while workers are stopped, and why reseeding
  preserves a valid progressed state.
- Application-list search rejects an embedded NUL at the HTTP parameter boundary
  and repeats the guard in the read repository. The exact
  `/applications?search=a%00b` request returns 400, executes no read query, and
  emits no raw NUL. Existing accepted search characters remain covered.

After the worker was recreated once to apply its dedicated cache configuration,
the same container survived five app cache clear/warmup and console/HTTP cycles,
the RabbitMQ probe, real asynchronous application enrichment, `make smoke`, and
two consecutive `make check` runs. Its identity remained
`5c5b3d3a505eba9d59e6541c3289727a7c505d45197d4d814a645a63c3410c8f`,
`StartedAt=2026-10-06T13:19:54.119846417Z`, and `RestartCount=0`; post-fix logs
contained the normal Messenger startup entry and no missing-container-file
error.

The post-audit regression result was 188 tests with 1,175 assertions, PHPStan
level 8 with no errors, no formatting changes required, and passing Compose
topology, MongoDB, RabbitMQ, HTTP, enrichment, and browsing smoke checks. Strict
Composer validation, the locked dependency audit, changed-PHP syntax checks,
all smoke-script syntax checks, and JavaScript module syntax checks also passed.

## Final evaluator polish

The final complete `make check` run passed with 192 tests and 1,252 assertions,
PHPStan level 8, formatting, and all live smoke checks. Conditional demo seeding
preserves nonempty collections, including progressed demos, without dispatching
messages. An isolated Compose project verified fresh initialization, repeated
initialization, and a destructive hard reset returning five records to the four
reserved demos. All four isolated volumes were removed and dependencies were
reinstalled; the original project's application data and volume identities were
verified unchanged after restoration. Chrome passed all 31 browser harness
scenarios and confirmed the visibly lowercase confirmation ID and canonical
detail link at 390 px width.

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
- The complete workspace check passed on 2026-10-06: 188 tests with 1,175
  assertions, PHPStan level 8, formatting, Compose topology, MongoDB and RabbitMQ
  probes, live HTTP, asynchronous enrichment, and browsing smoke checks.
- The browser harness was launched manually in a browser and passed all 31
  scenarios. Desktop and exact 390 px viewport reviews passed, including the
  responsive demo list, completed detail, deterministic result, and escaped
  HTML-like note. This does not constitute physical-device or real screen-reader
  certification.

Persistence/publication remains non-atomic, with no outbox or exactly-once claim.
Recovery must run while workers are stopped. The JavaScript assertion harness is
manually browser-launched and remains separate from the PHP checks.
