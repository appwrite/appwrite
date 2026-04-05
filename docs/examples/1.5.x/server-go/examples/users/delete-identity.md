package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/users"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetKey("<YOUR_API_KEY>") // Your secret API key

    service := users.NewUsers(client)
    response, error := service.DeleteIdentity(
        "<IDENTITY_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
