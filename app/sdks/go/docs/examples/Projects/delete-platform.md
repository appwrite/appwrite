# Projects Examples

## DeletePlatform

```go
    package appwrite-deleteplatform

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

        // Create a new Projects service passing Client
        var srv := appwrite.Projects{
            client: &clt
        }

        // Call DeletePlatform method and handle results
        var res, err := srv.DeletePlatform("[PROJECT_ID]", "[PLATFORM_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```