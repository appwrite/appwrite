---
layout: tutorial
title: Add authentication
description: Add Appwrite authentication to you Android app.
step: 4
---

## Creating an account service {% #creating-an-account-service %}

We can use services to abstract business logic from our views.
Create a service to handle user authentication with a new file `services/AccountService.kt`.

Add the following code to it.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>.services

import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.models.User
import io.appwrite.exceptions.AppwriteException
import io.appwrite.services.Account

class AccountService(client: Client) {
    private val account = Account(client)

    suspend fun getLoggedIn(): User<Map<String, Any>>? {
        return try {
            account.get()
        } catch (e: AppwriteException) {
            null
        }
    }

    suspend fun login(email: String, password: String): User<Map<String, Any>>? {
        return try {
            account.createEmailPasswordSession(
                email = email,
                password = password
            )
            getLoggedIn()
        } catch (e: AppwriteException) {
            null
        }
    }

    suspend fun register(email: String, password: String): User<Map<String, Any>>? {
        return try {
            account.create(
                userId = ID.unique(),
                email = email,
                password = password
            )
            login(email, password)
        } catch (e: AppwriteException) {
            null
        }
    }

    suspend fun logout() {
        account.deleteSession("current")
    }
}
```

We can now use this service to login, register and logout a user. Integrate the service to the `/services/Appwrite.kt` file.

Look for `// Add this line 👇` to find where the changes made here.

```kotlin
object Appwrite {
    private const val ENDPOINT = "https://<REGION>.cloud.appwrite.io/v1"
    private const val PROJECT_ID = "<PROJECT_ID>"

    private lateinit var client: Client

    // Add this line 👇
    internal lateinit var account: AccountService

    fun init(context: Context) {
        client = Client(context)
            .setEndpoint(ENDPOINT)
            .setProject(PROJECT_ID)

        // Add this line 👇
        account = AccountService(client)
    }
}
```

## Login screen {% #login-screen %}

Using this service, we can now create a screen to login or register a user.

Create a new file `ui/screens/UserScreen.kt` and add the following code to it.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>.ui.screens

import <YOUR_ROOT_PACKAGE_HERE>.services.AccountService
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.MutableState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import io.appwrite.models.User
import kotlinx.coroutines.launch

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun UserScreen(
    user: MutableState<User<Map<String, Any>>?>,
    accountService: AccountService
) {
    val coroutineScope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }

    fun onLogin(email: String, password: String) {
        coroutineScope.launch {
            user.value = accountService.login(email, password)
        }
        error = if (user.value === null) {
            "Unable to login"
        } else {
            null
        }
    }

    fun onRegister(email: String, password: String) {
        coroutineScope.launch {
            user.value = accountService.register(email, password)
        }
        error = if (user.value === null) {
            "Unable to register"
        } else {
            null
        }
    }

    fun onLogout() {
        coroutineScope.launch {
            accountService.logout()
            user.value = null
        }
    }

    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }

    if (user.value !== null) {
        Column(
            modifier = Modifier.fillMaxSize(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center
        ) {
            Text(text = "Logged in as ${user.value!!.email}")
            Button(onClick = { onLogout() }) {
                Text("Logout")
            }
        }
    }

    Column(
        modifier = Modifier.fillMaxSize(),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        TextField(
            value = username,
            onValueChange = { username = it },
            label = { Text("Email") }
        )
        TextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Password") },
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password)
        )
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Button(onClick = { onLogin(username, password) }) {
                Text("Login")
            }
            Button(onClick = { onRegister(username, password) }) {
                Text("Register")
            }
        }
        if (error !== null) {
            Text(
                text = error!!,
                modifier = Modifier.padding(16.dp),
                color = androidx.compose.ui.graphics.Color.Red
            )
        }
    }
}
```