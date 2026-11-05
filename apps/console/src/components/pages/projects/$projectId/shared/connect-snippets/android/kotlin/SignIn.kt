import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.foundation.text.KeyboardOptions
import io.appwrite.services.Account
import kotlinx.coroutines.launch

@Composable
fun SignIn(onSignedIn: () -> Unit, onGoToSignUp: () -> Unit) {
  val context = LocalContext.current
  val scope = rememberCoroutineScope()
  var email by remember { mutableStateOf("") }
  var password by remember { mutableStateOf("") }
  var error by remember { mutableStateOf("") }

  Text("Sign in")
  if (error.isNotEmpty()) Text(error)

  TextField(
    value = email,
    onValueChange = { email = it },
    placeholder = { Text("Email") },
    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
  )
  TextField(
    value = password,
    onValueChange = { password = it },
    placeholder = { Text("Password") },
    visualTransformation = PasswordVisualTransformation(),
  )

  TextButton(
    onClick = {
      if (email.isEmpty() || password.isEmpty()) return@TextButton
      error = ""
      scope.launch {
        try {
          Account(AppwriteClient.get(context))
            .createEmailPasswordSession(email, password)
          onSignedIn()
        } catch (e: Exception) {
          error = e.message ?: "Sign in failed"
        }
      }
    },
  ) { Text("Sign in") }

  Text("No account?")
  TextButton(onClick = onGoToSignUp) { Text("Sign up") }
}
