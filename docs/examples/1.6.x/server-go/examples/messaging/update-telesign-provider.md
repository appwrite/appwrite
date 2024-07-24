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
    response, error := service.UpdateTelesignProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateTelesignProviderName("<NAME>"),
        messaging.WithUpdateTelesignProviderEnabled(false),
        messaging.WithUpdateTelesignProviderCustomerId("<CUSTOMER_ID>"),
        messaging.WithUpdateTelesignProviderApiKey("<API_KEY>"),
        messaging.WithUpdateTelesignProviderFrom("<FROM>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
