using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Users users = new Users(client);

User result = await users.CreatePHPassUser(
    userId: "<USER_ID>",
    email: "email@example.com",
    password: "password",
    name: "<NAME>" // optional
);