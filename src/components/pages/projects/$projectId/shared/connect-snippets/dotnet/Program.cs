using Appwrite;
using Appwrite.Services;

var client = new Client()
  .SetEndpoint(Environment.GetEnvironmentVariable("APPWRITE_ENDPOINT")!)
  .SetProject(Environment.GetEnvironmentVariable("APPWRITE_PROJECT_ID")!)
  .SetKey(Environment.GetEnvironmentVariable("APPWRITE_API_KEY")!);

var account = new Account(client);
var user = await account.GetAsync();
Console.WriteLine($"Hello, {user.Name}");
