# Projects Examples

## CreatePlatform

```go
    package appwrite-createplatform

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

        // Create a new Projects service passing Client
        var srv := appwrite.Projects{
            client: &clt
        }

        // Call CreatePlatform method and handle results
        var res, err := srv.CreatePlatform("[PROJECT_ID]", "web", "[NAME]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```