package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("") // Your project ID
    client.SetKey("") // Your secret API key

    service := messaging.NewMessaging(client)
    response, error := service.UpdateMailgunProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateMailgunProviderName("<NAME>"),
        messaging.WithUpdateMailgunProviderApiKey("<API_KEY>"),
        messaging.WithUpdateMailgunProviderDomain("<DOMAIN>"),
        messaging.WithUpdateMailgunProviderIsEuRegion(false),
        messaging.WithUpdateMailgunProviderEnabled(false),
        messaging.WithUpdateMailgunProviderFromName("<FROM_NAME>"),
        messaging.WithUpdateMailgunProviderFromEmail("email@example.com"),
        messaging.WithUpdateMailgunProviderReplyToName("<REPLY_TO_NAME>"),
        messaging.WithUpdateMailgunProviderReplyToEmail("<REPLY_TO_EMAIL>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
