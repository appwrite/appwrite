import androidx.appcompat.app.AppCompatActivity
import android.os.Bundle
import kotlinx.coroutines.GlobalScope
import kotlinx.coroutines.launch
import io.appwrite.Client
import io.appwrite.services.Locale

public class MainActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        Client client = new Client(getApplicationContext())
            .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
            .setProject("5df5acd0d48c2"); // Your project ID

        Locale locale = new Locale(client);

        locale.listLanguages(new Continuation<Object>() {
            @NotNull
            @Override
            public CoroutineContext getContext() {
                return EmptyCoroutineContext.INSTANCE;
            }

            @Override
            public void resumeWith(@NotNull Object o) {
                String json = "";
                try {
                    if (o instanceof Result.Failure) {
                        Result.Failure failure = (Result.Failure) o;
                        throw failure.exception;
                    } else {
                            Response response = (Response) o;
                            json = response.body().string();
                        }                    
                    }
                } catch (Throwable th) {
                    Log.e("ERROR", th.toString());
                }
            }
        });
    }
}