# Projects Examples

## DeleteWebhook

```go
    package appwrite-deletewebhook

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

        // Call DeleteWebhook method and handle results
        var res, err := srv.DeleteWebhook("[PROJECT_ID]", "[WEBHOOK_ID]")
        if err != nil {
            panic(err)
        }

        fmt.Println(res)
    }
```