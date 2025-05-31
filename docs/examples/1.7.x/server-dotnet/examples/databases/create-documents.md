using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Databases databases = new Databases(client);

DocumentList result = await databases.CreateDocuments(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documents: new List<object>()
);