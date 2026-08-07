import android.content.Context;

import io.appwrite.Client;

public final class AppwriteClient {
  private static volatile Client instance;

  private AppwriteClient() {}

  public static Client get(Context context) {
    if (instance == null) {
      synchronized (AppwriteClient.class) {
        if (instance == null) {
          instance = new Client(context.getApplicationContext())
              .setEndpoint(BuildConfig.APPWRITE_ENDPOINT)
              .setProject(BuildConfig.APPWRITE_PROJECT_ID);
        }
      }
    }
    return instance;
  }
}
