import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let functions = Functions(client)

let executionList = try await functions.listExecutions(
    functionId: "[FUNCTION_ID]",
    queries: [], // optional
    search: "[SEARCH]" // optional
)

