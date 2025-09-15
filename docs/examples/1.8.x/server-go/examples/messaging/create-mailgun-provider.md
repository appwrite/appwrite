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

response, error := service.CreateMailgunProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateMailgunProviderApiKey("<API_KEY>"),
    messaging.WithCreateMailgunProviderDomain("<DOMAIN>"),
    messaging.WithCreateMailgunProviderIsEuRegion(false),
    messaging.WithCreateMailgunProviderFromName("<FROM_NAME>"),
    messaging.WithCreateMailgunProviderFromEmail("email@example.com"),
    messaging.WithCreateMailgunProviderReplyToName("<REPLY_TO_NAME>"),
    messaging.WithCreateMailgunProviderReplyToEmail("email@example.com"),
    messaging.WithCreateMailgunProviderEnabled(false),
)
