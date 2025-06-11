using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Users users = new Users(client);

JWT result = await users.CreateJWT(
    userId: "<USER_ID>",
    sessionId: "<SESSION_ID>", // optional
    duration: 0 // optional
);