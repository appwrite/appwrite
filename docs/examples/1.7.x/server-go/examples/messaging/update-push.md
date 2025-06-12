package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := messaging.New(client)
    response, error := service.UpdatePush(
        "<MESSAGE_ID>",
        messaging.WithUpdatePushTopics([]interface{}{}),
        messaging.WithUpdatePushUsers([]interface{}{}),
        messaging.WithUpdatePushTargets([]interface{}{}),
        messaging.WithUpdatePushTitle("<TITLE>"),
        messaging.WithUpdatePushBody("<BODY>"),
        messaging.WithUpdatePushData(map[string]interface{}{}),
        messaging.WithUpdatePushAction("<ACTION>"),
        messaging.WithUpdatePushImage("[ID1:ID2]"),
        messaging.WithUpdatePushIcon("<ICON>"),
        messaging.WithUpdatePushSound("<SOUND>"),
        messaging.WithUpdatePushColor("<COLOR>"),
        messaging.WithUpdatePushTag("<TAG>"),
        messaging.WithUpdatePushBadge(0),
        messaging.WithUpdatePushDraft(false),
        messaging.WithUpdatePushScheduledAt(""),
        messaging.WithUpdatePushContentAvailable(false),
        messaging.WithUpdatePushCritical(false),
        messaging.WithUpdatePushPriority("normal"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
