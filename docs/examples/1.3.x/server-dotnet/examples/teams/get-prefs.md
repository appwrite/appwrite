using Appwrite;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ..."); // Your secret JSON Web Token

var teams = new Teams(client);

Preferences result = await teams.GetPrefs(
    teamId: "[TEAM_ID]");