import { useState } from 'react'
import { IonButton, IonInput, IonText } from '@ionic/react'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignIn({
  onSignedIn,
  onGoToSignUp,
}: {
  onSignedIn: () => void
  onGoToSignUp: () => void
}) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit() {
    if (!email || !password) return
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      onSignedIn()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <>
      <h1>Sign in</h1>
      {error ? <IonText>{error}</IonText> : null}
      <IonInput
        type="email"
        placeholder="Email"
        value={email}
        onIonInput={(e) => setEmail(e.detail.value ?? '')}
      />
      <IonInput
        type="password"
        placeholder="Password"
        value={password}
        onIonInput={(e) => setPassword(e.detail.value ?? '')}
      />
      <IonButton onClick={handleSubmit}>Sign in</IonButton>
      <IonText>
        No account?{' '}
        <button type="button" onClick={onGoToSignUp}>
          Sign up
        </button>
      </IonText>
    </>
  )
}
