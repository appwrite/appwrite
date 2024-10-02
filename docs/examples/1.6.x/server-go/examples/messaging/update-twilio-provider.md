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
    response, error := service.UpdateTwilioProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateTwilioProviderName("<NAME>"),
        messaging.WithUpdateTwilioProviderEnabled(false),
        messaging.WithUpdateTwilioProviderAccountSid("<ACCOUNT_SID>"),
        messaging.WithUpdateTwilioProviderAuthToken("<AUTH_TOKEN>"),
        messaging.WithUpdateTwilioProviderFrom("<FROM>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
