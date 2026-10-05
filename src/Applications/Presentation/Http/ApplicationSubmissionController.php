<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Http;

use App\Applications\Application\Command\SubmitApplication;
use App\Applications\Application\Command\SubmitApplicationHandler;
use App\Applications\Application\Query\ListJobs;
use App\Applications\Application\Query\ListJobsHandler;
use App\Applications\Presentation\Form\ApplicationSubmissionInput;
use App\Applications\Presentation\Form\ApplicationSubmissionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

final class ApplicationSubmissionController extends AbstractController
{
    public function form(
        Request $request,
        SubmitApplicationHandler $submitApplication,
        ListJobsHandler $listJobs,
    ): Response {
        $input = new ApplicationSubmissionInput();
        $form = $this->createForm(ApplicationSubmissionType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $submitApplication(new SubmitApplication(
                $this->requiredString($input->fullName, 'fullName'),
                $this->requiredString($input->email, 'email'),
                $input->phone,
                $this->requiredString($input->jobId, 'jobId'),
                $input->notes,
                $this->requiredString($input->cvText, 'cvText'),
            ));

            $this->addFlash('application_submission', [
                'applicationId' => $result->applicationId->value,
                'analysisQueued' => $result->analysisQueued,
            ]);

            return $this->privateResponse($this->redirectToRoute('application_submitted', ['applicationId' => $result->applicationId->value]));
        }

        return $this->privateResponse($this->render('applications/apply.html.twig', [
            'form' => $form,
            'jobs' => ($listJobs)(new ListJobs()),
        ], $form->isSubmitted() ? new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY) : null));
    }

    public function submitted(Request $request, string $applicationId): Response
    {
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException('The application submission flow requires a flash-aware session.');
        }

        foreach ($session->getFlashBag()->get('application_submission') as $submission) {
            if (\is_array($submission)
                && $applicationId === ($submission['applicationId'] ?? null)
                && \is_bool($submission['analysisQueued'] ?? null)) {
                return $this->privateResponse($this->render('applications/submitted.html.twig', [
                    'applicationId' => $applicationId,
                    'analysisQueued' => $submission['analysisQueued'],
                ]));
            }
        }

        return $this->privateResponse(new RedirectResponse($this->generateUrl('application_apply')));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function requiredString(?string $value, string $field): string
    {
        if (null === $value) {
            throw new \LogicException(\sprintf('The valid application form is missing required field "%s".', $field));
        }

        return $value;
    }
}
