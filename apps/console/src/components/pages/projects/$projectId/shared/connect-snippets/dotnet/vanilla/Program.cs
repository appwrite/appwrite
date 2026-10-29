using System.Text.Json;
using Appwrite;
using Appwrite.Services;

var json = new JsonSerializerOptions { WriteIndented = true };

var client = new Client()
  .SetEndpoint(Environment.GetEnvironmentVariable("APPWRITE_ENDPOINT")!)
  .SetProject(Environment.GetEnvironmentVariable("APPWRITE_PROJECT_ID")!)
  .SetKey(Environment.GetEnvironmentVariable("APPWRITE_API_KEY")!);

var project = new Project(client);

var policy = await project.UpdatePasswordStrengthPolicy(
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
);

Console.WriteLine(JsonSerializer.Serialize(policy.ToMap(), json));

var policies = await project.ListPolicies();

Console.WriteLine(JsonSerializer.Serialize(policies.ToMap(), json));
