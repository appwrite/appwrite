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

response, error := service.GetScreenshot(
    "https://example.com",
    avatars.WithGetScreenshotHeaders(map[string]interface{}{}),
    avatars.WithGetScreenshotViewportWidth(1),
    avatars.WithGetScreenshotViewportHeight(1),
    avatars.WithGetScreenshotScale(0.1),
    avatars.WithGetScreenshotTheme("light"),
    avatars.WithGetScreenshotUserAgent("<USER_AGENT>"),
    avatars.WithGetScreenshotFullpage(false),
    avatars.WithGetScreenshotLocale("<LOCALE>"),
    avatars.WithGetScreenshotTimezone("africa/abidjan"),
    avatars.WithGetScreenshotLatitude(-90),
    avatars.WithGetScreenshotLongitude(-180),
    avatars.WithGetScreenshotAccuracy(0),
    avatars.WithGetScreenshotTouch(false),
    avatars.WithGetScreenshotPermissions([]interface{}{}),
    avatars.WithGetScreenshotSleep(0),
    avatars.WithGetScreenshotWidth(0),
    avatars.WithGetScreenshotHeight(0),
    avatars.WithGetScreenshotQuality(-1),
    avatars.WithGetScreenshotOutput("jpg"),
)
