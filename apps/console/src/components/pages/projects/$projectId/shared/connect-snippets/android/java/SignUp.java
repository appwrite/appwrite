import android.content.Context;
import android.text.InputType;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import io.appwrite.ID;
import io.appwrite.coroutines.CoroutineCallback;
import io.appwrite.services.Account;

public final class SignUp {
  private SignUp() {}

  public static View create(
      Context context, Runnable onSignedUp, Runnable onGoToSignIn) {
    LinearLayout layout = new LinearLayout(context);
    layout.setOrientation(LinearLayout.VERTICAL);
    int pad = (int) (24 * context.getResources().getDisplayMetrics().density);
    layout.setPadding(pad, pad, pad, pad);

    TextView heading = new TextView(context);
    heading.setText("Sign up");

    TextView error = new TextView(context);
    error.setVisibility(View.GONE);

    EditText name = new EditText(context);
    name.setHint("Name");
    name.setInputType(InputType.TYPE_CLASS_TEXT);

    EditText email = new EditText(context);
    email.setHint("Email");
    email.setInputType(InputType.TYPE_CLASS_TEXT
        | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);

    EditText password = new EditText(context);
    password.setHint("Password");
    password.setInputType(InputType.TYPE_CLASS_TEXT
        | InputType.TYPE_TEXT_VARIATION_PASSWORD);

    Button submit = new Button(context);
    submit.setText("Sign up");
    submit.setAllCaps(false);
    submit.setOnClickListener(v -> {
      String emailValue = email.getText().toString();
      String passwordValue = password.getText().toString();
      if (emailValue.isEmpty() || passwordValue.isEmpty()) return;
      String nameValue = name.getText().toString().trim();
      error.setVisibility(View.GONE);

      Account account = new Account(AppwriteClient.get(context));
      try {
        account.create(
            ID.Companion.unique(7),
            emailValue,
            passwordValue,
            nameValue.isEmpty() ? null : nameValue,
            new CoroutineCallback<>((user, e) -> {
              if (e != null) {
                layout.post(() -> showError(error, e, "Sign up failed"));
                return;
              }
              try {
                account.createEmailPasswordSession(emailValue, passwordValue,
                    new CoroutineCallback<>((session, e2) -> layout.post(() -> {
                      if (e2 == null) {
                        onSignedUp.run();
                      } else {
                        showError(error, e2, "Sign up failed");
                      }
                    })));
              } catch (Exception ignored) {}
            }));
      } catch (Exception ignored) {}
    });

    TextView hint = new TextView(context);
    hint.setText("Already have an account?");

    Button goToSignIn = new Button(context);
    goToSignIn.setText("Sign in");
    goToSignIn.setAllCaps(false);
    goToSignIn.setOnClickListener(v -> onGoToSignIn.run());

    layout.addView(heading);
    layout.addView(error);
    layout.addView(name);
    layout.addView(email);
    layout.addView(password);
    layout.addView(submit);
    layout.addView(hint);
    layout.addView(goToSignIn);
    return layout;
  }

  private static void showError(TextView error, Throwable e, String fallback) {
    error.setText(e.getMessage() != null ? e.getMessage() : fallback);
    error.setVisibility(View.VISIBLE);
  }
}
