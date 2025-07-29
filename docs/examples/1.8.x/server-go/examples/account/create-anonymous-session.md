package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/account"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
    )

    service := account.New(client)
    response, error := service.CreateAnonymousSession(
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
