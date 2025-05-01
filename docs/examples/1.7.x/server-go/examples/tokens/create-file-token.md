package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/tokens"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := tokens.NewTokens(client)
    response, error := service.CreateFileToken(
        "<BUCKET_ID>",
        "<FILE_ID>",
        tokens.WithCreateFileTokenExpire(""),
        tokens.WithCreateFileTokenPermissions(interface{}{"read("any")"}),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
