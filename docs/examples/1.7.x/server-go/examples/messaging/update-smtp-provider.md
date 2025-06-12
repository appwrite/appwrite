package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := messaging.New(client)
    response, error := service.UpdateSmtpProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateSmtpProviderName("<NAME>"),
        messaging.WithUpdateSmtpProviderHost("<HOST>"),
        messaging.WithUpdateSmtpProviderPort(1),
        messaging.WithUpdateSmtpProviderUsername("<USERNAME>"),
        messaging.WithUpdateSmtpProviderPassword("<PASSWORD>"),
        messaging.WithUpdateSmtpProviderEncryption("none"),
        messaging.WithUpdateSmtpProviderAutoTLS(false),
        messaging.WithUpdateSmtpProviderMailer("<MAILER>"),
        messaging.WithUpdateSmtpProviderFromName("<FROM_NAME>"),
        messaging.WithUpdateSmtpProviderFromEmail("email@example.com"),
        messaging.WithUpdateSmtpProviderReplyToName("<REPLY_TO_NAME>"),
        messaging.WithUpdateSmtpProviderReplyToEmail("<REPLY_TO_EMAIL>"),
        messaging.WithUpdateSmtpProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
