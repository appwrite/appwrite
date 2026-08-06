import io.appwrite.Client
import io.appwrite.services.Project

suspend fun main() {
  val client = Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
    .setKey(System.getenv("APPWRITE_API_KEY"))

  val project = Project(client)

  val policy = project.updatePasswordStrengthPolicy(
    min = 8,
    uppercase = true,
    number = true,
    symbols = true
  )

  println(policy)

  val policies = project.listPolicies()

  println(policies)
}
