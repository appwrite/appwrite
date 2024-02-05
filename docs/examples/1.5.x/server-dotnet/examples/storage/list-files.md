using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetSession(""); // The user session to authenticate with

var storage = new Storage(client);

FileList result = await storage.ListFiles(
    bucketId: "[BUCKET_ID]");