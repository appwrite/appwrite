package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/grids"
)

func main() {
    client := client.New(
        client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
        client.WithProject("<YOUR_PROJECT_ID>") // Your project ID
        client.WithKey("<YOUR_API_KEY>") // Your secret API key
    )

    service := grids.New(client)
    response, error := service.UpdateFloatColumn(
        "<DATABASE_ID>",
        "<TABLE_ID>",
        "",
        false,
        0,
        grids.WithUpdateFloatColumnMin(0),
        grids.WithUpdateFloatColumnMax(0),
        grids.WithUpdateFloatColumnNewKey(""),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
