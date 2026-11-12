import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import io.appwrite.services.Account
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    setContent { App() }
  }
}

@Composable
fun App() {
  var route by remember { mutableStateOf("home") }

  Column(modifier = Modifier.padding(24.dp)) {
    when (route) {
      "sign-in" -> SignIn(
        onSignedIn = { route = "home" },
        onGoToSignUp = { route = "sign-up" },
      )
      "sign-up" -> SignUp(
        onSignedUp = { route = "home" },
        onGoToSignIn = { route = "sign-in" },
      )
      else -> Home(
        onGoToSignIn = { route = "sign-in" },
        onGoToSignUp = { route = "sign-up" },
      )
    }
  }
}

@Composable
fun Home(onGoToSignIn: () -> Unit, onGoToSignUp: () -> Unit) {
  val context = LocalContext.current
  val scope = rememberCoroutineScope()
  var name by remember { mutableStateOf<String?>(null) }
  var loading by remember { mutableStateOf(true) }

  LaunchedEffect(Unit) {
    name = try {
      Account(AppwriteClient.get(context)).get().name
    } catch (e: Exception) {
      null
    }
    loading = false
  }

  if (loading) {
    Text("Loading...")
    return
  }

  if (name == null) {
    Text("Sign in to get started.")
    TextButton(onClick = onGoToSignIn) { Text("Sign in") }
    TextButton(onClick = onGoToSignUp) { Text("Sign up") }
    return
  }

  Text("Hello, $name")
  TextButton(
    onClick = {
      scope.launch {
        Account(AppwriteClient.get(context)).deleteSession("current")
        name = null
      }
    },
  ) { Text("Sign out") }
}
