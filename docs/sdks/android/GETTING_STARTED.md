## Getting Started

### Add your Android Platform
To initialize your SDK and start interacting with Appwrite services, you need to add a new Android platform to your project. To add a new platform, go to your Appwrite console, select your project (create one if you haven't already), and click the 'Add Platform' button on the project Dashboard.

From the options, choose to add a new **Android** platform and add your app credentials.

Add your app <u>name</u> and <u>package name</u>. Your package name is generally the applicationId in your app-level `build.gradle` file. By registering a new platform, you are allowing your app to communicate with the Appwrite API.

### Registering additional activities
In order to capture the Appwrite OAuth callback url, the following activity needs to be added to your [AndroidManifest.xml](https://github.com/appwrite/playground-for-android/blob/master/app/src/main/AndroidManifest.xml). Be sure to replace the **[PROJECT_ID]** string with your actual Appwrite project ID. You can find your Appwrite project ID in your project settings screen in the console.

```xml
<manifest>
    <application>
        <activity android:name="io.appwrite.views.CallbackActivity" >
            <intent-filter android:label="android_web_auth">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="appwrite-callback-[PROJECT_ID]" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

### Init your SDK

<p>Initialize your SDK with your Appwrite server API endpoint and project ID, which can be found in your project settings page.

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production
```

Before starting to send any API calls to your new Appwrite instance, make sure your Android emulator has network access to the Appwrite server hostname or IP address.


When trying to connect to Appwrite from an emulator or a mobile device, `localhost` is the hostname of the device or emulator and not your local Appwrite instance. You should replace `localhost` with your private IP. You can also use a service like [Tunnelmole](https://github.com/robbie-cahill/tunnelmole-client) or [ngrok](https://ngrok.com/) to proxy the Appwrite API.

Tunnelmole is an open source tunneling tool. To use it:
1. Install it with `curl -O https://install.tunnelmole.com/aPD4m/install && sudo bash install` (on Windows, download [tmole.exe](https://tunnelmole.com/downloads/tmole.exe) 
2. Run it with `tmole 8000` (replacing `8000` with the port number you are listening on if it is different). In the output, you'll see two URLs, one http and a https URL. Its best to use the https url for privacy and security.

Alternatively, you can use ngrok. [ngrok](https://ngrok.com/) is a popular closed-source tunnelling tool. It can be used to proxy the Appwrite API in the same way. To use it, first download and install ngrok from [https://ngrok.com/download](https://ngrok.com/download) then run `ngrok http 8000` (again, replacing `8000` with your port number). Be sure to use the https URL in the output for the best security.

### Make Your First Request

<p>Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```kotlin
// Register User
val account = Account(client)
val response = account.create(
    ID.unique(),
    "email@example.com",
    "password",
    "Walter O'Brien"
)
```

### Full Example

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account
import io.appwrite.ID

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production

val account = Account(client)
val user = account.create(
    ID.unique(),
    "email@example.com",
    "password",
    "Walter O'Brien"
)
```

### Error Handling
The Appwrite Android SDK raises an `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```kotlin
try {
    var user = account.create(ID.unique(),"email@example.com","password","Walter O'Brien")
    Log.d("Appwrite user", user.toMap())
} catch(e : AppwriteException) {
    e.printStackTrace()
}
```

### Learn more
You can use the following resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-android)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Android Playground](https://github.com/appwrite/playground-for-android)
