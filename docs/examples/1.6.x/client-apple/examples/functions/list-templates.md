import Appwrite

let client = Client()
    .setEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("&lt;YOUR_PROJECT_ID&gt;") // Your project ID

let functions = Functions(client)

let templateFunctionList = try await functions.listTemplates(
    runtimes: [], // optional
    useCases: [], // optional
    limit: 1, // optional
    offset: 0 // optional
)

