using Appwrite;
using Appwrite.Enums;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("5df5acd0d48c2") // Your project ID
    .SetKey("919c2d18fb5d4...a2ae413da83346ad2"); // Your secret API key

Functions functions = new Functions(client);

Function result = await functions.Update(
    functionId: "<FUNCTION_ID>",
    name: "<NAME>",
    runtime: .Node145, // optional
    execute: ["any"], // optional
    events: new List<string>(), // optional
    schedule: "", // optional
    timeout: 1, // optional
    enabled: false, // optional
    logging: false, // optional
    entrypoint: "<ENTRYPOINT>", // optional
    commands: "<COMMANDS>", // optional
    installationId: "<INSTALLATION_ID>", // optional
    providerRepositoryId: "<PROVIDER_REPOSITORY_ID>", // optional
    providerBranch: "<PROVIDER_BRANCH>", // optional
    providerSilentMode: false, // optional
    providerRootDirectory: "<PROVIDER_ROOT_DIRECTORY>" // optional
);