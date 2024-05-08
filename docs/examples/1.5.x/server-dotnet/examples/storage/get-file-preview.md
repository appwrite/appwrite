using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetSession(""); // The user session to authenticate with

Storage storage = new Storage(client);

byte[] result = await storage.GetFilePreview(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    width: 0, // optional
    height: 0, // optional
    gravity: ImageGravity.Center, // optional
    quality: 0, // optional
    borderWidth: 0, // optional
    borderColor: "", // optional
    borderRadius: 0, // optional
    opacity: 0, // optional
    rotation: -360, // optional
    background: "", // optional
    output: ImageFormat.Jpg // optional
);