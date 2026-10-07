## Add Appwrite Auth to a New Flutter App

Goal: Add Appwrite auth to a new Flutter app with a working login/register/logout page.

- Never assume project details. Ask the user for **Cloud Region** and **Project ID**.
- Ask the user which platform(s) they are targeting (**Web**, **iOS**, **Android**, **Linux**, **macOS**, **Windows**).
- For each target platform, guide the user through the platform-specific setup requirements.

## Step 1: Create Flutter Project

- If you already have a Flutter project open, stay in it and use it.
- Otherwise, run: `flutter create my_app && cd my_app`

## Step 2: Create Appwrite Project and Add Platform

Guide user to the [Appwrite Console](https://cloud.appwrite.io/console) to create a project if needed.

Ask the user for:

- **Cloud Region** (e.g., `fra`, `nyc`)
- **Project ID** (from Console -> Settings)

Under **Add a platform**, add a Flutter app for the target platform:

### Web

Add app name and **Hostname** (use `localhost` for local testing). Create `web/auth.html` for OAuth callback:

```html
<!DOCTYPE html>
<title>Authentication complete</title>
<p>Authentication is complete. If this does not happen automatically, please close the window.</p>
<script>
    window.opener.postMessage(
        {
            'flutter-web-auth-2': window.location.href
        },
        window.location.origin
    );
    window.close();
</script>
```

### iOS

Add app name and **Bundle ID**. Set iOS Deployment Target to iOS >= 11 in XCode.

### Android

Add app name and package name (`applicationId` from `build.gradle`). Add callback activity to `AndroidManifest.xml`:

```xml
<activity android:name="com.linusu.flutter_web_auth_2.CallbackActivity" android:exported="true">
  <intent-filter android:label="flutter_web_auth_2">
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="appwrite-callback-<PROJECT_ID>" />
  </intent-filter>
</activity>
```

### Linux/Windows

Add app name and package name (from `pubspec.yaml`).

### macOS

Add app name and **Bundle ID**. Set macOS Deployment Target to >= 10.15 in XCode. Add URL scheme to `Info.plist`:

```xml
<key>CFBundleURLTypes</key>
<array>
<dict>
    <key>CFBundleTypeRole</key>
    <string>Editor</string>
    <key>CFBundleURLName</key>
    <string>io.appwrite</string>
    <key>CFBundleURLSchemes</key>
    <array>
        <string>appwrite-callback-<PROJECT_ID></string>
    </array>
</dict>
</array>
```

## Step 3: Install Appwrite SDK

- Run: `flutter pub add appwrite:17.0.0`

## Step 4: Import and Initialize Appwrite

- File: `lib/main.dart`
- Replace `<REGION>` and `<PROJECT_ID>` with user-provided values:

```dart
import 'package:flutter/material.dart';
import 'package:appwrite/appwrite.dart';
import 'package:appwrite/models.dart' as models;

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  Client client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
      .setProject("<PROJECT_ID>");
  Account account = Account(client);

  runApp(MaterialApp(
    home: MyApp(account: account),
  ));
}

class MyApp extends StatefulWidget {
  final Account account;

  MyApp({required this.account});

  @override
  MyAppState createState() {
    return MyAppState();
  }
}
```

## Step 5: Create Login Page

Append to `lib/main.dart` the following widget with login/register/logout functionality:

```dart
class MyAppState extends State<MyApp> {
  models.User? loggedInUser;
  final TextEditingController emailController = TextEditingController();
  final TextEditingController passwordController = TextEditingController();
  final TextEditingController nameController = TextEditingController();

  @override
  void initState() {
    super.initState();
    widget.account.get().then((user) {
      setState(() {
        loggedInUser = user;
      });
    }).catchError((_) {});
  }

  Future<void> login(String email, String password) async {
    await widget.account.createEmailPasswordSession(
      email: email,
      password: password,
    );
    final user = await widget.account.get();
    setState(() {
      loggedInUser = user;
    });
  }

  Future<void> register(String email, String password, String name) async {
    await widget.account.create(
        userId: ID.unique(),
        email: email,
        password: password,
        name: name,
    );
    await login(email, password);
  }

  Future<void> logout() async {
    await widget.account.deleteSession(sessionId: 'current');
    setState(() {
      loggedInUser = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: Scaffold(
        body: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(loggedInUser != null
                ? 'Logged in as ${loggedInUser!.name}'
                : 'Not logged in'),
            SizedBox(height: 16.0),
            TextField(
              controller: emailController,
              decoration: InputDecoration(labelText: 'Email'),
            ),
            SizedBox(height: 16.0),
            TextField(
              controller: passwordController,
              decoration: InputDecoration(labelText: 'Password'),
              obscureText: true,
            ),
            SizedBox(height: 16.0),
            TextField(
              controller: nameController,
              decoration: InputDecoration(labelText: 'Name'),
            ),
            SizedBox(height: 16.0),
            Row(
              mainAxisAlignment: MainAxisAlignment.start,
              children: <Widget>[
                ElevatedButton(
                  onPressed: () {
                    login(emailController.text, passwordController.text);
                  },
                  child: Text('Login'),
                ),
                SizedBox(width: 16.0),
                ElevatedButton(
                  onPressed: () {
                    register(emailController.text, passwordController.text,
                        nameController.text);
                  },
                  child: Text('Register'),
                ),
                SizedBox(width: 16.0),
                ElevatedButton(
                  onPressed: () {
                    logout();
                  },
                  child: Text('Logout'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
```

The page displays:

- "Logged in as \<name>" when a session exists, otherwise "Not logged in"
- **Email**, **Password**, and **Name** text fields
- **Login**, **Register**, and **Logout** buttons

## Step 6: Run and Test

- Run: `flutter run`
- Select a browser, platform, or emulator to run your project.
- Test flows:
    - Register a new user and verify auto login works
    - Logout then login again
- Surface any Appwrite errors and fix by guiding updates to the endpoint/project ID and Console platform settings.

## Deliverables

- A running Flutter app with working Appwrite auth (register/login/logout)
- Files created/updated: `pubspec.yaml` (deps), `lib/main.dart`
- Platform-specific files if applicable: `web/auth.html` (Web), `AndroidManifest.xml` (Android), `Info.plist` (macOS)
