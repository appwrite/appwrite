import { useEffect, useMemo, useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { Loader2, ChevronLeft, ChevronRight, MonitorSmartphone, TriangleAlert } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  OAuth2ConsentCard,
  type OAuth2Flow,
  type OAuth2Outcome,
} from '@/components/global/auth/OAuth2ConsentCard'
import { OAuth2OutcomeCard } from '@/components/global/auth/OAuth2OutcomeCard'
import { OAuth2RelayCard } from '@/components/global/auth/OAuth2RelayCard'
import { OAuth2DeviceCodeInput, OAUTH2_DEVICE_CODE_LENGTH } from '@/components/global/auth/OAuth2DeviceCodeInput'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { getEffectiveMcpEndpointUrl } from '@/lib/debug-mcp-endpoint'
import {
  ORGANIZATION_RAR_TYPE,
  PROJECT_RAR_TYPE,
} from '@/lib/oauth2/authorization-details'
import { pageTitle } from '@/lib/utils/page-title'

const OAUTH2_PREVIEW_SCREENS = [
  'consent',
  'consent-mcp',
  'consent-resources',
  'device-enter-code',
  'device-confirm-code',
  'device-consent',
  'outcome-approved',
  'outcome-approved-device',
  'outcome-approved-deeplink',
  'outcome-denied',
  'error',
  'loading',
  'relay-success',
  'relay-failure',
  'relay-missing',
  'relay-error',
] as const

export type OAuth2PreviewScreen = (typeof OAUTH2_PREVIEW_SCREENS)[number]

const oauth2PreviewSearchSchema = z.object({
  screen: z.enum(OAUTH2_PREVIEW_SCREENS).optional(),
})

export const Route = createFileRoute('/_public/debug/oauth2-preview')({
  validateSearch: oauth2PreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('OAuth2 preview') }] }),
  component: OAuth2PreviewPage,
})

const ACCOUNT_LABEL = 'demo@appwrite.io'

const SCREEN_OPTIONS: { value: OAuth2PreviewScreen; label: string }[] = [
  { value: 'consent', label: 'Consent' },
  { value: 'consent-mcp', label: 'Consent (MCP)' },
  { value: 'consent-resources', label: 'Consent (resources)' },
  { value: 'device-enter-code', label: 'Device code' },
  { value: 'device-confirm-code', label: 'Device confirm' },
  { value: 'device-consent', label: 'Device consent' },
  { value: 'outcome-approved', label: 'Access granted' },
  { value: 'outcome-approved-device', label: 'Device connected' },
  { value: 'outcome-approved-deeplink', label: 'Access granted (deep link)' },
  { value: 'outcome-denied', label: 'Request cancelled' },
  { value: 'error', label: 'Authorization failed' },
  { value: 'loading', label: 'Loading' },
  { value: 'relay-success', label: 'Relay success' },
  { value: 'relay-failure', label: 'Relay failure' },
  { value: 'relay-missing', label: 'Relay missing URL' },
  { value: 'relay-error', label: 'Relay error' },
]

function mockApp(overrides?: Partial<Models.App>): Models.App {
  return {
    $id: 'demo-app',
    $createdAt: '2026-01-01T00:00:00.000Z',
    $updatedAt: '2026-01-01T00:00:00.000Z',
    name: 'Cursor',
    description: 'AI-powered code editor',
    clientUri: 'https://cursor.com',
    logoUri: '',
    privacyPolicyUrl: 'https://cursor.com/privacy',
    termsUrl: 'https://cursor.com/terms',
    contacts: ['support@cursor.com'],
    tagline: 'The AI Code Editor',
    tags: ['developer', 'ide'],
    labels: [],
    images: [],
    supportUrl: 'https://cursor.com/support',
    dataDeletionUrl: '',
    redirectUris: ['https://cursor.com/oauth/callback', 'cursor://oauth'],
    postLogoutRedirectUris: [],
    enabled: true,
    type: 'public',
    deviceFlow: true,
    teamId: '',
    userId: 'demo-user',
    installationScopes: [],
    installationRedirectUrl: '',
    secrets: [],
    ...overrides,
  }
}

