using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetSession(""); // The user session to authenticate with

Storage storage = new Storage(client);

FileList result = await storage.ListFiles(
    bucketId: "<BUCKET_ID>",
    queries: new List<string>(), // optional
    search: "<SEARCH>" // optional
);