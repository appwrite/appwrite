using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Users users = new Users(client);

JWT result = await users.CreateJWT(
    userId: "<USER_ID>",
    sessionId: "<SESSION_ID>", // optional
    duration: 0 // optional
);