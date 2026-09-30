import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  createFileRoute,
  redirect,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { CONSOLE_ENTRY_PATH } from '@/lib/root-guest-redirect'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  setLastLoginMethod,
  type LoginMethod,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  isPasskeyAutofillAvailable,
  isPasskeyCancellation,
  passkeySignInErrorMessage,
  signInWithPasskey,
} from '@/lib/passkeys'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
  navigateToConsoleMfaAfterSession,
  isConsoleMfaRequiredError,
} from '@/lib/react-query/hooks/auth'
import {
  isValidRelativeRedirect,
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'

const searchSchema = z.object({
  redirect: z
    .string()
    .optional()
    .refine((val) => !val || isValidRelativeRedirect(val), {
      message: 'Redirect must be a relative URL',
    }),
})

export const Route = createFileRoute('/_auth/sign-in')({
  component: SignInPage,
  validateSearch: searchSchema,
  // Keep the visible header stable while this link is hovered or focused.
  preload: false,
  loader: async ({ context, location }) => {
    if (typeof window === 'undefined') return
    const account = await ensureConsoleAccountQueryData(context.queryClient)
    if (account) {
      if (requiresConsoleEmailVerification(account)) {
        const pendingRedirect = (location.search as { redirect?: string })
          .redirect
        throw redirect({
          to: '/verify-email',
          search: pendingRedirect ? { redirect: pendingRedirect } : undefined,
          replace: true,
        })
      }
      // Already signed in: honor a console redirect (e.g. a /join invite link)
      // instead of always bouncing to the dashboard.
      const target = resolvePostAuthRedirect(
        (location.search as { redirect?: string }).redirect,
      )
      if (target) {
        throw redirect({ ...toRedirectNavigateOptions(target), replace: true })
      }
      throw redirect({ to: CONSOLE_ENTRY_PATH, replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Sign in') }] }),
})

function SignInPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/sign-in' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const handleOAuthLogin = async (provider: OAuthLoginMethod) => {
    setOauthLoading(provider)
    try {
      // Build success and failure URLs
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : `${window.location.origin}${CONSOLE_ENTRY_PATH}`
      const failureUrl = `${window.location.origin}/sign-in${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`

      setLastLoginMethod(provider)

      const url = await sdk.forConsole.account.createOAuth2Session({
        provider: CONSOLE_OAUTH_PROVIDERS[provider],
        success: successUrl,
        failure: failureUrl,
      })

      if (typeof url === 'string') {
        window.location.href = url
      }
    } catch (error: unknown) {
      setOauthLoading(null)
      toast.error(getErrorMessage(error, t(OAUTH_LOGIN_ERROR[provider])))
      console.error(`${provider} OAuth error:`, error)
    }
  }

  const finishSignIn = async (method: LoginMethod) => {
    setLastLoginMethod(method)
    try {
      const account = await refreshConsoleAccountAfterAuth(queryClient)

      // Cloud requires verification before org/project APIs; send unverified
      // users to /verify-email instead of provisioning a personal org.
      if (requiresConsoleEmailVerification(account)) {
        navigate({
          to: '/verify-email',
          search: search.redirect ? { redirect: search.redirect } : undefined,
        })
        return
      }

      await prefetchPostAuthDestination(queryClient, account, search.redirect)
      await router.invalidate()
      const targetRedirect = resolvePostAuthRedirect(search.redirect)
      if (targetRedirect) {
        navigate(toRedirectNavigateOptions(targetRedirect))
      } else {
        const orgId = await resolvePostAuthOrganizationId(account)
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
          replace: true,
        })
      }
    } catch (error: unknown) {
      console.error('Post sign-in navigation error:', error)
      toast.error(
        getErrorMessage(error, t('Signed in but could not open the console')),
      )
    }
  }

  /** Returns false when the error is not an MFA challenge. */
  const openMfaIfRequired = async (error: unknown): Promise<boolean> => {
    const isMfaRequired =
      typeof error === 'object' &&
      error !== null &&
      'isMfaRequired' in error &&
      (error as { isMfaRequired?: boolean }).isMfaRequired === true
    if (!isMfaRequired && !isConsoleMfaRequiredError(error)) return false

    setIsOpeningMfa(true)
    try {
      await navigateToConsoleMfaAfterSession(
        queryClient,
        navigate,
        search.redirect,
      )
    } catch (navigationError: unknown) {
      setIsOpeningMfa(false)
      toast.error(
        getErrorMessage(navigationError, t('Could not open MFA verification')),
      )
    }
    return true
  }

  const signInMutation = useMutation({
    mutationFn: async (data: { email: string; password: string }) => {
      try {
        await sdk.forConsole.account.createEmailPasswordSession({
          email: data.email,
          password: data.password,
        })

        // Detect MFA. Force a fresh fetch so it can't replay the guest account.get
        // the _auth loader fires on load (cached/in-flight 401 "missing scopes
        // account") - a race password managers hit by autofilling and submitting
        // before that guest request settled.
        await fetchConsoleAccount({ force: true })
      } catch (error: unknown) {
        // Check for MFA requirement - this is the key check
        if (
          error instanceof AppwriteException &&
          error.type === 'user_more_factors_required'
        ) {
          // Re-throw with a special marker so onError can handle it
          throw { ...error, isMfaRequired: true }
        }
        // Re-throw other errors
        throw error
      }
    },
    // Only called if account.get() succeeds (no MFA required)
    onSuccess: () => finishSignIn('email'),
    onError: async (error: unknown) => {
      if (await openMfaIfRequired(error)) return

      // Show error for other failures
      toast.error(getErrorMessage(error, t('Failed to sign in')))
      console.error('Sign in error:', error)
    },
  })

  const [passkeySelected, setPasskeySelected] = useState(false)
  const selected = useRef(false)
  const autofill = useRef<AbortController | null>(null)

  const passkeySignInMutation = useMutation({
    mutationFn: async (options: {
      autofill?: boolean
      signal?: AbortSignal
    }) => {
      const token = await signInWithPasskey({
        ...options,
        onSelected: () => {
          selected.current = true
          setPasskeySelected(true)
        },
      })
      await sdk.forConsole.account.createSession({
        userId: token.userId,
        secret: token.secret,
      })
      // A passkey session already satisfies MFA; this also replaces the guest
      // account.get the _auth loader cached.
      await fetchConsoleAccount({ force: true })
    },
    onSuccess: () => finishSignIn('passkey'),
    onMutate: () => {
      selected.current = false
      setPasskeySelected(false)
    },
    onError: async (error: unknown, options) => {
      const wasSelected = selected.current
      setPasskeySelected(false)
      if (isPasskeyCancellation(error)) return
      if (await openMfaIfRequired(error)) return
      // Autofill starts on page load, so stay quiet until the user has picked a passkey.
      if (options.autofill && !wasSelected) return

      toast.error(
        passkeySignInErrorMessage(error, t) ??
          getErrorMessage(error, t('Failed to sign in with a passkey')),
      )
      console.error('Passkey sign in error:', error)
    },
  })
  const { mutate: mutatePasskeySignIn } = passkeySignInMutation

  const startPasskeyAutofill = useCallback(async () => {
    autofill.current?.abort()
    const controller = new AbortController()
    autofill.current = controller
    if (!(await isPasskeyAutofillAvailable()) || controller.signal.aborted) {
      return
    }
    mutatePasskeySignIn({ autofill: true, signal: controller.signal })
  }, [mutatePasskeySignIn])

  useEffect(() => {
    if (!features.accountPasskeys) return
    void startPasskeyAutofill()
    return () => autofill.current?.abort()
  }, [features.accountPasskeys, startPasskeyAutofill])

  const handlePasskeyLogin = () => {
    // A pending autofill request blocks the browser prompt.
    autofill.current?.abort()
    mutatePasskeySignIn({}, { onError: () => void startPasskeyAutofill() })
  }

  const passkeyBusy =
    passkeySignInMutation.isPending &&
    (!passkeySignInMutation.variables?.autofill || passkeySelected)

  return (
    <AuthFlowShell width="illustration">
      <SignIn
        mode="sign-in"
        onSubmit={(data) => signInMutation.mutate(data)}
        onOAuthLogin={handleOAuthLogin}
        onPasskeyLogin={
          features.accountPasskeys ? handlePasskeyLogin : undefined
        }
        passkeyAutofill={features.accountPasskeys}
        passkeyLoading={passkeyBusy}
        isLoading={signInMutation.isPending || passkeyBusy || isOpeningMfa}
        oauthLoading={oauthLoading}
        redirect={search.redirect}
      />
    </AuthFlowShell>
  )
}
