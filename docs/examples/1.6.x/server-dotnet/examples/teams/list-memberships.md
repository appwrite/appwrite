using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetSession(""); // The user session to authenticate with

Teams teams = new Teams(client);

MembershipList result = await teams.ListMemberships(
    teamId: "<TEAM_ID>",
    queries: new List<string>(), // optional
    search: "<SEARCH>" // optional
);