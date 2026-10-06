<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Http;

use App\Applications\Application\Query\ApplicationSearchCriteria;
use App\Applications\Application\Query\JobView;
use App\Applications\Domain\Application\ApplicationStatus;
use App\Applications\Domain\Enrichment\EnrichmentStatus;
use App\Applications\Domain\Job\JobId;

final readonly class ApplicationListParameters
{
    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function __construct(
        public ApplicationSearchCriteria $criteria,
        public array $values,
        public array $errors,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @param list<JobView>        $jobs
     */
    public static function fromArray(array $parameters, array $jobs): self
    {
        $values = [];
        $errors = [];
        foreach (['search', 'job', 'applicationStatus', 'enrichmentStatus'] as $name) {
            $value = \array_key_exists($name, $parameters) ? $parameters[$name] : '';
            if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                $errors[$name] = 'Enter a valid text value.';
                $values[$name] = '';
                continue;
            }
            if ('search' === $name && str_contains($value, "\0")) {
                $errors[$name] = 'Search contains an invalid character.';
                $values[$name] = '';
                continue;
            }
            $values[$name] = trim($value);
        }

        if (mb_strlen($values['search'], 'UTF-8') > 254) {
            $errors['search'] = 'Search must be at most 254 characters.';
        }
        $jobId = null;
        if ('' !== $values['job']) {
            foreach ($jobs as $job) {
                if ($job->id === $values['job']) {
                    $jobId = new JobId($job->id);
                    break;
                }
            }
            if (null === $jobId) {
                $errors['job'] = 'Choose an available job.';
            }
        }
        $applicationStatus = ApplicationStatus::tryFrom($values['applicationStatus']);
        if ('' !== $values['applicationStatus'] && null === $applicationStatus) {
            $errors['applicationStatus'] = 'Choose a valid application status.';
        }
        $enrichmentStatus = EnrichmentStatus::tryFrom($values['enrichmentStatus']);
        if ('' !== $values['enrichmentStatus'] && null === $enrichmentStatus) {
            $errors['enrichmentStatus'] = 'Choose a valid enrichment status.';
        }

        return new self(new ApplicationSearchCriteria(
            '' === $values['search'] || isset($errors['search']) ? null : $values['search'],
            $jobId,
            $applicationStatus,
            $enrichmentStatus,
        ), $values, $errors);
    }

    /** @return array<string, string> */
    public function linkParameters(): array
    {
        return array_filter($this->values, fn (string $value, string $name): bool => '' !== $value && !isset($this->errors[$name]), \ARRAY_FILTER_USE_BOTH);
    }
}
