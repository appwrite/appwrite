import { Account, ID } from 'appwrite'
import { client } from './lib/appwrite'

const account = new Account(client)
const app = document.querySelector<HTMLDivElement>('#app')!

type Route = 'home' | 'sign-in' | 'sign-up'

function navigate(route: Route) {
  if (route === 'sign-in') return renderSignIn()
  if (route === 'sign-up') return renderSignUp()
  return loadHome()
}

function onClick(id: string, handler: () => void) {
  document.querySelector(`#${id}`)?.addEventListener('click', handler)
}

function showError(message: string) {
  const el = document.querySelector('#error')
  if (el) el.textContent = message
}

function renderHome(user: { name: string } | null) {
  if (!user) {
    app.innerHTML = `
      <p>Sign in to get started.</p>
      <p>
        <button type="button" id="to-sign-in">Sign in</button>
        <button type="button" id="to-sign-up">Sign up</button>
      </p>
    `
    onClick('to-sign-in', () => navigate('sign-in'))
    onClick('to-sign-up', () => navigate('sign-up'))
    return
  }
  app.innerHTML = `
    <p>Hello, ${user.name}</p>
    <button type="button" id="sign-out">Sign out</button>
  `
  onClick('sign-out', async () => {
    await account.deleteSession({ sessionId: 'current' })
    renderHome(null)
  })
}

function loadHome() {
  app.innerHTML = '<p>Loading...</p>'
  account
    .get()
    .then((user) => renderHome(user))
    .catch(() => renderHome(null))
}

function renderSignIn() {
  app.innerHTML = `
    <form id="form">
      <h1>Sign in</h1>
      <p id="error"></p>
      <input type="email" name="email" placeholder="Email" required />
      <input type="password" name="password" placeholder="Password" required />
      <button type="submit">Sign in</button>
      <p>No account? <button type="button" id="to-sign-up">Sign up</button></p>
    </form>
  `
  onClick('to-sign-up', () => navigate('sign-up'))
  document.querySelector('#form')!.addEventListener('submit', async (e) => {
    e.preventDefault()
    showError('')
    const form = new FormData(e.target as HTMLFormElement)
    try {
      await account.createEmailPasswordSession({
        email: String(form.get('email')),
        password: String(form.get('password')),
      })
      navigate('home')
    } catch (err) {
      showError(err instanceof Error ? err.message : 'Sign in failed')
    }
  })
}

function renderSignUp() {
  app.innerHTML = `
    <form id="form">
      <h1>Sign up</h1>
      <p id="error"></p>
      <input type="text" name="name" placeholder="Name" />
      <input type="email" name="email" placeholder="Email" required />
      <input type="password" name="password" placeholder="Password" required />
      <button type="submit">Sign up</button>
      <p>
        Already have an account?
        <button type="button" id="to-sign-in">Sign in</button>
      </p>
    </form>
  `
  onClick('to-sign-in', () => navigate('sign-in'))
  document.querySelector('#form')!.addEventListener('submit', async (e) => {
    e.preventDefault()
    showError('')
    const form = new FormData(e.target as HTMLFormElement)
    const email = String(form.get('email'))
    const password = String(form.get('password'))
    try {
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name: String(form.get('name') ?? '').trim() || undefined,
      })
      await account.createEmailPasswordSession({ email, password })
      navigate('home')
    } catch (err) {
      showError(err instanceof Error ? err.message : 'Sign up failed')
    }
  })
}

navigate('home')
