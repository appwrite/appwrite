import { useMemo, useState, type ReactNode } from 'react'
import {
  AlertTriangle,
  Check,
  ChevronDown,
  Cloud,
  Columns2,
  Globe,
  Plus,
  RotateCcw,
  Server,
  Trash2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugEndpoint } from '@/hooks/use-debug-endpoint'
import { usePromptDialog } from '@/hooks/use-prompt-dialog'
import {
  CONSOLE_PROFILES,
  getEnvProfileId,
  hasDebugProfileOverride,
  setDebugProfileOverride,
  type ConsoleProfileId,
} from '@/lib/console-profiles'
import {
  addCustomDebugEndpoint,
  ENDPOINT_PRESETS,
  getDebugEndpointBaseUrl,
  isCloudEndpointUrl,
  normalizeEndpointUrl,
  removeCustomDebugEndpoint,
  setDebugEndpointOverride,
  type EndpointPresetId,
} from '@/lib/debug-endpoint'
import { DEBUG_MENU_DIALOG_LAYER } from '@/lib/debug-menu-position'
import { cn } from '@/lib/utils'

type ProfileChoice = ConsoleProfileId | 'env'

/** `'env'` or a normalized endpoint URL. */
type EndpointChoice = string

type PresetId = Exclude<EndpointPresetId, 'custom'>

const PRESET_PROFILES: Record<PresetId, ConsoleProfileId> = {
  production: 'cloud',
  stage: 'cloud',
  localhostCloud: 'cloud',
  localhostCe: 'self-hosted',
  oss: 'self-hosted',
}

const PROFILE_ICONS: Record<ConsoleProfileId, ReactNode> = {
  cloud: <Cloud className="h-3.5 w-3.5" />,
  'self-hosted': <Server className="h-3.5 w-3.5" />,
}

const SECTION_LABEL_CLASS =
  'px-1 pb-2 text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80'

const MUTED_TEXT_CLASS = 'text-[11px] text-[var(--network-globe-edge)]/80'

function optionClassName(selected: boolean) {
  return cn(
    'rounded-lg border text-start transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40',
    selected
      ? 'border-[color-mix(in_srgb,var(--network-globe-edge)_60%,var(--border))] bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)]'
      : 'border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_8%,transparent)]',
  )
}

function SelectionDot({ selected }: { selected: boolean }) {
  return (
    <span
      className={cn(
        'flex h-4 w-4 shrink-0 items-center justify-center rounded-full border transition-colors',
        selected
          ? 'border-[var(--network-globe-edge)] bg-[var(--network-globe-edge)] text-background'
          : 'border-[color-mix(in_srgb,var(--network-globe-edge)_35%,var(--border))]',
      )}
      aria-hidden
    >
      {selected ? <Check className="h-2.5 w-2.5" strokeWidth={3} /> : null}
    </span>
  )
}

function AppliedTag() {
  return (
    <span className="shrink-0 rounded-full bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,transparent)] px-1.5 py-px text-[10px] font-medium text-[var(--network-globe-edge)]">
      Applied
    </span>
  )
}

function hostLabel(url: string): string {
  try {
    return new URL(url).host
  } catch {
    return url
  }
}

function presetIdForUrl(url: string): PresetId | null {
  const normalized = normalizeEndpointUrl(url)
  for (const [id, preset] of Object.entries(ENDPOINT_PRESETS) as [
    PresetId,
    (typeof ENDPOINT_PRESETS)[PresetId],
  ][]) {
    if (normalizeEndpointUrl(preset.url) === normalized) return id
  }
  return null
}

function impliedProfileForEndpoint(
  endpoint: EndpointChoice,
): ConsoleProfileId | null {
  if (endpoint === 'env') return null
  const presetId = presetIdForUrl(endpoint)
  if (presetId) return PRESET_PROFILES[presetId]
  return isCloudEndpointUrl(endpoint) ? 'cloud' : null
}

function profileChoiceLabel(choice: ProfileChoice): string {
  return choice === 'env'
    ? `Env (${CONSOLE_PROFILES[getEnvProfileId()].label})`
    : CONSOLE_PROFILES[choice].label
}

function endpointChoiceLabel(choice: EndpointChoice): string {
  if (choice === 'env') return 'Env endpoint'
  const presetId = presetIdForUrl(choice)
  return presetId ? ENDPOINT_PRESETS[presetId].label : hostLabel(choice)
}

