<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use Nyholm\Psr7\Response;
use OpenRuntimes\Orchestrator\Enum\JobState;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Exception\NetworkException;

final class JobsService implements ClientInterface
{
    private const string JOBS = '/v1/jobs';

    /** @var array<string, JobState> */
    public array $jobs = [];

    /**
     * @param (\Closure(): void)|null $duringSubmission
     */
    public function __construct(
        private readonly Submission $submission,
        private readonly ?\Closure $duringSubmission = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        return match (true) {
            $request->getMethod() === 'POST' && $path === self::JOBS => $this->submit($request),
            $request->getMethod() === 'GET' && \str_starts_with($path, self::JOBS . '/') => $this->lookup(\rawurldecode(\substr($path, \strlen(self::JOBS . '/')))),
            default => $this->error(404, 'Route not found.'),
        };
    }

    private function submit(RequestInterface $request): ResponseInterface
    {
        if ($this->duringSubmission !== null) {
            ($this->duringSubmission)();
        }

        if ($this->submission !== Submission::LostBeforeAccepting) {
            $this->accept($request);
        }

        return match ($this->submission) {
            Submission::LostBeforeAccepting => throw new NetworkException($request, 'Connection closed before the job was accepted.'),
            Submission::LostAfterAccepting => throw new NetworkException($request, 'Connection closed after the job was accepted.'),
            Submission::FailedAfterAccepting => $this->error(500, 'Internal error.'),
        };
    }

    private function accept(RequestInterface $request): void
    {
        $payload = \json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->jobs[$payload['id']] = JobState::Accepted;
    }

    private function lookup(string $id): ResponseInterface
    {
        if (!isset($this->jobs[$id])) {
            return $this->error(404, 'Job not found.');
        }

        return new Response(200, body: \json_encode([
            'id' => $id,
            'status' => $this->jobs[$id]->value,
        ], JSON_THROW_ON_ERROR));
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return new Response($status, body: \json_encode(['error' => $message], JSON_THROW_ON_ERROR));
    }
}
