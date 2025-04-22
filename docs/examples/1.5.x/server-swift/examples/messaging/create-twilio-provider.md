import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let provider = try await messaging.createTwilioProvider(
    providerId: "<PROVIDER_ID>",
    name: "<NAME>",
    from: "+12065550100", // optional
    accountSid: "<ACCOUNT_SID>", // optional
    authToken: "<AUTH_TOKEN>", // optional
    enabled: false // optional
)

