# ATS PoC

Minimal applicant tracking system: candidates apply with a plain-text CV, a
worker enriches each application asynchronously (mock AI summary and relevance
score), and recruiters browse, filter, and search applications.

Stack: PHP 8.4, Symfony 7, MongoDB (Doctrine ODM), RabbitMQ (Symfony Messenger),
Twig, Docker Compose.

## Requirements

Docker with Docker Compose v2. Make is optional. PHP and Composer are not needed
on the host.

## Run

```bash
make init
```

- Apply: <http://localhost:8080/apply>
- Applications: <http://localhost:8080/applications>
- RabbitMQ management: <http://localhost:15672> (`ats` / `ats-dev-password`)

`make init` can be rerun safely. Data lives in named volumes, and three
fictional demo applications are created only when the database is empty.
`make down` stops the services and keeps the data.

## Test

```bash
make test      # PHPUnit: unit, integration and functional tests (isolated `ats_test` database)
make analyse   # PHPStan level 8
make lint      # PHP-CS-Fixer, check only
make smoke     # End to end: submit the form, wait for the worker to complete the analysis
make check     # All of the above
```

<details>
<summary>Equivalent Docker Compose commands</summary>

```bash
# init
docker compose build
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose up -d --wait
docker compose exec -T app php bin/console messenger:setup-transports
docker compose exec -T app php bin/console doctrine:mongodb:schema:update
docker compose exec -T app php bin/console app:applications:seed-demo

# checks
docker compose run --rm -e APP_ENV=test -e APP_DEBUG=1 -e MONGODB_DB=ats_test app php vendor/bin/phpunit
docker compose run --rm app php vendor/bin/phpstan analyse
docker compose run --rm app php vendor/bin/php-cs-fixer check --diff
./tests/Smoke/enrichment.sh

# stop
docker compose down
```

</details>

## Demo flow

1. Open `/apply`, choose a job, and submit an application. The confirmation page
   says the analysis is pending.
2. Open `/applications`. The new application shows as pending, then as completed
   with a score once the worker finishes. The page refreshes itself while any
   analysis is in progress.
3. Combine the job and status filters, search by name or email, and open an
   application to see its details, original CV, summary, and score.

## Architecture

There is one `Applications` module with four layers:

- **Domain:** the `Application` aggregate, its value objects and enrichment states,
  and the `ApplicationSubmitted` event. It has no framework dependencies.
- **Application:** commands (`SubmitApplication`, `EnrichApplication`), queries
  (`ListApplications`, `GetApplicationDetail`, `ListJobs`), and ports.
- **Infrastructure:** MongoDB repositories, Messenger adapters, the
  version-controlled job catalogue, and the mock enricher.
- **Presentation:** Symfony controllers, forms, and Twig templates.

Flow: `SubmitApplication` stores the application, then publishes
`ApplicationSubmitted`. A listener dispatches `EnrichApplication`, which carries
only the application ID, to RabbitMQ. The worker atomically claims the
application (`pending` → `processing`), so duplicate deliveries are ignored.
It then stores the summary, score, and timestamp. Failures are retried three
times; after that, the application is marked `failed`.

Writes reconstitute the aggregate. Reads use queries that return view DTOs
directly from MongoDB.

## Mock AI

No external AI service is called. Each job lists expected skills with aliases.
The score is the percentage of those skills found in the CV, using
case-insensitive whole-word matching, and the summary lists the matched skills.
The result is deterministic and labelled "Mock analysis" in the UI. It is not a
real assessment of a candidate.

To mimic a real model call, the worker waits `MOCK_ENRICHMENT_LATENCY_MS`
(5 seconds by default; 0 in tests), so the pending and processing states are
visible in the UI.

## Known limitations

- Storing an application and publishing its message are not atomic (no outbox).
  If RabbitMQ is unavailable at submission time, the application is kept and
  stays `pending`.
- If a worker crashes mid-analysis, that application stays `processing`.
- There is no authentication, pagination, or job management, which is out of
  scope for this PoC. Use fictional data only.
