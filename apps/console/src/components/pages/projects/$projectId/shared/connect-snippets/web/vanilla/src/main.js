import { Client, Account, ID } from 'appwrite'

const client = new Client()
  .setEndpoint(import.meta.env.VITE_APPWRITE_ENDPOINT)
  .setProject(import.meta.env.VITE_APPWRITE_PROJECT_ID)

const account = new Account(client)
const app = document.querySelector('#app')

function renderHome(user) {
  if (!user) {
    app.innerHTML = `
      <p>Sign in to get started.</p>
      <p>
        <a href="/sign-in">Sign in</a> · <a href="/sign-up">Sign up</a>
      </p>
    `
    return
  }
  app.innerHTML = `
    <p>Hello, ${user.name}</p>
    <button type="button" id="sign-out">Sign out</button>
  `
  document.querySelector('#sign-out').addEventListener('click', async () => {
    await account.deleteSession({ sessionId: 'current' })
    renderHome(null)
  })
}

function renderSignIn() {
  app.innerHTML = `
    <form id="sign-in-form">
      <h1>Sign in</h1>
      <p id="error"></p>
      <input type="email" name="email" placeholder="Email" required />
      <input type="password" name="password" placeholder="Password" required />
      <button type="submit">Sign in</button>
      <p>No account? <a href="/sign-up">Sign up</a></p>
    </form>
  `
  document
    .querySelector('#sign-in-form')
    .addEventListener('submit', async (e) => {
    e.preventDefault()
    const form = new FormData(e.target)
    try {
      await account.createEmailPasswordSession({
        email: form.get('email'),
        password: form.get('password'),
      })
      window.location.href = '/'
    } catch (err) {
      document.querySelector('#error').textContent =
        err instanceof Error ? err.message : 'Sign in failed'
    }
  })
}

function renderSignUp() {
  app.innerHTML = `
    <form id="sign-up-form">
      <h1>Sign up</h1>
      <p id="error"></p>
      <input type="text" name="name" placeholder="Name" />
      <input type="email" name="email" placeholder="Email" required />
      <input type="password" name="password" placeholder="Password" required />
      <button type="submit">Sign up</button>
      <p>Already have an account? <a href="/sign-in">Sign in</a></p>
    </form>
  `
  document
    .querySelector('#sign-up-form')
    .addEventListener('submit', async (e) => {
    e.preventDefault()
    const form = new FormData(e.target)
    try {
      await account.create({
        userId: ID.unique(),
        email: form.get('email'),
        password: form.get('password'),
        name: form.get('name').trim() || undefined,
      })
      await account.createEmailPasswordSession({
        email: form.get('email'),
        password: form.get('password'),
      })
      window.location.href = '/'
    } catch (err) {
      document.querySelector('#error').textContent =
        err instanceof Error ? err.message : 'Sign up failed'
    }
  })
}

const path = window.location.pathname
if (path === '/sign-in') {
  renderSignIn()
} else if (path === '/sign-up') {
  renderSignUp()
} else {
  app.innerHTML = '<p>Loading...</p>'
  account
    .get()
    .then((user) => renderHome(user))
    .catch(() => renderHome(null))
}
