## Getting Started

### Initialize & Make API Request
Once you add the dependencies, its extremely easy to get started with the SDK; All you need to do is import the package in your code, set your Appwrite credentials, and start making API calls. Below is a simple example:

```csharp
using Appwrite;

static async Task Main(string[] args)
{
  var client = Client();

  client
    .setEndpoint('http://[HOSTNAME_OR_IP]/v1') // Make sure your endpoint is accessible
    .setProject('5ff3379a01d25') // Your project ID
    .setKey('cd868c7af8bdc893b4...93b7535db89')
    .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
  ;

  var users = Users(client);

  try {
    var request = await users.create('email@example.com', 'password', 'name');
    var response = await request.Content.ReadAsStringAsync();
    Console.WriteLine(response);
  } catch (AppwriteException e) {
    Console.WriteLine(e.Message);
  }
}
```

### Error Handling
The Appwrite .NET SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```csharp
var users = Users(client);

try {
  var request = await users.create('email@example.com', 'password', 'name');
  var response = await request.Content.ReadAsStringAsync();
  Console.WriteLine(response);
} catch (AppwriteException e) {
  Console.WriteLine(e.Message);
}
```

### Learn more
You can use the followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Dart Playground](https://github.com/appwrite/playground-for-dotnet)
