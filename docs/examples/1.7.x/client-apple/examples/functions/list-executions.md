import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let functions = Functions(client)

let executionList = try await functions.listExecutions(
    functionId: "<FUNCTION_ID>",
    queries: [] // optional
)

