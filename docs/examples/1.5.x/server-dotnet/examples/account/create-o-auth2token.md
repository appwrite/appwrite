using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>"); // Your project ID

Account account = new Account(client);

await account.CreateOAuth2Token(
    provider: OAuthProvider.Amazon,
    success: "https://example.com", // optional
    failure: "https://example.com", // optional
    scopes: new List<string>() // optional
);