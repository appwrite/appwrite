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
    response, error := service.CreateTextmagicProvider(
        "<PROVIDER_ID>",
        "<NAME>",
        messaging.WithCreateTextmagicProviderFrom("+12065550100"),
        messaging.WithCreateTextmagicProviderUsername("<USERNAME>"),
        messaging.WithCreateTextmagicProviderApiKey("<API_KEY>"),
        messaging.WithCreateTextmagicProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
