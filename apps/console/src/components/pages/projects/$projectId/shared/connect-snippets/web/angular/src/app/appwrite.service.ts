import { Injectable } from '@angular/core'
import { Account, ID } from 'appwrite'
import { client } from '../lib/appwrite'

@Injectable({ providedIn: 'root' })
export class AppwriteService {
  private account = new Account(client)

  getUser() {
    return this.account.get()
  }

  signUp(name: string, email: string, password: string) {
    return this.account.create({
      userId: ID.unique(),
      email,
      password,
      name: name.trim() || undefined,
    })
  }

  signIn(email: string, password: string) {
    return this.account.createEmailPasswordSession({ email, password })
  }

  signOut() {
    return this.account.deleteSession({ sessionId: 'current' })
  }
}
