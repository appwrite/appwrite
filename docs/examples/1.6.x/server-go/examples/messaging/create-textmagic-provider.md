package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/messaging"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

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
