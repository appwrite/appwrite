using Appwrite;
using Appwrite.Models;
using Appwrite.Services;
using Appwrite.Permission;
using Appwrite.Role;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Storage storage = new Storage(client);

File result = await storage.UpdateFile(
    bucketId: "<BUCKET_ID>",
    fileId: "<FILE_ID>",
    name: "<NAME>", // optional
    permissions: new List<string> { Permission.Read(Role.Any()) } // optional
);