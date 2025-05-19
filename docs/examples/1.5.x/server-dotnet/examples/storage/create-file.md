using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Storage storage = new Storage(client);

File result = await storage.CreateFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    file: InputFile.FromPath("./path-to-files/image.jpg"),
    permissions: ["read("any")"] // optional
);