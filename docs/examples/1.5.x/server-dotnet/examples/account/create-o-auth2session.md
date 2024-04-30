using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2"); // Your project ID

Account account = new Account(client);

await account.CreateOAuth2Session(
    provider: OAuthProvider.Amazon,
    success: "https://example.com", // optional
    failure: "https://example.com", // optional
    scopes: new List<string>() // optional
);