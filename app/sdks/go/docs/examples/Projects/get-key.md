# Projects Examples

## GetKey

```go
    package appwrite-getkey

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

        // Call GetKey method and handle results
        var res, err := srv.GetKey("[PROJECT_ID]", "[KEY_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```