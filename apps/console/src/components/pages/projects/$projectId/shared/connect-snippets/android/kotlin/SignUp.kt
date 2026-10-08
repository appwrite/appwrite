import androidx.compose.foundation.text.KeyboardOptions
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
import io.appwrite.ID
import io.appwrite.services.Account
import kotlinx.coroutines.launch

@Composable
fun SignUp(onSignedUp: () -> Unit, onGoToSignIn: () -> Unit) {
  val context = LocalContext.current
  val scope = rememberCoroutineScope()
  var name by remember { mutableStateOf("") }
  var email by remember { mutableStateOf("") }
  var password by remember { mutableStateOf("") }
  var error by remember { mutableStateOf("") }

  Text("Sign up")
  if (error.isNotEmpty()) Text(error)

  TextField(
    value = name,
    onValueChange = { name = it },
    placeholder = { Text("Name") },
  )
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
          val account = Account(AppwriteClient.get(context))
          account.create(
            userId = ID.unique(),
            email = email,
            password = password,
            name = name.trim().ifEmpty { null },
          )
          account.createEmailPasswordSession(email, password)
          onSignedUp()
        } catch (e: Exception) {
          error = e.message ?: "Sign up failed"
        }
      }
    },
  ) { Text("Sign up") }

  Text("Already have an account?")
  TextButton(onClick = onGoToSignIn) { Text("Sign in") }
}
