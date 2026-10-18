## Getting Started

### Install dependencies

The SDK depends on two packages that are installed separately:

- [**UniTask**](https://github.com/Cysharp/UniTask) for async/await support.
- [**NativeWebSocket**](https://github.com/endel/NativeWebSocket) for realtime subscriptions.

When you first import the SDK, the **Appwrite Setup Assistant** opens and offers to install them. You can reopen it at any time from **Appwrite > Setup Assistant**. The SDK's runtime code stays disabled until UniTask is installed. `System.Text.Json` and its dependencies ship with the SDK as DLLs.

### Configure your project

Create a project in the [Appwrite Console](https://cloud.appwrite.io/). Find your endpoint and project ID in the project's **Settings** page.

If you build for WebGL, add a **Web** platform to your project with the hostname that serves your build, so Appwrite accepts requests from it.

Then create an `AppwriteConfig` asset with **Appwrite > Create Configuration**, or click **Setup Current Scene** in the Setup Assistant to create the asset and add an `AppwriteManager` to the open scene. Set the endpoint (for example `https://<REGION>.cloud.appwrite.io/v1`), your project ID, and the services you want `AppwriteManager` to create.

### Make your first request with AppwriteManager

`AppwriteManager` creates the client and the services selected in your configuration, and can open the realtime connection.

```csharp
using Appwrite;
using Appwrite.Services;
using Cysharp.Threading.Tasks;
using UnityEngine;

public class AppwriteExample : MonoBehaviour
{
    [SerializeField] private AppwriteConfig config;

    private RealtimeSubscription subscription;

    private async void Start()
    {
        var manager = AppwriteManager.Instance;
        if (manager == null)
        {
            manager = new GameObject("AppwriteManager").AddComponent<AppwriteManager>();
        }

        manager.SetConfig(config);

        if (!await manager.Initialize(needRealtime: true))
        {
            Debug.LogError("Failed to initialize AppwriteManager");
            return;
        }

        var tablesDB = manager.GetService<TablesDB>();
        var rows = await tablesDB.ListRows(
            databaseId: "<DATABASE_ID>",
            tableId: "<TABLE_ID>");
        Debug.Log($"Found {rows.Total} rows");

        subscription = manager.Realtime.Subscribe(
            Channel.TablesDB("<DATABASE_ID>").Table("<TABLE_ID>").Row(),
            response => Debug.Log($"Realtime event: {response.Events[0]}"));
    }

    private void OnDestroy()
    {
        subscription?.Close();
    }
}
```

`GetService<T>()` throws if the service is not selected in your `AppwriteConfig`.

### Using the client directly

You can also create a client and services yourself, without `AppwriteManager`:

```csharp
var client = Client.From(
    projectId: "<PROJECT_ID>",
    endpoint: "https://<REGION>.cloud.appwrite.io/v1");

var account = new Account(client);
var session = await account.CreateAnonymousSession();
Debug.Log($"Signed in as {session.UserId}");
```

`Client.FromSession`, `Client.FromJWT` and `Client.FromImpersonation` create clients that are already authenticated.

### OAuth sign-in

`account.CreateOAuth2Session()` opens the provider's sign-in page and waits for Appwrite to redirect back to your app on the `appwrite-callback-<PROJECT_ID>` URL scheme. Appwrite accepts this scheme for your project without extra Console setup, but your build must handle it:

- **Android**: see [OAuth on Android](#oauth-on-android).
- **iOS**: add `appwrite-callback-<PROJECT_ID>` to **Supported URL schemes** under **Project Settings > Player > iOS > Other Settings**.
- **WebGL**: not supported. The sign-in page replaces your build in the same tab, and a browser cannot return to it on the callback scheme, so the call never completes. Use another sign-in method, such as email and password, in WebGL builds.

### Error handling

The SDK throws an `AppwriteException` when a request fails. It exposes `Message`, `Code`, `Type`, and `Response` properties that you can use to inspect the error.

```csharp
try
{
    var user = await account.Get();
}
catch (AppwriteException ex)
{
    Debug.LogError($"Appwrite error: {ex.Message}");
    Debug.LogError($"Status code: {ex.Code}");
    Debug.LogError($"Response: {ex.Response}");
}
```

### Preparing models for TablesDB

The SDK serializes request data with `System.Text.Json` and converts property names from `PascalCase` to `camelCase`. If your table's column names are not in `camelCase`, the serialized names will not match your columns.

Add the `JsonPropertyName` attribute to each property of your model so it serializes to the column name:

```csharp
using System.Text.Json.Serialization;

public class Movie
{
    [JsonPropertyName("name")]
    public string Name { get; set; }

    [JsonPropertyName("release_date")]
    public System.DateTime ReleaseDate { get; set; }
}
```

### Learn more
You can use the following resources to learn more and get help:

- 🚀 [Appwrite Quick Starts](https://appwrite.io/docs/quick-starts)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
