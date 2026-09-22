import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  OAUTH2_CONSENT_PREVIEW_SCREEN_OPTIONS,
  OAUTH2_DEVICE_FLOW_PREVIEW_SCREEN_OPTIONS,
  OAUTH2_OUTCOME_PREVIEW_SCREEN_OPTIONS,
  isOAuth2PreviewScreen,
  type OAuth2PreviewScreen,
} from '@/lib/debug-demos/oauth2-preview-screens'
import { dispatchDebugDemoPreviewControl } from '@/lib/debug-demos/preview-controls'
import {
  loadDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
} from '@/lib/debug-overrides'
import type { OrgSetupPreviewPhase } from '@/routes/_public/debug.org-setup-preview'

const VERIFY_EMAIL_STATUSES = ['pending', 'confirming'] as const
const GIT_CONTRIBUTOR_STATUSES = ['awaiting', 'success', 'error'] as const
const JOIN_INVITE_VIEWS = [
  'accept',
  'wrong-account',
  'invalid',
  'error',
  'success',
] as const

const ORG_SETUP_PHASES: { value: OrgSetupPreviewPhase; label: string }[] = [
  { value: 'submitting', label: 'Creating org' },
  { value: 'confirming-payment', label: 'Payment' },
  { value: 'activating', label: 'Activating' },
  { value: 'complete', label: 'Finishing' },
]

const OAUTH2_INTERACTIVE_CONSENT_SCREENS = new Set<OAuth2PreviewScreen>([
  'consent',
  'consent-mcp',
  'consent-resources',
  'device-consent',
])

function parseSearchParams(searchStr: string) {
  return new URLSearchParams(
    searchStr.startsWith('?') ? searchStr.slice(1) : searchStr,
  )
}

type DebugDemoNavigatorOptionsProps = {
  currentDemoId: string
}

