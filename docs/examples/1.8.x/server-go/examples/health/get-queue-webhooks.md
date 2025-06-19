package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/health"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := health.New(client)
    response, error := service.GetQueueWebhooks(
        health.WithGetQueueWebhooksThreshold(0),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
