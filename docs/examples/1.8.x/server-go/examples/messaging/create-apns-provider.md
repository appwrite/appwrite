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

response, error := service.CreateAPNSProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateAPNSProviderAuthKey("<AUTH_KEY>"),
    messaging.WithCreateAPNSProviderAuthKeyId("<AUTH_KEY_ID>"),
    messaging.WithCreateAPNSProviderTeamId("<TEAM_ID>"),
    messaging.WithCreateAPNSProviderBundleId("<BUNDLE_ID>"),
    messaging.WithCreateAPNSProviderSandbox(false),
    messaging.WithCreateAPNSProviderEnabled(false),
)
