using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Users users = new Users(client);

Target result = await users.CreateTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    providerType: MessagingProviderType.Email,
    identifier: "<IDENTIFIER>",
    providerId: "<PROVIDER_ID>", // optional
    name: "<NAME>" // optional
);