function mockGrant(overrides?: Partial<Models.Oauth2Grant>): Models.Oauth2Grant {
  return {
    $id: 'demo-grant',
    $createdAt: '2026-01-01T00:00:00.000Z',
    $updatedAt: '2026-01-01T00:00:00.000Z',
    userId: 'demo-user',
    appId: 'demo-app',
    scopes: ['openid', 'profile', 'email'],
    resources: [],
    authorizationDetails: '',
    prompt: 'consent',
    redirectUri: 'https://cursor.com/oauth/callback',
    authTime: Math.floor(Date.now() / 1000),
    expire: '2026-12-31T00:00:00.000Z',
    ...overrides,
  }
}

const IDENTITY_GRANT = mockGrant()

const FULL_ACCESS_GRANT = mockGrant({
  scopes: ['openid', 'profile', 'email', 'all'],
})

const RESOURCES_GRANT = mockGrant({
  scopes: [
    'openid',
    'profile',
    'email',
    'project:all',
    'organization:projects.read',
    'organization:organization.read',
  ],
  authorizationDetails: JSON.stringify([
    { type: PROJECT_RAR_TYPE, identifiers: ['*'] },
    { type: ORGANIZATION_RAR_TYPE, identifiers: ['*'] },
  ]),
})

function mcpGrant(): Models.Oauth2Grant {
  return mockGrant({
    scopes: [
      'openid',
      'profile',
      'email',
      'project:all',
      'project:users.read',
      'project:users.write',
      'project:databases.read',
      'project:databases.write',
      'project:tables.read',
      'project:tables.write',
      'project:rows.read',
      'project:rows.write',
      'project:files.read',
      'project:files.write',
      'project:functions.read',
      'project:functions.write',
      'organization:all',
      'organization:projects.read',
      'organization:projects.write',
      'organization:organization.read',
    ],
    resources: [getEffectiveMcpEndpointUrl()],
    authorizationDetails: JSON.stringify([
      { type: PROJECT_RAR_TYPE, identifiers: ['*'] },
      { type: ORGANIZATION_RAR_TYPE, identifiers: ['*'] },
    ]),
  })
}

function DeviceCodeCard({
  code: initialCode,
  hasPrefilledCode,
  error,
}: {
  code: string
  hasPrefilledCode: boolean
  error?: string | null
}) {
  const [code, setCode] = useState(initialCode)

  useEffect(() => {
    setCode(initialCode)
  }, [initialCode])

  return (
    <Card className="overflow-hidden p-6 md:p-8">
      <form onSubmit={(e) => e.preventDefault()} className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="bg-muted text-muted-foreground flex size-10 items-center justify-center rounded-xl ring-1 ring-border/50">
            <MonitorSmartphone className="size-4" />
          </div>
          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">
              {hasPrefilledCode ? 'Confirm your code' : 'Connect a device'}
            </h1>
            <p className="text-muted-foreground text-[13px] leading-relaxed">
              {hasPrefilledCode
                ? 'Make sure this matches the code shown on your device, then continue.'
                : 'Enter the code shown on your device to continue.'}
            </p>
          </div>
        </div>

        <div className="space-y-3">
          <Label htmlFor="user-code">Device code</Label>
          <OAuth2DeviceCodeInput
            id="user-code"
            value={code}
            onChange={setCode}
            autoFocus
            aria-invalid={Boolean(error)}
          />
          {error ? <p className="text-destructive text-[13px]">{error}</p> : null}
        </div>

        <div className="flex flex-col gap-2">
          <Button
            type="submit"
            variant="brandCta"
            className="w-full"
            disabled={code.length < OAUTH2_DEVICE_CODE_LENGTH}
          >
            Continue
          </Button>
        </div>

        <p className="text-muted-foreground text-center text-[12px]">
          Signed in as{' '}
          <span className="text-foreground font-medium">{ACCOUNT_LABEL}</span>.
        </p>
      </form>
    </Card>
  )
}

