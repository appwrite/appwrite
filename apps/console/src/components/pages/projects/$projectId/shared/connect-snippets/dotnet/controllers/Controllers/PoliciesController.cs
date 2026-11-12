using Appwrite.Services;
using Microsoft.AspNetCore.Mvc;

namespace ConnectQa.Controllers;

[ApiController]
[Route("v1/policies")]
public class PoliciesController(Project project) : ControllerBase
{
  [HttpPatch]
  public async Task<IActionResult> UpdatePolicy()
  {
    var policy = await project.UpdatePasswordStrengthPolicy(
      min: 8,
      uppercase: true,
      number: true,
      symbols: true
    );

    return Ok(policy.ToMap());
  }

  [HttpGet]
  public async Task<IActionResult> ListPolicies()
  {
    var policies = await project.ListPolicies();

    return Ok(policies.ToMap());
  }
}
