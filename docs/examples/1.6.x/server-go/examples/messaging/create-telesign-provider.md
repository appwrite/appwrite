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
    response, error := service.CreateTelesignProvider(
        "<PROVIDER_ID>",
        "<NAME>",
        messaging.WithCreateTelesignProviderFrom("+12065550100"),
        messaging.WithCreateTelesignProviderCustomerId("<CUSTOMER_ID>"),
        messaging.WithCreateTelesignProviderApiKey("<API_KEY>"),
        messaging.WithCreateTelesignProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
