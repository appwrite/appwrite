import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { createFileRoute, useNavigate, useRouter } from '@tanstack/react-router'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { MagicUrlLoginCard } from '@/components/global/auth/MagicUrlLoginCard'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { sdk } from '@/lib/appwrite/sdk'
import {
  resolveMagicUrlRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import {
  performConsoleSignOut,
  refreshConsoleAccountAfterAuth,
} from '@/lib/react-query/hooks/auth'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import { isZeroAccount } from '@/lib/zero/session'

/** Read the params from the URL directly so long secrets are not altered by router/search parsing. */
function getMagicUrlParamsFromUrl(): {
  userId: string
  secret: string
  redirect: string | null
} | null {
  if (typeof window === 'undefined') return null
  const params = new URLSearchParams(window.location.search)
  const userId = params.get('userId')
  const secret = params.get('secret')
  if (userId && secret) {
    return { userId, secret, redirect: params.get('redirect') }
  }
  return null
}

export const Route = createFileRoute('/_auth/auth/magic-url')({
  component: MagicUrlPage,
  head: () => ({ meta: [{ title: pageTitle('Magic URL login') }] }),
})

function MagicUrlPage() {
  const t = useT()
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const { account: accountUnknown, isLoading } = useAuth()
  const account = accountUnknown as Models.User | undefined
  const [params] = useState(getMagicUrlParamsFromUrl)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  const [isSwitchingAccount, setIsSwitchingAccount] = useState(false)

  const openDestination = useCallback(() => {
    const destination = resolveMagicUrlRedirect(params?.redirect)
    navigate({ ...toRedirectNavigateOptions(destination), replace: true })
  }, [navigate, params])

  const { mutate: confirmSession } = useMutation({
    mutationFn: async (params: { userId: string; secret: string }) => {
      await sdk.forConsole.account.createSession({
        userId: params.userId,
        secret: params.secret,
      })
    },
    onSuccess: async () => {
      try {
        await refreshConsoleAccountAfterAuth(queryClient)
        await router.invalidate()
      } catch {
        // Root loader re-fetches the account; proceed regardless.
      }
      openDestination()
    },
    onError: (error: unknown) => {
      setErrorMessage(
        error instanceof AppwriteException
          ? error.message
          : t('The magic URL is invalid or has expired.'),
      )
    },
  })

  // A session cannot be created over another one: sign out, then reload this
  // link as a guest so it signs in with the token.
  const switchAccount = useCallback(async () => {
    if (isSwitchingAccount) return
    setIsSwitchingAccount(true)
    try {
      await performConsoleSignOut(queryClient, {
        destination: `${window.location.pathname}${window.location.search}`,
        requireServerRevocation: true,
      })
    } catch {
      setIsSwitchingAccount(false)
      setErrorMessage(t('Could not sign out. Try switching accounts again.'))
    }
  }, [isSwitchingAccount, queryClient, t])

  const hasTriggeredConfirm = useRef(false)
  const signedInElsewhere =
    !!params && !!account && account.$id !== params.userId
  const needsSwitchConfirm = signedInElsewhere && !isZeroAccount(account)

  useEffect(() => {
    if (hasTriggeredConfirm.current || isLoading) return
    if (!params) {
      hasTriggeredConfirm.current = true
      setErrorMessage(t('The magic URL is missing required parameters.'))
      return
    }
    // A real account is never replaced without asking.
    if (needsSwitchConfirm) return
    hasTriggeredConfirm.current = true
    if (signedInElsewhere) {
      void switchAccount()
    } else if (account) {
      openDestination()
    } else {
      confirmSession(params)
    }
  }, [
    isLoading,
    params,
    account,
    needsSwitchConfirm,
    signedInElsewhere,
    switchAccount,
    openDestination,
    confirmSession,
    t,
  ])

  return (
    <MagicUrlLoginCard
      errorMessage={errorMessage}
      switchAccount={
        needsSwitchConfirm && account
          ? {
              email: account.email || account.name || account.$id,
              pending: isSwitchingAccount,
              onContinue: () => void switchAccount(),
              onCancel: () => navigate({ to: '/', replace: true }),
            }
          : undefined
      }
    />
  )
}
