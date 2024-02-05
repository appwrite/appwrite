using Appwrite;
using Appwrite.Services;
using Appwrite.Models;
using Appwrite.Enums;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetSession(""); // The user session to authenticate with

var avatars = new Avatars(client);

byte[] result = await avatars.GetFlag(
    code: Flag.Afghanistan);