package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/avatars"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithSession("") // The user session to authenticate with
    )

    service := avatars.New(client)
    response, error := service.GetBrowser(
        "aa",
        avatars.WithGetBrowserWidth(0),
        avatars.WithGetBrowserHeight(0),
        avatars.WithGetBrowserQuality(-1),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
