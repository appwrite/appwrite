<?php

namespace App\Controller;

use Appwrite\Services\Project;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PolicyController extends AbstractController
{
  public function __construct(private Project $project)
  {
  }

  #[Route('/v1/policies', methods: ['PATCH'])]
  public function update(): JsonResponse
  {
    $policy = $this->project->updatePasswordStrengthPolicy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    );

    return $this->json($policy->toArray());
  }

  #[Route('/v1/policies', methods: ['GET'])]
  public function index(): JsonResponse
  {
    return $this->json($this->project->listPolicies()->toArray());
  }
}
