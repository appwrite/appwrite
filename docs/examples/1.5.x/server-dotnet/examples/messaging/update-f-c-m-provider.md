using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

Provider result = await messaging.UpdateFCMProvider(
    providerId: "[PROVIDER_ID]",
    name: "[NAME]", // optional
    enabled: false, // optional
    serviceAccountJSON: [object] // optional
);