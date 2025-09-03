package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithKey("<YOUR_API_KEY>")
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
