import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let attributeUrl = try await databases.createUrlAttribute(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    key: "",
    required: false,
    default: "https://example.com", // optional
    array: false // optional
)

