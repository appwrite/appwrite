using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Avatars avatars = new Avatars(client);

byte[] result = await avatars.GetScreenshot(
    url: "https://example.com",
    headers: [object], // optional
    viewportWidth: 1, // optional
    viewportHeight: 1, // optional
    scale: 0.1, // optional
    theme: Theme.Light, // optional
    userAgent: "<USER_AGENT>", // optional
    fullpage: false, // optional
    locale: "<LOCALE>", // optional
    timezone: Timezone.AfricaAbidjan, // optional
    latitude: -90, // optional
    longitude: -180, // optional
    accuracy: 0, // optional
    touch: false, // optional
    permissions: new List<string>(), // optional
    sleep: 0, // optional
    width: 0, // optional
    height: 0, // optional
    quality: -1, // optional
    output: Output.Jpg // optional
);