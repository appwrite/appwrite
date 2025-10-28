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
    response, error := service.CreateVonageProvider(
        "<PROVIDER_ID>",
        "<NAME>",
        messaging.WithCreateVonageProviderFrom("+12065550100"),
        messaging.WithCreateVonageProviderApiKey("<API_KEY>"),
        messaging.WithCreateVonageProviderApiSecret("<API_SECRET>"),
        messaging.WithCreateVonageProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
