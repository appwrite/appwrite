import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let databases = Databases(client)

let attributeInteger = try await databases.updateIntegerAttribute(
    databaseId: "[DATABASE_ID]",
    collectionId: "[COLLECTION_ID]",
    key: "",
    required: xfalse,
    min: 0,
    max: 0,
    default: 0
)

