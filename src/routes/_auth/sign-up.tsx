import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import {
  createFileRoute,
  useNavigate,
  useRouter,
  useSearch,
} from '@tanstack/react-router'
import { z } from 'zod'
import { SignIn } from '@/components/global/auth/SignIn'
import { AppwriteLogo } from '@/components/global/auth/AppwriteLogo'
import { sdk } from '@/lib/appwrite/sdk'
import { AppwriteException, ID, OAuthProvider } from '@appwrite.io/console'
import { toast } from 'sonner'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { pageTitle } from '@/lib/utils/page-title'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { ensurePersonalOrgAndFirstProject } from '@/lib/ensure-personal-org'

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
  head: () => ({ meta: [{ title: pageTitle('Sign up') }] }),
})

function SignUpPage() {
  const search = useSearch({ from: '/_auth/sign-up' })
  const navigate = useNavigate()
  const router = useRouter()
  const [isGitHubLoading, setIsGitHubLoading] = useState(false)

  const handleGitHubLogin = async () => {
    setIsGitHubLoading(true)
    try {
      // Build success and failure URLs
      const successUrl =
        search.redirect && isValidRelativeRedirect(search.redirect)
          ? `${window.location.origin}${search.redirect}`
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
      toast.error(getErrorMessage(error, 'Failed to initiate GitHub login'))
      console.error('GitHub OAuth error:', error)
    }
  }

  const signUpMutation = useMutation({
    mutationFn: async (data: {
      email: string
      password: string
      name?: string
    }) => {
      try {
        // Create account
        await sdk.forConsole.account.create({
          userId: ID.unique(),
          email: data.email,
          password: data.password,
          name: data.name,
        })

        // Create session
        await sdk.forConsole.account.createEmailPasswordSession({
          email: data.email,
          password: data.password,
        })
      } catch (error) {
        if (error instanceof AppwriteException) {
          throw new Error(error.message || 'Failed to sign up')
        }
        throw error
      }
    },
    onSuccess: async () => {
      setLastLoginMethod('email')
      await router.invalidate()

      const features = getActiveProfileFeatures()
      if (features.userVerification) {
        try {
          const verifyUrl = `${window.location.origin}/verify-email${search.redirect ? `?redirect=${encodeURIComponent(search.redirect)}` : ''}`
          await sdk.forConsole.account.createEmailVerification({ url: verifyUrl })
        } catch (err) {
          console.error('Failed to send verification email:', err)
          toast.error(
            getErrorMessage(err, 'Account created but verification email could not be sent'),
          )
        }
        navigate({
          to: '/verify-email',
          search: search.redirect
            ? { redirect: search.redirect }
            : undefined,
        })
        return
      }

      // No verification: ensure personal org + first project, then redirect to org
      try {
        const orgId = await ensurePersonalOrgAndFirstProject()
        await router.invalidate()
        if (search.redirect && isValidRelativeRedirect(search.redirect)) {
          navigate({ to: search.redirect })
        } else {
          navigate({
            to: '/organizations/$orgId',
            params: { orgId },
            replace: true,
          })
        }
      } catch {
        navigate({ to: '/' })
      }
    },
    onError: async (error: unknown) => {
      toast.error(getErrorMessage(error, 'Failed to sign up'))
      console.error('Sign up error:', error)
    },
  })

  return (
    <div className="bg-background relative flex min-h-svh flex-col items-center justify-center p-6 md:p-10">
      <div className="w-full max-w-sm md:max-w-4xl">
        <SignIn
          mode="sign-up"
          onSubmit={(data) => signUpMutation.mutate(data)}
          onGitHubLogin={handleGitHubLogin}
          isLoading={signUpMutation.isPending}
          isGitHubLoading={isGitHubLoading}
          redirect={search.redirect}
        />
        <p className="mt-6 text-center text-xs text-muted-foreground">
          By clicking continue, you agree to our{' '}
          <a
            href="https://appwrite.io/terms"
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-4 hover:text-primary"
          >
            Terms of Service
          </a>{' '}
          and{' '}
          <a
            href="https://appwrite.io/privacy"
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-4 hover:text-primary"
          >
            Privacy Policy
          </a>
          .
        </p>
        <div className="mt-10 md:mt-16 flex justify-center">
          <AppwriteLogo className="h-6 w-auto" />
        </div>
      </div>
    </div>
  )
}
