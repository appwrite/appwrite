import { useEffect, useRef, useState } from 'react'
import { createFileRoute, useNavigate } from '@tanstack/react-router'
import { useMutation } from '@tanstack/react-query'
import { Loader2, MonitorSmartphone } from 'lucide-react'
import { AppwriteException } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Card } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  OAuth2ConsentCard,
  type OAuth2Flow,
} from '@/components/global/auth/OAuth2ConsentCard'
import {
  OAUTH2_DEVICE_CODE_LENGTH,
  OAuth2DeviceCodeInput,
  normalizeUserCode,
} from '@/components/global/auth/OAuth2DeviceCodeInput'
import { OAuth2OutcomeCard } from '@/components/global/auth/OAuth2OutcomeCard'
import { getOAuth2App } from '@/lib/oauth2/cimd'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { pageTitle } from '@/lib/utils/page-title'

const searchSchema = (
  search: Record<string, unknown>,
): { user_code?: string } => ({
  user_code:
    typeof search.user_code === 'string' ? search.user_code : undefined,
})

export const Route = createFileRoute('/_auth/oauth2/device')({
  component: OAuth2DevicePage,
  validateSearch: searchSchema,
  head: () => ({ meta: [{ title: pageTitle('Connect a device') }] }),
})

type Phase = 'loading' | 'enter-code' | 'consent' | 'approved' | 'denied'

const DEVICE_FLOW: OAuth2Flow = 'device'

