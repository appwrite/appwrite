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

response, error := service.CreateVonageProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateVonageProviderFrom("+12065550100"),
    messaging.WithCreateVonageProviderApiKey("<API_KEY>"),
    messaging.WithCreateVonageProviderApiSecret("<API_SECRET>"),
    messaging.WithCreateVonageProviderEnabled(false),
)
