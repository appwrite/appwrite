import { useState } from 'react'
import { IonButton, IonInput, IonText } from '@ionic/react'
import { Account, ID } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignUp({
  onSignedUp,
  onGoToSignIn,
}: {
  onSignedUp: () => void
  onGoToSignIn: () => void
}) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit() {
    if (!email || !password) return
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name: name.trim() || undefined,
      })
      await account.createEmailPasswordSession({ email, password })
      onSignedUp()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <>
      <h1>Sign up</h1>
      {error ? <IonText>{error}</IonText> : null}
      <IonInput
        placeholder="Name"
        value={name}
        onIonInput={(e) => setName(e.detail.value ?? '')}
      />
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
      <IonButton onClick={handleSubmit}>Sign up</IonButton>
      <IonText>
        Already have an account?{' '}
        <button type="button" onClick={onGoToSignIn}>
          Sign in
        </button>
      </IonText>
    </>
  )
}
