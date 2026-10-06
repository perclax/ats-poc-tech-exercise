#!/bin/sh
set -eu

temporary_directory="$(mktemp -d)"
trap 'rm -rf "$temporary_directory"' EXIT

base_url="http://localhost:8080"

css_status="$(curl --silent --show-error --output "$temporary_directory/css-body" --dump-header "$temporary_directory/css-headers" --write-out '%{http_code}' "$base_url/styles/application.css")"
test "$css_status" = '200'
grep -Eiq '^content-type: *text/css' "$temporary_directory/css-headers"
grep -Fq ':root {' "$temporary_directory/css-body"
if grep -Eiq 'fatal error|<!doctype html|<html' "$temporary_directory/css-body"; then
    echo 'The stylesheet response contains PHP or HTML output.' >&2
    exit 1
fi

apply_status="$(curl --silent --show-error --output "$temporary_directory/apply-body" --write-out '%{http_code}' "$base_url/apply")"
test "$apply_status" = '200'
grep -Fq '<h1>Apply for a role</h1>' "$temporary_directory/apply-body"

health_status="$(curl --silent --show-error --output "$temporary_directory/health-body" --write-out '%{http_code}' "$base_url/health")"
test "$health_status" = '200'
grep -Fq '"status":"ok"' "$temporary_directory/health-body"

missing_status="$(curl --silent --show-error --output "$temporary_directory/missing-body" --write-out '%{http_code}' "$base_url/styles/does-not-exist.css")"
test "$missing_status" = '404'
if grep -Eiq 'fatal error|callable object expected|invalid return value' "$temporary_directory/missing-body"; then
    echo 'A missing asset exposed a PHP Runtime fatal error.' >&2
    exit 1
fi

nul_status="$(curl --silent --show-error --output "$temporary_directory/nul-body" --write-out '%{http_code}' "$base_url/applications?search=a%00b")"
test "$nul_status" = '400'
grep -Fq 'Search contains an invalid character.' "$temporary_directory/nul-body"
tr -d '\000' < "$temporary_directory/nul-body" > "$temporary_directory/nul-body-without-nul"
if ! cmp -s "$temporary_directory/nul-body" "$temporary_directory/nul-body-without-nul"; then
    echo 'The invalid search response contains a raw NUL.' >&2
    exit 1
fi

echo 'Live HTTP routing verified: CSS 200, application 200, health 200, missing asset 404, embedded-NUL search 400.'
