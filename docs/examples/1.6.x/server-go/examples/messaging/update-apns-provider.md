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
