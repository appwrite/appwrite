import { Component, signal } from '@angular/core'
import { Account } from 'appwrite'
import { client } from '../../lib/appwrite'

@Component({
  standalone: true,
  template: `
    @if (user(); as u) {
      <p>Hello, {{ u.name }}</p>
    } @else {
      <p>Sign in to get started.</p>
    }
  `,
})
export default class HomePageComponent {
  user = signal<{ name: string } | null>(null)

  constructor() {
    const account = new Account(client)
    account
      .get()
      .then((u) => this.user.set({ name: u.name }))
      .catch(() => {})
  }
}
