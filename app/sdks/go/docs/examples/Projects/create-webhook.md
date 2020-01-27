# Projects Examples

## CreateWebhook

```go
    package appwrite-createwebhook

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

        // Call CreateWebhook method and handle results
        var res, err := srv.CreateWebhook("[PROJECT_ID]", "[NAME]", [], "[URL]", 0)
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```