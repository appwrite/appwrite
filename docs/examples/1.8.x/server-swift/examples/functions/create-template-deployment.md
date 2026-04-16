import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let functions = Functions(client)

let deployment = try await functions.createTemplateDeployment(
    functionId: "<FUNCTION_ID>",
    repository: "<REPOSITORY>",
    owner: "<OWNER>",
    rootDirectory: "<ROOT_DIRECTORY>",
    type: .commit,
    reference: "<REFERENCE>",
    activate: false // optional
)

