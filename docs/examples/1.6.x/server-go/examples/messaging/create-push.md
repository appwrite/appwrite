package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("") // Your project ID
    client.SetKey("") // Your secret API key

    service := messaging.NewMessaging(client)
    response, error := service.CreatePush(
        "<MESSAGE_ID>",
        "<TITLE>",
        "<BODY>",
        messaging.WithCreatePushTopics([]interface{}{}),
        messaging.WithCreatePushUsers([]interface{}{}),
        messaging.WithCreatePushTargets([]interface{}{}),
        messaging.WithCreatePushData(map[string]interface{}{}),
        messaging.WithCreatePushAction("<ACTION>"),
        messaging.WithCreatePushImage("[ID1:ID2]"),
        messaging.WithCreatePushIcon("<ICON>"),
        messaging.WithCreatePushSound("<SOUND>"),
        messaging.WithCreatePushColor("<COLOR>"),
        messaging.WithCreatePushTag("<TAG>"),
        messaging.WithCreatePushBadge("<BADGE>"),
        messaging.WithCreatePushDraft(false),
        messaging.WithCreatePushScheduledAt(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
