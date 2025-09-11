import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let attributeLine = try await databases.updateLineAttribute(
    databaseId: "<DATABASE_ID>",
    collectionId: "<COLLECTION_ID>",
    key: "",
    required: false,
    default: [[1, 2], [3, 4], [5, 6]], // optional
    newKey: "" // optional
)