function AuthorizationFailedCard() {
  return (
    <Card className="overflow-hidden p-6 md:p-8">
      <div className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="bg-destructive/10 flex size-10 items-center justify-center rounded-xl">
            <TriangleAlert className="text-destructive size-4" />
          </div>
          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">
              Authorization failed
            </h1>
            <p className="text-muted-foreground text-[13px] leading-relaxed">
              This authorization request is invalid or has expired.
            </p>
          </div>
        </div>
        <Button variant="outline" type="button" className="w-full">
          Go to console
        </Button>
      </div>
    </Card>
  )
}

function OAuth2PreviewPage() {
  const search = Route.useSearch()
  const navigate = Route.useNavigate()
  const screenFromUrl: OAuth2PreviewScreen = search.screen ?? 'consent'
  const [screen, setScreen] = useState<OAuth2PreviewScreen>(screenFromUrl)
  const [outcome, setOutcome] = useState<OAuth2Outcome | null>(null)

  useEffect(() => {
    setScreen(screenFromUrl)
  }, [screenFromUrl])

  useEffect(() => {
    setOutcome(null)
  }, [screen])

  const app = useMemo(() => mockApp(), [])
  const mcp = useMemo(() => mcpGrant(), [])

  const screenIndex = SCREEN_OPTIONS.findIndex(
    (option) => option.value === screen,
  )
  const currentIndex = screenIndex >= 0 ? screenIndex : 0

  const setPreviewScreen = (next: OAuth2PreviewScreen) => {
    setScreen(next)
    void navigate({
      to: '/debug/oauth2-preview',
      search: { screen: next },
      replace: true,
    })
  }

  const goToRelativeScreen = (delta: number) => {
    const nextIndex =
      (currentIndex + delta + SCREEN_OPTIONS.length) % SCREEN_OPTIONS.length
    setPreviewScreen(SCREEN_OPTIONS[nextIndex]!.value)
  }

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      const target = event.target
      if (!(target instanceof HTMLElement)) return
      if (
        target.isContentEditable ||
        target.closest('input, textarea, select')
      ) {
        return
      }
      if (event.key === 'ArrowLeft') {
        event.preventDefault()
        goToRelativeScreen(-1)
      } else if (event.key === 'ArrowRight') {
        event.preventDefault()
        goToRelativeScreen(1)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentIndex])

  const consentFlow: OAuth2Flow =
    screen === 'device-consent' ? 'device' : 'authorization'

  const activeGrant = useMemo(() => {
    if (screen === 'consent-mcp' || screen === 'device-consent') return mcp
    if (screen === 'consent-resources') return RESOURCES_GRANT
    if (screen === 'consent') return FULL_ACCESS_GRANT
    return IDENTITY_GRANT
  }, [mcp, screen])

  const showConsent =
    !outcome &&
    (screen === 'consent' ||
      screen === 'consent-mcp' ||
      screen === 'consent-resources' ||
      screen === 'device-consent')

  return (
    <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
      <div className="flex shrink-0 flex-wrap items-center gap-3 border-b border-border px-4 py-2">
        <p className="text-[12px] text-muted-foreground">
          Debug preview. OAuth2 screens.
        </p>
        <div className="flex items-center gap-1.5">
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="h-8 w-8 cursor-pointer px-0"
            onClick={() => goToRelativeScreen(-1)}
            aria-label="Previous screen"
          >
            <ChevronLeft className="h-4 w-4" />
          </Button>
          <select
            value={screen}
            onChange={(event) =>
              setPreviewScreen(event.target.value as OAuth2PreviewScreen)
            }
            aria-label="OAuth2 preview screen"
            className="border-input bg-background h-8 w-[280px] cursor-pointer rounded-md border px-2 text-[12px] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
          >
            {SCREEN_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="h-8 w-8 cursor-pointer px-0"
            onClick={() => goToRelativeScreen(1)}
            aria-label="Next screen"
          >
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
        <span className="text-[11px] text-muted-foreground">
          {currentIndex + 1} / {SCREEN_OPTIONS.length}
        </span>
        {outcome ? (
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="h-8 cursor-pointer text-[12px]"
            onClick={() => setOutcome(null)}
          >
            Reset outcome
          </Button>
        ) : null}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="flex min-h-full flex-col items-center p-6 md:p-10">
          <div className="my-auto w-full max-w-xl">
          {showConsent ? (
            <OAuth2ConsentCard
              key={screen}
              grant={activeGrant}
              app={app}
              accountLabel={ACCOUNT_LABEL}
              flow={consentFlow}
              preview
              onSwitchAccount={
                consentFlow === 'authorization' ? () => undefined : undefined
              }
              onDone={(next) => setOutcome(next)}
            />
          ) : null}

          {outcome ? (
            <OAuth2OutcomeCard
              outcome={outcome}
              flow={consentFlow}
              app={app}
              accountLabel={ACCOUNT_LABEL}
              redirectUrl={
                outcome === 'approved' && consentFlow === 'authorization'
                  ? 'cursor://oauth'
                  : undefined
              }
            />
          ) : null}

          {!outcome && screen === 'device-enter-code' ? (
            <DeviceCodeCard code="" hasPrefilledCode={false} />
          ) : null}

          {!outcome && screen === 'device-confirm-code' ? (
            <DeviceCodeCard code="AB12CD" hasPrefilledCode />
          ) : null}

          {!outcome && screen === 'outcome-approved' ? (
            <OAuth2OutcomeCard
              outcome="approved"
              flow="authorization"
              app={app}
              accountLabel={ACCOUNT_LABEL}
            />
          ) : null}

          {!outcome && screen === 'outcome-approved-device' ? (
            <OAuth2OutcomeCard
              outcome="approved"
              flow="device"
              app={app}
              accountLabel={ACCOUNT_LABEL}
            />
          ) : null}

          {!outcome && screen === 'outcome-approved-deeplink' ? (
            <OAuth2OutcomeCard
              outcome="approved"
              flow="authorization"
              app={app}
              accountLabel={ACCOUNT_LABEL}
              redirectUrl="cursor://oauth"
            />
          ) : null}

          {!outcome && screen === 'outcome-denied' ? (
            <OAuth2OutcomeCard
              outcome="denied"
              flow="authorization"
              app={app}
              accountLabel={ACCOUNT_LABEL}
            />
          ) : null}

          {!outcome && screen === 'error' ? <AuthorizationFailedCard /> : null}

          {!outcome && screen === 'loading' ? (
            <div className="flex min-h-64 items-center justify-center">
              <Loader2 className="text-muted-foreground size-8 animate-spin" />
            </div>
          ) : null}

          {!outcome && screen === 'relay-success' ? (
            <OAuth2RelayCard
              title="You're now logged in"
              preview
              previewProject="demoProject"
              previewSearch="?project=demoProject&secret=demo"
            />
          ) : null}

          {!outcome && screen === 'relay-failure' ? (
            <OAuth2RelayCard
              title="Login failed"
              preview
              previewProject="demoProject"
              previewSearch="?project=demoProject&error=denied"
            />
          ) : null}

          {!outcome && screen === 'relay-missing' ? (
            <OAuth2RelayCard title="You're now logged in" preview />
          ) : null}

          {!outcome && screen === 'relay-error' ? (
            <OAuth2RelayCard
              title="Login failed"
              preview
              previewError={{
                message: 'The user cancelled the authorization request.',
                type: 'user_denied',
                code: 401,
              }}
            />
          ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
