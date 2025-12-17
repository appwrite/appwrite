using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

Collection result = await databases.CreateCollection(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    name: "<NAME>",
    permissions: new List<string> { Permission.Read(Role.Any()) }, // optional
    documentSecurity: false, // optional
    enabled: false, // optional
    attributes: new List<object>(), // optional
    indexes: new List<object>() // optional
);