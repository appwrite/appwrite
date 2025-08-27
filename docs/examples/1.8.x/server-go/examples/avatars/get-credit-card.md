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

response, error := service.GetCreditCard(
    "amex",
    avatars.WithGetCreditCardWidth(0),
    avatars.WithGetCreditCardHeight(0),
    avatars.WithGetCreditCardQuality(-1),
)
