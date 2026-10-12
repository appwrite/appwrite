---
layout: tutorial
title: Add MainActivity
description: Add navigation to your Android application.
step: 5
---

## Creating the MainActivity {% #creating-the-mainactivity %}

To show the new screen, we need to set up our `MainActivity` class. Open `MainActivity.kt` and update it with following code.
This code sets up our `MainActivity` with a bottom navigation bar, including a **User** screen.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.*
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import io.appwrite.models.User
import <YOUR_ROOT_PACKAGE_HERE>.services.Appwrite
import <YOUR_ROOT_PACKAGE_HERE>.ui.screens.UserScreen
import <YOUR_ROOT_PACKAGE_HERE>.services.AccountService

enum class Screen {
    User
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        Appwrite.init(applicationContext)

        setContent {
            AppContent(Appwrite.account)
        }
    }
}

@Composable
private fun AppBottomBar(screen: MutableState<Screen>) {
    BottomAppBar {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center
        ) {
            IconButton(onClick = { screen.value = Screen.User }) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Default.Person, contentDescription = "User")
                    Text("User")
                }
            }
        }
    }
}


@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppContent(accountService: AccountService) {
    val user = remember { mutableStateOf<User<Map<String, Any>>?>(null) }
    val screen = remember { mutableStateOf(Screen.User) }

    LaunchedEffect(screen) {
        user.value = accountService.getLoggedIn()
    }

    Scaffold(bottomBar = { AppBottomBar(screen) }) { padding ->
        Column(modifier = Modifier.padding(padding)) {
            when (screen.value) {
                Screen.User -> UserScreen(user, accountService)
                else -> Text("Ideas screen")
            }
        }
    }
}
```

## Test the MainActivity {% #test-the-mainactivity %}

Launch the app and you should be able to use the Login screen to register, login, and logout. Confirm your email address is displayed once you are logged in.