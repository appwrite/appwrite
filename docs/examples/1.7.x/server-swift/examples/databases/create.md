import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let databases = Databases(client)

let database = try await databases.create(
    databaseId: "<DATABASE_ID>",
    name: "<NAME>",
    enabled: false // optional
)

