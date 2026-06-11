---
layout: article
title: Start with Android (Java)
description: Get started with Appwrite on Android using Java and learn how to build secure and scalable apps using our powerful backend.
difficulty: beginner
readtime: 3
back: /docs/quick-starts
---

Learn how to setup your first Android project powered by Appwrite and the [Appwrite Android SDK](https://github.com/appwrite/sdk-for-android) using Java.

{% info title="Using Kotlin?" %}
Check out the [Start with Android (Kotlin)](/docs/quick-starts/android) guide.
{% /info %}

{% section #step-1 step=1 title="Create Android project" %}
Open Android Studio and click **New Project** to create a new project.

Choose your desired project template, for example **Empty Activity**, and click **Next**.

Now enter your app **name** and **package name**. You will need both of these later when you create your project in the Appwrite console. Click **Finish** to create your project.

{% /section %}

{% section #step-2 step=2 title="Create Appwrite project" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console).

{% only_dark %}
![Create project screen](/images/docs/quick-starts/dark/create-project.avif)
{% /only_dark %}
{% only_light %}
![Create project screen](/images/docs/quick-starts/create-project.avif)
{% /only_light %}

If this is your first time using Appwrite, create an account and create your first project.

Then, under **Add a platform**, add an **Android app**.

Add your app's **name** and **package name**, your package name is the one entered when creating an Android project. For existing projects, you should use the **applicationId** in your app-level [build.gradle](https://github.com/appwrite/playground-for-android/blob/master/app/build.gradle#L11) file.

{% only_dark %}
![Add a platform](/images/docs/quick-starts/dark/add-platform.avif)
{% /only_dark %}
{% only_light %}
![Add a platform](/images/docs/quick-starts/add-platform.avif)
{% /only_light %}

You can skip optional steps.

{% /section %}

{% section #step-3 step=3 title="Add the Appwrite SDK" %}
To add the Appwrite SDK for Android as a dependency, add the following to your app-level **build.gradle** file inside the **dependencies** block.

```groovy
implementation "io.appwrite:sdk-for-android:8.1.0"
```

In order to allow creating OAuth sessions, the following activity needs to be added inside the `<application>` tag, along side the existing `<activity>` tags in your [AndroidManifest.xml](https://github.com/appwrite/playground-for-android/blob/master/app/src/main/AndroidManifest.xml).
Be sure to replace the **<PROJECT_ID>** string with your actual Appwrite project ID.
You can find your Appwrite project ID in you project settings screen in your Appwrite Console.

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
{% /section %}

{% section #step-4 step=4 title="Create Appwrite helper class" %}
Find your project's ID in the **Settings** page.

{% only_dark %}
![Project settings screen](/images/docs/quick-starts/dark/project-id.avif)
{% /only_dark %}
{% only_light %}
![Project settings screen](/images/docs/quick-starts/project-id.avif)
{% /only_light %}

Create a new file `AppwriteHelper.java` and add the following code to it, replacing `<PROJECT_ID>` with your project ID.

```java
package <YOUR_ROOT_PACKAGE_HERE>;

import android.content.Context;

import java.util.Map;

import io.appwrite.Client;
import io.appwrite.ID;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.models.Session;
import io.appwrite.models.User;
import io.appwrite.services.Account;

public class AppwriteHelper {
    private static AppwriteHelper instance;
    private Client client;
    private Account account;

    private AppwriteHelper(Context context) {
        client = new Client(context)
                .setEndpoint("https://<REGION>.cloud.appwrite.io/v1")
                .setProject("<PROJECT_ID>");

        account = new Account(client);
    }

    public static synchronized AppwriteHelper getInstance(Context context) {
        if (instance == null) {
            instance = new AppwriteHelper(context.getApplicationContext());
        }
        return instance;
    }

    public interface AuthCallback<T> {
        void onSuccess(T result);
        void onError(Exception error);
    }

    public void login(String email, String password, final AuthCallback<Session> callback) {
        account.createEmailPasswordSession(
            email,
            password,
            new CoroutineCallback<>((result, error) -> {
                if (error != null) {
                    callback.onError(error);
                    return;
                }
                callback.onSuccess(result);
            })
        );
    }

    public void register(String email, String password, final AuthCallback<User<Map<String, Object>>> callback) {
        account.create(
            ID.unique(),
            email,
            password,
            new CoroutineCallback<>((result, error) -> {
                if (error != null) {
                    callback.onError(error);
                    return;
                }
                callback.onSuccess(result);
            })
        );
    }

    public void logout(final AuthCallback<Object> callback) {
        account.deleteSession(
            "current",
            new CoroutineCallback<>((result, error) -> {
                if (error != null) {
                    callback.onError(error);
                    return;
                }
                callback.onSuccess(result);
            })
        );
    }
}
```
{% /section %}

{% section #step-5 step=5 title="Create a login UI in XML" %}
First, update your `activity_main.xml` layout file:

```xml
<?xml version="1.0" encoding="utf-8"?>
<LinearLayout xmlns:android="http://schemas.android.com/apk/res/android"
    xmlns:app="http://schemas.android.com/apk/res-auto"
    xmlns:tools="http://schemas.android.com/tools"
    android:layout_width="match_parent"
    android:layout_height="match_parent"
    android:orientation="vertical"
    android:padding="16dp"
    tools:context=".MainActivity">

    <TextView
        android:id="@+id/textViewStatus"
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:gravity="center"
        android:padding="16dp"
        android:textSize="18sp"
        android:visibility="gone" />

    <Button
        android:id="@+id/buttonLogout"
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:text="Logout"
        android:layout_marginBottom="16dp"
        android:visibility="gone" />

    <EditText
        android:id="@+id/editTextEmail"
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:layout_marginBottom="8dp"
        android:hint="Email"
        android:inputType="textEmailAddress" />

    <EditText
        android:id="@+id/editTextPassword"
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:layout_marginBottom="16dp"
        android:hint="Password"
        android:inputType="textPassword" />

    <LinearLayout
        android:layout_width="match_parent"
        android:layout_height="wrap_content"
        android:orientation="horizontal">

        <Button
            android:id="@+id/buttonLogin"
            android:layout_width="0dp"
            android:layout_height="wrap_content"
            android:layout_weight="1"
            android:layout_marginEnd="8dp"
            android:text="Login" />

        <Button
            android:id="@+id/buttonRegister"
            android:layout_width="0dp"
            android:layout_height="wrap_content"
            android:layout_weight="1"
            android:layout_marginStart="8dp"
            android:text="Register" />
    </LinearLayout>

</LinearLayout>
```
{% /section %}

{% section #step-6 step=6 title="Create MainActivity" %}
Now update your `MainActivity.java` file with the following code:

```java
package <YOUR_ROOT_PACKAGE_HERE>;

import android.os.Bundle;
import android.util.Log;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import java.util.Map;

import io.appwrite.models.Session;
import io.appwrite.models.User;

public class MainActivity extends AppCompatActivity {
    private static final String TAG = "MainActivity";

    private EditText editTextEmail;
    private EditText editTextPassword;
    private Button buttonLogin;
    private Button buttonRegister;
    private TextView textViewStatus;
    private Button buttonLogout;
    private AppwriteHelper appwrite;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Initialize Appwrite
        appwrite = AppwriteHelper.getInstance(getApplicationContext());

        setContentView(R.layout.activity_main);

        // Initialize UI components
        editTextEmail = findViewById(R.id.editTextEmail);
        editTextPassword = findViewById(R.id.editTextPassword);
        buttonLogin = findViewById(R.id.buttonLogin);
        buttonRegister = findViewById(R.id.buttonRegister);
        textViewStatus = findViewById(R.id.textViewStatus);
        buttonLogout = findViewById(R.id.buttonLogout);

        // Set up click listeners
        buttonLogin.setOnClickListener(v -> login());
        buttonRegister.setOnClickListener(v -> register());
        buttonLogout.setOnClickListener(v -> logout());
    }

    private void login() {
        String email = editTextEmail.getText().toString().trim();
        String password = editTextPassword.getText().toString().trim();

        if (email.isEmpty() || password.isEmpty()) {
            Toast.makeText(this, "Please enter email and password", Toast.LENGTH_SHORT).show();
            return;
        }

        appwrite.login(email, password, new AppwriteHelper.AuthCallback<Session>() {
            @Override
            public void onSuccess(Session result) {
                runOnUiThread(() -> {
                    Toast.makeText(MainActivity.this, "Login successful", Toast.LENGTH_SHORT).show();
                    showLoggedInUI(email);
                });
            }

            @Override
            public void onError(Exception error) {
                runOnUiThread(() -> {
                    Log.e(TAG, "Login failed", error);
                    Toast.makeText(MainActivity.this, "Login failed: " + error.getMessage(), Toast.LENGTH_SHORT).show();
                });
            }
        });
    }

    private void register() {
        String email = editTextEmail.getText().toString().trim();
        String password = editTextPassword.getText().toString().trim();

        if (email.isEmpty() || password.isEmpty()) {
            Toast.makeText(this, "Please enter email and password", Toast.LENGTH_SHORT).show();
            return;
        }

        appwrite.register(email, password, new AppwriteHelper.AuthCallback<User<Map<String, Object>>>() {
            @Override
            public void onSuccess(User<Map<String, Object>> result) {
                runOnUiThread(() -> {
                    Toast.makeText(MainActivity.this, "Registration successful. You can now login.", Toast.LENGTH_SHORT).show();
                });
            }

            @Override
            public void onError(Exception error) {
                runOnUiThread(() -> {
                    Log.e(TAG, "Registration failed", error);
                    Toast.makeText(MainActivity.this, "Registration failed: " + error.getMessage(), Toast.LENGTH_SHORT).show();
                });
            }
        });
    }

    private void logout() {
        appwrite.logout(new AppwriteHelper.AuthCallback<Object>() {
            @Override
            public void onSuccess(Object result) {
                runOnUiThread(() -> {
                    Toast.makeText(MainActivity.this, "Logout successful", Toast.LENGTH_SHORT).show();
                    showLoginUI();
                });
            }

            @Override
            public void onError(Exception error) {
                runOnUiThread(() -> {
                    Log.e(TAG, "Logout failed", error);
                    Toast.makeText(MainActivity.this, "Logout failed: " + error.getMessage(), Toast.LENGTH_SHORT).show();
                });
            }
        });
    }

    private void showLoggedInUI(String email) {
        editTextEmail.setVisibility(View.GONE);
        editTextPassword.setVisibility(View.GONE);
        buttonLogin.setVisibility(View.GONE);
        buttonRegister.setVisibility(View.GONE);

        textViewStatus.setVisibility(View.VISIBLE);
        buttonLogout.setVisibility(View.VISIBLE);

        textViewStatus.setText("Logged in as " + email);
    }

    private void showLoginUI() {
        editTextEmail.setVisibility(View.VISIBLE);
        editTextPassword.setVisibility(View.VISIBLE);
        buttonLogin.setVisibility(View.VISIBLE);
        buttonRegister.setVisibility(View.VISIBLE);

        textViewStatus.setVisibility(View.GONE);
        buttonLogout.setVisibility(View.GONE);
    }
}
```
{% /section %}

{% section #step-7 step=7 title="All set" %}
Run your project by clicking **Run app** in Android Studio.
{% /section %}