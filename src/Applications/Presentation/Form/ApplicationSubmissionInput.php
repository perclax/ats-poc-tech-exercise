<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Form;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ApplicationSubmissionInput
{
    #[Assert\NotBlank(message: 'Enter your full name.', normalizer: 'trim')]
    #[Assert\Length(max: 150, maxMessage: 'Full name must be at most {{ limit }} characters.')]
    public ?string $fullName = null;

    #[Assert\NotBlank(message: 'Enter your email address.', normalizer: 'trim')]
    #[Assert\Length(max: 254, maxMessage: 'Email must be at most {{ limit }} characters.')]
    #[Assert\Callback(callback: [self::class, 'validateEmail'])]
    public ?string $email = null;

    #[Assert\Length(max: 30, maxMessage: 'Phone must be at most {{ limit }} characters.')]
    public ?string $phone = null;

    #[Assert\NotBlank(message: 'Choose a job.')]
    public ?string $jobId = null;

    #[Assert\Length(max: 2_000, maxMessage: 'Notes must be at most {{ limit }} characters.')]
    public ?string $notes = null;

    #[Assert\NotBlank(message: 'Paste your CV text.', normalizer: 'trim')]
    #[Assert\Length(max: 30_000, maxMessage: 'CV text must be at most {{ limit }} characters.')]
    public ?string $cvText = null;

    public static function validateEmail(?string $email, ExecutionContextInterface $context): void
    {
        if (null !== $email && '' !== trim($email) && false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL)) {
            $context->buildViolation('Enter a valid email address.')->addViolation();
        }
    }
}
