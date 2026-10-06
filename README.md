# ATS PoC technical exercise

The application accepts candidate applications, stores them in MongoDB, and
processes identifier-only enrichment commands asynchronously through RabbitMQ.
The analysis is a deterministic local simulation: it matches explicit job skill
aliases and does not call an external AI service or assess professional
suitability.

The summary uses a deterministic template listing detected skill groups. Each
group contributes once to the rounded percentage score, including a valid zero
when no expected skills match. Identical inputs produce identical results.

The Applications module separates a framework-independent domain, application
use cases and ports, infrastructure adapters, and Twig/HTTP presentation.
Commands persist through MongoDB ODM; queries return view DTOs from MongoDB.
Symfony events dispatch identifier-only Messenger commands to the separate
RabbitMQ worker, which uses an atomic claim before processing.

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
docker compose exec -T app php bin/console app:applications:seed-demo --if-empty
```

`make init` creates the four fictional demo applications only when the entire
applications collection is empty. Rerunning it never adds demos to a nonempty
database or resets existing applications or named volumes.

URLs:

- Application form: <http://localhost:8080/apply>
- Application list: <http://localhost:8080/applications>
- Application detail: <http://localhost:8080/applications/{id}>
- HTTP health endpoint: <http://localhost:8080/health>
- RabbitMQ management: <http://localhost:15672> (`ats` / `ats-dev-password`)

To add missing demo applications manually, including in a nonempty database:

```bash
docker compose exec -T app php bin/console app:applications:seed-demo
```

The command is idempotent and dispatches no messages. It demonstrates completed
100 and zero scores, pending and failed analysis, repeated applications for one
email, and escaped HTML-like notes. It validates all four reserved IDs before
writing; a conflict is preserved and makes the command fail without creating
any missing records. Open the Applications page and search for `Demo` to inspect
the records.

The seeded pending application is created without an enrichment message and
intentionally remains pending. To advance it, stop the worker and run the
recovery command documented below before starting the worker again. If the
application has already progressed through the valid enrichment flow, rerunning
the seed command accepts its current state and does not reset it to pending.

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
./tests/Smoke/browse.sh
```

`make check` runs the PHP test suite, PHPStan at level 8, formatting checks,
focused Compose topology validation, and the live infrastructure, enrichment,
and browsing smoke checks.

PHPUnit refuses any MongoDB database other than `ats_test`. Tests use isolated
in-memory Messenger transports; live smoke checks use real RabbitMQ and clean up
only their exact fictional application IDs, verified against their correlation.
Infrastructure probes remain useful diagnostics and contain no candidate data.

Run the dependency advisory check inside the container:

```bash
docker compose exec -T app composer audit --locked
```

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

To return to the four-record fictional factory demo state:

```bash
make hard-reset
```

This is destructive: it runs `docker compose down --volumes --remove-orphans`,
then the initialization commands above. It deletes this Compose project's
MongoDB application data, RabbitMQ state, Composer vendor volume, and probe
receipts. Dependencies are reinstalled and the four demos are recreated.
Source files, Git data, Docker images, and other Compose projects' volumes are
preserved. No interactive confirmation is requested.

## Native execution (unverified alternative)

Docker is the recommended and verified setup for this exercise. It was chosen
for convenience and reproducibility, and to avoid requiring PHP, Composer,
MongoDB, RabbitMQ, and PHP extensions on the host, not because the application
depends on Docker. The native path below is for developers who already maintain
the required services locally. It was not executed as part of the verified
delivery path.

Native execution requires PHP 8.4.x, Composer 2.8.x, the `amqp`, `mongodb`,
`intl`, `ctype`, and `iconv` PHP extensions, MongoDB 8, and RabbitMQ 4. MongoDB
and RabbitMQ must already be installed, configured, and running.

Symfony loads the uncommitted `.env.local` file after `.env`, so it can override
the Docker-oriented service hostnames. For example:

```dotenv
MONGODB_URI=mongodb://127.0.0.1:27017
MONGODB_DB=ats
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@127.0.0.1:5672/%2f/messages
PROBE_RECEIPT_DIRECTORY=var/probes
DEFAULT_URI=http://127.0.0.1:8080
```

This example assumes MongoDB without authentication and RabbitMQ's local
`guest` user on the default `/` vhost. Adapt the DSNs when using authentication
or a custom vhost. Do not commit `.env.local`; values such as `APP_SECRET` may
continue to come from `.env`.

Install dependencies and initialize the transports, MongoDB schema, and demo
data:

```bash
composer install
php bin/console messenger:setup-transports
php bin/console doctrine:mongodb:schema:update
php bin/console app:applications:seed-demo --if-empty
```

Conditional seeding creates the four fictional demo applications only when the
applications collection is empty. Start the web server and leave its terminal
running:

```bash
php -S 127.0.0.1:8080 -t public public/router.php
```

In a second terminal, start the worker with a separate cache directory:

