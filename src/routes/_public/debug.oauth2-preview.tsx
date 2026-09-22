import { useEffect, useMemo, useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { Loader2, MonitorSmartphone, TriangleAlert } from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import {
  OAuth2ConsentCard,
  type OAuth2Flow,
  type OAuth2Outcome,
} from '@/components/global/auth/OAuth2ConsentCard'
import { OAuth2OutcomeCard } from '@/components/global/auth/OAuth2OutcomeCard'
import {
  OAUTH2_DEVICE_CODE_MIN_LENGTH,
  OAuth2DeviceCodeInput,
} from '@/components/global/auth/OAuth2DeviceCodeInput'
import { Button } from '@/components/ui/button'
import { AuthFlowAccountSwitcherStatic } from '@/components/global/auth/AuthFlowAccountSwitcherStatic'
import {
  AuthFlowNarrowCard,
  authFlowOAuthNarrowCardContentClassName,
} from '@/components/global/auth/AuthFlowCard'
import { AuthFlowHeaderIcon } from '@/components/global/auth/AuthFlowHeaderIcon'
import { AuthFlowShell } from '@/components/global/auth/AuthFlowShell'
import { Label } from '@/components/ui/label'
import { getEffectiveMcpEndpointUrl } from '@/lib/debug-mcp-endpoint'
import {
  ORGANIZATION_RAR_TYPE,
  PROJECT_RAR_TYPE,
} from '@/lib/oauth2/authorization-details'
import {
  OAUTH2_PREVIEW_SCREENS,
  type OAuth2PreviewScreen,
} from '@/lib/debug-demos/oauth2-preview-screens'
import { DEBUG_DEMO_MOCK_EMAIL } from '@/lib/debug-demos/constants'
import { subscribeDebugDemoPreviewControl } from '@/lib/debug-demos/preview-controls'
import { pageTitle } from '@/lib/utils/page-title'

export type { OAuth2PreviewScreen }

import { useT } from '@/lib/i18n/translate'

const OAUTH2_DEVICE_CODE_STEPS = ['enter', 'confirm'] as const
export type OAuth2DeviceCodePreviewStep =
  (typeof OAUTH2_DEVICE_CODE_STEPS)[number]

const oauth2PreviewSearchSchema = z.object({
  screen: z.enum(OAUTH2_PREVIEW_SCREENS).optional(),
  deviceStep: z.enum(OAUTH2_DEVICE_CODE_STEPS).optional(),
})

export const Route = createFileRoute('/_public/debug/oauth2-preview')({
  validateSearch: oauth2PreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('OAuth2 preview') }] }),
  component: OAuth2PreviewPage,
})

const ACCOUNT_LABEL = DEBUG_DEMO_MOCK_EMAIL

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

function mockGrant(
  overrides?: Partial<Models.Oauth2Grant>,
): Models.Oauth2Grant {
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
    <AuthFlowNarrowCard
      contentClassName={authFlowOAuthNarrowCardContentClassName}
    >
      <form onSubmit={(e) => e.preventDefault()} className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <AuthFlowHeaderIcon icon={MonitorSmartphone} />
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
          {error ? (
            <p className="text-destructive text-[13px]">{error}</p>
          ) : null}
        </div>

        <div className="flex flex-col gap-2">
          <Button
            type="submit"
            variant="brandCta"
            className="w-full"
            disabled={code.length < OAUTH2_DEVICE_CODE_MIN_LENGTH}
          >
            Continue
          </Button>
        </div>
      </form>
    </AuthFlowNarrowCard>
  )
}

function AuthorizationFailedCard() {
  const t = useT()
  return (
    <AuthFlowNarrowCard
      contentClassName={authFlowOAuthNarrowCardContentClassName}
    >
      <div className="space-y-6">
        <div className="flex flex-col items-center gap-4 text-center">
          <AuthFlowHeaderIcon icon={TriangleAlert} variant="destructive" />
          <div className="space-y-1">
            <h1 className="text-2xl font-semibold tracking-tight">
              {t('Authorization failed')}
            </h1>
            <p className="text-muted-foreground text-[13px] leading-relaxed">
              {t('This authorization request is invalid or has expired.')}
            </p>
          </div>
        </div>
        <Button variant="outline" type="button" className="w-full">
          {t('Go to console')}
        </Button>
      </div>
    </AuthFlowNarrowCard>
  )
}

function OAuth2PreviewPage() {
  const search = Route.useSearch()
  const screen: OAuth2PreviewScreen = search.screen ?? 'consent'
  const deviceStep: OAuth2DeviceCodePreviewStep = search.deviceStep ?? 'enter'
  const [outcome, setOutcome] = useState<OAuth2Outcome | null>(null)

  useEffect(() => {
    setOutcome(null)
  }, [screen, deviceStep])

  useEffect(
    () =>
      subscribeDebugDemoPreviewControl((control) => {
        if (control.type === 'oauth2-reset-outcome') {
          setOutcome(null)
        }
      }),
    [],
  )

  const app = useMemo(() => mockApp(), [])
  const mcp = useMemo(() => mcpGrant(), [])

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
    <AuthFlowShell
      width="narrow"
      showLegal={false}
      accountSwitcher={
        <AuthFlowAccountSwitcherStatic accountLabel={ACCOUNT_LABEL} preview />
      }
    >
      {showConsent ? (
        <OAuth2ConsentCard
          key={screen}
          grant={activeGrant}
          app={app}
          flow={consentFlow}
          preview
          onDone={(next) => setOutcome(next)}
        />
      ) : null}

      {outcome ? (
        <OAuth2OutcomeCard
          outcome={outcome}
          flow={consentFlow}
          app={app}
          redirectUrl={
            outcome === 'approved' && consentFlow === 'authorization'
              ? 'cursor://oauth'
              : undefined
          }
        />
      ) : null}

      {!outcome && screen === 'device-code' ? (
        <DeviceCodeCard
          code={deviceStep === 'confirm' ? 'MDF2TN39' : ''}
          hasPrefilledCode={deviceStep === 'confirm'}
        />
      ) : null}

      {!outcome && screen === 'outcome-approved' ? (
        <OAuth2OutcomeCard outcome="approved" flow="authorization" app={app} />
      ) : null}

      {!outcome && screen === 'outcome-approved-device' ? (
        <OAuth2OutcomeCard outcome="approved" flow="device" app={app} />
      ) : null}

      {!outcome && screen === 'outcome-approved-deeplink' ? (
        <OAuth2OutcomeCard
          outcome="approved"
          flow="authorization"
          app={app}
          redirectUrl="cursor://oauth"
        />
      ) : null}

      {!outcome && screen === 'outcome-denied' ? (
        <OAuth2OutcomeCard outcome="denied" flow="authorization" app={app} />
      ) : null}

      {!outcome && screen === 'error' ? <AuthorizationFailedCard /> : null}

      {!outcome && screen === 'loading' ? (
        <AuthFlowNarrowCard
          contentClassName={authFlowOAuthNarrowCardContentClassName}
        >
          <div className="flex flex-col items-center py-8 text-center">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground motion-reduce:animate-none" />
          </div>
        </AuthFlowNarrowCard>
      ) : null}
    </AuthFlowShell>
  )
}
