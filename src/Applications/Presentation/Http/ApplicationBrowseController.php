<?php

declare(strict_types=1);

namespace App\Applications\Presentation\Http;

use App\Applications\Application\Query\GetApplicationDetail;
use App\Applications\Application\Query\GetApplicationDetailHandler;
use App\Applications\Application\Query\ListApplications;
use App\Applications\Application\Query\ListApplicationsHandler;
use App\Applications\Application\Query\ListJobs;
use App\Applications\Application\Query\ListJobsHandler;
use App\Applications\Domain\Application\ApplicationId;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplicationBrowseController extends AbstractController
{
    public function list(Request $request, ListApplicationsHandler $applications, ListJobsHandler $jobs): Response
    {
        $catalogue = $jobs(new ListJobs());
        $parameters = ApplicationListParameters::fromArray($request->query->all(), $catalogue);

        return $this->privateResponse($this->render('applications/list.html.twig', [
            'applications' => [] === $parameters->errors ? $applications(new ListApplications($parameters->criteria)) : [],
            'jobs' => $catalogue,
            'parameters' => $parameters,
        ], new Response(status: [] === $parameters->errors ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST)));
    }

    public function detail(Request $request, string $id, GetApplicationDetailHandler $applications, ListJobsHandler $jobs): Response
    {
        try {
            $applicationId = ApplicationId::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->error('Invalid application identifier.', Response::HTTP_BAD_REQUEST);
        }

        $application = $applications(new GetApplicationDetail($applicationId));
        if (null === $application) {
            return $this->error('Application not found.', Response::HTTP_NOT_FOUND);
        }
        $parameters = ApplicationListParameters::fromArray($request->query->all(), $jobs(new ListJobs()));

        return $this->privateResponse($this->render('applications/detail.html.twig', [
            'application' => $application,
            'listParameters' => $parameters->linkParameters(),
        ]));
    }

    private function error(string $message, int $status): Response
    {
        return $this->privateResponse($this->render('applications/read_error.html.twig', ['message' => $message], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
