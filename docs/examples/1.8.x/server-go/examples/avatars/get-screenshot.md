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
    avatars.WithGetScreenshotHeaders(map[string]interface{}{
        "Authorization": "Bearer token123",
        "X-Custom-Header": "value"
    }),
    avatars.WithGetScreenshotViewportWidth(1920),
    avatars.WithGetScreenshotViewportHeight(1080),
    avatars.WithGetScreenshotScale(2),
    avatars.WithGetScreenshotTheme("dark"),
    avatars.WithGetScreenshotUserAgent("Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15"),
    avatars.WithGetScreenshotFullpage(true),
    avatars.WithGetScreenshotLocale("en-US"),
    avatars.WithGetScreenshotTimezone("america/new_york"),
    avatars.WithGetScreenshotLatitude(37.7749),
    avatars.WithGetScreenshotLongitude(-122.4194),
    avatars.WithGetScreenshotAccuracy(100),
    avatars.WithGetScreenshotTouch(true),
    avatars.WithGetScreenshotPermissions(interface{}{"geolocation","notifications"}),
    avatars.WithGetScreenshotSleep(3),
    avatars.WithGetScreenshotWidth(800),
    avatars.WithGetScreenshotHeight(600),
    avatars.WithGetScreenshotQuality(85),
    avatars.WithGetScreenshotOutput("jpeg"),
)
