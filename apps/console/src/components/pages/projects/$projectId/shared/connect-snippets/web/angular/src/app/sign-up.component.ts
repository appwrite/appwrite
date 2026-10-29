import { Component, inject, signal } from '@angular/core'
import { FormsModule } from '@angular/forms'
import { Router, RouterLink } from '@angular/router'
import { AppwriteService } from './appwrite.service'

@Component({
  selector: 'app-sign-up',
  imports: [FormsModule, RouterLink],
  template: `
    <form id="sign-up-form" (ngSubmit)="handleSubmit()">
      <h1>Sign up</h1>
      @if (error()) {
        <p>{{ error() }}</p>
      }
      <input
        type="text"
        name="name"
        placeholder="Name"
        [(ngModel)]="name"
      />
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
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <a routerLink="/sign-in">Sign in</a>
      </p>
    </form>
  `,
})
export class SignUpComponent {
  private appwrite = inject(AppwriteService)
  private router = inject(Router)

  name = signal('')
  email = signal('')
  password = signal('')
  error = signal('')

  async handleSubmit() {
    this.error.set('')
    try {
      await this.appwrite.signUp(this.name(), this.email(), this.password())
      await this.appwrite.signIn(this.email(), this.password())
      await this.router.navigateByUrl('/')
    } catch (err) {
      this.error.set(err instanceof Error ? err.message : 'Sign up failed')
    }
  }
}
