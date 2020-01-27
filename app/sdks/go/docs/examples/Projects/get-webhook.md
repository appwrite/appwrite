# Projects Examples

## GetWebhook

```go
    package appwrite-getwebhook

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

        // Call GetWebhook method and handle results
        var res, err := srv.GetWebhook("[PROJECT_ID]", "[WEBHOOK_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```