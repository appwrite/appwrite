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

    var realtime = _manager.Realtime;
    var subscription = realtime.Subscribe(
        new[] { "databases.*.collections.*.documents" },
        response =>
        {
            var eventName = response.Events != null && response.Events.Length > 0
                ? response.Events[0]
                : "unknown";

            Debug.Log($"Realtime event: {eventName}");
        }
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

When working with the Databases API in Unity, models should be prepared for serialization using the System.Text.Json library. System.Text.Json uses CLR property names by default unless a naming policy is configured. If your project or SDK configuration serializes property names differently from your Appwrite collection attributes, this can cause errors due to mismatches between serialized property names and actual attribute names in your collection.

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

### Learn more
You can use the following resources to learn more and get help:

- 🚀 [Getting Started Tutorial](https://appwrite.io/docs/getting-started-for-client)
- 📜 [Appwrite Docs](https://appwrite.io/docs)
- 💬 [Discord Community](https://appwrite.io/discord)
