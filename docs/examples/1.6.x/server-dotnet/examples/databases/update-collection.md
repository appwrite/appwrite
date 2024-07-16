using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Databases databases = new Databases(client);

Collection result = await databases.UpdateCollection(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    name: "<NAME>",
    permissions: ["read("any")"], // optional
    documentSecurity: false, // optional
    enabled: false // optional
);