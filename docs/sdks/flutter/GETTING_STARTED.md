## Getting Started

### Add your Flutter Platform
To init your SDK and start interacting with Appwrite services, you need to add a new Flutter platform to your project. To add a new platform, go to your Appwrite console, choose the project you created in the step before, and click the 'Add Platform' button.

From the options, choose to add a new **Flutter** platform and add your app credentials. Appwrite Flutter SDK currently supports building apps for Android, iOS, Linux, Mac OS, Web and Windows.

If you are building your Flutter application for multiple devices, you have to follow this process for each different device.

### Android
For **Android** first add your app <u>name</u> and <u>package name</u>, Your package name is generally the **applicationId** in your app-level <a href="https://github.com/appwrite/playground-for-flutter/blob/0fdbdff98384fff940ed0b1e08cf14cfe3a2be3e/android/app/build.gradle#L41" target="_blank" rel="noopener">build.gradle</a> file. By registering your new app platform, you are allowing your app to communicate with the Appwrite API.

In order to capture the Appwrite OAuth callback url, the following activity needs to be added to your [AndroidManifest.xml](https://github.com/appwrite/playground-for-flutter/blob/master/android/app/src/main/AndroidManifest.xml). Be sure to replace the **[PROJECT_ID]** string with your actual Appwrite project ID. You can find your Appwrite project ID in your project settings screen in the console.

```xml
<manifest>
    <application>
        <activity android:name="com.linusu.flutter_web_auth.CallbackActivity" >
            <intent-filter android:label="flutter_web_auth">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="appwrite-callback-[PROJECT_ID]" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

### iOS
For **iOS** first add your app name and Bundle ID, You can find your Bundle Identifier in the General tab for your app's primary target in Xcode.

The Appwrite SDK uses ASWebAuthenticationSession on iOS 12+ and SFAuthenticationSession on iOS 11 to allow OAuth authentication. You have to change your iOS Deployment Target in Xcode to be iOS >= 11 to be able to build your app on an emulator or a real device.

1. In Xcode, open Runner.xcworkspace in your app's ios folder.
2. To view your app's settings, select the Runner project in the Xcode project navigator. Then, in the main view sidebar, select the Runner target.
3. Select the General tab.
4. In Deployment Info, 'Target' select iOS 11.0

### Linux
For **Linux** add your app <u>name</u> and <u>package name</u>, Your package name is generally the **name** in your <a href="https://github.com/appwrite/playground-for-flutter/blob/0fdbdff98384fff940ed0b1e08cf14cfe3a2be3e/pubspec.yaml#L1" target="_blank" rel="noopener">pubspec.yaml<a> file. If you cannot find the correct package name, run the application in linux, and make any request with proper exception handling, you should get the application ID needed to add in the received error message.

### Mac OS
For **Mac OS** add your app name and Bundle ID, You can find your Bundle Identifier in the General tab for your app's primary target in Xcode.

### Web
Appwrite 0.7, and the Appwrite Flutter SDK 0.3.0 have added support for Flutter Web. To build web apps that integrate with Appwrite successfully, all you have to do is add a web platform on your Appwrite project's dashboard and list the domain your website will use to allow communication to the Appwrite API.

#### Flutter Web Cross-Domain Communication & Cookies
While running Flutter Web, make sure your Appwrite server and your Flutter client are using the same top-level domain and the same protocol (HTTP or HTTPS) to communicate. When trying to communicate between different domains or protocols, you may receive HTTP status error 401 because some modern browsers block cross-site or insecure cookies for enhanced privacy. In production, Appwrite allows you set multiple [custom-domains](https://appwrite.io/docs/custom-domains) for each project.

### Windows
For **Windows** add your app <u>name</u> and <u>package name</u>, Your package name is generally the **name** in your <a href="https://github.com/appwrite/playground-for-flutter/blob/0fdbdff98384fff940ed0b1e08cf14cfe3a2be3e/pubspec.yaml#L1" target="_blank" rel="noopener">pubspec.yaml</a> file. If you cannot find the correct package name, run the application in windows, and make any request with proper exception handling, you should get the application id needed to add in the received error message.

### Init your SDK

<p>Initialize your SDK with your Appwrite server API endpoint and project ID, which can be found in your project settings page.

```dart
import 'package:appwrite/appwrite.dart';
Client client = Client();


client
  .setEndpoint('https://localhost/v1') // Your Appwrite Endpoint
  .setProject('5e8cf4f46b5e8') // Your project ID
  .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
;
```

Before starting to send any API calls to your new Appwrite instance, make sure your Android or iOS emulators has network access to the Appwrite server hostname or IP address.

When trying to connect to Appwrite from an emulator or a mobile device, localhost is the hostname for the device or emulator and not your local Appwrite instance. You should replace localhost with your private IP as the Appwrite endpoint's hostname. You can also use a service like [ngrok](https://ngrok.com/) to proxy the Appwrite API.

### Make Your First Request

<p>Once your SDK object is set, access any of the Appwrite services and choose any request to send. Full documentation for any service method you would like to use can be found in your SDK documentation or in the [API References](https://appwrite.io/docs) section.

```dart
// Register User
Account account = Account(client);
Response user = await account
  .create(
    email: 'me@appwrite.io',
    password: 'password',
    name: 'My Name'
  );
```

### Full Example

```dart
import 'package:appwrite/appwrite.dart';
Client client = Client();


client
  .setEndpoint('https://localhost/v1') // Your Appwrite Endpoint
  .setProject('5e8cf4f46b5e8') // Your project ID
  .setSelfSigned() // Use only on dev mode with a self-signed SSL cert
  ;


// Register User
Account account = Account(client);

Response user = await account
  .create(
    email: 'me@appwrite.io',
    password: 'password',
    name: 'My Name'
  );
```

### Error Handling
The Appwrite Flutter SDK raises `AppwriteException` object with `message`, `code` and `response` properties. You can handle any errors by catching `AppwriteException` and present the `message` to the user or handle it yourself based on the provided error information. Below is an example.

```dart
Users users = Users(client);

try {
  final response = await users.create(email: ‘email@example.com’,password: ‘password’, name: ‘name’);
  print(response.data);
} on AppwriteException catch(e) {
  //show message to user or do other operation based on error as required
  print(e.message);
}
```

### Learn more
You can use the followng resources to learn more and get help
- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-flutter)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
- 🚂 [Appwrite Flutter Playground](https://github.com/appwrite/playground-for-flutter)
