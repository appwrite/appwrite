# Avatars Examples

## GetFavicon

```go
    package appwrite-getfavicon

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
        clt.SetKey("")

        // Create a new Avatars service passing Client
        var srv := appwrite.Avatars{
            client: &clt
        }

        // Call GetFavicon method and handle results
        var res, err := srv.GetFavicon("https://example.com")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```