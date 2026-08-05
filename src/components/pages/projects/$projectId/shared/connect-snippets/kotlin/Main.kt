import io.appwrite.Client
import io.appwrite.services.Account

fun main() {
  val client = Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
    .setKey(System.getenv("APPWRITE_API_KEY"))

  val account = Account(client)
  val user = account.get()
  println("Hello, ${user.name}")
}
