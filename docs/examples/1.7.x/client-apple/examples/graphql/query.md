import Appwrite

let client = Client()
    .setEndpoint("https://example.com/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let graphql = Graphql(client)

let any = try await graphql.query(
    query: [:]
)

