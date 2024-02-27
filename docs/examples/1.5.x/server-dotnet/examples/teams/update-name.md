using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetSession(""); // The user session to authenticate with

Teams teams = new Teams(client);

Team result = await teams.UpdateName(
    teamId: "<TEAM_ID>",
    name: "<NAME>"
);