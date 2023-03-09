import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
    .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
    .setProject("5df5acd0d48c2") // Your project ID

val account = Account(client)

val response = account.updatePhone(
    phone = "+12065550100",
    password = "password"
)
