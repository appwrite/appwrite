import Appwrite
import AppwriteEnums

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

let functions = Functions(client)

let deployment = try await functions.createVcsDeployment(
    functionId: "<FUNCTION_ID>",
    type: .branch,
    reference: "<REFERENCE>",
    activate: false // optional
)

