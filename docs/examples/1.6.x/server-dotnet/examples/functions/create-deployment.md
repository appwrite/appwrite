using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID
    .SetKey("&lt;YOUR_API_KEY&gt;"); // Your secret API key

Functions functions = new Functions(client);

Deployment result = await functions.CreateDeployment(
    functionId: "<FUNCTION_ID>",
    code: InputFile.FromPath("./path-to-files/image.jpg"),
    activate: false,
    entrypoint: "<ENTRYPOINT>", // optional
    commands: "<COMMANDS>" // optional
);