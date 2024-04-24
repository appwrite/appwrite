using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Messaging messaging = new Messaging(client);

Message result = await messaging.UpdateEmail(
    messageId: "<MESSAGE_ID>",
    topics: new List<string>(), // optional
    users: new List<string>(), // optional
    targets: new List<string>(), // optional
    subject: "<SUBJECT>", // optional
    content: "<CONTENT>", // optional
    draft: false, // optional
    html: false, // optional
    cc: new List<string>(), // optional
    bcc: new List<string>(), // optional
    scheduledAt: "" // optional
);