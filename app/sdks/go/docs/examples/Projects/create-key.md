# Projects Examples

## CreateKey

```go
    package appwrite-createkey

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

        // Call CreateKey method and handle results
        var res, err := srv.CreateKey("[PROJECT_ID]", "[NAME]", [])
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```