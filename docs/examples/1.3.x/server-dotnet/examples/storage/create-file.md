using Appwrite;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Storage storage = new Storage(client);

File result = await storage.CreateFile(
    bucketId: "[BUCKET_ID]",
    fileId: "[FILE_ID]",
    file: new File("./path-to-files/image.jpg"));