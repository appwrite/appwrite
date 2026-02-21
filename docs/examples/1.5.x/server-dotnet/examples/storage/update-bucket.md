using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

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