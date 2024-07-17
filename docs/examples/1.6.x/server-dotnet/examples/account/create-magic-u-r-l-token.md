using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;"); // Your project ID

Account account = new Account(client);

Token result = await account.CreateMagicURLToken(
    userId: "<USER_ID>",
    email: "email@example.com",
    url: "https://example.com", // optional
    phrase: false // optional
);