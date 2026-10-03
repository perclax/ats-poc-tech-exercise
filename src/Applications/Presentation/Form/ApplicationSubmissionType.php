<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Form;

use App\Applications\Application\Query\ListJobs;
use App\Applications\Application\Query\ListJobsHandler;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApplicationSubmissionInput> */
final class ApplicationSubmissionType extends AbstractType
{
    public function __construct(private readonly ListJobsHandler $listJobs)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (($this->listJobs)(new ListJobs()) as $job) {
            $choices[$job->title] = $job->id;
        }

        $builder
            ->add('fullName', TextType::class, ['label' => 'Full name', 'empty_data' => ''])
            ->add('email', EmailType::class, ['label' => 'Email', 'empty_data' => ''])
            ->add('phone', TextType::class, ['label' => 'Phone (optional)', 'required' => false])
            ->add('jobId', ChoiceType::class, [
                'label' => 'Job',
                'placeholder' => 'Choose a job',
                'choices' => $choices,
                'empty_data' => '',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes (optional)',
                'required' => false,
                'trim' => false,
            ])
            ->add('cvText', TextareaType::class, [
                'label' => 'CV text',
                'trim' => false,
                'empty_data' => '',
                'attr' => ['rows' => 14],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Submit application']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApplicationSubmissionInput::class,
            'csrf_protection' => true,
            'csrf_token_id' => 'application_submission',
        ]);
    }
}
