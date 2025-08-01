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
    response, error := service.UpdateApnsProvider(
        "<PROVIDER_ID>",
        messaging.WithUpdateApnsProviderName("<NAME>"),
        messaging.WithUpdateApnsProviderEnabled(false),
        messaging.WithUpdateApnsProviderAuthKey("<AUTH_KEY>"),
        messaging.WithUpdateApnsProviderAuthKeyId("<AUTH_KEY_ID>"),
        messaging.WithUpdateApnsProviderTeamId("<TEAM_ID>"),
        messaging.WithUpdateApnsProviderBundleId("<BUNDLE_ID>"),
        messaging.WithUpdateApnsProviderSandbox(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
