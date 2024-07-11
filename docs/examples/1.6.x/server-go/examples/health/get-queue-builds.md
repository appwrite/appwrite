package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/health"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("") // Your project ID
    client.SetKey("") // Your secret API key

    service := health.NewHealth(client)
    response, error := service.GetQueueBuilds(
        health.WithGetQueueBuildsThreshold(0),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
