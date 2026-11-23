import { Component, inject, signal } from '@angular/core'
import { FormsModule } from '@angular/forms'
import { Router, RouterLink } from '@angular/router'
import { AppwriteService } from './appwrite.service'

@Component({
  selector: 'app-sign-in',
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
export class SignInComponent {
  private appwrite = inject(AppwriteService)
  private router = inject(Router)

  email = signal('')
  password = signal('')
  error = signal('')

  async handleSubmit() {
    this.error.set('')
    try {
      await this.appwrite.signIn(this.email(), this.password())
      await this.router.navigateByUrl('/')
    } catch (err) {
      this.error.set(err instanceof Error ? err.message : 'Sign in failed')
    }
  }
}
