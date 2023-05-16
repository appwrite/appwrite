using Appwrite;
using Appwrite.Models;

var client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

var functions = new Functions(client);

Variable result = await functions.CreateVariable(
    functionId: "[FUNCTION_ID]",
    key: "[KEY]",
    value: "[VALUE]");