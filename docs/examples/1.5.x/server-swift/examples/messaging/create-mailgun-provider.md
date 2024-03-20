import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID
    .setKey("919c2d18fb5d4...a2ae413da83346ad2") // Your secret API key

let messaging = Messaging(client)

let provider = try await messaging.createMailgunProvider(
    providerId: "<PROVIDER_ID>",
    name: "<NAME>",
    apiKey: "<API_KEY>", // optional
    domain: "<DOMAIN>", // optional
    isEuRegion: false, // optional
    fromName: "<FROM_NAME>", // optional
    fromEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>", // optional
    replyToEmail: "email@example.com", // optional
    enabled: false // optional
)

