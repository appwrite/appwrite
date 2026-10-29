using Appwrite;
using Appwrite.Services;

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddSingleton(new Project(new Client()
  .SetEndpoint(Environment.GetEnvironmentVariable("APPWRITE_ENDPOINT")!)
  .SetProject(Environment.GetEnvironmentVariable("APPWRITE_PROJECT_ID")!)
  .SetKey(Environment.GetEnvironmentVariable("APPWRITE_API_KEY")!)));

var app = builder.Build();

app.MapPatch("/v1/policies", async (Project project) =>
{
  var policy = await project.UpdatePasswordStrengthPolicy(
    min: 8,
    uppercase: true,
    number: true,
    symbols: true
  );

  return Results.Ok(policy.ToMap());
});

app.MapGet("/v1/policies", async (Project project) =>
{
  var policies = await project.ListPolicies();

  return Results.Ok(policies.ToMap());
});

app.Run("http://localhost:5000");
