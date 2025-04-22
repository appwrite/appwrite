package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := messaging.NewMessaging(client)
    response, error := service.CreateSmtpProvider(
        "<PROVIDER_ID>",
        "<NAME>",
        "<HOST>",
        messaging.WithCreateSmtpProviderPort(1),
        messaging.WithCreateSmtpProviderUsername("<USERNAME>"),
        messaging.WithCreateSmtpProviderPassword("<PASSWORD>"),
        messaging.WithCreateSmtpProviderEncryption("none"),
        messaging.WithCreateSmtpProviderAutoTLS(false),
        messaging.WithCreateSmtpProviderMailer("<MAILER>"),
        messaging.WithCreateSmtpProviderFromName("<FROM_NAME>"),
        messaging.WithCreateSmtpProviderFromEmail("email@example.com"),
        messaging.WithCreateSmtpProviderReplyToName("<REPLY_TO_NAME>"),
        messaging.WithCreateSmtpProviderReplyToEmail("email@example.com"),
        messaging.WithCreateSmtpProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
