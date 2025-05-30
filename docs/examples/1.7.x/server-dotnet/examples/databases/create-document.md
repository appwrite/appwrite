using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetSession("") // The user session to authenticate with
    .SetKey("<YOUR_API_KEY>") // Your secret API key
    .SetJWT("<YOUR_JWT>"); // Your secret JSON Web Token

Databases databases = new Databases(client);

Document result = await databases.CreateDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: [object],
    permissions: ["read("any")"] // optional
);