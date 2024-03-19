using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Storage storage = new Storage(client);

Bucket result = await storage.CreateBucket(
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