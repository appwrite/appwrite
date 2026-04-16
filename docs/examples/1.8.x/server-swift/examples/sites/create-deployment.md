import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let sites = Sites(client)

let deployment = try await sites.createDeployment(
    siteId: "<SITE_ID>",
    code: InputFile.fromPath("file.png"),
    activate: false,
    installCommand: "<INSTALL_COMMAND>", // optional
    buildCommand: "<BUILD_COMMAND>", // optional
    outputDirectory: "<OUTPUT_DIRECTORY>" // optional
)

