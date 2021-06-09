## Getting Started

### Add your Android Platform
To init your SDK and start interacting with Appwrite services, you need to add a new Flutter platform to your project. To add a new platform, go to your Appwrite console, choose the project you created in the step before, and click the 'Add Platform' button.

From the options, choose to add a new **Flutter** platform and add your app credentials, ignoring iOS.

Add your app <u>name</u> and <u>package name</u>, Your package name is generally the applicationId in your app-level build.gradle file. By registering your new app platform, you are allowing your app to communicate with the Appwrite API.

### OAuth
In order to capture the Appwrite OAuth callback url, the following activity needs to be added to your [AndroidManifest.xml](https://github.com/appwrite/playground-for-flutter/blob/master/android/app/src/main/AndroidManifest.xml). Be sure to relpace the **[PROJECT_ID]** string with your actual Appwrite project ID. You can find your Appwrite project ID in you project settings screen in your Appwrite console.

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

<p>Initialize your SDK code with your project ID, which can be found in your project settings page.

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production
```

Before starting to send any API calls to your new Appwrite instance, make sure your Android emulators has network access to the Appwrite server hostname or IP address.

When trying to connect to Appwrite from an emulator or a mobile device, localhost is the hostname for the device or emulator and not your local Appwrite instance. You should replace localhost with your private IP as the Appwrite endpoint's hostname. You can also use a service like [ngrok](https://ngrok.com/) to proxy the Appwrite API.

### Make Your First Request

<p>Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the API References section.

```kotlin
// Register User
val account = Account(client)
val response = account.create(
    "email@example.com", 
    "password"
)
```

### Full Example

```kotlin
import io.appwrite.Client
import io.appwrite.services.Account

val client = Client(context)
  .setEndpoint("https://[HOSTNAME_OR_IP]/v1") // Your API Endpoint
  .setProject("5df5acd0d48c2") // Your project ID
  .setSelfSigned(true) // Remove in production

val account = Account(client)
val response = account.create(
    "email@example.com", 
    "password"
)
```

### Error Handling
The Apopwrite Android SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on provided error information. Below is an example.

```kotlin
try {
    var response = account.create("email@example.com", "password")
    Log.d("Appwrite response", response.body?.string())
} catch(e : AppwriteException) {
    Log.e("AppwriteException",e.message.toString())
}
```

### Learn more
You can use followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-android)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- - 🚂 [Appwrite Android Playground](https://github.com/appwrite/playground-for-android)