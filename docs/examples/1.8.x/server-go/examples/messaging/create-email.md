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
