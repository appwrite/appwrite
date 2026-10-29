import { useEffect, useMemo, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  OAUTH2_CONSENT_PREVIEW_SCREEN_OPTIONS,
  OAUTH2_DEVICE_FLOW_PREVIEW_SCREEN_OPTIONS,
  OAUTH2_OUTCOME_PREVIEW_SCREEN_OPTIONS,
  isOAuth2PreviewScreen,
  type OAuth2PreviewScreen,
} from '@/lib/debug-demos/oauth2-preview-screens'
import {
  OAUTH2_PREVIEW_MOCK_APP_ID,
  isOAuth2PreviewMockAppId,
} from '@/lib/debug-demos/oauth2-preview-app'
import { dispatchDebugDemoPreviewControl } from '@/lib/debug-demos/preview-controls'
import {
  MARKETPLACE_APPS_LIMIT,
  marketplaceCatalogPickerQueryOptions,
  organizationAppQueryOptions,
  useOrganizations,
} from '@/lib/react-query/hooks'

const OAUTH2_INTERACTIVE_CONSENT_SCREENS = new Set<OAuth2PreviewScreen>([
  'consent',
  'consent-mcp',
  'consent-resources',
  'device-consent',
])

type OAuth2PreviewSearch = {
  screen?: OAuth2PreviewScreen
  deviceStep?: 'enter' | 'confirm'
  appId?: string
  appName?: string
  orgId?: string
}

function buildOAuth2PreviewSearch(
  current: OAuth2PreviewSearch,
  patch: Partial<OAuth2PreviewSearch>,
): OAuth2PreviewSearch {
  const next: OAuth2PreviewSearch = {
    ...current,
    ...patch,
  }
  if (patch.appId !== undefined && isOAuth2PreviewMockAppId(patch.appId)) {
    delete next.appId
    delete next.appName
  }
  if (patch.screen && patch.screen !== 'device-code') {
    delete next.deviceStep
  }
  return next
}

type DebugOAuth2PreviewOptionsProps = {
  params: URLSearchParams
}

