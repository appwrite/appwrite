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
    response, error := service.UpdateSms(
        "<MESSAGE_ID>",
        messaging.WithUpdateSmsTopics([]interface{}{}),
        messaging.WithUpdateSmsUsers([]interface{}{}),
        messaging.WithUpdateSmsTargets([]interface{}{}),
        messaging.WithUpdateSmsContent("<CONTENT>"),
        messaging.WithUpdateSmsDraft(false),
        messaging.WithUpdateSmsScheduledAt(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
