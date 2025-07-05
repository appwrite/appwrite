package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/locale"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := locale.NewLocale(client)
    response, error := service.ListCurrencies(
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
