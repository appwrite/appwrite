using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2"); // Your project ID

var account = new Account(client);

 result = await account.CreateOAuth2Session(
    provider: OAuthProvider.Amazon);