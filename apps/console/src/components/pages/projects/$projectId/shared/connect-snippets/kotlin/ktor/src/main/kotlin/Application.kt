import io.ktor.serialization.gson.gson
import io.ktor.server.application.install
import io.ktor.server.engine.embeddedServer
import io.ktor.server.netty.Netty
import io.ktor.server.plugins.contentnegotiation.ContentNegotiation
import io.ktor.server.response.respond
import io.ktor.server.routing.get
import io.ktor.server.routing.patch
import io.ktor.server.routing.routing

fun main() {
  embeddedServer(Netty, port = 8080) {
    install(ContentNegotiation) { gson() }

    routing {
      patch("/v1/policies") {
        val policy = appwrite.updatePasswordStrengthPolicy(
          min = 8,
          uppercase = true,
          number = true,
          symbols = true
        )
        call.respond(policy.toMap())
      }

      get("/v1/policies") {
        call.respond(appwrite.listPolicies().toMap())
      }
    }
  }.start(wait = true)
}
