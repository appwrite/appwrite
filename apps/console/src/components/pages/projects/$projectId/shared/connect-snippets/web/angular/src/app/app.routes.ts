import { Routes } from '@angular/router'
import { HomeComponent } from './home.component'
import { SignInComponent } from './sign-in.component'
import { SignUpComponent } from './sign-up.component'

export const routes: Routes = [
  { path: '', component: HomeComponent },
  { path: 'sign-in', component: SignInComponent },
  { path: 'sign-up', component: SignUpComponent },
]
