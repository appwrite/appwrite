package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/account"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := account.NewAccount(client)
    response, error := service.DeleteSession(
        "<SESSION_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