export function DebugOAuth2PreviewOptions({
  params,
}: DebugOAuth2PreviewOptionsProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()
  const { organizations, isLoading: orgsLoading } = useOrganizations()

  const screenParam = params.get('screen') ?? 'consent'
  const screen: OAuth2PreviewScreen = isOAuth2PreviewScreen(screenParam)
    ? screenParam
    : 'consent'
  const deviceStepRaw = params.get('deviceStep')
  const deviceStep: 'enter' | 'confirm' =
    deviceStepRaw === 'confirm' ? 'confirm' : 'enter'
  const appIdParam = params.get('appId') ?? OAUTH2_PREVIEW_MOCK_APP_ID
  const appNameParam = params.get('appName') ?? undefined
  const orgIdParam = params.get('orgId') ?? ''

  const resolvedOrgId = useMemo(() => {
    if (orgIdParam && organizations.some((o) => o.$id === orgIdParam)) {
      return orgIdParam
    }
    return organizations[0]?.$id ?? ''
  }, [orgIdParam, organizations])

  useEffect(() => {
    if (!isAuthenticated || orgsLoading || !resolvedOrgId) return
    if (orgIdParam === resolvedOrgId) return
    navigate({
      to: '/debug/oauth2-preview',
      search: buildOAuth2PreviewSearch(
        { screen, deviceStep, appId: appIdParam, orgId: orgIdParam },
        { orgId: resolvedOrgId },
      ),
      replace: true,
    })
  }, [
    isAuthenticated,
    orgsLoading,
    resolvedOrgId,
    orgIdParam,
    navigate,
    screen,
    deviceStep,
    appIdParam,
  ])

  const { data: catalogData, isLoading: appsLoading } = useQuery({
    ...marketplaceCatalogPickerQueryOptions(
      isAuthenticated && resolvedOrgId ? resolvedOrgId : null,
    ),
  })

  const marketplaceApps = useMemo(() => {
    const apps = catalogData?.apps ?? []
    return [...apps].sort((a, b) => a.name.localeCompare(b.name))
  }, [catalogData?.apps])

  const catalogTotal = catalogData?.total ?? 0
  const catalogTruncated = catalogTotal > MARKETPLACE_APPS_LIMIT

  const currentSearch: OAuth2PreviewSearch = {
    screen,
    deviceStep: screen === 'device-code' ? deviceStep : undefined,
    appId: isOAuth2PreviewMockAppId(appIdParam) ? undefined : appIdParam,
    appName: appNameParam,
    orgId: resolvedOrgId || undefined,
  }

  const goToOAuth2Screen = (
    nextScreen: OAuth2PreviewScreen,
    nextDeviceStep?: 'enter' | 'confirm',
  ) => {
    navigate({
      to: '/debug/oauth2-preview',
      search: buildOAuth2PreviewSearch(currentSearch, {
        screen: nextScreen,
        deviceStep:
          nextScreen === 'device-code'
            ? (nextDeviceStep ?? deviceStep)
            : undefined,
      }),
      replace: true,
    })
  }

  const setPreviewApp = (value: string) => {
    const catalogName =
      value === OAUTH2_PREVIEW_MOCK_APP_ID
        ? undefined
        : marketplaceApps.find((app) => app.$id === value)?.name

    if (!isOAuth2PreviewMockAppId(value)) {
      void queryClient.prefetchQuery(organizationAppQueryOptions(value))
    }

    navigate({
      to: '/debug/oauth2-preview',
      search: buildOAuth2PreviewSearch(currentSearch, {
        appId: value,
        appName: catalogName,
        orgId: resolvedOrgId || undefined,
      }),
      replace: true,
    })
  }

  const setPreviewOrg = (value: string) => {
    navigate({
      to: '/debug/oauth2-preview',
      search: buildOAuth2PreviewSearch(currentSearch, {
        orgId: value,
        appId: OAUTH2_PREVIEW_MOCK_APP_ID,
      }),
      replace: true,
    })
  }

  const isInteractiveConsent = OAUTH2_INTERACTIVE_CONSENT_SCREENS.has(screen)

  return (
    <>
      <OptionsSection label="Preview client">
        {!isAuthenticated ? (
          <p className="text-[11px] leading-relaxed text-muted-foreground">
            Sign in to pick an app from the marketplace catalog (same list as
            Explore / Catalog).
          </p>
        ) : orgsLoading ? (
          <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
            <Loader2 className="size-3.5 animate-spin" />
            Loading organizations…
          </div>
        ) : organizations.length === 0 ? (
          <p className="text-[11px] leading-relaxed text-muted-foreground">
            Create an organization to list its OAuth apps here.
          </p>
        ) : (
          <div className="space-y-2">
            <div className="space-y-1">
              <Label className="text-[11px] font-normal text-muted-foreground">
                Organization context
              </Label>
              <Select value={resolvedOrgId} onValueChange={setPreviewOrg}>
                <SelectTrigger className="h-8 text-[12px]">
                  <SelectValue placeholder="Organization" />
                </SelectTrigger>
                <SelectContent className="z-[10070]" dir="ltr" lang="en">
                  {organizations.map((org) => (
                    <SelectItem key={org.$id} value={org.$id}>
                      {org.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-[11px] font-normal text-muted-foreground">
                Marketplace app
              </Label>
              <Select value={appIdParam} onValueChange={setPreviewApp}>
                <SelectTrigger className="h-8 text-[12px]">
                  <SelectValue placeholder="Marketplace app" />
                </SelectTrigger>
                <SelectContent className="z-[10070]" dir="ltr" lang="en">
                  <SelectItem value={OAUTH2_PREVIEW_MOCK_APP_ID}>
                    Mock (Cursor)
                  </SelectItem>
                  {appsLoading ? (
                    <SelectItem value="__loading" disabled>
                      Loading marketplace apps…
                    </SelectItem>
                  ) : marketplaceApps.length === 0 ? (
                    <SelectItem value="__empty" disabled>
                      Marketplace catalog is empty
                    </SelectItem>
                  ) : (
                    marketplaceApps.map((app) => (
                      <SelectItem key={app.$id} value={app.$id}>
                        {app.name}
                      </SelectItem>
                    ))
                  )}
                </SelectContent>
              </Select>
              {catalogTruncated ? (
                <p className="text-[10px] leading-relaxed text-muted-foreground">
                  Showing first {MARKETPLACE_APPS_LIMIT} of {catalogTotal}{' '}
                  catalog apps (same query as marketplace browse).
                </p>
              ) : null}
            </div>
          </div>
        )}
      </OptionsSection>
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
