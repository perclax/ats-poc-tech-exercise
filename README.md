# ATS PoC technical exercise

The application accepts candidate applications, stores them in MongoDB, and
processes identifier-only enrichment commands asynchronously through RabbitMQ.
The analysis is a deterministic local simulation: it matches explicit job skill
aliases and does not call an external AI service or assess professional
suitability.

## Requirements

- Docker with Docker Compose v2
- Make (optional convenience wrapper)

Host PHP and Composer are not required. The setup uses native multi-architecture
images and does not force an `amd64` platform.

## Start the project

```bash
make init
```

Equivalent Docker Compose commands:

```bash
docker compose build
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
docker compose up -d --wait
docker compose exec -T app php bin/console messenger:setup-transports
docker compose exec -T app php bin/console doctrine:mongodb:schema:update
```

`make init` is safe to run again. It neither removes nor resets named volumes.

URLs:

- Application form: <http://localhost:8080/apply>
- HTTP health endpoint: <http://localhost:8080/health>
- RabbitMQ management: <http://localhost:15672> (`ats` / `ats-dev-password`)

The committed credentials are local development defaults only. If they are
changed for local use, keep the RabbitMQ container values and
`MESSENGER_TRANSPORT_DSN` consistent.

## Infrastructure verification

Verify MongoDB connectivity without creating an application document:

```bash
docker compose exec app php bin/console app:probe:mongodb
```

Publish a correlation-specific technical message to RabbitMQ and wait for the
worker to consume it:

```bash
docker compose exec app php bin/console app:probe:rabbitmq
```

The RabbitMQ command uses a bounded wait, removes its receipt after success, and
prunes only validated probe receipts older than one hour. It does not use ATS
application data.

Submitting an application stores it before queueing enrichment. If queueing
fails, the confirmation reports that the stored application still needs
recovery. Persistence and message publication are intentionally not atomic in
this proof of concept.

Pending messages and processing attempts left by a worker crash can be
recovered while workers are stopped:

```bash
docker compose stop worker
docker compose exec -T app php bin/console app:applications:recover-enrichments --dry-run
docker compose exec -T app php bin/console app:applications:recover-enrichments
docker compose start worker
```

The default stale threshold is 900 seconds and the default batch limit is 100;
override them with `--stale-after` and `--limit`. Repeated recovery is state-safe
and uses at-least-once dispatch: it can enqueue duplicate messages for an
application that remains pending, while the atomic MongoDB claim makes those
duplicates harmless. It does not provide exactly-once dispatch or queue-level
idempotency. Completed and failed applications are never recovered.

## Tests and quality checks

```bash
make test
make analyse
make lint
make check
```

Equivalent commands:

```bash
docker compose run --rm -e APP_ENV=test -e APP_DEBUG=1 -e MONGODB_DB=ats_test app php vendor/bin/phpunit
docker compose run --rm app php vendor/bin/phpstan analyse
docker compose run --rm app php vendor/bin/php-cs-fixer check --diff
./tests/Smoke/compose.sh
docker compose exec -T app php bin/console app:probe:mongodb
docker compose exec -T app php bin/console app:probe:rabbitmq
./tests/Smoke/http.sh
./tests/Smoke/enrichment.sh
```

`make check` runs the PHP test suite, PHPStan at level 8, formatting checks,
focused Compose topology validation, and the live infrastructure and enrichment
smoke checks.

## Stop the environment

```bash
make down
```

Equivalent command:

```bash
docker compose down
```

This stops and removes containers and the project network while preserving the
MongoDB, RabbitMQ, Composer dependency, and probe named volumes. Do not add `-v`
unless deletion of local persisted data is intentional.
