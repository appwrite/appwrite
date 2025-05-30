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
