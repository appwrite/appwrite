using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Tokens tokens = new Tokens(client);

ResourceToken result = await tokens.CreateFileToken(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    expire: "" // optional
);