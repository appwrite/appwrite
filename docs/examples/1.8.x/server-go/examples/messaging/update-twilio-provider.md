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

response, error := service.UpdateTwilioProvider(
    "<PROVIDER_ID>",
    messaging.WithUpdateTwilioProviderName("<NAME>"),
    messaging.WithUpdateTwilioProviderEnabled(false),
    messaging.WithUpdateTwilioProviderAccountSid("<ACCOUNT_SID>"),
    messaging.WithUpdateTwilioProviderAuthToken("<AUTH_TOKEN>"),
    messaging.WithUpdateTwilioProviderFrom("<FROM>"),
)
