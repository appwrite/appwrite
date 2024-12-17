using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Avatars avatars = new Avatars(client);

byte[] result = await avatars.GetBrowser(
    code: Browser.AvantBrowser,
    width: 0, // optional
    height: 0, // optional
    quality: 0 // optional
);