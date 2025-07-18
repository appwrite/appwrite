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
    response, error := service.UpdateMsg91Provider(
        "<PROVIDER_ID>",
        messaging.WithUpdateMsg91ProviderName("<NAME>"),
        messaging.WithUpdateMsg91ProviderEnabled(false),
        messaging.WithUpdateMsg91ProviderTemplateId("<TEMPLATE_ID>"),
        messaging.WithUpdateMsg91ProviderSenderId("<SENDER_ID>"),
        messaging.WithUpdateMsg91ProviderAuthKey("<AUTH_KEY>"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
