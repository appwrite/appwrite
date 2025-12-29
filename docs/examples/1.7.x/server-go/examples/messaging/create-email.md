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
