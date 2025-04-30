using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://example.com/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Tokens tokens = new Tokens(client);

ResourceToken result = await tokens.Update(
    tokenId: "<TOKEN_ID>",
    expire: "", // optional
    permissions: ["read("any")"] // optional
);