## Add Appwrite auth to a new Android (Java) app with a working login/register/logout UI

- Never assume project details. Ask the user for **Cloud Region**, **Project ID**, and **package name**.
- Use **Android Studio** as the development environment.
- Ensure the user creates a platform in the Appwrite Console with the correct **package name**.

## Step 1: Create or use existing Android project

- If you already have an Android project open in Android Studio, stay in it and use it.
- Otherwise, open Android Studio and click **New Project**.
- Choose a project template (e.g., **Empty Activity**) and click **Next**.
- Enter the app **name** and **package name**, then click **Finish**.

## Step 2: Create Appwrite project

- Ask the user to go to the [Appwrite Console](https://cloud.appwrite.io/console).
- If this is their first time, they should create an account and create their first project.
- Under **Add a platform**, add an **Android app** with the app's **name** and **package name** (the `applicationId` from the app-level `build.gradle`).

## Step 3: Add the Appwrite SDK (ask user for Project ID)

- Add the following dependency to the app-level `build.gradle` file inside the **dependencies** block:

```groovy
implementation "io.appwrite:sdk-for-android:8.1.0"
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

## Step 4: Create Appwrite helper class (ask user for Region and Project ID)

- Ask the user for:
    - **Cloud Region** (e.g., `fra`, `nyc`)
    - **Project ID** (from Console -> Settings)
- Create file: `AppwriteHelper.java` with the following code (replace `<YOUR_ROOT_PACKAGE_HERE>`, `<REGION>`, and `<PROJECT_ID>`):

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
                    callback.onError(new Exception(error));
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
                    callback.onError(new Exception(error));
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
                    callback.onError(new Exception(error));
                    return;
                }
                callback.onSuccess(result);
            })
        );
    }
}
```

## Step 5: Create login UI in XML

- Update `activity_main.xml` layout file:

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

## Step 6: Create MainActivity

- Update `MainActivity.java` with the following code (replace `<YOUR_ROOT_PACKAGE_HERE>`):

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

## Step 7: Run and test

- Click **Run app** in Android Studio.
- Test flows:
    - Register a new user
    - Login with the registered user
    - Logout
- Surface any Appwrite errors and fix by guiding updates to `AppwriteHelper.java` and Console settings.

## Deliverables

- A running Android app with working Appwrite auth (register/login/logout)
- Files created/updated: app-level `build.gradle` (dependency), `AndroidManifest.xml` (OAuth callback), `AppwriteHelper.java`, `activity_main.xml`, `MainActivity.java`
