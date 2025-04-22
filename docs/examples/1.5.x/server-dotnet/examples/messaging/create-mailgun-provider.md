using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Messaging messaging = new Messaging(client);

Provider result = await messaging.CreateMailgunProvider(
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
);