function OAuth2DevicePage() {
  const t = useT()
  const navigate = useNavigate()
  const search = Route.useSearch()
  const [phase, setPhase] = useState<Phase>('loading')
  const [account, setAccount] =
    useState<Models.User<Models.Preferences> | null>(null)
  const [code, setCode] = useState(normalizeUserCode(search.user_code ?? ''))
  const [grant, setGrant] = useState<Models.Oauth2Grant | null>(null)
  const [app, setApp] = useState<Models.App | null>(null)
  const [error, setError] = useState<string | null>(null)
  const hasPrefilledCode = Boolean(normalizeUserCode(search.user_code ?? ''))

  // The code the page is currently acting on. Set synchronously at the two
  // points it can change - on submit (below) and on a URL `user_code` change
  // (sync effect) - so a `createGrant` that resolves after the user moved to a
  // different code is ignored, without depending on render/effect timing.
  const activeCodeRef = useRef(code)

  const submitMutation = useMutation({
    mutationFn: async (userCode: string) => {
      const loadedGrant = await sdk.forConsole.oauth2.createGrant({
        userCode,
      })
      const loadedApp = await getOAuth2App(loadedGrant.appId)
      return { loadedGrant, loadedApp }
    },
    onSuccess: ({ loadedGrant, loadedApp }, userCode) => {
      // Drop results from a submission the user has since navigated away from.
      if (userCode !== activeCodeRef.current) return
      setGrant(loadedGrant)
      setApp(loadedApp)
      setError(null)
      setPhase('consent')
    },
    onError: (e: unknown, userCode) => {
      if (userCode !== activeCodeRef.current) return
      if (
        e instanceof AppwriteException &&
        e.type === 'oauth2_invalid_user_code'
      ) {
        setError(
          t(
            'That code is invalid or has expired. Check your device and try again.',
          ),
        )
      } else {
        setError(getErrorMessage(e, t('Could not verify that code.')))
      }
      setPhase('enter-code')
    },
  })

  useEffect(() => {
    let cancelled = false

    async function init() {
      const loggedInAccount = (await sdk.forConsole.account
        .get()
        .catch(() => null)) as Models.User<Models.Preferences> | null
      if (cancelled) return

      if (!loggedInAccount) {
        navigate({
          to: '/sign-in',
          search: {
            redirect: window.location.pathname + window.location.search,
          },
          replace: true,
        })
        return
      }

      setAccount(loggedInAccount)
      // Always show the code (prefilled from the URL or typed) so the user can
      // confirm it matches their device before exchanging it - never auto-submit.
      setPhase('enter-code')
    }

    void init()
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keep the shown code in sync with the URL's `user_code` while this route
  // stays mounted (e.g. the user follows a fresh `verification_uri_complete`
  // link, or navigates back to the bare `/oauth2/device`). Any change means a
  // different request, so drop the loaded grant and return to confirmation
  // rather than showing or approving the previous code - including when the
  // code is removed entirely.
  useEffect(() => {
    const next = normalizeUserCode(search.user_code ?? '')
    if (next === code) return
    // Update the gate synchronously (not via the passive ref-sync effect on the
    // next render) so a `createGrant` for the old code that resolves in this
    // window is rejected by onSuccess instead of pushing the page into consent.
    activeCodeRef.current = next
    setCode(next)
    setGrant(null)
    setApp(null)
    setError(null)
    // Clear any in-flight submission for the previous code; its result is also
    // gated by activeCodeRef in case it resolves after this.
    submitMutation.reset()
    setPhase((current) => (current === 'loading' ? current : 'enter-code'))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search.user_code])

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const normalized = normalizeUserCode(code)
    if (!normalized) return
    setError(null)
    // Mark this as the active submission so onSuccess accepts its result.
    activeCodeRef.current = normalized
    submitMutation.mutate(normalized)
  }

  return (
    <div className="bg-background h-full overflow-y-auto">
      <div className="flex min-h-full flex-col items-center p-6 md:p-10">
        <div className="my-auto w-full max-w-xl">
          {phase === 'loading' && (
            <div className="flex min-h-64 items-center justify-center">
              <Loader2 className="text-muted-foreground size-8 animate-spin" />
            </div>
          )}

          {phase === 'enter-code' && (
            <Card className="overflow-hidden p-6 md:p-8">
              <form onSubmit={handleSubmit} className="space-y-6">
                <div className="flex flex-col items-center gap-4 text-center">
                  <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-xl ring-1 ring-border/50">
                    <MonitorSmartphone className="size-4" />
                  </div>
                  <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                      {hasPrefilledCode
                        ? t('Confirm your code')
                        : t('Connect a device')}
                    </h1>
                    <p className="text-muted-foreground text-[13px] leading-relaxed">
                      {hasPrefilledCode
                        ? t(
                            'Make sure this matches the code shown on your device, then continue.',
                          )
                        : t('Enter the code shown on your device to continue.')}
                    </p>
                  </div>
                </div>

                <div className="space-y-3">
                  <Label htmlFor="user-code">{t('Device code')}</Label>
                  <OAuth2DeviceCodeInput
                    id="user-code"
                    value={code}
                    onChange={(next) => {
                      setCode(next)
                      setError(null)
                    }}
                    autoFocus
                    disabled={submitMutation.isPending}
                    aria-invalid={Boolean(error)}
                  />
                  {error && (
                    <p className="text-destructive text-[13px]">{error}</p>
                  )}
                </div>

                <div className="flex flex-col gap-2">
                  <Button
                    type="submit"
                    variant="brandCta"
                    className="w-full"
                    disabled={
                      code.length < OAUTH2_DEVICE_CODE_LENGTH ||
                      submitMutation.isPending
                    }
                  >
                    {t('Continue')}
                  </Button>
                </div>

                {account && (
                  <p className="text-muted-foreground text-center text-[12px]">
                    {t('Signed in as')}{' '}
                    <span className="text-foreground font-medium">
                      {account.email || account.name}
                    </span>
                    .
                  </p>
                )}
              </form>
            </Card>
          )}

          {phase === 'consent' && grant && app && (
            <OAuth2ConsentCard
              grant={grant}
              app={app}
              accountLabel={account?.email || account?.name || undefined}
              flow={DEVICE_FLOW}
              onDone={(outcome) =>
                setPhase(outcome === 'approved' ? 'approved' : 'denied')
              }
            />
          )}

          {(phase === 'approved' || phase === 'denied') && (
            <OAuth2OutcomeCard
              outcome={phase}
              flow={DEVICE_FLOW}
              app={app}
              accountLabel={account?.email || account?.name || undefined}
            />
          )}
        </div>
      </div>
    </div>
  )
}
