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

response, error := service.UpdateVonageProvider(
    "<PROVIDER_ID>",
    messaging.WithUpdateVonageProviderName("<NAME>"),
    messaging.WithUpdateVonageProviderEnabled(false),
    messaging.WithUpdateVonageProviderApiKey("<API_KEY>"),
    messaging.WithUpdateVonageProviderApiSecret("<API_SECRET>"),
    messaging.WithUpdateVonageProviderFrom("<FROM>"),
)
