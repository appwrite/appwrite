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
    response, error := service.CreateEmail(
        "<MESSAGE_ID>",
        "<SUBJECT>",
        "<CONTENT>",
        messaging.WithCreateEmailTopics([]interface{}{}),
        messaging.WithCreateEmailUsers([]interface{}{}),
        messaging.WithCreateEmailTargets([]interface{}{}),
        messaging.WithCreateEmailCc([]interface{}{}),
        messaging.WithCreateEmailBcc([]interface{}{}),
        messaging.WithCreateEmailAttachments([]interface{}{}),
        messaging.WithCreateEmailDraft(false),
        messaging.WithCreateEmailHtml(false),
        messaging.WithCreateEmailScheduledAt(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
