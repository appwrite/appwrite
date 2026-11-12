<?php

namespace App\Http\Controllers;

use Appwrite\Services\Project;
use Illuminate\Http\JsonResponse;

class PolicyController extends Controller
{
  public function __construct(private Project $project)
  {
  }

  public function update(): JsonResponse
  {
    $policy = $this->project->updatePasswordStrengthPolicy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    );

    return response()->json($policy->toArray());
  }

  public function index(): JsonResponse
  {
    return response()->json($this->project->listPolicies()->toArray());
  }
}
