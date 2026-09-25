import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearch } from '@tanstack/react-router'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { Loader2, TriangleAlert } from 'lucide-react'
import { AuthFlowAccountSwitcher } from '@/components/global/auth/AuthFlowAccountSwitcher'
import {
  AuthFlowDescription,
  AuthFlowNarrowCard,
  AuthFlowTitle,
  authFlowMetaClassName,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { EducationJoinPartnerHeader } from '@/components/pages/education/join/EducationJoinPartnerHeader'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Button } from '@/components/ui/button'
import { useAnalytics } from '@/hooks/use-analytics'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { EDUCATION_JOIN_PATH } from '@/lib/education/paths'
import {
  addToStudentMailingList,
  connectGithubForStudentProgram,
  hasGithubIdentity,
  joinGithubStudentProgram,
  rememberEducationOrganization,
} from '@/lib/education/program'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { requiresConsoleEmailVerification } from '@/lib/post-auth-navigation'
import { accountIdentitiesQueryOptions } from '@/lib/react-query/hooks/auth'
import {
  organizationQueryOptions,
  organizationsQueryOptions,
} from '@/lib/react-query/hooks/organizations'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { setLastLoginMethod } from '@/lib/utils/auth-storage'
import { GitHubIcon } from '@/lib/vcs/providers'

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
  const accountLabel = isAuthenticated
    ? account?.email || account?.name
    : undefined

  const [isRedirectingToGithub, setIsRedirectingToGithub] = useState(false)
  const [error, setError] = useState<EnrollmentError | null>(null)
  const [isOpeningOrganization, setIsOpeningOrganization] = useState(false)
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
      setIsOpeningOrganization(true)
      track('Resource Created', { resource: 'education-membership' })
      addToStudentMailingList(account)
      // Seed the returned organization, as in the organization creation wizard,
      // so the destination loader does not wait for a redundant list refresh.
      const organizationOptions: ReturnType<typeof organizationQueryOptions> = {
        ...organizationQueryOptions(organization.$id),
        initialData: organization,
      }
      const listOptions = organizationsQueryOptions()
      const previous = queryClient.getQueryData(listOptions.queryKey)
      const teams = previous?.teams ?? []
      const updatedList = teams.some((team) => team.$id === organization.$id)
        ? previous!
        : {
            ...previous,
            teams: [...teams, organization],
            total: (previous?.total ?? teams.length) + 1,
          }
      // Initialize cold entries with their normal retention settings; bare
      // setQueryData would inherit the global gcTime: 0 before routes mount.
      void queryClient.ensureQueryData(organizationOptions).catch(() => {})
      void queryClient
        .ensureQueryData({
          ...listOptions,
          initialData: updatedList,
        })
        .catch(() => {})
      queryClient.setQueryData(organizationOptions.queryKey, organization)
      queryClient.setQueryData(listOptions.queryKey, updatedList)
      // Saving the default organization is best effort and cannot delay access.
      void rememberEducationOrganization(
        queryClient,
        account,
        organization.$id,
      ).catch(() => {})
      try {
        await navigate({
          to: '/organizations/$orgId',
          params: { orgId: organization.$id },
          replace: true,
        })
        // Refresh the full list after arrival, preserving other organizations
        // when enrollment started with an empty cache.
        void queryClient
          .refetchQueries({
            queryKey: organizationsQueryOptions().queryKey,
            type: 'all',
          })
          .catch(() => {})
      } catch {
        // The organization exists. Retry navigation without repeating enrollment.
        window.location.replace(
          `/organizations/${encodeURIComponent(organization.$id)}`,
        )
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

  const isEnrolling = joinMutation.isPending || isOpeningOrganization
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
    <AuthFlowShell
      width="narrow"
      accountSwitcher={
        accountLabel ? (
          <AuthFlowAccountSwitcher accountLabel={accountLabel} />
        ) : null
      }
    >
      <AuthFlowNarrowCard>
            {visibleError ? (
              <div className="space-y-6">
                <div className="flex flex-col items-center gap-4 text-center">
                  <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
                  <div className="space-y-2">
                    <AuthFlowTitle>{visibleError.title}</AuthFlowTitle>
                    <AuthFlowDescription>
                      {visibleError.description}
                    </AuthFlowDescription>
                  </div>
                </div>
                {error?.recovery === 'connect' ? (
                  <Button
                    variant="brandCta"
                    className="w-full"
                    onClick={() => void handleConnectGithub()}
                    disabled={isRedirectingToGithub}
                    {...analyticsAttrs('education-connect-github')}
                  >
                    <GitHubIcon className="size-4 shrink-0" />
                    {t('Connect GitHub')}
                  </Button>
                ) : error?.recovery === 'retry' || identitiesQuery.isError ? (
                  <Button
                    variant="brandCta"
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
              <div className="flex flex-col items-center gap-4 py-8 text-center">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
                <AuthFlowDescription>
                  {isEnrolling
                    ? t('Setting up your Education plan organization...')
                    : t('Checking your account...')}
                </AuthFlowDescription>
              </div>
            ) : (
              <div className="space-y-6">
                <div className="flex flex-col items-center gap-4 text-center">
                  <EducationJoinPartnerHeader />
                  <div className="space-y-2">
                  <AuthFlowTitle>
                    {t('Join the Appwrite Education Program')}
                  </AuthFlowTitle>
                  <AuthFlowDescription>
                    {t(
                      'Enjoy Appwrite Cloud for free throughout your student journey as part of the GitHub Student Developer Pack.',
                    )}
                  </AuthFlowDescription>
                  </div>
                </div>
                <Button
                  variant="brandCta"
                  className="w-full"
                  onClick={() => void handleConnectGithub()}
                  disabled={isRedirectingToGithub}
                  {...analyticsAttrs('education-connect-github')}
                >
                  <GitHubIcon className="size-4 shrink-0" />
                  {isAuthenticated
                    ? t('Connect GitHub')
                    : t('Sign up with GitHub')}
                </Button>
                {!isAuthenticated ? (
                  <p className={cn(authFlowMetaClassName, 'text-center')}>
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
                  <AuthFlowDescription>
                    {t(
                      'GitHub did not complete the sign in. Try again to join the program.',
                    )}
                  </AuthFlowDescription>
                ) : null}
                <p className={cn(authFlowMetaClassName, 'text-center')}>
                  <MarketingSiteLink className="link-neutral" href="/education">
                    {t('Read about the program')}
                  </MarketingSiteLink>
                </p>
              </div>
            )}
      </AuthFlowNarrowCard>
    </AuthFlowShell>
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
