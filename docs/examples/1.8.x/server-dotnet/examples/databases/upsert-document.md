using Appwrite;
using Appwrite.Models;
using Appwrite.Services;
using Appwrite.Permission;
using Appwrite.Role;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Databases databases = new Databases(client);

Document result = await databases.UpsertDocument(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    documentId: "<DOCUMENT_ID>",
    data: [object],
    permissions: new List<string> { Permission.Read(Role.Any()) }, // optional
    transactionId: "<TRANSACTION_ID>" // optional
);