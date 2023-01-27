import io.appwrite.Client
import io.appwrite.services.Graphql

val client = Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val graphql = Graphql(client)

val response = graphql.query(
    query = mapOf( "a" to "b" )
)
