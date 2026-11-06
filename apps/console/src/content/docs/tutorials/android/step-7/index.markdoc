---
layout: tutorial
title: Create ideas page
description: Add pagination and ordering to you Android application powered by Appwrite Databases.
step: 7
---

Using the `IdeasService`, we can build a screen to submit and view ideas.
Overwrite the contents of `ui/screens/IdeasScreen.kt` with the following code.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>.screens


import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.material3.TextField
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import io.appwrite.models.Row
import io.appwrite.models.User
import kotlinx.coroutines.launch
import <YOUR_ROOT_PACKAGE_HERE>services.IdeaService


@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun IdeasScreen(
    user: User<Map<String, Any>>?,
    ideasService: IdeaService
) {
    var ideas by remember { mutableStateOf<List<Row<Map<String, Any>>>>(listOf()) }
    val coroutineScope = rememberCoroutineScope()

    LaunchedEffect(ideasService) {
        coroutineScope.launch {
            ideas = ideasService.fetch()
        }
    }

    fun onSubmit(title: String, description: String) {
        if (user === null) return
        coroutineScope.launch {
            ideas = listOf(ideasService.add(user.id, title, description)).plus(ideas)
        }
    }

    fun onRemove(ideaId: String) {
        coroutineScope.launch {
            ideas = ideas.filter { idea -> idea.id !== ideaId }
            ideasService.remove(ideaId)
        }
    }

    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }

    Column(
        modifier = Modifier.fillMaxSize(),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        if (user != null) {
            TextField(
                value = title,
                onValueChange = { title = it },
                label = { Text("Title") },
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp)
            )
            TextField(
                value = description,
                onValueChange = { description = it },
                label = { Text("Description") },
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp)
            )
            Button(onClick = {
                onSubmit(title, description)
                title = ""
                description = ""
            }) {
                Text("Submit")
            }
        }

        LazyColumn(modifier = Modifier.fillMaxSize()) {
            items(ideas) { idea ->
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(text = idea.data["title"]?.toString() ?: "", fontWeight = FontWeight(700))
                    Text(text = idea.data["description"]?.toString() ?: "")
                    if (user?.id == idea.data["userId"])
                        Button(onClick = { onRemove(idea.id) }) {
                            Text("Remove")
                        }
                }
            }
        }
    }
}
```

## Update navigation {% #update-navigation %}

Update `MainActivity.kt` to add the `IdeasScreen` to the navigation bar.

Look for `// Add this line 👇` to find where the changes made here.

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.runtime.*
import androidx.compose.foundation.layout.*
import androidx.compose.material.icons.Icons
// Add this line 👇
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import io.appwrite.models.User
import <YOUR_ROOT_PACKAGE_HERE>.services.Appwrite
import <YOUR_ROOT_PACKAGE_HERE>.services.AccountService
import <YOUR_ROOT_PACKAGE_HERE>.ui.screens.UserScreen

// Add this line 👇
import <YOUR_ROOT_PACKAGE_HERE>.services.IdeaService

// Add this line 👇
import <YOUR_ROOT_PACKAGE_HERE>.ui.screens.IdeasScreen


enum class Screen {
    User,
    // Add this line 👇
    Ideas
}

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        Appwrite.init(applicationContext)

        setContent {
            // Update this line 👇
            AppContent(Appwrite.account, Appwrite.ideas)
        }
    }
}

// Add navigation with this composable 👇
@Composable
private fun AppBottomBar(screen: MutableState<Screen>) {
    BottomAppBar {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center
        ) {
            IconButton(onClick = { screen.value = Screen.Ideas }) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Default.List, contentDescription = "Ideas")
                    Text("Ideas")
                }
            }
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
// Update this line 👇
private fun AppContent(accountService: AccountService, ideasService: IdeaService) {
    val user = remember { mutableStateOf<User<Map<String, Any>>?>(null) }
    // Update this line 👇
    val screen = remember { mutableStateOf(Screen.Ideas) }

    LaunchedEffect(screen) {
        user.value = accountService.getLoggedIn()
    }

    Scaffold(bottomBar = { AppBottomBar(screen) }) { padding ->
        Column(modifier = Modifier.padding(padding)) {
            when (screen.value) {
                Screen.User -> UserScreen(user, accountService)
                // Update this line 👇
                else -> IdeasScreen(user.value, ideasService)
            }
        }
    }
}
```
