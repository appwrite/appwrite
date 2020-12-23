/// Swift Appwrite SDK
/// Produced by Appwrite SDK Generator
///


var client: Client = Client()

client
    .setEndpoint(endpoint: "https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject(value: "5df5acd0d48c2") // Your project ID

var teams: Teams =  Teams(client: client);

var result = teams.update(_teamId: "[TEAM_ID]", _name: "[NAME]");
