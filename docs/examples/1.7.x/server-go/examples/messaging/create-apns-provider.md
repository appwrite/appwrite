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
    response, error := service.CreateApnsProvider(
        "<PROVIDER_ID>",
        "<NAME>",
        messaging.WithCreateApnsProviderAuthKey("<AUTH_KEY>"),
        messaging.WithCreateApnsProviderAuthKeyId("<AUTH_KEY_ID>"),
        messaging.WithCreateApnsProviderTeamId("<TEAM_ID>"),
        messaging.WithCreateApnsProviderBundleId("<BUNDLE_ID>"),
        messaging.WithCreateApnsProviderSandbox(false),
        messaging.WithCreateApnsProviderEnabled(false),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
