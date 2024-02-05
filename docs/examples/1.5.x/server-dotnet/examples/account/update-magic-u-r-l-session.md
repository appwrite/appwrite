using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;
using Appwrite.Enums;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2"); // Your project ID

var account = new Account(client);

Session result = await account.UpdateMagicURLSession(
    userId: "[USER_ID]",
    secret: "[SECRET]");