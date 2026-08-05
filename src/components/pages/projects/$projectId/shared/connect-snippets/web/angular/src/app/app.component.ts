import { Component } from '@angular/core'
import { from } from 'rxjs'
import { AppwriteService } from './appwrite.service'

@Component({
  selector: 'app-root',
  template: `
    @if (user$ | async; as user) {
      <p>Hello, {{ user.name }}</p>
    } @else {
      <p>Sign in to get started.</p>
    }
  `,
})
export class AppComponent {
  user$ = from(this.appwrite.getUser().catch(() => null))

  constructor(private appwrite: AppwriteService) {}
}
