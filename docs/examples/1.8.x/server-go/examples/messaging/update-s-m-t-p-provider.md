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
    response, error := service.UpdateSMTPProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateSMTPProviderName("<NAME>"),
        messaging.WithUpdateSMTPProviderHost("<HOST>"),
        messaging.WithUpdateSMTPProviderPort(1),
        messaging.WithUpdateSMTPProviderUsername("<USERNAME>"),
        messaging.WithUpdateSMTPProviderPassword("<PASSWORD>"),
        messaging.WithUpdateSMTPProviderEncryption("none"),
        messaging.WithUpdateSMTPProviderAutoTLS(false),
        messaging.WithUpdateSMTPProviderMailer("<MAILER>"),
        messaging.WithUpdateSMTPProviderFromName("<FROM_NAME>"),
        messaging.WithUpdateSMTPProviderFromEmail("email@example.com"),
        messaging.WithUpdateSMTPProviderReplyToName("<REPLY_TO_NAME>"),
        messaging.WithUpdateSMTPProviderReplyToEmail("<REPLY_TO_EMAIL>"),
        messaging.WithUpdateSMTPProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
