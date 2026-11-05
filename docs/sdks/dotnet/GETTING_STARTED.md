## Getting Started

### Initialize and make an API request
Initialize the SDK with your Appwrite endpoint, project ID, and an API key. Find the endpoint and project ID in your project's **Settings** page, and create an API key with the scopes your code needs under **Overview > Integrations > API keys**. API keys grant server-level access, so keep them out of client-side code.

```csharp
using Appwrite;
using Appwrite.Services;

var client = new Client()
    .SetEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    .SetProject("<PROJECT_ID>")
    .SetKey("<API_KEY>");

var users = new Users(client);

var user = await users.Create(
    userId: ID.Unique(),
    email: "email@example.com",
    password: "password",
    name: "Walter O'Brien");

Console.WriteLine(user.Name);
```

If you self-host Appwrite, use your own endpoint, such as `https://appwrite.example.com/v1`. During local development with a self-signed certificate, call `.SetSelfSigned(true)` to skip certificate verification. Never use it in production.

### Error handling
The SDK throws an `AppwriteException` when a request fails. It exposes `Message`, `Code`, `Type`, and `Response` properties that you can use to inspect the error.

```csharp
try
{
    var user = await users.Create(
        userId: ID.Unique(),
        email: "email@example.com",
        password: "password",
        name: "Walter O'Brien");
}
catch (AppwriteException e)
{
    Console.WriteLine(e.Message);
}
```

### Preparing models for TablesDB
The SDK serializes request data with `System.Text.Json` and converts property names from `PascalCase` to `camelCase`. If your table's column names are not in `camelCase`, the serialized names will not match your columns.

Add the `JsonPropertyName` attribute to each property of your model so it serializes to the column name. For example, for a table with a `name` column (string) and a `release_date` column (datetime):

```csharp
using System.Text.Json.Serialization;

public class Movie
{
    [JsonPropertyName("name")]
    public string Name { get; set; }

    [JsonPropertyName("release_date")]
    public DateTime ReleaseDate { get; set; }
}
```

Pass the model as the row data:

```csharp
var tablesDB = new TablesDB(client);

var row = await tablesDB.CreateRow(
    databaseId: "<DATABASE_ID>",
    tableId: "<TABLE_ID>",
    rowId: ID.Unique(),
    data: new Movie { Name = "Spider-Man", ReleaseDate = new DateTime(2002, 5, 3) });
```

### Learn more
You can use the following resources to learn more and get help:
- 🚀 [.NET Quick Start](https://appwrite.io/docs/quick-starts/dotnet)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite .NET Playground](https://github.com/appwrite/playground-for-dotnet)
