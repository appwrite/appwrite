import Appwrite
import SwiftUI

struct SignUpView: View {
  let onSignedUp: () -> Void
  let onGoToSignIn: () -> Void

  @State private var name = ""
  @State private var email = ""
  @State private var password = ""
  @State private var error = ""

  var body: some View {
    Text("Sign up")
    if !error.isEmpty {
      Text(error)
    }

    TextField("Name", text: $name)
    TextField("Email", text: $email)
      .keyboardType(.emailAddress)
      .textInputAutocapitalization(.never)
    SecureField("Password", text: $password)

    Button("Sign up", action: submit)

    HStack {
      Text("Already have an account?")
      Button("Sign in", action: onGoToSignIn)
    }
  }

  private func submit() {
    guard !email.isEmpty, !password.isEmpty else { return }
    error = ""
    Task {
      do {
        let account = Account(client)
        let trimmed = name.trimmingCharacters(in: .whitespaces)
        _ = try await account.create(
          userId: ID.unique(),
          email: email,
          password: password,
          name: trimmed.isEmpty ? nil : trimmed
        )
        _ = try await account.createEmailPasswordSession(
          email: email,
          password: password
        )
        onSignedUp()
      } catch let error as AppwriteError {
        self.error = error.message
      } catch {
        self.error = "Sign up failed"
      }
    }
  }
}
