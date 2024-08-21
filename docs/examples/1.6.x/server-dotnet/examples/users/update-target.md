using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Users users = new Users(client);

Target result = await users.UpdateTarget(
    userId: "<USER_ID>",
    targetId: "<TARGET_ID>",
    identifier: "<IDENTIFIER>", // optional
    providerId: "<PROVIDER_ID>", // optional
    name: "<NAME>" // optional
);