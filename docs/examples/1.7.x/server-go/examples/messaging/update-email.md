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
    response, error := service.UpdateEmail(
        "<MESSAGE_ID>",
        messaging.WithUpdateEmailTopics([]interface{}{}),
        messaging.WithUpdateEmailUsers([]interface{}{}),
        messaging.WithUpdateEmailTargets([]interface{}{}),
        messaging.WithUpdateEmailSubject("<SUBJECT>"),
        messaging.WithUpdateEmailContent("<CONTENT>"),
        messaging.WithUpdateEmailDraft(false),
        messaging.WithUpdateEmailHtml(false),
        messaging.WithUpdateEmailCc([]interface{}{}),
        messaging.WithUpdateEmailBcc([]interface{}{}),
        messaging.WithUpdateEmailScheduledAt(""),
        messaging.WithUpdateEmailAttachments([]interface{}{}),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
