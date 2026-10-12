## Add Appwrite auth to a new Android (Kotlin/Jetpack Compose) app with a working login/register/logout page

- Never assume project details. Ask the user for **Cloud Region**, **Project ID**, and **package name**.
- Use explicit config (no hardcoding in code except reading constants the user sets).
- Respect the user's existing project structure and theme.

## Step 1: Create or use existing Android project

- If you already have an Android project open in Android Studio, stay in it and use it.
- Otherwise, guide the user to open Android Studio and click **New Project**.
- Choose **Empty Activity** template and click **Next**.
- Enter the app **name** and **package name** (both are needed later for Appwrite Console).
- Click **Finish** to create the project.

## Step 2: Create Appwrite project (ask user)

- Guide user to the [Appwrite Console](https://cloud.appwrite.io/console).
- Create an account and project if this is their first time.
- Under **Add a platform**, add an **Android app**.
- Add the app's **name** and **package name** (use the **applicationId** from app-level `build.gradle` for existing projects).
- Ask user for their **Cloud Region** and **Project ID** from Console -> Settings.

## Step 3: Add the Appwrite SDK

- Add the following dependency to the app-level `build.gradle.kts` file inside the **dependencies** block:

```kotlin
implementation("io.appwrite:sdk-for-android:8.1.0")
```

- Add the OAuth callback activity inside the `<application>` tag in `AndroidManifest.xml` (replace `<PROJECT_ID>` with actual project ID):

```xml
<manifest ...>
  ...
  <application ...>
    ...
    <!-- Add this inside the `<application>` tag, along side the existing `<activity>` tags -->
    <activity android:name="io.appwrite.views.CallbackActivity" android:exported="true">
      <intent-filter android:label="android_web_auth">
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data android:scheme="appwrite-callback-<PROJECT_ID>" />
      </intent-filter>
    </activity>
  </application>
</manifest>
```

## Step 4: Create Appwrite Singleton (key snippet)

- File: `Appwrite.kt` (in your root package)
- Ask user for **Cloud Region** and **Project ID**, then create:

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>

import android.content.Context
import io.appwrite.Client
import io.appwrite.ID
import io.appwrite.models.*
import io.appwrite.services.*

object Appwrite {
    lateinit var client: Client
    lateinit var account: Account

    fun init(context: Context) {
        client = Client(context)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
            .setProject("<PROJECT_ID>")

        account = Account(client)
    }

    suspend fun onLogin(
        email: String,
        password: String,
    ): Session {
        return account.createEmailPasswordSession(
            email,
            password,
        )
    }

    suspend fun onRegister(
        email: String,
        password: String,
    ): User<Map<String, Any>> {
        return account.create(
            userId = ID.unique(),
            email,
            password,
        )
    }

    suspend fun onLogout() {
        account.deleteSession("current")
    }
}
```

## Step 5: Create login page (MainActivity.kt)

- Replace or update `MainActivity.kt` with the following Jetpack Compose UI:

```kotlin
package <YOUR_ROOT_PACKAGE_HERE>

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.*
import androidx.compose.ui.text.input.*
import androidx.compose.ui.unit.*
import <YOUR_ROOT_PACKAGE_HERE>.ui.theme.MyApplicationTheme
import kotlinx.coroutines.launch

class MainActivity : ComponentActivity() {
    @OptIn(ExperimentalMaterial3Api::class)
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        Appwrite.init(applicationContext)

        setContent {
            MyApplicationTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background
                ) {
                    val coroutineScope = rememberCoroutineScope()

                    var user by remember { mutableStateOf("") }
                    var email by remember { mutableStateOf("") }
                    var password by remember { mutableStateOf("") }

                    if (user.isNotEmpty()) {
                        Column(
                            modifier = Modifier.fillMaxSize(),
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.Center
                        ) {
                            Text(text = "Logged in as $user")
                            Button(onClick = {
                                coroutineScope.launch {
                                    Appwrite.onLogout()
                                }
                            }) {
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
                            value = email,
                            onValueChange = { email = it },
                            label = { Text("Username") },
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(16.dp)
                        )
                        TextField(
                            value = password,
                            onValueChange = { password = it },
                            label = { Text("Password") },
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(16.dp),
                            visualTransformation = PasswordVisualTransformation(),
                            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password)
                        )
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(16.dp),
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Button(onClick = {
                                coroutineScope.launch {
                                    try {
                                        Appwrite.onLogin(email, password)

                                        user = email
                                    } catch (e: Exception) {
                                        e.printStackTrace()
                                    }
                                }
                            }) {
                                Text("Login")
                            }
                            Button(onClick = {
                                coroutineScope.launch {
                                    try {
                                        Appwrite.onRegister(email, password)
                                    } catch (e: Exception) {
                                        e.printStackTrace()
                                    }
                                }
                            }) {
                                Text("Register")
                            }
                        }
                    }
                }
            }
        }
    }
}
```

- The UI includes:
    - Email and password text fields
    - Login and Register buttons
    - Shows "Logged in as (email)" when a session exists
    - Logout button when logged in

## Step 6: Verify configuration

- Confirm **Cloud Region** and **Project ID** are set in `Appwrite.kt`.
- Confirm the **PROJECT_ID** in `AndroidManifest.xml` matches the actual project ID.
- Ensure the Android platform exists in Appwrite Console with the correct **package name**.

## Step 7: Run and test

- Click **Run app** in Android Studio.
- Test flows:
    - Register a new user
    - Login with the registered user
    - Logout
- Surface any Appwrite errors and fix by guiding updates to `Appwrite.kt` and Console settings.

## Deliverables

- A running Android app with working Appwrite auth (register/login/logout)
- Files created/updated: `build.gradle.kts` (deps), `AndroidManifest.xml` (OAuth callback), `Appwrite.kt` (singleton), `MainActivity.kt` (UI)
