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

response, error := service.CreateResendProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateResendProviderApiKey("<API_KEY>"),
    messaging.WithCreateResendProviderFromName("<FROM_NAME>"),
    messaging.WithCreateResendProviderFromEmail("email@example.com"),
    messaging.WithCreateResendProviderReplyToName("<REPLY_TO_NAME>"),
    messaging.WithCreateResendProviderReplyToEmail("email@example.com"),
    messaging.WithCreateResendProviderEnabled(false),
)
