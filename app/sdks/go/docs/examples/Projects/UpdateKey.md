# Projects Examples

## UpdateKey

```go
    package appwrite-updatekey

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

        // Call UpdateKey method and handle results
        var res, err := srv.UpdateKey("[PROJECT_ID]", "[KEY_ID]", "[NAME]", [])
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```