export function DebugMenuTargetPanel({
  onApply,
  comparisonTable,
}: {
  /** Runs the override writes, then closes the menu and reloads. */
  onApply: (action: () => void) => void
  comparisonTable: ReactNode
}) {
  const { profileId } = useConsoleProfile()
  const { customEndpoints, envUrl } = useDebugEndpoint()
  const { prompt, promptDialog } = usePromptDialog(DEBUG_MENU_DIALOG_LAYER)

  const appliedProfile: ProfileChoice = hasDebugProfileOverride()
    ? profileId
    : 'env'
  const appliedEndpoint: EndpointChoice = getDebugEndpointBaseUrl() ?? 'env'

  const [pendingProfile, setPendingProfile] =
    useState<ProfileChoice>(appliedProfile)
  const [pendingEndpoint, setPendingEndpoint] =
    useState<EndpointChoice>(appliedEndpoint)
  const [showComparison, setShowComparison] = useState(false)

  const profileChanged = pendingProfile !== appliedProfile
  const endpointChanged = pendingEndpoint !== appliedEndpoint
  const isDirty = profileChanged || endpointChanged

  const envProfileId = getEnvProfileId()
  const resolvedPendingProfile =
    pendingProfile === 'env' ? envProfileId : pendingProfile
  const suggestedProfile = impliedProfileForEndpoint(pendingEndpoint)
  const hasProfileMismatch =
    suggestedProfile !== null && suggestedProfile !== resolvedPendingProfile

  const endpointOptions = useMemo(
    () => [
      ...(Object.entries(ENDPOINT_PRESETS) as [
        PresetId,
        (typeof ENDPOINT_PRESETS)[PresetId],
      ][]).map(([id, preset]) => ({
        value: normalizeEndpointUrl(preset.url),
        label: preset.label,
        url: preset.url,
        description: preset.description,
        profile: PRESET_PROFILES[id] as ConsoleProfileId | null,
        removable: false,
      })),
      ...customEndpoints.map((url) => ({
        value: url,
        label: hostLabel(url),
        url,
        description: 'Custom endpoint',
        profile: isCloudEndpointUrl(url) ? ('cloud' as const) : null,
        removable: true,
      })),
    ],
    [customEndpoints],
  )

  const handleAddCustom = async () => {
    const values = await prompt({
      title: 'Custom API endpoint',
      fields: [
        {
          name: 'url',
          label: 'API endpoint URL',
          placeholder: 'https://my-appwrite.example/v1',
          defaultValue: 'http://localhost:9601/v1',
        },
      ],
      confirmLabel: 'Add endpoint',
    })
    const url = values?.url.trim()
    if (!url) return
    const normalized = normalizeEndpointUrl(url)
    if (!presetIdForUrl(normalized) && !addCustomDebugEndpoint(normalized)) {
      return
    }
    setPendingEndpoint(normalized)
  }

  const handleRemoveCustom = (url: string) => {
    removeCustomDebugEndpoint(url)
    if (pendingEndpoint === url) setPendingEndpoint(appliedEndpoint)
  }

  const handleApply = () => {
    if (!isDirty) return
    onApply(() => {
      if (profileChanged) {
        setDebugProfileOverride(pendingProfile === 'env' ? null : pendingProfile)
      }
      if (endpointChanged) {
        if (pendingEndpoint === 'env') {
          setDebugEndpointOverride(null)
        } else {
          const presetId = presetIdForUrl(pendingEndpoint)
          if (presetId) setDebugEndpointOverride(presetId)
          else setDebugEndpointOverride('custom', pendingEndpoint)
        }
      }
    })
  }

  const profileOptions: {
    value: ProfileChoice
    label: string
    description: string
    icon: ReactNode
  }[] = [
    {
      value: 'cloud',
      label: CONSOLE_PROFILES.cloud.label,
      description: 'Full Cloud feature set',
      icon: PROFILE_ICONS.cloud,
    },
    {
      value: 'self-hosted',
      label: CONSOLE_PROFILES['self-hosted'].label,
      description: 'Cloud-only features off',
      icon: PROFILE_ICONS['self-hosted'],
    },
    {
      value: 'env',
      label: 'Env',
      description: `Uses env var (${CONSOLE_PROFILES[envProfileId].label})`,
      icon: <RotateCcw className="h-3.5 w-3.5" />,
    },
  ]

  return (
    <div className="flex flex-col" aria-label="Profile and endpoint">
      <div className="space-y-5 px-1 pb-4">
        <section>
          <div className="flex items-center justify-between">
            <p className={SECTION_LABEL_CLASS}>Profile</p>
            <button
              type="button"
              onClick={() => setShowComparison((value) => !value)}
              className="mb-2 flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium text-[var(--network-globe-edge)] transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]"
              aria-expanded={showComparison}
            >
              <Columns2 className="h-3 w-3" />
              Compare
              <ChevronDown
                className={cn(
                  'h-3 w-3 transition-transform',
                  showComparison && 'rotate-180',
                )}
              />
            </button>
          </div>
          <div className="grid grid-cols-3 gap-2" role="radiogroup">
            {profileOptions.map((option) => {
              const selected = pendingProfile === option.value
              return (
                <button
                  key={option.value}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  onClick={() => setPendingProfile(option.value)}
                  className={cn(
                    optionClassName(selected),
                    'flex flex-col gap-1.5 px-3 py-2.5',
                  )}
                >
                  <span className="flex items-center gap-1.5">
                    <span className="text-[var(--network-globe-edge)]">
                      {option.icon}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground">
                      {option.label}
                    </span>
                    <SelectionDot selected={selected} />
                  </span>
                  <span className={cn(MUTED_TEXT_CLASS, 'line-clamp-2')}>
                    {option.description}
                  </span>
                  {appliedProfile === option.value ? (
                    <span>
                      <AppliedTag />
                    </span>
                  ) : null}
                </button>
              )
            })}
          </div>
          {showComparison ? (
            <div className="mt-3 rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] p-2">
              {comparisonTable}
            </div>
          ) : null}
        </section>

        <section>
          <div className="flex items-center justify-between">
            <p className={SECTION_LABEL_CLASS}>Endpoint</p>
            <button
              type="button"
              onClick={() => void handleAddCustom()}
              className="mb-2 flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium text-[var(--network-globe-edge)] transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]"
            >
              <Plus className="h-3 w-3" />
              Add custom
            </button>
          </div>
          <div className="space-y-1.5" role="radiogroup">
            {[
              {
                value: 'env',
                label: 'Env',
                url: envUrl ?? 'VITE_APPWRITE_ENDPOINT',
                description: 'VITE_APPWRITE_ENDPOINT',
                profile: null as ConsoleProfileId | null,
                removable: false,
              },
              ...endpointOptions,
            ].map((option) => {
              const selected = pendingEndpoint === option.value
              const isApplied = appliedEndpoint === option.value
              return (
                <div
                  key={option.value}
                  className={cn(optionClassName(selected), 'flex items-center')}
                >
                  <button
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    onClick={() => setPendingEndpoint(option.value)}
                    className="flex min-w-0 flex-1 items-center gap-3 px-3 py-2 text-start focus-visible:outline-none"
                  >
                    <SelectionDot selected={selected} />
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-1.5">
                        <span className="truncate text-[13px] font-medium text-foreground">
                          {option.label}
                        </span>
                        {isApplied ? <AppliedTag /> : null}
                      </span>
                      <span
                        className={cn(
                          MUTED_TEXT_CLASS,
                          'block truncate font-mono',
                        )}
                      >
                        {option.url}
                      </span>
                    </span>
                    {option.profile ? (
                      <span className="flex shrink-0 items-center gap-1 text-[11px] text-[var(--network-globe-edge)]/70">
                        {PROFILE_ICONS[option.profile]}
                        {CONSOLE_PROFILES[option.profile].label}
                      </span>
                    ) : option.value === 'env' ? (
                      <Globe className="h-3.5 w-3.5 shrink-0 text-[var(--network-globe-edge)]/70" />
                    ) : null}
                  </button>
                  {option.removable ? (
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <span className="me-1.5">
                          <button
                            type="button"
                            disabled={isApplied}
                            onClick={() => handleRemoveCustom(option.value)}
                            className="flex h-7 w-7 items-center justify-center rounded-md text-[var(--network-globe-edge)]/80 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                            aria-label="Remove custom endpoint"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </span>
                      </TooltipTrigger>
                      <TooltipContent side="left">
                        {isApplied
                          ? 'Apply another endpoint before removing this one'
                          : 'Remove custom endpoint'}
                      </TooltipContent>
                    </Tooltip>
                  ) : null}
                </div>
              )
            })}
          </div>
        </section>
      </div>

      <div className="sticky -bottom-3 -mx-3 -mb-3 space-y-2.5 border-t border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-popover px-4 py-3">
        {hasProfileMismatch && suggestedProfile ? (
          <div className="flex items-center gap-2 rounded-lg bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)] px-3 py-2">
            <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-[var(--network-globe-edge)]" />
            <p className="min-w-0 flex-1 text-[11px] text-foreground/90">
              {endpointChoiceLabel(pendingEndpoint)} usually runs the{' '}
              {CONSOLE_PROFILES[suggestedProfile].label} profile.
            </p>
            <button
              type="button"
              onClick={() => setPendingProfile(suggestedProfile)}
              className="shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium text-[var(--network-globe-edge)] transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)]"
            >
              Use {CONSOLE_PROFILES[suggestedProfile].label}
            </button>
          </div>
        ) : null}
        <div className="flex items-center gap-3">
          <p className="min-w-0 flex-1 truncate text-[12px] text-foreground/90">
            {isDirty ? (
              <>
                <span className="text-[var(--network-globe-edge)]/80">
                  Pending:{' '}
                </span>
                <span className="font-medium text-foreground">
                  {profileChoiceLabel(pendingProfile)}
                </span>
                <span className="text-[var(--network-globe-edge)]/70">
                  {' · '}
                </span>
                <span className="font-medium text-foreground">
                  {endpointChoiceLabel(pendingEndpoint)}
                </span>
              </>
            ) : (
              <span className={MUTED_TEXT_CLASS}>No pending changes</span>
            )}
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            disabled={!isDirty}
            onClick={() => {
              setPendingProfile(appliedProfile)
              setPendingEndpoint(appliedEndpoint)
            }}
          >
            Discard
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-8 text-[12px]"
            disabled={!isDirty}
            onClick={handleApply}
          >
            Apply and reload
          </Button>
        </div>
      </div>
      {promptDialog}
    </div>
  )
}
