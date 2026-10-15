import { Component, inject, signal } from '@angular/core'
import { FormsModule } from '@angular/forms'
import { Router, RouterLink } from '@angular/router'
import { Account } from 'appwrite'
import { client } from '../../lib/appwrite'

@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <form id="sign-in-form" (ngSubmit)="handleSubmit()">
      <h1>Sign in</h1>
      @if (error()) {
        <p>{{ error() }}</p>
      }
      <input
        type="email"
        name="email"
        placeholder="Email"
        [(ngModel)]="email"
        required
      />
      <input
        type="password"
        name="password"
        placeholder="Password"
        [(ngModel)]="password"
        required
      />
      <button type="submit">Sign in</button>
      <p>
        No account? <a routerLink="/sign-up">Sign up</a>
      </p>
    </form>
  `,
})
export default class SignInPageComponent {
  private router = inject(Router)

  email = signal('')
  password = signal('')
  error = signal('')

  async handleSubmit() {
    this.error.set('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({
        email: this.email(),
        password: this.password(),
      })
      await this.router.navigateByUrl('/')
    } catch (err) {
      this.error.set(err instanceof Error ? err.message : 'Sign in failed')
    }
  }
}
