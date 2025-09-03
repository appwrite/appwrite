package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/storage"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := storage.New(client)

response, error := service.GetFilePreview(
    "<BUCKET_ID>",
    "<FILE_ID>",
    storage.WithGetFilePreviewWidth(0),
    storage.WithGetFilePreviewHeight(0),
    storage.WithGetFilePreviewGravity("center"),
    storage.WithGetFilePreviewQuality(-1),
    storage.WithGetFilePreviewBorderWidth(0),
    storage.WithGetFilePreviewBorderColor(""),
    storage.WithGetFilePreviewBorderRadius(0),
    storage.WithGetFilePreviewOpacity(0),
    storage.WithGetFilePreviewRotation(-360),
    storage.WithGetFilePreviewBackground(""),
    storage.WithGetFilePreviewOutput("jpg"),
    storage.WithGetFilePreviewToken("<TOKEN>"),
)
