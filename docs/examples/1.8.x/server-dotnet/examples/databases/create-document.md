using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Databases databases = new Databases(client);

Document result = await databases.CreateDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: new {
        username = "walter.obrien",
        email = "walter.obrien@example.com",
        fullName = "Walter O'Brien",
        age = 30,
        isAdmin = false
    },
    permissions: ["read("any")"] // optional
);