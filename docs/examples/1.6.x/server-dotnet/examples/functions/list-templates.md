using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("&lt;YOUR_PROJECT_ID&gt;"); // Your project ID

Functions functions = new Functions(client);

TemplateFunctionList result = await functions.ListTemplates(
    runtimes: new List<string>(), // optional
    useCases: new List<string>(), // optional
    limit: 1, // optional
    offset: 0 // optional
);