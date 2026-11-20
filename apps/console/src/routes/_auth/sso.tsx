import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { createFileRoute, Link, useSearch } from '@tanstack/react-router'
import { ArrowLeft, KeyRound } from 'lucide-react'
import { z } from 'zod'
import {
  AuthFlowDescription,
  AuthFlowIllustrationCard,
  AuthFlowTitle,
} from '@/components/global/auth/AuthFlowCard'
import {
  AuthFlowIllustrationColumn,
  AuthFlowShell,
} from '@/components/global/auth/AuthFlowShell'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { sdk } from '@/lib/appwrite/sdk'
import type { SSOSignInProvider } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = z.object({
  /**
   * Set by the server when a sign-in fails: `{"message": …}` as JSON, which the
   * router already parses into an object.
   */
  error: z.unknown().optional(),
  redirect: z.string().optional(),
  /**
   * A provider's shared SSO URL (Settings → Single sign-on → Copy SSO URL):
   * the sign-in starts at once, with no email to type and nothing to pick.
   */
  provider: z.coerce.string().optional(),
  /** Set when an organization that requires single sign-on sent the member here. */
  required: z.stringbool().optional(),
})

export const Route = createFileRoute('/_auth/sso')({
  component: SingleSignOnPage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Sign in with SSO') }] }),
})

/** The message of the error the sign-in returned in `?error=`, if any. */
function failureMessage(error: unknown): string | null {
  const message = rawFailureMessage(error)
  // The identity provider vouched for an email outside the organization's
  // verified domains: retrying the same login cannot help.
  if (message?.includes('email_domain_not_verified')) {
    return "Your company login returned an email that isn't on a domain your organization verified. Sign in with your work email account."
  }
  return message
}

function rawFailureMessage(error: unknown): string | null {
  if (error === undefined || error === null || error === '') return null
  if (typeof error === 'object') {
    const message = (error as { message?: unknown }).message
    return typeof message === 'string' ? message : null
  }
  try {
    const parsed = JSON.parse(String(error)) as { message?: string }
    return parsed.message ?? null
  } catch {
    return String(error)
  }
}

/**
 * Console sign-in through an organization's identity provider: the work email
 * picks the organization, then its provider, chosen here when there are several.
 */
function SingleSignOnPage() {
  const t = useT()
  const search = useSearch({ from: '/_auth/sso' })
  const [email, setEmail] = useState('')
  const [providers, setProviders] = useState<SSOSignInProvider[] | null>(null)
  const failure = failureMessage(search.error)

  const start = (providerId: string) => {
    const origin = window.location.origin
    const success = `${origin}${search.redirect ?? '/'}`
    const back = `${origin}/sso`
    window.location.href = sdk.forConsole.teamSSO.signInURL(
      providerId,
      success,
      back,
    )
  }

  const lookup = useMutation({
    mutationFn: (value: string) =>
      sdk.forConsole.teamSSO.listSignInProviders(value.trim().toLowerCase()),
    onSuccess: (result) => {
      // One way in: go straight to it. Several: let the person choose.
      if (result.providers.length === 1) {
        start(result.providers[0].$id)
        return
      }
      setProviders(result.providers)
    },
  })

  const domain = email.includes('@') ? email.split('@').pop() : ''

  // A failed sign-in comes back without `provider`, so this never loops.
  const direct = !failure && !!search.provider
  useEffect(() => {
    if (direct && search.provider) start(search.provider)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- start once per provider
  }, [direct, search.provider])

  return (
    <AuthFlowShell width="illustration">
      <AuthFlowIllustrationCard illustration={<AuthFlowIllustrationColumn />}>
        <div className="space-y-6">
          <div className="space-y-2">
            <Link
              to="/sign-in"
              className="inline-flex items-center gap-1 text-[13px] text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="size-3.5" />
              {t('Back to sign in')}
            </Link>
            <AuthFlowTitle>{t('Sign in with SSO')}</AuthFlowTitle>
            <AuthFlowDescription>
              {t(
                "Enter your work email. We'll take you to your company's login page.",
              )}
            </AuthFlowDescription>
          </div>

          {search.required && !failure ? (
            <Alert>
              <AlertDescription className="text-[13px] text-pretty">
                {t(
                  'This organization requires single sign-on. Sign in with your company login to continue.',
                )}
              </AlertDescription>
            </Alert>
          ) : null}

          {failure ? (
            <Alert variant="destructive">
              <AlertDescription className="text-[13px] text-pretty break-words">
                {failure}
              </AlertDescription>
            </Alert>
          ) : null}

          {direct ? (
            <p
              className="text-[13px] text-muted-foreground"
              role="status"
              aria-live="polite"
            >
              {t('Taking you to your company login...')}
            </p>
          ) : providers && providers.length > 1 ? (
            <div className="space-y-3">
              <p className="text-[13px] text-muted-foreground">
                {t('Choose how to sign in')}
              </p>
              {providers.map((provider) => (
                <Button
                  key={provider.$id}
                  type="button"
                  variant="outline"
                  className="h-9 w-full"
                  onClick={() => start(provider.$id)}
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
              onSubmit={(event) => {
                event.preventDefault()
                if (email.trim() === '') return
                setProviders(null)
                lookup.mutate(email)
              }}
            >
              <div className="space-y-2">
                <Label htmlFor="sso-email">{t('Work email')}</Label>
                <Input
                  id="sso-email"
                  type="email"
                  autoComplete="email"
                  placeholder="you@company.com"
                  value={email}
                  onChange={(event) => {
                    setEmail(event.target.value)
                    setProviders(null)
                  }}
                  aria-invalid={providers?.length === 0}
                />
                {providers?.length === 0 ? (
                  <p className="text-[12px] text-pretty break-words text-destructive">
                    {t("Single sign-on isn't set up for")} {domain}.
                  </p>
                ) : null}
                {lookup.isError ? (
                  <p className="text-[12px] text-pretty break-words text-destructive">
                    {getErrorMessage(lookup.error) ||
                      t('Failed to find your company login')}
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
