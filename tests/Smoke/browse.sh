#!/bin/sh
set -eu

temporary_directory="$(mktemp -d)"
application_id=''
cleanup() {
    cleanup_status=0
    if [ -n "$application_id" ]; then
        docker compose exec -T app php tests/Smoke/verify-enrichment.php cleanup "$application_id" "$correlation" >/dev/null || cleanup_status=1
    fi
    rm -rf "$temporary_directory"
    if [ "$cleanup_status" -ne 0 ]; then
        echo "Could not remove the exact browsing smoke application." >&2
        exit 1
    fi
}
trap cleanup EXIT

base_url='http://localhost:8080'
correlation="$(docker compose exec -T app php -r 'echo bin2hex(random_bytes(8));')"
cookie_jar="$temporary_directory/cookies"
apply_body="$temporary_directory/apply-body"
response_headers="$temporary_directory/response-headers"

curl --silent --show-error --cookie-jar "$cookie_jar" --output "$apply_body" "$base_url/apply"
csrf_token="$(sed -n 's/.*name="application_submission\[_token\]".*value="\([^"]*\)".*/\1/p' "$apply_body" | head -1)"
test -n "$csrf_token"

invalid_status="$(curl --silent --show-error \
    --cookie "$cookie_jar" \
    --output "$temporary_directory/invalid-body" \
    --write-out '%{http_code}' \
    --data-urlencode 'application_submission[fullName]=' \
    --data-urlencode 'application_submission[email]=' \
    --data-urlencode 'application_submission[jobId]=' \
    --data-urlencode 'application_submission[cvText]=' \
    --data-urlencode "application_submission[_token]=$csrf_token" \
    "$base_url/apply")"
test "$invalid_status" = '422'
grep -Fq 'id="validation-summary"' "$temporary_directory/invalid-body"
grep -Fq 'href="#application_submission_fullName"' "$temporary_directory/invalid-body"
grep -Fq 'aria-invalid="true"' "$temporary_directory/invalid-body"
grep -Fq 'aria-describedby="application_submission_fullName_error1"' "$temporary_directory/invalid-body"

status="$(curl --silent --show-error \
    --cookie "$cookie_jar" \
    --dump-header "$response_headers" \
    --output "$temporary_directory/submit-body" \
    --write-out '%{http_code}' \
    --request POST \
    --data-urlencode "application_submission[fullName]=Smoke Candidate <script>$correlation</script>" \
    --data-urlencode "application_submission[email]=smoke-$correlation@example.test" \
    --data-urlencode 'application_submission[phone]=' \
    --data-urlencode 'application_submission[jobId]=backend-developer' \
    --data-urlencode "application_submission[notes]=<script>$correlation</script>" \
    --data-urlencode "application_submission[cvText]=PHP, Symfony, MongoDB, and REST API.
<script>$correlation</script>" \
    --data-urlencode "application_submission[_token]=$csrf_token" \
    --data-urlencode 'application_submission[submit]=' \
    "$base_url/apply")"
test "$status" = '302'

location="$(awk 'BEGIN { IGNORECASE=1 } /^Location:/ { print $2 }' "$response_headers" | tr -d '\r' | tail -1)"
application_id="$(printf '%s' "$location" | sed -n 's#^.*/apply/submitted/\([0-9a-f-]*\)$#\1#p')"
test -n "$application_id"

expected_summary='Mock analysis: Matched 4 of 4 expected skill groups: PHP, Symfony, Databases, REST APIs.'

curl --silent --show-error --cookie "$cookie_jar" --output "$temporary_directory/confirmation" "$base_url$location"
grep -Fq "href=\"/applications/$application_id\"" "$temporary_directory/confirmation"
grep -Fq "<span class=\"application-id\">$application_id</span>" "$temporary_directory/confirmation"
docker compose exec -T app php tests/Smoke/verify-enrichment.php wait "$application_id" 100 "$expected_summary"
docker compose exec -T app php tests/Smoke/verify-browse.php "$application_id" "$correlation"
printf 'RabbitMQ-backed application browsing verified for fictional correlation %s.\n' "$correlation"
