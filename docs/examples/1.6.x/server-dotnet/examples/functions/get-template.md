using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>"); // Your project ID

Functions functions = new Functions(client);

TemplateFunction result = await functions.GetTemplate(
    templateId: "<TEMPLATE_ID>"
);