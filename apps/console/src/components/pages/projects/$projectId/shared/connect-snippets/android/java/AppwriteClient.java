import android.content.Context;

import io.appwrite.Client;

public final class AppwriteClient {
  private static volatile Client instance;

  private AppwriteClient() {}

  public static Client get(Context context) {
    if (instance == null) {
      synchronized (AppwriteClient.class) {
        if (instance == null) {
          String endpoint = BuildConfig.APPWRITE_ENDPOINT;
          instance = new Client(
                  context.getApplicationContext(),
                  endpoint,
                  endpoint.replaceFirst("http", "ws"))
              .setProject(BuildConfig.APPWRITE_PROJECT_ID);
        }
      }
    }
    return instance;
  }
}
