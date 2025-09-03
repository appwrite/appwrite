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

response, error := service.CreateMsg91Provider(
    "<PROVIDER_ID>",
    "<NAME>",
    messaging.WithCreateMsg91ProviderTemplateId("<TEMPLATE_ID>"),
    messaging.WithCreateMsg91ProviderSenderId("<SENDER_ID>"),
    messaging.WithCreateMsg91ProviderAuthKey("<AUTH_KEY>"),
    messaging.WithCreateMsg91ProviderEnabled(false),
)
