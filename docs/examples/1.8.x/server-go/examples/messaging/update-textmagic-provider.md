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

response, error := service.UpdateTextmagicProvider(
    "<PROVIDER_ID>",
    messaging.WithUpdateTextmagicProviderName("<NAME>"),
    messaging.WithUpdateTextmagicProviderEnabled(false),
    messaging.WithUpdateTextmagicProviderUsername("<USERNAME>"),
    messaging.WithUpdateTextmagicProviderApiKey("<API_KEY>"),
    messaging.WithUpdateTextmagicProviderFrom("<FROM>"),
)
