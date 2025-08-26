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

response, error := service.UpdateMailgunProvider(
    "<PROVIDER_ID>",
    messaging.WithUpdateMailgunProviderName("<NAME>"),
    messaging.WithUpdateMailgunProviderApiKey("<API_KEY>"),
    messaging.WithUpdateMailgunProviderDomain("<DOMAIN>"),
    messaging.WithUpdateMailgunProviderIsEuRegion(false),
    messaging.WithUpdateMailgunProviderEnabled(false),
    messaging.WithUpdateMailgunProviderFromName("<FROM_NAME>"),
    messaging.WithUpdateMailgunProviderFromEmail("email@example.com"),
    messaging.WithUpdateMailgunProviderReplyToName("<REPLY_TO_NAME>"),
    messaging.WithUpdateMailgunProviderReplyToEmail("<REPLY_TO_EMAIL>"),
)
