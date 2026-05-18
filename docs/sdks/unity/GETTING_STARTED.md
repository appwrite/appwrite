## Getting Started

Before you begin, create an Appwrite project and add a Unity platform in your Appwrite Console.

This SDK requires the following Unity packages and libraries:

- [**UniTask**](https://github.com/Cysharp/UniTask): For async/await support in Unity.
- [**NativeWebSocket**](https://github.com/endel/NativeWebSocket): For WebSocket realtime subscriptions.
- **System.Text.Json**: For JSON serialization, provided as a DLL in the project.

After installing the SDK, open **Appwrite → Setup Assistant** in Unity and install the required dependencies.

### Configure the SDK

Create an Appwrite configuration using the **QuickStart** window in the **Appwrite Setup Assistant**, or through **Appwrite → Create Configuration**.

### Using AppwriteManager

```csharp
[SerializeField] private AppwriteConfig config;
private AppwriteManager _manager;

private async UniTask ExampleWithManager()
{
    _manager = AppwriteManager.Instance ?? new GameObject("AppwriteManager").AddComponent<AppwriteManager>();
    _manager.SetConfig(config);

    var success = await _manager.Initialize();
    if (!success)
    {
        Debug.LogError("Failed to initialize AppwriteManager");
        return;
    }

    var client = _manager.Client;
    var pingResult = await client.Ping();
    Debug.Log($"Ping result: {pingResult}");

    var account = _manager.GetService<Account>();
    var databases = _manager.GetService<Databases>();

    var realtime = _manager.Realtime;
    var subscription = realtime.Subscribe(
        new[] { "databases.*.collections.*.documents" },
        response => Debug.Log($"Realtime event: {response.Events[0]}")
    );
}
```

### Using Client directly

```csharp
[SerializeField] private AppwriteConfig config;

private async UniTask ExampleWithDirectClient()
{
    var client = new Client()
        .SetEndpoint(config.Endpoint)
        .SetProject(config.ProjectId);

    if (!string.IsNullOrEmpty(config.DevKey))
    {
        client.SetDevKey(config.DevKey);
    }

    if (!string.IsNullOrEmpty(config.RealtimeEndpoint))
    {
        client.SetEndPointRealtime(config.RealtimeEndpoint);
    }

    var pingResult = await client.Ping();
    Debug.Log($"Direct client ping: {pingResult}");

    var account = new Account(client);
    var databases = new Databases(client);
}
```

### Error handling

```csharp
try
{
    var result = await client.Ping();
}
catch (AppwriteException ex)
{
    Debug.LogError($"Appwrite Error: {ex.Message}");
    Debug.LogError($"Status Code: {ex.Code}");
    Debug.LogError($"Response: {ex.Response}");
}
```

## Preparing Models for Databases API

When working with the Databases API in Unity, models should be prepared for serialization using the System.Text.Json library. By default, System.Text.Json converts property names from PascalCase to camelCase when serializing to JSON. If your Appwrite collection attributes are not in camelCase, this can cause errors due to mismatches between serialized property names and actual attribute names in your collection.

To avoid this, add the `JsonPropertyName` attribute to each property in your model class to match the attribute name in Appwrite:

```csharp
using System.Text.Json.Serialization;

public class TestModel
{
    [JsonPropertyName("name")]
    public string Name { get; set; }

    [JsonPropertyName("release_date")]
    public System.DateTime ReleaseDate { get; set; }
}
```

The `JsonPropertyName` attribute ensures your data object is serialized with the correct attribute names for Appwrite databases.
