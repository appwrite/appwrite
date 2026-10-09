import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
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
import { AppwriteException, ID } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  setLastLoginMethod,
  type OAuthLoginMethod,
} from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'
import { resolvePostAuthOrganizationId } from '@/lib/ensure-personal-org'
import {
  ensureConsoleAccountQueryData,
  refreshConsoleAccountAfterAuth,
  navigateToConsoleMfaAfterSession,
  isConsoleMfaRequiredError,
} from '@/lib/react-query/hooks/auth'
import {
  markOpenAiAdsRegistrationIntent,
  measureOpenAiAdsRegistrationCompleted,
} from '@/lib/openai-ads'
import {
  isValidRelativeRedirect,
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'

const searchSchema = z.object({
  redirect: z
    .string()
    .optional()
    .refine((val) => !val || isValidRelativeRedirect(val), {
      message: 'Redirect must be a relative URL',
    }),
})

export const Route = createFileRoute('/_auth/sign-up')({
  component: SignUpPage,
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
      const target = resolvePostAuthRedirect(
        (location.search as { redirect?: string }).redirect,
      )
      if (target) {
        throw redirect({ ...toRedirectNavigateOptions(target), replace: true })
      }
      throw redirect({ to: CONSOLE_ENTRY_PATH, replace: true })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Sign up') }] }),
})

function SignUpPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/sign-up' })
  const navigate = useNavigate()
  const router = useRouter()
  const queryClient = useQueryClient()
  const [oauthLoading, setOauthLoading] = useState<OAuthLoginMethod | null>(
    null,
  )
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const handleOAuthLogin = async (provider: OAuthLoginMethod) => {
    setOauthLoading(provider)
    try {
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : `${window.location.origin}${CONSOLE_ENTRY_PATH}`
      const failureUrl = `${window.location.origin}/sign-up${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`

      setLastLoginMethod(provider)
      markOpenAiAdsRegistrationIntent()

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

  const signUpMutation = useMutation({
    mutationFn: async (data: {
      email: string
      password: string
      name?: string
      skipAccountCreate?: boolean
    }) => {
      try {
        if (!data.skipAccountCreate) {
          await sdk.forConsole.account.create({
            userId: ID.unique(),
            email: data.email,
            password: data.password,
            name: data.name,
          })
        }

        // Create session
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
        if (
          error instanceof AppwriteException &&
          error.type === 'user_more_factors_required'
        ) {
          throw { ...error, isMfaRequired: true }
        }
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to sign up')
        }
        throw error
      }
    },
    onSuccess: async () => {
      setLastLoginMethod('email')
      const account = await refreshConsoleAccountAfterAuth(queryClient)

      // Cloud requires a verified console account before org/project APIs work.
      // Skip post-auth provisioning until after /verify-email; that page sends the link and handles it.
      if (requiresConsoleEmailVerification(account)) {
        navigate({
          to: '/verify-email',
          search: search.redirect ? { redirect: search.redirect } : undefined,
        })
        return
      }

      if (account?.$id) {
        measureOpenAiAdsRegistrationCompleted(account.$id, {
          email: account.email,
          phone: account.phone,
        })
      }

      try {
        await prefetchPostAuthDestination(queryClient, account, search.redirect)
        await router.invalidate()

        // If we're headed to a specific destination (e.g. an OAuth2 consent/device
        // flow), go straight there without provisioning a personal org/project.
        const targetRedirect = resolvePostAuthRedirect(search.redirect)
        if (targetRedirect) {
          navigate(toRedirectNavigateOptions(targetRedirect))
          return
        }

        // Org was ensured during prefetchPostAuthDestination
        const orgId = await resolvePostAuthOrganizationId(account)
        navigate({
          to: '/organizations/$orgId',
          params: { orgId },
          replace: true,
        })
      } catch (error: unknown) {
        console.error('Post sign-up navigation error:', error)
        toast.error(
          getErrorMessage(
            error,
            t('Account created but could not open the console'),
          ),
        )
      }
    },
    onError: async (error: unknown) => {
      const isMfaRequired =
        typeof error === 'object' &&
        error !== null &&
        'isMfaRequired' in error &&
        (error as { isMfaRequired?: boolean }).isMfaRequired === true
      if (isMfaRequired || isConsoleMfaRequiredError(error)) {
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
            getErrorMessage(
              navigationError,
              t('Could not open MFA verification'),
            ),
          )
        }
        return
      }

      toast.error(getErrorMessage(error, t('Failed to sign up')))
      console.error('Sign up error:', error)
    },
  })

  return (
    <AuthFlowShell width="illustration">
      <SignIn
        mode="sign-up"
        onSubmit={(data, options) =>
          signUpMutation.mutate({
            ...data,
            skipAccountCreate: options?.skipAccountCreate,
          })
        }
        onOAuthLogin={handleOAuthLogin}
        isLoading={signUpMutation.isPending || isOpeningMfa}
        oauthLoading={oauthLoading}
        redirect={search.redirect}
      />
    </AuthFlowShell>
  )
}
