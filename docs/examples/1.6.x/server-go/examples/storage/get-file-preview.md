package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/storage"
)

func main() {
    client := client.NewClient()

    client.SetEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    client.SetProject("<YOUR_PROJECT_ID>") // Your project ID
    client.SetSession("") // The user session to authenticate with

    service := storage.NewStorage(client)
    response, error := service.GetFilePreview(
        "<BUCKET_ID>",
        "<FILE_ID>",
        storage.WithGetFilePreviewWidth(0),
        storage.WithGetFilePreviewHeight(0),
        storage.WithGetFilePreviewGravity("center"),
        storage.WithGetFilePreviewQuality(0),
        storage.WithGetFilePreviewBorderWidth(0),
        storage.WithGetFilePreviewBorderColor(""),
        storage.WithGetFilePreviewBorderRadius(0),
        storage.WithGetFilePreviewOpacity(0),
        storage.WithGetFilePreviewRotation(-360),
        storage.WithGetFilePreviewBackground(""),
        storage.WithGetFilePreviewOutput("jpg"),
    )

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}
