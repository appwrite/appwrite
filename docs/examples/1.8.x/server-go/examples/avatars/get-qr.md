package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go/client"
    "github.com/appwrite/sdk-for-go/avatars"
)

client := client.New(
    client.WithEndpoint("https://<REGION>.cloud.appwrite.io/v1")
    client.WithProject("<YOUR_PROJECT_ID>")
    client.WithSession("")
)

service := avatars.New(client)

response, error := service.GetQR(
    "<TEXT>",
    avatars.WithGetQRSize(1),
    avatars.WithGetQRMargin(0),
    avatars.WithGetQRDownload(false),
)
