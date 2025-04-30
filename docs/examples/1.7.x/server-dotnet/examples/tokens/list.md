using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://example.com/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Tokens tokens = new Tokens(client);

ResourceTokenList result = await tokens.List(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    queries: new List<string>() // optional
);