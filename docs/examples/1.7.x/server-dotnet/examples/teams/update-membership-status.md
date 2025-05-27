using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetSession(""); // The user session to authenticate with

Teams teams = new Teams(client);

Membership result = await teams.UpdateMembershipStatus(
    teamId: "<TEAM_ID>",
    membershipId: "<MEMBERSHIP_ID>",
    userId: "<USER_ID>",
    secret: "<SECRET>"
);