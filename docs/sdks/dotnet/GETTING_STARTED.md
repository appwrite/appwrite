## Getting Started

### Initialize & Make API Request
Once you have installed the package, it is extremely easy to get started with the SDK; all you need to do is import the package in your code, set your Appwrite credentials, and start making API calls. Below is a simple example:

```csharp
using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
  .SetEndpoint("http://cloud.appwrite.io/v1")  // Make sure your endpoint is accessible
  .SetProject("5ff3379a01d25")                 // Your project ID
  .SetKey("cd868db89")                         // Your secret API key
  .SetSelfSigned();                            // Use only on dev mode with a self-signed SSL cert

var users = new Users(client);

var user = await users.Create(
    userId: ID.Unique(),
    email: "email@example.com",
    password: "password",
    name: "name");

Console.WriteLine(user.ToMap());
```

### Error Handling
The Appwrite .NET SDK raises an `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```csharp
var users = new Users(client);

try
{
    var user = await users.Create(
        userId: ID.Unique(),
        email: "email@example.com",
        password: "password",
        name: "name");
} 
catch (AppwriteException e)
{
    Console.WriteLine(e.Message);
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-server)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite .NET Playground](https://github.com/appwrite/playground-for-dotnet)