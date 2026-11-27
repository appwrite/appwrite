import { useEffect, useRef, useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  createFileRoute,
  Link,
  redirect,
  useSearch,
} from '@tanstack/react-router'
import { ArrowLeft, KeyRound } from 'lucide-react'
import { toast } from 'sonner'
import {
  AuthFlowDescription,
  AuthFlowIllustrationCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import {
  AuthFlowIllustrationColumn,
  AuthFlowShell,
  authFlowShellFooterClassName,
} from '@/components/global/auth/AuthFlowShell'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ssoProviderSignInURL,
  type SSOSignInProvider,
} from '@/lib/appwrite/team-sso'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { useT } from '@/lib/i18n/translate'
import { parseOrganizationIdFromPath } from '@/lib/organization-overview-prefetch'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { organizationsQueryOptions } from '@/lib/react-query/hooks/organizations'
import {
  isValidWorkEmail,
  SSO_EMAIL_MAX_LENGTH,
  ssoFailureMessage,
  ssoReturnPath,
  ssoSignInURLs,
} from '@/lib/sso-sign-in'
import { ssoSearchSchema, ssoSharedProvider } from '@/lib/sso-shared-url'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_auth/sso')({
  component: SingleSignOnPage,
  validateSearch: ssoSearchSchema,
  // Where the Console has no single sign-on (self-hosted), the API has none of
  // its routes either: the sign-in page is the way in.
  beforeLoad: ({ search }) => {
    if (!getActiveProfileFeatures().sso) {
      const back = ssoReturnPath(search.redirect)
      throw redirect({
        to: '/sign-in',
        search: back ? { redirect: back } : undefined,
        replace: true,
      })
    }
  },
  head: () => ({ meta: [{ title: pageTitle('Sign in with SSO') }] }),
})

/** The work email, kept across the trip to the identity provider and back. */
const EMAIL_STORAGE_KEY = 'console.sso.email'

function readSavedEmail(): string {
  try {
    return sessionStorage.getItem(EMAIL_STORAGE_KEY) ?? ''
  } catch {
    return ''
  }
}

function saveEmail(value: string): void {
  try {
    if (value) sessionStorage.setItem(EMAIL_STORAGE_KEY, value)
  } catch {
    // Storage is unavailable: the person types it again.
  }
}

/** One notice about the trip to the identity provider, however often it starts. */
const REDIRECT_TOAST_ID = 'sso-redirect'

// `--destructive` is a background shade in the dark theme; its text red is
// `--destructive-foreground` (other themes keep `text-destructive`).
const errorTextClassName =
  'text-destructive [.dark_&]:text-destructive-foreground'

/**
 * Console sign-in through an organization's identity provider: the work email
 * picks the organization, then its provider, chosen here when there are several.
 */
function SingleSignOnPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/sso' })
  const failure = ssoFailureMessage(search.error)
  const returnPath = ssoReturnPath(search.redirect)
  const [email, setEmail] = useState(() => (failure ? readSavedEmail() : ''))
  const [providers, setProviders] = useState<SSOSignInProvider[] | null>(null)
  const [invalidEmail, setInvalidEmail] = useState(false)
  const emailRef = useRef<HTMLInputElement>(null)
  const firstProviderRef = useRef<HTMLButtonElement>(null)
  const choosing = !!providers && providers.length > 1
  const wasChoosing = useRef(false)

  // Someone an organization sent here is already signed in: they need ways
  // back to the Console, not to the sign-in page.
  const { data: account } = useQuery(consoleAccountQueryOptions())
  const signedIn = !!account?.$id
  const { data: organizations } = useQuery({
    ...organizationsQueryOptions(),
    enabled: signedIn,
  })
  const refusedOrgId = returnPath
    ? parseOrganizationIdFromPath(returnPath)
    : undefined
  const teams = organizations?.teams ?? []
  const hasOtherOrganization = refusedOrgId
    ? teams.some((team) => team.$id !== refusedOrgId)
    : teams.length > 1

  const start = (providerId: string, { replace = false } = {}) => {
    const { success, failure: back } = ssoSignInURLs(
      window.location.origin,
      search.redirect,
    )
    saveEmail(email.trim())
    const url = ssoProviderSignInURL(
      sdk.forConsole.client,
      providerId,
      success,
      back,
    )
    if (replace) window.location.replace(url)
    else window.location.href = url
  }

  // The identity provider takes a moment to answer. Until then this page stays,
  // with its form ready again: say that the sign-in is on its way.
  const startWith = (provider: SSOSignInProvider) => {
    toast.success(
      t('Taking you to {provider}...').replace('{provider}', provider.name),
      {
        id: REDIRECT_TOAST_ID,
        description: t('Finish signing in with your identity provider.'),
        // Stays for as long as a slow identity provider takes to load
        duration: 10_000,
      },
    )
    start(provider.$id)
  }

  const lookup = useMutation({
    mutationFn: (value: string) =>
      sdk.forConsole.account.listSSOProviders({
        email: value.trim().toLowerCase(),
      }),
    onSuccess: (result) => {
      // One way in: go straight to it. Several: let the person choose.
      if (result.providers.length === 1) {
        startWith(result.providers[0])
        return
      }
      setProviders(result.providers)
    },
  })

  const domain = email.includes('@') ? email.trim().split('@').pop() : ''

  // A shared SSO URL. The server sends a browser's navigation straight to the
  // identity provider (`ssoSharedURLMiddleware`); this is for a visit it left
  // to the page. The identity provider takes this page's place in the history:
  // left there, Back from the identity provider would start the sign-in again.
  const sharedProvider = ssoSharedProvider(search)
  const direct = !!sharedProvider
  useEffect(() => {
    if (sharedProvider) start(sharedProvider, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps -- start once per provider
  }, [sharedProvider])

  // Back from the identity provider can restore this page as it was left, with
  // the notice still up: nobody is being taken anywhere now.
  useEffect(() => {
    const onPageShow = (event: PageTransitionEvent) => {
      if (event.persisted) toast.dismiss(REDIRECT_TOAST_ID)
    }
    window.addEventListener('pageshow', onPageShow)
    return () => window.removeEventListener('pageshow', onPageShow)
  }, [])

  // The chooser replaces the form: move focus to the first way in, and back to
  // the email field when the person leaves it.
  useEffect(() => {
    if (choosing) {
      firstProviderRef.current?.focus()
    } else if (wasChoosing.current) {
      emailRef.current?.focus()
    }
    wasChoosing.current = choosing
  }, [choosing])

  const lookupInvalid =
    lookup.error instanceof AppwriteException &&
    lookup.error.type === 'general_argument_invalid'
  const fieldMessage =
    invalidEmail || lookupInvalid ? (
      t('Please enter a valid email address')
    ) : providers?.length === 0 ? (
      <>
        {t("Single sign-on isn't set up for {domain}.").replace(
          '{domain}',
          domain ?? '',
        )}
        {signedIn ? null : (
          <>
            {' '}
            <Link
              to="/sign-in"
              search={returnPath ? { redirect: returnPath } : undefined}
              className="underline underline-offset-2"
            >
              {t('Sign in with email and password')}
            </Link>
          </>
        )}
      </>
    ) : lookup.isError ? (
      getErrorMessage(lookup.error) ||
      t('Failed to look up single sign-on for this email')
    ) : null

  const backLink = signedIn ? (
    hasOtherOrganization ? (
      <Link to="/" className={backLinkClassName}>
        <ArrowLeft className="size-3.5" />
        {t('Back to the Console')}
      </Link>
    ) : (
      <Link to="/account" className={backLinkClassName}>
        <ArrowLeft className="size-3.5" />
        {t('Go to your account')}
      </Link>
    )
  ) : (
    <Link
      to="/sign-in"
      search={returnPath ? { redirect: returnPath } : undefined}
      className={backLinkClassName}
    >
      <ArrowLeft className="size-3.5" />
      {t('Back to sign in')}
    </Link>
  )

  const footer = signedIn ? (
    <>
      <span>
        {t('Signed in as {email}.').replace('{email}', account?.email ?? '')}
      </span>
      <Link to="/sign-out" preload={false} className="link-neutral">
        {t('Sign out')}
      </Link>
    </>
  ) : choosing || direct ? (
    // Neither the chooser nor a shared SSO URL has a Continue button to click.
    <p className={cn(authFlowShellFooterClassName, 'mt-0')}>
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
  ) : undefined

  return (
    <AuthFlowShell width="illustration" footer={footer}>
      <AuthFlowIllustrationCard illustration={<AuthFlowIllustrationColumn />}>
        <div className="space-y-6">
          <div className="space-y-2">
            {/* On the way to the identity provider there is no email to
                enter, and no reason to go back. */}
            {direct ? null : backLink}
            <AuthFlowTitle>{t('Sign in with SSO')}</AuthFlowTitle>
            {direct ? null : (
              <AuthFlowDescription>
                {t('Enter your work email to continue with single sign-on.')}
              </AuthFlowDescription>
            )}
          </div>

          {search.required && !failure ? (
            <Alert>
              <AlertDescription className="text-[13px] text-pretty">
                {t(
                  'This organization requires single sign-on. Sign in with SSO to continue.',
                )}
              </AlertDescription>
            </Alert>
          ) : null}

          {failure ? (
            <Alert
              variant="destructive"
              className="[.dark_&]:text-destructive-foreground"
            >
              <AlertDescription className="text-[13px] text-pretty break-words [.dark_&]:!text-destructive-foreground">
                {t(failure)}
              </AlertDescription>
            </Alert>
          ) : null}

          {direct ? (
            <p
              className="text-[13px] text-muted-foreground"
              role="status"
              aria-live="polite"
            >
              {t('Taking you to your identity provider...')}
            </p>
          ) : choosing ? (
            <div
              role="group"
              aria-labelledby="sso-choose-label"
              className="space-y-3"
            >
              <p
                id="sso-choose-label"
                className="text-[13px] text-muted-foreground"
              >
                {t('Choose how to sign in')}
              </p>
              {providers.map((provider, index) => (
                <Button
                  key={provider.$id}
                  ref={index === 0 ? firstProviderRef : undefined}
                  type="button"
                  variant="outline"
                  className="h-9 w-full"
                  onClick={() => startWith(provider)}
                >
                  <KeyRound className="size-4" />
                  {provider.name}
                </Button>
              ))}
              <Button
                type="button"
                variant="ghost"
                className="w-full text-[13px] text-muted-foreground"
                onClick={() => setProviders(null)}
              >
                {t('Use a different email')}
              </Button>
            </div>
          ) : (
            <form
              className="space-y-4"
              noValidate
              onSubmit={(event) => {
                event.preventDefault()
                if (email.trim() === '' || lookup.isPending) return
                setProviders(null)
                if (!isValidWorkEmail(email)) {
                  lookup.reset()
                  setInvalidEmail(true)
                  return
                }
                lookup.mutate(email)
              }}
            >
              <div className="space-y-2">
                <Label htmlFor="sso-email">{t('Work email')}</Label>
                <Input
                  ref={emailRef}
                  id="sso-email"
                  type="email"
                  autoComplete="email"
                  placeholder="you@company.com"
                  maxLength={SSO_EMAIL_MAX_LENGTH}
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value)
                    setProviders(null)
                    setInvalidEmail(false)
                    lookup.reset()
                  }}
                  aria-invalid={!!fieldMessage}
                  aria-describedby={
                    fieldMessage ? 'sso-email-message' : undefined
                  }
                />
                {fieldMessage ? (
                  <p
                    id="sso-email-message"
                    role="alert"
                    className={cn(
                      'text-[12px] text-pretty break-words',
                      errorTextClassName,
                    )}
                  >
                    {fieldMessage}
                  </p>
                ) : null}
              </div>
              <Button
                type="submit"
                variant="brandCta"
                className="w-full"
                disabled={lookup.isPending || email.trim() === ''}
              >
                {lookup.isPending ? t('Continuing...') : t('Continue')}
              </Button>
            </form>
          )}
        </div>
      </AuthFlowIllustrationCard>
    </AuthFlowShell>
  )
}

const backLinkClassName =
  'inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground'
