using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2"); // Your project ID

Account account = new Account(client);

Token result = await account.CreateEmailToken(
    userId: "<USER_ID>",
    email: "email@example.com",
    phrase: false // optional
);