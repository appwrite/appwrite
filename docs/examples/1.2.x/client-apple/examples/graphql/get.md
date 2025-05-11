import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

let graphql = Graphql(client)

let any = try await graphql.get(
    query: "[QUERY]"
)

