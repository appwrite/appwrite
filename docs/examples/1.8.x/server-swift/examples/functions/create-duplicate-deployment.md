import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let functions = Functions(client)

let deployment = try await functions.createDuplicateDeployment(
    functionId: "<FUNCTION_ID>",
    deploymentId: "<DEPLOYMENT_ID>",
    buildId: "<BUILD_ID>" // optional
)

