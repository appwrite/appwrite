using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://example.com/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Functions functions = new Functions(client);

Deployment result = await functions.CreateTemplateDeployment(
    functionId: "<FUNCTION_ID>",
    repository: "<REPOSITORY>",
    owner: "<OWNER>",
    rootDirectory: "<ROOT_DIRECTORY>",
    version: "<VERSION>",
    activate: false // optional
);