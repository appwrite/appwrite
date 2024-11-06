import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let messaging = Messaging(client)

let provider = try await messaging.updateSmtpProvider(
    providerId: "<PROVIDER_ID>",
    name: "<NAME>", // optional
    host: "<HOST>", // optional
    port: 1, // optional
    username: "<USERNAME>", // optional
    password: "<PASSWORD>", // optional
    encryption: .none, // optional
    autoTLS: false, // optional
    mailer: "<MAILER>", // optional
    fromName: "<FROM_NAME>", // optional
    fromEmail: "email@example.com", // optional
    replyToName: "<REPLY_TO_NAME>", // optional
    replyToEmail: "<REPLY_TO_EMAIL>", // optional
    enabled: false // optional
)

