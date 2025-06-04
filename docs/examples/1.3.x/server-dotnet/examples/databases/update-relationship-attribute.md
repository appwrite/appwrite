using Appwrite;
using Appwrite.Services;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

var databases = new Databases(client);

AttributeRelationship result = await databases.UpdateRelationshipAttribute(
    databaseId: "[DATABASE_ID]",
    collectionId: "[COLLECTION_ID]",
    key: "");