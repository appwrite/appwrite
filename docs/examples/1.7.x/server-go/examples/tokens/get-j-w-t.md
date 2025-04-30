package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tokens"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://example.com/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := tokens.NewTokens(client)
    response, error := service.GetJWT(
        "<TOKEN_ID>",
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
