import { Injectable } from '@angular/core'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

@Injectable({ providedIn: 'root' })
export class AppwriteService {
  private account = new Account(client)

  getUser() {
    return this.account.get()
  }
}