export function DebugDemoNavigatorOptions({
  currentDemoId,
}: DebugDemoNavigatorOptionsProps) {
  const location = useLocation()
  const navigate = useNavigate()
  const params = useMemo(
    () => parseSearchParams(location.searchStr ?? ''),
    [location.searchStr],
  )
  const [fullscreenLoaderEnabled, setFullscreenLoaderEnabled] = useState(
    () => loadDebugOverrides().showFullscreenLoader,
  )

  useEffect(
    () =>
      subscribeToDebugOverrides((overrides) => {
        setFullscreenLoaderEnabled(overrides.showFullscreenLoader)
      }),
    [],
  )

  const pathname = location.pathname

  if (currentDemoId === 'tools-fullscreen-loader') {
    return (
      <OptionsSection label="Fullscreen loader">
        <ToggleRow
          id="demo-fullscreen-loader"
          label="Show loader"
          checked={fullscreenLoaderEnabled}
          onCheckedChange={(checked) => {
            setDebugOverride('showFullscreenLoader', checked)
          }}
        />
      </OptionsSection>
    )
  }

  if (pathname === '/debug/verify-email-preview') {
    const status = params.get('status') ?? 'pending'
    return (
      <OptionsSection label="Verify email">
        <OptionButtonRow>
          {VERIFY_EMAIL_STATUSES.map((value) => (
            <OptionChip
              key={value}
              active={status === value}
              onClick={() =>
                navigate({
                  to: '/debug/verify-email-preview',
                  search: { status: value },
                  replace: true,
                })
              }
            >
              {value === 'pending' ? 'Pending' : 'Confirming'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/reset-preview') {
    const view = params.get('view') ?? 'form'
    return (
      <OptionsSection label="Reset password">
        <OptionButtonRow>
          {(['form', 'success', 'invalid'] as const).map((value) => (
            <OptionChip
              key={value}
              active={view === value}
              onClick={() =>
                navigate({
                  to: '/debug/reset-preview',
                  search: { view: value },
                  replace: true,
                })
              }
            >
              {value === 'form'
                ? 'Form'
                : value === 'success'
                  ? 'Success'
                  : 'Invalid link'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/mfa-preview') {
    const factor = params.get('factor') ?? 'totp'
    return (
      <OptionsSection label="MFA factor">
        <OptionButtonRow>
          {(['totp', 'email', 'phone', 'recovery'] as const).map((value) => (
            <OptionChip
              key={value}
              active={factor === value}
              onClick={() =>
                navigate({
                  to: '/debug/mfa-preview',
                  search: { factor: value },
                  replace: true,
                })
              }
            >
              {value === 'totp'
                ? 'TOTP'
                : value === 'email'
                  ? 'Email'
                  : value === 'phone'
                    ? 'Phone'
                    : 'Recovery'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/magic-url-preview') {
    const view = params.get('view') ?? 'signing-in'
    return (
      <OptionsSection label="Magic URL">
        <OptionButtonRow>
          {(['signing-in', 'error'] as const).map((value) => (
            <OptionChip
              key={value}
              active={view === value}
              onClick={() =>
                navigate({
                  to: '/debug/magic-url-preview',
                  search: { view: value },
                  replace: true,
                })
              }
            >
              {value === 'signing-in' ? 'Signing in' : 'Error'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/sites-auth-preview') {
    const status = params.get('status') ?? 'checking'
    return (
      <OptionsSection label="Sites preview">
        <OptionButtonRow>
          {(['checking', 'denied', 'error', 'invalid'] as const).map(
            (value) => (
              <OptionChip
                key={value}
                active={status === value}
                onClick={() =>
                  navigate({
                    to: '/debug/sites-auth-preview',
                    search: { status: value },
                    replace: true,
                  })
                }
              >
                {value === 'checking'
                  ? 'Checking'
                  : value === 'denied'
                    ? 'Private'
                    : value === 'error'
                      ? 'Error'
                      : 'Invalid'}
              </OptionChip>
            ),
          )}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/oauth2-relay-preview') {
    const variant = params.get('variant') ?? 'success'
    return (
      <OptionsSection label="OAuth2 relay">
        <OptionButtonRow>
          {(['success', 'failure', 'missing', 'error'] as const).map(
            (value) => (
              <OptionChip
                key={value}
                active={variant === value}
                onClick={() =>
                  navigate({
                    to: '/debug/oauth2-relay-preview',
                    search: { variant: value },
                    replace: true,
                  })
                }
              >
                {value.charAt(0).toUpperCase() + value.slice(1)}
              </OptionChip>
            ),
          )}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/education-join-preview') {
    const view = params.get('view') ?? 'landing'
    return (
      <OptionsSection label="Education join">
        <OptionButtonRow>
          {(['landing', 'oauth-failure', 'loading', 'ineligible'] as const).map(
            (value) => (
              <OptionChip
                key={value}
                active={view === value}
                onClick={() =>
                  navigate({
                    to: '/debug/education-join-preview',
                    search: { view: value },
                    replace: true,
                  })
                }
              >
                {value === 'landing'
                  ? 'Landing'
                  : value === 'oauth-failure'
                    ? 'OAuth fail'
                    : value === 'loading'
                      ? 'Loading'
                      : 'Ineligible'}
              </OptionChip>
            ),
          )}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/join-invite-preview') {
    const view = params.get('view') ?? 'accept'
    return (
      <OptionsSection label="Organization invite">
        <OptionButtonRow>
          {JOIN_INVITE_VIEWS.map((value) => (
            <OptionChip
              key={value}
              active={view === value}
              onClick={() =>
                navigate({
                  to: '/debug/join-invite-preview',
                  search: { view: value },
                  replace: true,
                })
              }
            >
              {value === 'accept'
                ? 'Accept'
                : value === 'wrong-account'
                  ? 'Wrong account'
                  : value === 'invalid'
                    ? 'Invalid link'
                    : value === 'error'
                      ? 'Error'
                      : 'Success'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/authorize-contributor-preview') {
    const status = params.get('status') ?? 'awaiting'
    return (
      <OptionsSection label="Git authorization">
        <OptionButtonRow>
          {GIT_CONTRIBUTOR_STATUSES.map((value) => (
            <OptionChip
              key={value}
              active={status === value}
              onClick={() =>
                navigate({
                  to: '/debug/authorize-contributor-preview',
                  search: { status: value },
                  replace: true,
                })
              }
            >
              {value === 'awaiting'
                ? 'Awaiting'
                : value === 'success'
                  ? 'Approved'
                  : 'Failed'}
            </OptionChip>
          ))}
        </OptionButtonRow>
      </OptionsSection>
    )
  }

  if (pathname === '/debug/org-setup-preview') {
    const phaseParam = params.get('phase')
    const phase: OrgSetupPreviewPhase = ORG_SETUP_PHASES.some(
      (option) => option.value === phaseParam,
    )
      ? (phaseParam as OrgSetupPreviewPhase)
      : 'activating'
    const mode = params.get('mode') === 'upgrade' ? 'upgrade' : 'create'
    const payment = params.get('payment') !== 'false'
    const activation = params.get('activation') !== 'false'

    const patchSearch = (
      patch: Partial<{
        phase: OrgSetupPreviewPhase
        mode: 'create' | 'upgrade'
        payment: boolean
        activation: boolean
      }>,
    ) => {
      const nextPhase = patch.phase ?? phase
      const nextMode = patch.mode ?? mode
      const nextPayment = patch.payment ?? payment
      const nextActivation = patch.activation ?? activation
      navigate({
        to: '/debug/org-setup-preview',
        search: {
          phase: nextPhase,
          mode: nextMode,
          payment: nextPayment,
          activation: nextActivation,
        },
        replace: true,
      })
    }

    return (
      <OptionsSection label="Org setup">
        <OptionButtonRow>
          {ORG_SETUP_PHASES.map((option) => (
            <OptionChip
              key={option.value}
              active={phase === option.value}
              onClick={() => patchSearch({ phase: option.value })}
            >
              {option.label}
            </OptionChip>
          ))}
        </OptionButtonRow>
        <div className="space-y-2 pt-1">
          <Label className="text-[10px] text-muted-foreground">Mode</Label>
          <Select
            value={mode}
            onValueChange={(value: 'create' | 'upgrade') =>
              patchSearch({ mode: value })
            }
          >
            <SelectTrigger className="h-8 text-[12px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent className="z-[10070]" dir="ltr" lang="en">
              <SelectItem value="create">Create organization</SelectItem>
              <SelectItem value="upgrade">Change plan</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <ToggleRow
          id="demo-org-payment"
          label="Payment step"
          checked={payment}
          onCheckedChange={(checked) => patchSearch({ payment: checked })}
        />
        <ToggleRow
          id="demo-org-activation"
          label="Activation step"
          checked={activation}
          onCheckedChange={(checked) => patchSearch({ activation: checked })}
        />
      </OptionsSection>
    )
  }

  if (pathname === '/debug/oauth2-preview') {
    const screenParam = params.get('screen') ?? 'consent'
    const screen: OAuth2PreviewScreen = isOAuth2PreviewScreen(screenParam)
      ? screenParam
      : 'consent'
    const deviceStepRaw = params.get('deviceStep')
    const deviceStep: 'enter' | 'confirm' =
      deviceStepRaw === 'confirm' ? 'confirm' : 'enter'
    const isInteractiveConsent = OAUTH2_INTERACTIVE_CONSENT_SCREENS.has(screen)

    const goToOAuth2Screen = (
      nextScreen: OAuth2PreviewScreen,
      nextDeviceStep?: 'enter' | 'confirm',
    ) => {
      if (nextScreen === 'device-code') {
        navigate({
          to: '/debug/oauth2-preview',
          search: {
            screen: nextScreen,
            deviceStep: nextDeviceStep ?? deviceStep,
          },
          replace: true,
        })
        return
      }
      navigate({
        to: '/debug/oauth2-preview',
        search: { screen: nextScreen },
        replace: true,
      })
    }

    return (
      <>
        <OptionsSection label="OAuth2 consent">
          <OptionButtonRow>
            {OAUTH2_CONSENT_PREVIEW_SCREEN_OPTIONS.map((option) => (
              <OptionChip
                key={option.value}
                active={screen === option.value}
                onClick={() => goToOAuth2Screen(option.value)}
              >
                {option.label}
              </OptionChip>
            ))}
          </OptionButtonRow>
        </OptionsSection>
        <OptionsSection label="OAuth2 device flow">
          <OptionButtonRow>
            {OAUTH2_DEVICE_FLOW_PREVIEW_SCREEN_OPTIONS.map((option) => (
              <OptionChip
                key={option.value}
                active={screen === option.value}
                onClick={() => goToOAuth2Screen(option.value)}
              >
                {option.label}
              </OptionChip>
            ))}
          </OptionButtonRow>
          {screen === 'device-code' ? (
            <OptionButtonRow>
              {(['enter', 'confirm'] as const).map((value) => (
                <OptionChip
                  key={value}
                  active={deviceStep === value}
                  onClick={() => goToOAuth2Screen('device-code', value)}
                >
                  {value === 'enter' ? 'Enter code' : 'Confirm code'}
                </OptionChip>
              ))}
            </OptionButtonRow>
          ) : null}
        </OptionsSection>
        <OptionsSection label="OAuth2 outcomes">
          <OptionButtonRow>
            {OAUTH2_OUTCOME_PREVIEW_SCREEN_OPTIONS.map((option) => (
              <OptionChip
                key={option.value}
                active={screen === option.value}
                onClick={() => goToOAuth2Screen(option.value)}
              >
                {option.label}
              </OptionChip>
            ))}
          </OptionButtonRow>
        </OptionsSection>
        {isInteractiveConsent ? (
          <OptionsSection label="OAuth2 consent actions">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 w-full text-[12px]"
              onClick={() =>
                dispatchDebugDemoPreviewControl({
                  type: 'oauth2-reset-outcome',
                })
              }
            >
              Reset consent outcome
            </Button>
          </OptionsSection>
        ) : null}
      </>
    )
  }

  return null
}

function OptionsSection({
  label,
  children,
}: {
  label: string
  children: ReactNode
}) {
  return (
    <div className="shrink-0 space-y-2 border-b border-border px-2 py-2">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      {children}
    </div>
  )
}

function OptionButtonRow({ children }: { children: ReactNode }) {
  return <div className="flex flex-wrap gap-1">{children}</div>
}

function OptionChip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <Button
      type="button"
      size="sm"
      variant={active ? 'secondary' : 'outline'}
      className="h-7 px-2 text-[11px]"
      onClick={onClick}
    >
      {children}
    </Button>
  )
}

function ToggleRow({
  id,
  label,
  checked,
  onCheckedChange,
}: {
  id: string
  label: string
  checked: boolean
  onCheckedChange: (checked: boolean) => void
}) {
  return (
    <div className="flex items-center justify-between gap-2 rounded-md border border-border px-2 py-1.5">
      <Label htmlFor={id} className="text-[11px] font-normal">
        {label}
      </Label>
      <Switch id={id} checked={checked} onCheckedChange={onCheckedChange} />
    </div>
  )
}
