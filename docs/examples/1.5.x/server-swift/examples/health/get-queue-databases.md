import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let health = Health(client)

let healthQueue = try await health.getQueueDatabases(
    name: "<NAME>", // optional
    threshold: 0 // optional
)

