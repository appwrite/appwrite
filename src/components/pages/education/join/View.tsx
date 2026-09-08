import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { Loader2 } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { useAnalytics } from '@/hooks/use-analytics'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { GitHubIcon } from '@/lib/vcs/providers'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'
import {
  addToStudentMailingList,
  connectGithubForStudentProgram,
  hasGithubIdentity,
  joinGithubStudentProgram,
  rememberEducationOrganization,
} from '@/lib/education/program'
import { useT } from '@/lib/i18n/translate'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks/auth'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'

type EnrollmentError = {
  title: string
  description: string
  recovery?: 'connect' | 'retry'
}

export function View() {
  const t = useT()
  const search = useSearch({ from: '/_auth/education/join' })
  const navigate = useNavigate()
  const { track } = useAnalytics()
  const queryClient = useQueryClient()
  const { account: accountUnknown, isLoading, isAuthenticated } = useAuth()
  const account = accountUnknown as Models.User | undefined

  const [isRedirectingToGithub, setIsRedirectingToGithub] = useState(false)
  const [error, setError] = useState<EnrollmentError | null>(null)
  const [organizationId, setOrganizationId] = useState<string | null>(null)
  const needsEmailVerification =
    isAuthenticated && requiresConsoleEmailVerification(account)

  // Enrollment is verified against the account's GitHub identity, so a student
  // who signed up with email has to link GitHub before the program accepts them.
  const identitiesQuery = useQuery({
    ...accountIdentitiesQueryOptions(),
    enabled: isAuthenticated && !needsEmailVerification,
  })
  const isCheckingIdentities = isAuthenticated && identitiesQuery.isPending
  const githubConnected = hasGithubIdentity(identitiesQuery.data?.identities)

  const joinMutation = useMutation({
    mutationFn: joinGithubStudentProgram,
    onMutate: () => setError(null),
    onSuccess: async (organization) => {
      // Organization creation is the success boundary. Preference writes and
      // navigation must never turn it into a failed enrollment or a second POST.
      setOrganizationId(organization.$id)
      track('Resource Created', { resource: 'education-membership' })
      addToStudentMailingList(account)
      await Promise.allSettled([
        rememberEducationOrganization(queryClient, account, organization.$id),
        queryClient.refetchQueries({
          queryKey: organizationsQueryOptions().queryKey,
          type: 'all',
        }),
      ])
      try {
        await navigate({
          to: '/organizations/$orgId',
          params: { orgId: organization.$id },
          replace: true,
        })
      } catch {
        // Keep the success screen and its direct organization link available.
      }
    },
    onError: (enrollError: unknown) => {
      track('Resource Creation Failed', { resource: 'education-membership' })
      setError(toEnrollmentError(enrollError, t))
    },
  })

  // Cloud blocks org APIs until the console email is verified, and this page is
  // exempt from the global guard so guests can reach it. Send unverified
  // accounts through /verify-email and back, rather than into a 401.
  useEffect(() => {
    if (!needsEmailVerification) return
    void navigate({
      to: '/verify-email',
      search: { redirect: EDUCATION_JOIN_PATH },
      replace: true,
    })
  }, [needsEmailVerification, navigate])

  // The GitHub redirect lands back here with a session; enroll without a second
  // click. `useRef` keeps a re-render (or a failed attempt) from retrying.
  const autoJoinAttempted = useRef(false)
  useEffect(() => {
    if (isLoading || !isAuthenticated || !githubConnected) return
    if (identitiesQuery.isError || identitiesQuery.isFetching) return
    if (search.status === 'failure') return
    if (needsEmailVerification || autoJoinAttempted.current) return
    autoJoinAttempted.current = true
    joinMutation.mutate()
  }, [
    isLoading,
    isAuthenticated,
    githubConnected,
    identitiesQuery.isError,
    identitiesQuery.isFetching,
    search.status,
    needsEmailVerification,
    joinMutation,
  ])

  const handleConnectGithub = async () => {
    setIsRedirectingToGithub(true)
    setError(null)
    try {
      if (!isAuthenticated) setLastLoginMethod('github')
      await connectGithubForStudentProgram()
    } catch (oauthError: unknown) {
      setIsRedirectingToGithub(false)
      setError({
        title: t('We could not reach GitHub'),
        description: getErrorMessage(
          oauthError,
          t('Try again in a moment, or sign in and explore Appwrite.'),
        ),
        recovery: 'connect',
      })
    }
  }

  const isEnrolling = joinMutation.isPending
  const visibleError =
    error ??
    (identitiesQuery.isError
      ? {
          title: t('We could not check your GitHub connection'),
          description: getErrorMessage(
            identitiesQuery.error,
            t('Try again in a moment, or sign in and explore Appwrite.'),
          ),
        }
      : null)

  return (
    <div className="bg-background h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full min-w-0 max-w-md">
          <div className="w-full min-w-0 overflow-hidden rounded-xl border border-border bg-card/50 p-6 md:p-8">
            {organizationId ? (
              <div className="space-y-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                  {t('Your Education plan organization is ready.')}
                </h1>
                <Button className="w-full" asChild>
                  <Link
                    to="/organizations/$orgId"
                    params={{ orgId: organizationId }}
                  >
                    {t('Continue to Appwrite')}
                  </Link>
                </Button>
              </div>
            ) : visibleError ? (
              <div className="space-y-6">
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {visibleError.title}
                  </h1>
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {visibleError.description}
                  </p>
                </div>
                {error?.recovery === 'connect' ? (
                  <Button
                    className="w-full"
                    onClick={() => void handleConnectGithub()}
                    disabled={isRedirectingToGithub}
                    {...analyticsAttrs('education-connect-github')}
                  >
                    {t('Connect GitHub')}
                  </Button>
                ) : error?.recovery === 'retry' || identitiesQuery.isError ? (
                  <Button
                    className="w-full"
                    onClick={() => {
                      if (identitiesQuery.isError) {
                        void identitiesQuery.refetch()
                      } else {
                        joinMutation.mutate()
                      }
                    }}
                    disabled={identitiesQuery.isFetching || isEnrolling}
                  >
                    {t('Try again')}
                  </Button>
                ) : null}
                <Button
                  className="w-full"
                  variant="outline"
                  onClick={() => navigate({ to: '/', replace: true })}
                >
                  {t('Continue to Appwrite')}
                </Button>
              </div>
            ) : isLoading ||
              isCheckingIdentities ||
              isEnrolling ||
              needsEmailVerification ? (
              <div className="space-y-4 py-4 text-center">
                <Loader2 className="text-muted-foreground mx-auto h-8 w-8 animate-spin" />
                <p className="text-muted-foreground text-[13px] leading-relaxed">
                  {isEnrolling
                    ? t('Setting up your Education plan organization...')
                    : t('Checking your account...')}
                </p>
              </div>
            ) : (
              <div className="space-y-6">
                <div className="flex items-center justify-center gap-4">
                  <img
                    src="/assets/logomark/logo.svg"
                    alt="Appwrite"
                    className="h-6 w-auto brightness-0 dark:invert"
                  />
                  <div className="bg-border h-6 w-px" aria-hidden />
                  <GitHubIcon className="h-6 w-6" />
                </div>
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold tracking-tight">
                    {t('Join the Appwrite Education Program')}
                  </h1>
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {t(
                      'Enjoy Appwrite Cloud for free throughout your student journey as part of the GitHub Student Developer Pack.',
                    )}
                  </p>
                </div>
                <Button
                  className="w-full"
                  onClick={() => void handleConnectGithub()}
                  disabled={isRedirectingToGithub}
                  {...analyticsAttrs('education-connect-github')}
                >
                  {isAuthenticated
                    ? t('Connect GitHub')
                    : t('Sign up with GitHub')}
                </Button>
                {!isAuthenticated ? (
                  <p className="text-muted-foreground text-center text-xs">
                    {t('Already have an account?')}{' '}
                    <Link
                      className="link-neutral"
                      to="/sign-in"
                      search={{ redirect: EDUCATION_JOIN_PATH }}
                    >
                      {t('Sign in')}
                    </Link>
                  </p>
                ) : null}
                {search.status === 'failure' ? (
                  <p className="text-muted-foreground text-[13px] leading-relaxed">
                    {t(
                      'GitHub did not complete the sign in. Try again to join the program.',
                    )}
                  </p>
                ) : null}
                <p className="text-muted-foreground text-center text-xs">
                  {t('Not a student?')}{' '}
                  <MarketingSiteLink className="link-neutral" href="/education">
                    {t('Read about the program')}
                  </MarketingSiteLink>
                </p>
              </div>
            )}
          </div>
          <p className="text-muted-foreground mt-6 text-center text-xs">
            {t('By continuing, you agree to our')}{' '}
            <MarketingSiteLink className="link-neutral" href="/terms">
              {t('Terms of Service')}
            </MarketingSiteLink>{' '}
            {t('and')}{' '}
            <MarketingSiteLink className="link-neutral" href="/privacy">
              {t('Privacy Policy')}
            </MarketingSiteLink>
            .
          </p>
        </div>
      </div>
    </div>
  )
}

/**
 * The verification endpoint answers 403 for "not in the pack" and 409 for an
 * Education organization this account already belongs to. Everything else is
 * unexpected, so it keeps the API's own message.
 */
function toEnrollmentError(
  error: unknown,
  t: (text: string) => string,
): EnrollmentError {
  const code = error instanceof AppwriteException ? error.code : undefined

  if (code === 403) {
    return {
      title: t(
        "It looks like you're not currently eligible for the GitHub Student Developer Pack.",
      ),
      description: t('You can still use Appwrite without an Education plan.'),
      recovery: 'connect',
    }
  }

  if (code === 409) {
    return {
      title: t("You've already joined the Education program."),
      description: t(
        'Continue to Appwrite, then use the organization switcher to find your Education plan.',
      ),
    }
  }

  return {
    title: t('We could not set up your Education plan'),
    description: getErrorMessage(
      error,
      t('Try again in a moment, or sign in and explore Appwrite.'),
    ),
    recovery: 'retry',
  }
}
