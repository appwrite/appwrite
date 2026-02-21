```swift
import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let messaging = Messaging(client)

let result = try await messaging.deleteSubscriber(
    topicId: "<TOPIC_ID>",
    subscriberId: "<SUBSCRIBER_ID>"
)

```
