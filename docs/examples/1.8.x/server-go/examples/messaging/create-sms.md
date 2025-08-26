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

response, error := service.CreateSMS(
    "<MESSAGE_ID>",
    "<CONTENT>",
    messaging.WithCreateSMSTopics([]interface{}{}),
    messaging.WithCreateSMSUsers([]interface{}{}),
    messaging.WithCreateSMSTargets([]interface{}{}),
    messaging.WithCreateSMSDraft(false),
    messaging.WithCreateSMSScheduledAt(""),
)
