# Avatars Examples

## GetBrowser

```go
    package appwrite-getbrowser

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

        // Call GetBrowser method and handle results
        var res, err := srv.GetBrowser("aa")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```