import { useEffect, useState } from 'react'
import { IonApp, IonButton, IonContent, IonPage, IonText } from '@ionic/react'
import { Account } from 'appwrite'
import { client } from './lib/appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home({
  onGoToSignIn,
  onGoToSignUp,
}: {
  onGoToSignIn: () => void
  onGoToSignUp: () => void
}) {
  const [user, setUser] = useState<{ name: string } | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const account = new Account(client)
    account
      .get()
      .then((u) => setUser({ name: u.name }))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  if (loading) {
    return <IonText>Loading...</IonText>
  }

  if (!user) {
    return (
      <>
        <IonText>Sign in to get started.</IonText>
        <IonButton onClick={onGoToSignIn}>Sign in</IonButton>
        <IonButton onClick={onGoToSignUp}>Sign up</IonButton>
      </>
    )
  }

  async function handleSignOut() {
    const account = new Account(client)
    await account.deleteSession({ sessionId: 'current' })
    setUser(null)
  }

  return (
    <>
      <IonText>{`Hello, ${user.name}`}</IonText>
      <IonButton onClick={handleSignOut}>Sign out</IonButton>
    </>
  )
}

export default function App() {
  const [route, setRoute] = useState('home')

  return (
    <IonApp>
      <IonPage>
        <IonContent className="ion-padding">
          {route === 'sign-in' ? (
            <SignIn
              onSignedIn={() => setRoute('home')}
              onGoToSignUp={() => setRoute('sign-up')}
            />
          ) : route === 'sign-up' ? (
            <SignUp
              onSignedUp={() => setRoute('home')}
              onGoToSignIn={() => setRoute('sign-in')}
            />
          ) : (
            <Home
              onGoToSignIn={() => setRoute('sign-in')}
              onGoToSignUp={() => setRoute('sign-up')}
            />
          )}
        </IonContent>
      </IonPage>
    </IonApp>
  )
}
