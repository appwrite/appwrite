using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Users users = new Users(client);

User result = await users.Create(
    userId: "<USER_ID>",
    email: "email@example.com", // optional
    phone: "+12065550100", // optional
    password: "", // optional
    name: "<NAME>" // optional
);