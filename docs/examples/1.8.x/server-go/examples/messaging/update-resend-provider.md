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

response, error := service.UpdateResendProvider(
    "<PROVIDER_ID>",
    messaging.WithUpdateResendProviderName("<NAME>"),
    messaging.WithUpdateResendProviderEnabled(false),
    messaging.WithUpdateResendProviderApiKey("<API_KEY>"),
    messaging.WithUpdateResendProviderFromName("<FROM_NAME>"),
    messaging.WithUpdateResendProviderFromEmail("email@example.com"),
    messaging.WithUpdateResendProviderReplyToName("<REPLY_TO_NAME>"),
    messaging.WithUpdateResendProviderReplyToEmail("<REPLY_TO_EMAIL>"),
)
