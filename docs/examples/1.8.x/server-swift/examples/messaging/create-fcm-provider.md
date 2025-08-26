import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let provider = try await messaging.createFCMProvider(
    providerId: "<PROVIDER_ID>",
    name: "<NAME>",
    serviceAccountJSON: [:], // optional
    enabled: false // optional
)

