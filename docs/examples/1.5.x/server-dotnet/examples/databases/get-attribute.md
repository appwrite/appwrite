using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Databases databases = new Databases(client);

 result = await databases.GetAttribute(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    key: ""
);