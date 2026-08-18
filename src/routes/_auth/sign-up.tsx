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
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import { AppwriteException, ID, OAuthProvider } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
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
  prefetchPostAuthDestination,
  requiresConsoleEmailVerification,
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'

// Helper function to validate that a redirect URL is relative (prevents redirect hijacking)
function isValidRelativeRedirect(url: string): boolean {
  try {
    // Must start with / and not contain :// (which would indicate a protocol)
    return url.startsWith('/') && !url.includes('://')
  } catch {
    return false
  }
}

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
      throw redirect({ to: '/', replace: true })
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
  const [isGitHubLoading, setIsGitHubLoading] = useState(false)
  const [isOpeningMfa, setIsOpeningMfa] = useState(false)

  const handleGitHubLogin = async () => {
    setIsGitHubLoading(true)
    try {
      // Build success and failure URLs
      const resolvedRedirect = resolvePostAuthRedirect(search.redirect)
      const successUrl = resolvedRedirect
        ? `${window.location.origin}${resolvedRedirect}`
        : `${window.location.origin}/`
      const failureUrl = `${window.location.origin}/sign-up${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`

      // Store GitHub as last login method before redirecting
      setLastLoginMethod('github')

      // Create OAuth2 session - this may return a URL or void (if it redirects automatically)
      const url = await sdk.forConsole.account.createOAuth2Session({
        provider: OAuthProvider.Github,
        success: successUrl,
        failure: failureUrl,
      })

      // If URL is returned, redirect manually; otherwise SDK handles redirect automatically
      if (typeof url === 'string') {
        window.location.href = url
      }
      // If void, the SDK has already initiated the redirect, so we don't need to do anything
    } catch (error: unknown) {
      setIsGitHubLoading(false)
      toast.error(getErrorMessage(error, t('Failed to initiate GitHub login')))
      console.error('GitHub OAuth error:', error)
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
      // Skip post-auth provisioning until after /verify-email; that page handles it.
      if (requiresConsoleEmailVerification(account)) {
        try {
          const verifyUrl = `${window.location.origin}/verify-email${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`
          await sdk.forConsole.account.createEmailVerification({
            url: verifyUrl,
          })
        } catch (err) {
          console.error('Failed to send verification email:', err)
          toast.error(
            getErrorMessage(
              err,
              t('Account created but verification email could not be sent'),
            ),
          )
        }
        navigate({
          to: '/verify-email',
          search: search.redirect ? { redirect: search.redirect } : undefined,
        })
        return
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
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <SignIn
          mode="sign-up"
          onSubmit={(data, options) =>
            signUpMutation.mutate({
              ...data,
              skipAccountCreate: options?.skipAccountCreate,
            })
          }
          onGitHubLogin={handleGitHubLogin}
          isLoading={signUpMutation.isPending || isOpeningMfa}
          isGitHubLoading={isGitHubLoading}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          {t('By clicking continue, you agree to our')}{' '}
          <MarketingSiteLink className="link-neutral" href="/terms">
            {t('Terms of Service')}
          </MarketingSiteLink>{' '}
          {t('and')}{' '}
          <MarketingSiteLink className="link-neutral" href="/privacy">
            {t('Privacy Policy')}
          </MarketingSiteLink>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
