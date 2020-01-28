package main

import (
    "fmt"
    "os"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    // Create a Client
    var client := appwrite.Client{}

    // Set Client required headers
    client.SetProject("")
    client.SetKey("")

    // Create a new Storage service passing Client
    var srv := appwrite.Storage{
        client: &client
    }

    // Call GetFileDownload method and handle results
    var res, err := srv.GetFileDownload("[FILE_ID]")
    if err != nil {
        panic(err)
    }

    fmt.Println(res)
}