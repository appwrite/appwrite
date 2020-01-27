# Avatars Examples

## GetImage

```go
    package appwrite-getimage

    import (
        "fmt"
        "os"
        "github.com/appwrite/sdk-for-go"
    )

    func main() {
        // Create a Client
        var clt := appwrite.Client{}

        // Set Client required headers
        clt.SetProject("")

        // Create a new Avatars service passing Client
        var srv := appwrite.Avatars{
            client: &clt
        }

        // Call GetImage method and handle results
        var res, err := srv.GetImage("https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```