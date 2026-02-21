package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

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
