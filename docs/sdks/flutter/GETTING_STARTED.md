## Getting Started

### Add your Flutter platform

Before the SDK can talk to your project, register each app you build in the Appwrite Console: open your project, choose **Add platform**, pick **Flutter**, then pick the target and enter its identifier. Register every target you ship separately.

| Target | Identifier |
| --- | --- |
| Android | Package name: the `applicationId` in `android/app/build.gradle.kts` |
| iOS | Bundle ID: Xcode, **Runner** target, **General** tab |
| macOS | Bundle ID: Xcode, **Runner** target, **General** tab |
| Linux | Package name: the `name` in `pubspec.yaml` |
| Windows | Package name: the `name` in `pubspec.yaml` |
| Web | Hostname your app is served from, such as `localhost` during development |

If a request fails because the platform is not registered, the error message includes the identifier the SDK sent, so you can copy it into the Console.

### Platform setup

#### Android

To return to your app after an OAuth2 login, add this activity inside the `<application>` tag of `android/app/src/main/AndroidManifest.xml`, alongside your existing activities. Replace `<PROJECT_ID>` with your Appwrite project ID.

```xml
<manifest ...>
    <application ...>
        <activity
            android:name="com.linusu.flutter_web_auth_2.CallbackActivity"
            android:exported="true"
            android:taskAffinity="">
            <intent-filter android:label="flutter_web_auth_2">
                <action android:name="android.intent.action.VIEW" />
                <category android:name="android.intent.category.DEFAULT" />
                <category android:name="android.intent.category.BROWSABLE" />
                <data android:scheme="appwrite-callback-<PROJECT_ID>" />
            </intent-filter>
        </activity>
    </application>
</manifest>
```

#### iOS

Set the iOS deployment target to 13.0 or later: in Xcode, open `ios/Runner.xcworkspace`, select the **Runner** target, and set **Minimum Deployments** on the **General** tab. OAuth2 login uses `ASWebAuthenticationSession` and needs no further setup.

#### macOS

Set the macOS deployment target to 10.15 or later in the **Runner** target's **General** tab. macOS apps run in the App Sandbox, which blocks network requests until you allow them. Add this key to both `macos/Runner/DebugProfile.entitlements` and `macos/Runner/Release.entitlements`:

```xml
<key>com.apple.security.network.client</key>
<true/>
```

#### Web

To complete an OAuth2 login on the web, create `web/auth.html` in your Flutter project. It sends the callback URL back to your app with `postMessage()`:

```html
<!DOCTYPE html>
<title>Authentication complete</title>
<p>Authentication is complete. If this does not happen automatically, please close the window.</p>
<script>
  function postAuthenticationMessage() {
    const message = {
      'flutter-web-auth-2': window.location.href
    };

    if (window.opener) {
      window.opener.postMessage(message, window.location.origin);
      window.close();
    } else if (window.parent && window.parent !== window) {
      window.parent.postMessage(message, window.location.origin);
    } else {
      localStorage.setItem('flutter-web-auth-2', window.location.href);
      window.close();
    }
  }

  postAuthenticationMessage();
</script>
```

Then pass that page as the `success` and `failure` URLs. It must be served from the same scheme, host and port as your app:

```dart
import 'package:appwrite/enums.dart' as enums;

await account.createOAuth2Session(
  provider: enums.OAuthProvider.github,
  success: '${Uri.base.origin}/auth.html',
  failure: '${Uri.base.origin}/auth.html',
);
```

Browsers block third-party cookies, so serve your app and Appwrite from the same registrable domain over HTTPS, for example with a [custom domain](https://appwrite.io/docs/products/network/custom-domains) for your Appwrite project. Otherwise requests after login can fail with a 401 error.

#### OAuth2 on Android, iOS and macOS

Call `createOAuth2Session` without the `success` and `failure` URLs. The SDK returns to your app only through the `appwrite-callback-<PROJECT_ID>` scheme, and only the default redirect carries the new session with it. A custom URL, such as an https App Link, makes the call fail (for example with `PlatformException(CANCELED)`) even when the login itself succeeded. Await the returned `Future` and navigate from your Dart code instead.

```dart
import 'package:appwrite/enums.dart' as enums;

await account.createOAuth2Session(provider: enums.OAuthProvider.github);
```

### Initialize the client

Initialize the client with your project's endpoint and ID, both found on your project's **Settings** page in the Console.

```dart
import 'package:appwrite/appwrite.dart';

Client client = Client()
    .setEndpoint('https://<REGION>.cloud.appwrite.io/v1')
    .setProject('<YOUR_PROJECT_ID>');
```

> If you self-host Appwrite, use your instance's endpoint instead. On an emulator or a device, `localhost` is the device itself, not your computer: use your computer's private IP address (for example `http://192.168.1.100/v1`) or a tunnel such as [ngrok](https://ngrok.com/). Call `setSelfSigned()` only in development, when the instance uses a self-signed certificate.

### Make a request

Every service takes the client in its constructor, and every method takes named parameters and returns a typed model from `package:appwrite/models.dart`.

```dart
import 'package:appwrite/appwrite.dart';
import 'package:appwrite/models.dart' as models;

Account account = Account(client);

models.User user = await account.create(
  userId: ID.unique(),
  email: 'email@example.com',
  password: 'password',
  name: "Walter O'Brien",
);

models.Session session = await account.createEmailPasswordSession(
  email: 'email@example.com',
  password: 'password',
);
```

### Error handling

The SDK throws an `AppwriteException` with `message`, `code`, `type` and `response` properties. Catch it to present the `message` to the user or to handle the error based on its `type`.

```dart
try {
  models.User user = await account.get();
} on AppwriteException catch (e) {
  print('${e.code} ${e.type}: ${e.message}');
}
```

### Learn more

- [Flutter quick start](https://appwrite.io/docs/quick-starts/flutter)
- [Client Flutter API reference](https://appwrite.io/docs/references/cloud/client-flutter/account)
- [Appwrite docs](https://appwrite.io/docs)
- [Discord community](https://appwrite.io/discord)
- [Appwrite Flutter playground](https://github.com/appwrite/playground-for-flutter)
