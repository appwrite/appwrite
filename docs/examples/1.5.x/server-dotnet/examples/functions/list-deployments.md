using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Functions functions = new Functions(client);

DeploymentList result = await functions.ListDeployments(
    functionId: "<FUNCTION_ID>",
    queries: new List<string>(), // optional
    search: "<SEARCH>" // optional
);