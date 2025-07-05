import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Storage

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val client = Client(applicationContext)
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("5df5acd0d48c2") // Your project ID

        val storage = Storage(client)

        GlobalScope.launch {
            val result = storage.getFilePreview(
                bucketId = "[BUCKET_ID]",
                fileId = "[FILE_ID]",
            )
            println(result); // Resource URL        
        }
    }
}