package main

import (
    "fmt"
    "github.com/appwrite/sdk-for-go"
)

func main() {
    var client := appwrite.Client{}

    client.SetProject("")
    client.SetKey("")

    var service := appwrite.Storage{
        client: &client
    }

    var response, error := service.GetFilePreview("[FILE_ID]", 0, 0, 0, "", "jpg")

    if error != nil {
        panic(error)
    }

    fmt.Println(response)
}