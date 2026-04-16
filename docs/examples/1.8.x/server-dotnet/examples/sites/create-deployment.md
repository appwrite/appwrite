using Appwrite;
using Appwrite.Models;
using Appwrite.Services;

Client client = new Client()
    .SetEndPoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .SetProject("<YOUR_PROJECT_ID>") // Your project ID
    .SetKey("<YOUR_API_KEY>"); // Your secret API key

Sites sites = new Sites(client);

Deployment result = await sites.CreateDeployment(
    siteId: "<SITE_ID>",
    code: InputFile.FromPath("./path-to-files/image.jpg"),
    activate: false,
    installCommand: "<INSTALL_COMMAND>", // optional
    buildCommand: "<BUILD_COMMAND>", // optional
    outputDirectory: "<OUTPUT_DIRECTORY>" // optional
);