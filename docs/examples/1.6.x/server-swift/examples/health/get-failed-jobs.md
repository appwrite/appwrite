import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let health = Health(client)

let healthQueue = try await health.getFailedJobs(
    name: .v1Database,
    threshold: 0 // optional
)

