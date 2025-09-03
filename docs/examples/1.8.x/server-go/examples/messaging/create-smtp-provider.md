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

response, error := service.CreateSMTPProvider(
    "<PROVIDER_ID>",
    "<NAME>",
    "<HOST>",
    messaging.WithCreateSMTPProviderPort(1),
    messaging.WithCreateSMTPProviderUsername("<USERNAME>"),
    messaging.WithCreateSMTPProviderPassword("<PASSWORD>"),
    messaging.WithCreateSMTPProviderEncryption("none"),
    messaging.WithCreateSMTPProviderAutoTLS(false),
    messaging.WithCreateSMTPProviderMailer("<MAILER>"),
    messaging.WithCreateSMTPProviderFromName("<FROM_NAME>"),
    messaging.WithCreateSMTPProviderFromEmail("email@example.com"),
    messaging.WithCreateSMTPProviderReplyToName("<REPLY_TO_NAME>"),
    messaging.WithCreateSMTPProviderReplyToEmail("email@example.com"),
    messaging.WithCreateSMTPProviderEnabled(false),
)
