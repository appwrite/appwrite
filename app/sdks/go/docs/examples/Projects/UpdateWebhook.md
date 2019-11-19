# Projects Examples

## UpdateWebhook

```go
    package appwrite-updatewebhook

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

        // Call UpdateWebhook method and handle results
        var res, err := srv.UpdateWebhook("[PROJECT_ID]", "[WEBHOOK_ID]", "[NAME]", [], "[URL]", 0)
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```