using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>"); // Your project ID

Account account = new Account(client);

User result = await account.Create(
    userId: "<USER_ID>",
    email: "email@example.com",
    password: "",
    name: "<NAME>" // optional
);