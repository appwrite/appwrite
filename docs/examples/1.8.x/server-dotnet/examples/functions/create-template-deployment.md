using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Functions functions = new Functions(client);

Deployment result = await functions.CreateTemplateDeployment(
    functionId: "<FUNCTION_ID>",
    repository: "<REPOSITORY>",
    owner: "<OWNER>",
    rootDirectory: "<ROOT_DIRECTORY>",
    type: TemplateReferenceType.Commit,
    reference: "<REFERENCE>",
    activate: false // optional
);