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
    response, error := service.UpdateAPNSProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateAPNSProviderName("<NAME>"),
        messaging.WithUpdateAPNSProviderEnabled(false),
        messaging.WithUpdateAPNSProviderAuthKey("<AUTH_KEY>"),
        messaging.WithUpdateAPNSProviderAuthKeyId("<AUTH_KEY_ID>"),
        messaging.WithUpdateAPNSProviderTeamId("<TEAM_ID>"),
        messaging.WithUpdateAPNSProviderBundleId("<BUNDLE_ID>"),
        messaging.WithUpdateAPNSProviderSandbox(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
