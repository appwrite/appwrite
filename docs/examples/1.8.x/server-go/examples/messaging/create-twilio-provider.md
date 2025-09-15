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

response, error := service.CreateTwilioProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateTwilioProviderFrom("+12065550100"),
    messaging.WithCreateTwilioProviderAccountSid("<ACCOUNT_SID>"),
    messaging.WithCreateTwilioProviderAuthToken("<AUTH_TOKEN>"),
    messaging.WithCreateTwilioProviderEnabled(false),
)