```bash
APP_CACHE_DIR="$PWD/var/cache/worker" \
php bin/console messenger:consume infrastructure_async enrichment_async \
    --time-limit=3600 \
    --memory-limit=128M \
    --no-interaction
```

The long-running worker must use a cache separate from the web application so
it does not retain references to compiled container files pruned by web-side
cache rebuilds. The environment-assignment syntax above assumes a
POSIX-compatible shell; other shells must set the same variable using their
native syntax.

Run the PHP tests and quality tools natively with:

```bash
APP_ENV=test APP_DEBUG=1 MONGODB_DB=ats_test php vendor/bin/phpunit
php vendor/bin/phpstan analyse
php vendor/bin/php-cs-fixer check --diff
```

PHPUnit still requires the isolated `ats_test` MongoDB database, while its
Messenger transports remain in memory. Docker-specific smoke scripts and
`make check` are outside this documented native path.

Stop the PHP server and worker in their terminals. MongoDB and RabbitMQ
lifecycle management depends on the local installation. There is deliberately
no native equivalent of `make hard-reset`, because a generic command could
delete MongoDB databases or RabbitMQ data belonging to other projects.

The `make init`, `make down`, `make hard-reset`, `make smoke`, and `make check`
commands remain Docker-oriented.

## Browse applications

Submit a fictional application, follow the confirmation link to its detail,
then open Applications. Use the GET filter form to combine job, application
status (`received`), and analysis status (`pending`, `processing`, `completed`,
`failed`). Search is a case-insensitive Unicode literal substring of candidate
name or email, limited to 254 characters. Invalid filter values return 400;
malformed application IDs return 400 and unknown canonical IDs return 404.

The list shows at most 100 newest matches, ordered by `appliedAt` and `_id`
descending. Narrow the filters to find older applications; there is no
pagination or total count. Literal substring searches can scan records.
Detail/back links preserve validated filters.
Completed zero scores display as `0/100`; unfinished analysis has no invented
result. Original CV and notes remain escaped text with preserved line breaks.
Job labels come from the current catalogue; a removed job displays its stored
ID and an unavailable-job label.

With JavaScript enabled, select filters update immediately and candidate search
updates after 300 ms of typing inactivity. Accepted filters appear in the URL;
Back/Forward restores them. Server-rendered results remain visible if refreshing
fails, with Try again and an ordinary results-page link.

Lists containing pending/processing applications and active detail pages refresh
after 3 seconds, then 3 seconds after each completed refresh. Automatic refreshing
stops when no active analysis remains or after 2 minutes. Hidden tabs pause work
without extending that deadline. A network error pauses automatic refreshing;
Try again uses the remaining time. After timeout, Refresh results checks once
without restarting automatic polling. These controls only refresh displayed
results; they do not retry analysis.

Without JavaScript, submission works normally, Apply filters submits the GET
form, and navigation/detail/back links remain ordinary links. Refresh the page
to check analysis results. The form's linked validation summary and field errors
are server-rendered; JavaScript additionally focuses the summary after an invalid
submission.

This unauthenticated evaluator demo displays personal-data fields. Use fictional
data only and do not expose it publicly with real candidate records. Search
text is a conventional GET parameter: a name or email appears in the URL and
browser history, and the PHP development server may include the request target
in its local access log. The application does not log search text, candidate
records, or CV content; MongoDB query logging and profiling are disabled.
Browsing responses use `Referrer-Policy: no-referrer` and
`Cache-Control: private, no-store`. A real deployment must configure
authentication, access controls, HTTPS, and access-log redaction.

`make smoke` includes a real form submission through RabbitMQ to list/detail
verification. It uses a unique fictional correlation, bounded waiting, and
removes only its exact application ID without purging collections or queues.

## Browser review

The dependency-free assertion harness is **manual browser-launched**, separate
from `make check`. Start its isolated server:

```bash
docker compose run --rm --no-deps -p 8081:8001 app \
  php -S 0.0.0.0:8001 -t tests/Browser tests/Browser/router.php
```

Open <http://localhost:8081/>. Stop this foreground server with Ctrl+C before
running `make smoke` or `make check`: concurrent app-service containers can
affect which container Compose selects for `exec app`.

Every scenario shows PASS/FAIL; failures also appear
in the console. It imports production modules with injected fetch responses and
deterministic timers, never calls the application, and uses fictional content.
No npm packages, extension, or build process is required. PHPUnit does not execute
JavaScript; `node --check` (when Node is already available) checks syntax only.

Separately review `/apply` and `/applications` at desktop and 375–390px widths,
with keyboard navigation, JavaScript disabled, and reduced motion. Check error
summary focus/links, long escaped text, Back/Forward, console/network activity,
and refresh errors. Observe the real 3-second first poll, no concurrent polls,
hidden-tab pause, completion/failure/navigation stopping, and full 2-minute
timeout followed by a manual refresh. Use a unique fictional application and
clean up only its exact ID. Visual and accessibility verification remains manual.
