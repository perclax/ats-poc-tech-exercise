<?php

declare(strict_types=1);

use App\Applications\Domain\Application\ApplicationId;
use Symfony\Component\DomCrawler\Crawler;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (!isset($argv[1], $argv[2])) {
    throw new RuntimeException('Usage: verify-browse.php <application-id> <fictional-correlation>');
}
$id = ApplicationId::fromString($argv[1])->value;
$correlation = $argv[2];
if (1 !== preg_match('/\A[0-9a-f]{16}\z/', $correlation)) {
    throw new RuntimeException('Invalid smoke correlation.');
}
$baseUrl = 'http://127.0.0.1:8000';
$fetch = static function (string $path) use ($baseUrl): string {
    $body = file_get_contents($baseUrl.$path, false, stream_context_create(['http' => ['timeout' => 5]]));
    if (false === $body || !isset($http_response_header[0]) || !str_contains($http_response_header[0], ' 200 ')) {
        throw new RuntimeException('A browsing smoke HTTP request failed.');
    }

    return $body;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$parameters = ['search' => $correlation, 'job' => 'backend-developer', 'applicationStatus' => 'received', 'enrichmentStatus' => 'completed'];
$query = http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);
$listHtml = $fetch('/applications?'.$query);
$list = new Crawler($listHtml);
$row = $list->filter('.application-card[data-application-id="'.$id.'"]');
$assert(1 === $list->filter('#application-results')->count(), 'The refreshable results region was missing.');
$assert(1 === $row->count(), 'The exact smoke application was not found in the list.');
$link = $row->filter('a')->attr('href');
$assert('/applications/'.$id.'?'.$query === $link, 'The exact detail link did not preserve validated filters.');
$assert(str_contains($row->text(), 'Backend Developer') && str_contains($row->text(), 'Completed') && str_contains($row->text(), '100/100'), 'The list did not display the expected enrichment.');
if (null === $link) {
    throw new RuntimeException('The detail link was missing.');
}
$detailHtml = $fetch($link);
$detail = new Crawler($detailHtml);
$assert(1 === $detail->filter('#application-analysis[data-enrichment-status="completed"]')->count(), 'The completed analysis region was missing.');
$assert(str_contains($detail->text(), 'Mock analysis: Matched 4 of 4 expected skill groups: PHP, Symfony, Databases, REST APIs.'), 'The expected deterministic summary was missing.');
$assert(str_contains($detail->text(), 'Backend Developer') && str_contains($detail->text(), 'Completed') && str_contains($detail->text(), '100/100'), 'The detail enrichment was incorrect.');
$marker = '<script>'.$correlation.'</script>';
foreach ([$listHtml, $detailHtml] as $html) {
    $assert(!str_contains($html, $marker) && str_contains($html, '&lt;script&gt;'.$correlation.'&lt;/script&gt;'), 'Correlation-specific content was not escaped.');
    $assert(!str_contains($html, 'processingAttemptId') && !str_contains($html, 'processingStartedAt'), 'Technical processing fields were exposed.');
}
$assert("PHP, Symfony, MongoDB, and REST API.\n".$marker === $detail->filter('.cv-text')->text(null, false), 'Original CV text or line breaks changed.');
$assert('/applications?'.$query === $detail->filter('.back-to-list')->attr('href'), 'The filtered back link was incorrect.');
echo "Exact application list, detail, escaped content, and filtered navigation verified.\n";
