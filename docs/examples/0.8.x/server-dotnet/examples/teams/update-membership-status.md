using Appwrite;

Client client = new Client();

client
  .SetEndPoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .SetProject("5df5acd0d48c2") // Your project ID
  .SetJWT("eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ...") // Your secret JSON Web Token
;

Teams teams = new Teams(client);

HttpResponseMessage result = await teams.UpdateMembershipStatus("[TEAM_ID]", "[INVITE_ID]", "[USER_ID]", "[SECRET]");
