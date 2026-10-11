import { afterNextRender, Component, signal } from '@angular/core'
import { RouterLink } from '@angular/router'
import { Account } from 'appwrite'
import { client } from '../../lib/appwrite'

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    @if (loading()) {
      <p>Loading...</p>
    } @else {
      @if (user(); as u) {
        <p>Hello, {{ u.name }}</p>
        <button type="button" (click)="handleSignOut()">Sign out</button>
      } @else {
        <p>Sign in to get started.</p>
        <p>
          <a routerLink="/sign-in">Sign in</a>
          ·
          <a routerLink="/sign-up">Sign up</a>
        </p>
      }
    }
  `,
})
export default class HomePageComponent {
  user = signal<{ name: string } | null>(null)
  loading = signal(true)

  constructor() {
    afterNextRender(() => {
      const account = new Account(client)
      account
        .get()
        .then((u) => this.user.set({ name: u.name }))
        .catch(() => this.user.set(null))
        .finally(() => this.loading.set(false))
    })
  }

  async handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    this.user.set(null)
  }
}
