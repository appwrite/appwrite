import { Component, inject, signal } from '@angular/core'
import { RouterLink } from '@angular/router'
import { AppwriteService } from './appwrite.service'

@Component({
  selector: 'app-home',
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
export class HomeComponent {
  private appwrite = inject(AppwriteService)

  user = signal<{ name: string } | null>(null)
  loading = signal(true)

  constructor() {
    this.appwrite
      .getUser()
      .then((u) => this.user.set({ name: u.name }))
      .catch(() => this.user.set(null))
      .finally(() => this.loading.set(false))
  }

  async handleSignOut() {
    await this.appwrite.signOut()
    this.user.set(null)
  }
}
