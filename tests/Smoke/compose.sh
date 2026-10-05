#!/bin/sh
set -eu

services="$(docker compose config --services)"
for required_service in app worker mongodb rabbitmq; do
    echo "$services" | grep -qx "$required_service" || {
        echo "Missing required service: $required_service" >&2
        exit 1
    }
done

images="$(docker compose config --images)"
echo "$images" | grep -qx 'ats-poc-php:8.4.25'
echo "$images" | grep -qx 'mongo:8.0.30-noble'
echo "$images" | grep -qx 'rabbitmq:4.2.9-management'
if echo "$images" | grep -Eq '(^|:)latest$'; then
    echo 'Unpinned latest image found.' >&2
    exit 1
fi

volumes="$(docker compose config --volumes)"
for required_volume in mongodb_data probe_data rabbitmq_data vendor_data; do
    echo "$volumes" | grep -qx "$required_volume" || {
        echo "Missing required volume: $required_volume" >&2
        exit 1
    }
done

configuration="$(docker compose config)"
if echo "$configuration" | grep -Eq 'platform:.*amd64'; then
    echo 'An amd64 platform override was found.' >&2
    exit 1
fi

healthcheck_count="$(echo "$configuration" | grep -c '^    healthcheck:')"
test "$healthcheck_count" -eq 4 || {
    echo "Expected four service health checks, found $healthcheck_count." >&2
    exit 1
}

worker_configuration="$(echo "$configuration" | sed -n '/^  worker:/,/^  [a-z][a-z]*:/p')"
echo "$worker_configuration" | grep -Fq -- '- infrastructure_async'
echo "$worker_configuration" | grep -Fq -- '- enrichment_async'
if echo "$worker_configuration" | grep -Fq -- '- failed'; then
    echo 'The normal worker must not consume the failure transport.' >&2
    exit 1
fi

echo 'Docker Compose topology verified.'
