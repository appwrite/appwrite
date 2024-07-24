using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Storage storage = new Storage(client);

Bucket result = await storage.UpdateBucket(
    bucketId: "<BUCKET_ID>",
    name: "<NAME>",
    permissions: ["read("any")"], // optional
    fileSecurity: false, // optional
    enabled: false, // optional
    maximumFileSize: 1, // optional
    allowedFileExtensions: new List<string>(), // optional
    compression: .None, // optional
    encryption: false, // optional
    antivirus: false // optional
);