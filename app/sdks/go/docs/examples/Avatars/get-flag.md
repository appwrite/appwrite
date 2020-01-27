# Avatars Examples

## GetFlag

```go
    package appwrite-getflag

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

        // Call GetFlag method and handle results
        var res, err := srv.GetFlag("af")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```