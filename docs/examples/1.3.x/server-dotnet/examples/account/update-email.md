using Appwrite;
using Appwrite.Models;

Client client = new Client()
    .SetEndPoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ..."); // Your secret JSON Web Token

Account account = new Account(client);

User result = await account.UpdateEmail(
    email: "email@example.com",
    password: "password");