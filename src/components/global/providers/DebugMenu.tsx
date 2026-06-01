import { useState, useEffect, useMemo, type ComponentProps } from 'react'
import {
  Bug,
  ChevronRight,
  Megaphone,
  Trash2,
  RotateCcw,
  Image,
  Palette,
  Sparkles,
  ChevronLeft,
  Cloud,
  Server,
  Settings,
  Globe,
  FlaskConical,
  AlertTriangle,
  Loader2,
  Check,
  Minus,
  Columns2,
  Braces,
  CalendarDays,
  Ticket,
  Boxes,
} from 'lucide-react'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { usePromoBanner } from './PromoBanner'
import { useDebugMode } from './DebugMode'
import { Switch } from '@/components/ui/switch'
import { useTheme } from 'next-themes'
import {
  loadDebugOverrides,
  resetFeatureFlagsMenuDebugOverrides,
  setDebugOverride,
  subscribeToDebugOverrides,
  type DebugOverrides,
  type MockCloudStatusAlert,
} from '@/lib/debug-overrides'
import { formatInitMockCurrentDay } from '@/lib/init/mock-current-day'
import { formatInitMockTicketType } from '@/lib/init/ticket-types'
import { useFavicon, type FaviconVariant } from '@/hooks/use-favicon'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  setDebugProfileOverride,
  setDebugProfileFeatureOverride,
  resetDebugProfileFeatureOverrides,
  CONSOLE_PROFILES,
  CONSOLE_PROFILE_FEATURE_LABELS,
} from '@/lib/console-profiles'
import {
  setDebugEndpointOverride,
  ENDPOINT_PRESETS,
  getEffectiveEndpointBaseUrl,
  getEnvEndpointBaseUrl,
  isCloudEndpointUrl,
} from '@/lib/debug-endpoint'
import { useDebugEndpoint } from '@/hooks/use-debug-endpoint'
import { useNavigate } from '@tanstack/react-router'
import { Branch as DismissableLayerBranch } from '@radix-ui/react-dismissable-layer'
import { cn } from '@/lib/utils'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import { DebugMenuPrefsPanel } from '@/components/global/providers/DebugMenuPrefsPanel'
import { DebugMenuInitDayPanel } from '@/components/global/providers/DebugMenuInitDayPanel'
import { DebugMenuInitTicketPanel } from '@/components/global/providers/DebugMenuInitTicketPanel'
import { DebugMenuSeedResourcesPanel } from '@/components/global/providers/DebugMenuSeedResourcesPanel'
import {
  useInitLowPowerAnimationDecision,
  type InitLowPowerAnimationDecision,
} from '@/lib/init/use-init-low-power-animations'
interface DebugAction {
  label: string
  onClick: () => void
  icon?: React.ReactNode
}

interface DebugMenuProps {
  actions?: DebugAction[]
}

interface MenuItem {
  label: string
  onClick?: () => void
  icon?: React.ReactNode
  active?: boolean
  disabled?: boolean
  badge?: string | number
  variant?: 'button' | 'switch'
  switchValue?: boolean
  switchOnChange?: (checked: boolean) => void
  description?: string
  submenu?: MenuItem[]
  /** Opens the profile comparison table instead of a submenu list. */
  submenuVariant?:
    | 'profileComparison'
    | 'prefsDebug'
    | 'initDayMock'
    | 'initTicketMock'
    | 'seedResources'
  /** Extra classes on submenu row buttons (e.g. separator above reset actions). */
  rowClassName?: string
}

interface MenuSection {
  title: string
  icon?: React.ReactNode
  items: MenuItem[]
}

type ResolvedSubmenu = {
  title: string
  items: MenuItem[]
  parentSection: string
  /** Menu key to return to on back; null opens the root debug list. */
  parentSubmenuKey: string | null
  submenuVariant?: MenuItem['submenuVariant']
}

function menuItemHasSubmenu(item: MenuItem): boolean {
  return (
    Boolean(item.submenu?.length) ||
    item.submenuVariant === 'profileComparison' ||
    item.submenuVariant === 'prefsDebug' ||
    item.submenuVariant === 'initDayMock' ||
    item.submenuVariant === 'initTicketMock' ||
    item.submenuVariant === 'seedResources'
  )
}

function resolveMenuItemSubmenu(
  item: MenuItem,
  itemKey: string,
  activeKey: string,
  sectionTitle: string,
  parentSubmenuKey: string | null,
): ResolvedSubmenu | null {
  if (itemKey === activeKey) {
    if (item.submenuVariant) {
      return {
        title: item.label,
        items: [],
        parentSection: sectionTitle,
        parentSubmenuKey,
        submenuVariant: item.submenuVariant,
      }
    }
    if (item.submenu?.length) {
      return {
        title: item.label,
        items: item.submenu,
        parentSection: sectionTitle,
        parentSubmenuKey,
      }
    }
  }

  if (item.submenu) {
    for (const child of item.submenu) {
      const childKey = `${itemKey}-${child.label}`
      const resolved = resolveMenuItemSubmenu(
        child,
        childKey,
        activeKey,
        sectionTitle,
        itemKey,
      )
      if (resolved) return resolved
    }
  }

  return null
}

function resolveActiveSubmenu(
  sections: MenuSection[],
  activeKey: string,
): ResolvedSubmenu | null {
  for (const section of sections) {
    for (const item of section.items) {
      const itemKey = `${section.title}-${item.label}`
      const resolved = resolveMenuItemSubmenu(
        item,
        itemKey,
        activeKey,
        section.title,
        null,
      )
      if (resolved) return resolved
    }
  }
  return null
}

function getInitSubmenuDescription(overrides: DebugOverrides): string {
  const parts: string[] = []
  if (overrides.mockInitCurrentDay !== null) {
    parts.push(formatInitMockCurrentDay(overrides.mockInitCurrentDay))
  }
  if (overrides.mockInitTicketType !== null) {
    parts.push(formatInitMockTicketType(overrides.mockInitTicketType))
  }
  if (overrides.previewInitReactionConfetti) {
    parts.push('Confetti preview on')
  }
  if (overrides.initLowPowerAnimations !== 'auto') {
    parts.push(`Low power ${overrides.initLowPowerAnimations}`)
  }
  return parts.length > 0 ? parts.join(' · ') : 'Launch week mocks and previews'
}

function formatLowPowerSignalValue(value: number | string | boolean | null) {
  if (value === null) return 'Unavailable'
  if (typeof value === 'boolean') return value ? 'On' : 'Off'
  return String(value)
}

function getLowPowerDecisionDescription(
  decision: InitLowPowerAnimationDecision,
) {
  const result = decision.enabled ? 'enabled' : 'disabled'
  const jool = decision.joolAnimationEnabled
    ? 'Jool can animate'
    : 'Jool is blocked'
  if (decision.override !== 'auto') {
    return `Result: ${result}. ${decision.reason} Auto would be ${
      decision.autoDetected ? 'enabled' : 'disabled'
    }. ${jool}.`
  }
  return `Result: ${result}. ${decision.reason} ${jool}.`
}

const PROFILE_IDS = ['cloud', 'self-hosted'] as const

function ConsoleProfileComparisonTable({
  activeProfileId,
}: {
  activeProfileId: (typeof PROFILE_IDS)[number]
}) {
  const featureKeys = Object.keys(
    CONSOLE_PROFILE_FEATURE_LABELS,
  ) as (keyof ConsoleProfileFeatures)[]

  return (
    <div className="space-y-3">
      <p className="px-1 text-[11px] leading-relaxed text-[#9B87F5]/90">
        Canonical defaults from{' '}
        <code className="rounded bg-black/30 px-1 py-0.5 text-[10px]">
          CONSOLE_PROFILES
        </code>
        . Debug feature overrides are not reflected here.
      </p>
      <div className="overflow-x-auto rounded-lg border border-[#9B87F5]/20">
        <table className="w-full border-collapse text-left text-[11px]">
          <thead>
            <TableRow className="border-b border-[#9B87F5]/20 bg-black/20">
              <TableHead className="min-w-[140px]">Feature</TableHead>
              {PROFILE_IDS.map((id) => (
                <TableHead
                  key={id}
                  className={cn(
                    'w-[88px] text-center font-semibold uppercase tracking-wider',
                    id === activeProfileId && 'bg-[#9B87F5]/15 text-[#E5DEFF]',
                  )}
                >
                  {CONSOLE_PROFILES[id].label}
                </TableHead>
              ))}
            </TableRow>
          </thead>
          <tbody>
            {featureKeys.map((key) => (
              <TableRow
                key={key}
                className="border-b border-[#9B87F5]/10 last:border-0"
              >
                <TableCell className="text-[#E5DEFF]/95">
                  {CONSOLE_PROFILE_FEATURE_LABELS[key]}
                </TableCell>
                {PROFILE_IDS.map((id) => {
                  const on = CONSOLE_PROFILES[id].features[key]
                  return (
                    <TableCell
                      key={id}
                      className={cn(
                        'text-center',
                        id === activeProfileId && 'bg-[#9B87F5]/10',
                      )}
                    >
                      {on ? (
                        <Check
                          className="mx-auto h-3.5 w-3.5 text-emerald-400"
                          aria-label="On"
                        />
                      ) : (
                        <Minus
                          className="mx-auto h-3.5 w-3.5 text-[#9B87F5]/35"
                          aria-label="Off"
                        />
                      )}
                    </TableCell>
                  )
                })}
              </TableRow>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function TableRow({ className, ...props }: ComponentProps<'tr'>) {
  return <tr className={cn('hover:bg-[#9B87F5]/5', className)} {...props} />
}

function TableHead({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th
      className={cn(
        'px-2 py-2 text-[10px] font-semibold text-[#9B87F5]/90',
        className,
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: ComponentProps<'td'>) {
  return (
    <td
      className={cn('px-2 py-1.5 align-middle text-[#E5DEFF]', className)}
      {...props}
    />
  )
}

export function DebugMenu({ actions = [] }: DebugMenuProps) {
  const { isDebugModeOpen: isVisible } = useDebugMode()
  const [isOpen, setIsOpen] = useState(false)
  const [overrides, setOverrides] = useState<DebugOverrides>(loadDebugOverrides)
  const { addMockBanner, clearAllBanners, banners } = usePromoBanner()
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const [currentFavicon, setCurrentFavicon] = useState<string | null>(null)
  const { theme, setTheme } = useTheme()
  const [activeSubmenu, setActiveSubmenu] = useState<string | null>(null)
  const { profileId, features } = useConsoleProfile()
  const { preset: endpointPreset, customUrl: endpointCustomUrl } =
    useDebugEndpoint()
  const navigate = useNavigate()
  const initLowPowerDecision = useInitLowPowerAnimationDecision()

  const applyOverrideAndReload = (action: () => void) => {
    setIsOpen(false)
    setTimeout(() => {
      action()
      window.location.reload()
    }, 0)
  }

  useEffect(() => {
    const unsubscribe = subscribeToDebugOverrides(setOverrides)
    return () => {
      unsubscribe?.()
    }
  }, [])

  // Update current favicon state when it changes
  useEffect(() => {
    const updateCurrentFavicon = () => {
      const favicon = getCurrentFavicon()
      setCurrentFavicon(favicon)
    }

    updateCurrentFavicon()
    const interval = setInterval(updateCurrentFavicon, 500)
    return () => clearInterval(interval)
  }, [getCurrentFavicon, isOpen])

  // Reset submenu when popover closes
  useEffect(() => {
    if (!isOpen) {
      setActiveSubmenu(null)
    }
  }, [isOpen])

  // Organize sections
  const sections: MenuSection[] = useMemo(() => {
    const themeSource: Array<{ label: string; themeValue: string }> = [
      { label: '☀️ Light', themeValue: 'light' },
      { label: '🌙 Dark', themeValue: 'dark' },
      { label: '💻 System', themeValue: 'system' },
      { label: '🎨 Crazy', themeValue: 'crazy' },
      { label: '🥷 Stealth', themeValue: 'stealth' },
      { label: '✨ Premium', themeValue: 'premium' },
      { label: '🔆 High contrast', themeValue: 'high-contrast' },
      { label: '💖 Barbie', themeValue: 'barbie' },
      { label: '📟 90s web', themeValue: 'nineties' },
    ]
    const themeOptions: MenuItem[] = themeSource.map((opt) => ({
      label: opt.label,
      onClick: () => {
        setTheme(opt.themeValue)
        setIsOpen(false)
      },
      active: theme === opt.themeValue,
      icon: <Palette className="h-3 w-3" />,
    }))

    const profileOptions: MenuItem[] = [
      {
        label: 'Cloud',
        description:
          'Full feature set (billing, domains, usage, activity, org roles, system status, account MFA, account identities)',
        onClick: () => {
          applyOverrideAndReload(() => {
            const effectiveEndpoint = getEffectiveEndpointBaseUrl()
            if (!effectiveEndpoint || !isCloudEndpointUrl(effectiveEndpoint)) {
              setDebugEndpointOverride('production')
            }
            setDebugProfileOverride('cloud')
          })
        },
        active: profileId === 'cloud',
        icon: <Cloud className="h-3 w-3" />,
      },
      {
        label: 'Self-hosted',
        description: 'Cloud-only features disabled',
        onClick: () => {
          applyOverrideAndReload(() => {
            const effectiveEndpoint = getEffectiveEndpointBaseUrl()
            if (!effectiveEndpoint || isCloudEndpointUrl(effectiveEndpoint)) {
              setDebugEndpointOverride('localhost')
            }
            setDebugProfileOverride('self-hosted')
          })
        },
        active: profileId === 'self-hosted',
        icon: <Server className="h-3 w-3" />,
      },
      {
        label: 'Use env var',
        description: 'Reset to VITE_CONSOLE_PROFILE',
        onClick: () => {
          applyOverrideAndReload(() => setDebugProfileOverride(null))
        },
        icon: <RotateCcw className="h-3 w-3" />,
      },
    ]

    const faviconOptions: MenuItem[] = (
      [
        { label: 'Default', faviconValue: 'default' },
        { label: 'Green', faviconValue: 'green' },
        { label: 'Blue', faviconValue: 'blue' },
        { label: 'Red', faviconValue: 'red' },
        { label: 'Theme', faviconValue: 'theme' },
        { label: 'Theme + Green', faviconValue: 'theme-green' },
        { label: 'Theme + Blue', faviconValue: 'theme-blue' },
        { label: 'Theme + Red', faviconValue: 'theme-red' },
      ] as const satisfies ReadonlyArray<{
        label: string
        faviconValue: FaviconVariant
      }>
    ).map((opt) => ({
      label: opt.label,
      onClick: () => {
        setFavicon(opt.faviconValue)
        setIsOpen(false)
      },
      active: currentFavicon === opt.faviconValue,
      icon: <Image className="h-3 w-3" />,
    }))

    const lowPowerAnimationOptions: MenuItem[] = (
      [
        {
          value: 'auto',
          label: 'Auto',
          description: 'Use device, memory, and data-saver signals.',
        },
        {
          value: 'on',
          label: 'On',
          description: 'Force optimized Init animations for testing.',
        },
        {
          value: 'off',
          label: 'Off',
          description: 'Force full Init animations for comparison.',
        },
      ] as const
    ).map((option) => ({
      label: option.label,
      description: option.description,
      active: overrides.initLowPowerAnimations === option.value,
      icon: <Sparkles className="h-3 w-3" />,
      onClick: () => {
        setOverrides((prev) => ({
          ...prev,
          initLowPowerAnimations: option.value,
        }))
        setDebugOverride('initLowPowerAnimations', option.value)
      },
    }))

    const lowPowerAnimationSubmenu: MenuItem[] = [
      ...lowPowerAnimationOptions,
      {
        label: 'Low-power decision',
        description: getLowPowerDecisionDescription(initLowPowerDecision),
        icon: <Sparkles className="h-3 w-3" />,
        disabled: true,
        rowClassName: 'mt-2 border-t border-[#9B87F5]/15 pt-3',
      },
      {
        label: 'Jool animation gate',
        description: initLowPowerDecision.joolAnimationReason,
        icon: <Sparkles className="h-3 w-3" />,
        disabled: true,
      },
      {
        label: 'Detection thresholds',
        description:
          'Auto enables low-power mode if Data Saver is on, connection is 2g or slow-2g, memory is 4 GB or less, or CPU has 4 logical cores or fewer.',
        icon: <Sparkles className="h-3 w-3" />,
        disabled: true,
      },
      {
        label: 'Detected signals',
        description: `CPU: ${formatLowPowerSignalValue(
          initLowPowerDecision.signals.hardwareConcurrency,
        )} cores. Memory: ${formatLowPowerSignalValue(
          initLowPowerDecision.signals.deviceMemory,
        )} GB. Data Saver: ${formatLowPowerSignalValue(
          initLowPowerDecision.signals.saveData,
        )}. Connection: ${formatLowPowerSignalValue(
          initLowPowerDecision.signals.effectiveType,
        )}. Reduced motion: ${formatLowPowerSignalValue(
          initLowPowerDecision.prefersReducedMotion,
        )}.`,
        icon: <Sparkles className="h-3 w-3" />,
        disabled: true,
      },
    ]

    const initSubmenuItems: MenuItem[] = [
      {
        label: 'Mock current day',
        description: formatInitMockCurrentDay(overrides.mockInitCurrentDay),
        icon: <CalendarDays className="h-3 w-3" />,
        submenuVariant: 'initDayMock',
      },
      {
        label: 'Mock ticket type',
        description: formatInitMockTicketType(overrides.mockInitTicketType),
        icon: <Ticket className="h-3 w-3" />,
        submenuVariant: 'initTicketMock',
      },
      {
        label: 'Preview reaction confetti',
        description: overrides.previewInitReactionConfetti
          ? 'Confetti triggers with 1 user on the same reaction'
          : 'Confetti needs 5 users on the same reaction',
        icon: <Sparkles className="h-3 w-3" />,
        variant: 'switch' as const,
        switchValue: overrides.previewInitReactionConfetti,
        switchOnChange: (checked: boolean) => {
          setOverrides((prev) => ({
            ...prev,
            previewInitReactionConfetti: checked,
          }))
          setDebugOverride('previewInitReactionConfetti', checked)
        },
      },
      {
        label: 'Low-power animations',
        description: getLowPowerDecisionDescription(initLowPowerDecision),
        icon: <Sparkles className="h-3 w-3" />,
        submenu: lowPowerAnimationSubmenu,
      },
    ]

    return [
      {
        title: 'Appearance',
        icon: <Palette className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Theme',
            icon: <Palette className="h-3 w-3" />,
            submenu: themeOptions,
          },
          {
            label: 'Favicon',
            icon: <Image className="h-3 w-3" />,
            submenu: faviconOptions,
          },
          {
            label: 'Cloud status alert',
            description:
              overrides.mockCloudStatusAlert === 'live'
                ? 'Use live Appwrite Cloud status'
                : overrides.mockCloudStatusAlert === 'operational'
                  ? 'Preview the normal state with no alert'
                  : `Mock ${overrides.mockCloudStatusAlert} alert`,
            icon: <Cloud className="h-3 w-3" />,
            submenu: [
              {
                label: 'Live status',
                description: 'Use the public Appwrite Cloud status page.',
                onClick: () => {
                  setDebugOverride('mockCloudStatusAlert', 'live')
                  setIsOpen(false)
                },
                active: overrides.mockCloudStatusAlert === 'live',
                icon: <Cloud className="h-3 w-3" />,
              },
              ...(
                [
                  {
                    label: 'No alert',
                    value: 'operational',
                    description:
                      'Preview the normal operational state with no banner.',
                  },
                  {
                    label: 'Degraded',
                    value: 'degraded',
                    description: 'Preview the degraded-service alert.',
                  },
                  {
                    label: 'Downtime',
                    value: 'downtime',
                    description: 'Preview the outage alert.',
                  },
                  {
                    label: 'Maintenance',
                    value: 'maintenance',
                    description: 'Preview the maintenance alert.',
                  },
                ] as const
              ).map((option) => ({
                label: option.label,
                description: option.description,
                onClick: () => {
                  setDebugOverride(
                    'mockCloudStatusAlert',
                    option.value as MockCloudStatusAlert,
                  )
                  setIsOpen(false)
                },
                active: overrides.mockCloudStatusAlert === option.value,
                icon: <AlertTriangle className="h-3 w-3" />,
              })),
            ],
          },
          {
            label: 'Add promo banner',
            onClick: () => {
              addMockBanner()
              setIsOpen(false)
            },
            icon: <Megaphone className="h-3 w-3" />,
            badge: banners.length > 0 ? banners.length : undefined,
          },
          {
            label: 'Fullscreen loader',
            description: overrides.showFullscreenLoader
              ? 'Enabled'
              : 'Disabled',
            icon: <Loader2 className="h-3 w-3" />,
            submenu: [
              {
                label: 'Show fullscreen loader',
                description:
                  'Keep the initial loader visible to preview it (e.g. with status banner).',
                onClick: () => {
                  setOverrides((prev) => ({
                    ...prev,
                    showFullscreenLoader: true,
                  }))
                  setDebugOverride('showFullscreenLoader', true)
                  setIsOpen(false)
                },
                active: overrides.showFullscreenLoader,
                icon: <Loader2 className="h-3 w-3" />,
              },
              {
                label: 'Hide fullscreen loader',
                description: 'Return to normal loading behavior.',
                onClick: () => {
                  setOverrides((prev) => ({
                    ...prev,
                    showFullscreenLoader: false,
                  }))
                  setDebugOverride('showFullscreenLoader', false)
                  setIsOpen(false)
                },
                active: !overrides.showFullscreenLoader,
                icon: <RotateCcw className="h-3 w-3" />,
              },
            ],
          },
          {
            label: 'Error page',
            description: 'Preview the error page as users see it.',
            onClick: () => {
              navigate({ to: '/debug/error-preview' })
              setIsOpen(false)
            },
            icon: <Bug className="h-3 w-3" />,
          },
          {
            label: 'Org setup wizard',
            description:
              'Preview the organization creation setup progress stage.',
            onClick: () => {
              navigate({ to: '/debug/org-setup-preview' })
              setIsOpen(false)
            },
            icon: <Loader2 className="h-3 w-3" />,
          },
        ],
      },
      {
        title: 'Settings',
        icon: <Settings className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Console profile',
            description: CONSOLE_PROFILES[profileId].description,
            icon:
              profileId === 'cloud' ? (
                <Cloud className="h-3 w-3" />
              ) : (
                <Server className="h-3 w-3" />
              ),
            submenu: profileOptions,
          },
          {
            label: 'Compare profiles',
            description: 'Canonical Cloud vs self-hosted feature flags',
            icon: <Columns2 className="h-3 w-3" />,
            submenuVariant: 'profileComparison',
          },
          {
            label: 'User & team prefs',
            description:
              'Account prefs and team (org) prefs: view, edit JSON, set/delete keys, reset',
            icon: <Braces className="h-3 w-3" />,
            submenuVariant: 'prefsDebug',
          },
          {
            label: 'Seed resources',
            description:
              'Create projects, mock memberships, empty DBs, buckets, and domains in the current context.',
            icon: <Boxes className="h-3 w-3" />,
            submenuVariant: 'seedResources',
          },
          {
            label: 'Init',
            description: getInitSubmenuDescription(overrides),
            icon: <CalendarDays className="h-3 w-3" />,
            submenu: initSubmenuItems,
          },
          {
            label: 'Feature flags',
            description:
              'Console profile overrides (dedicated DBs, org features, and more)',
            icon: <FlaskConical className="h-3 w-3" />,
            submenu: [
              {
                label: 'Dedicated DBs support (global)',
                description:
                  'Use fullscreen create wizard and show spec upgrade for supported DB types.',
                variant: 'switch' as const,
                switchValue: features.dedicatedDbsSupport,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride(
                        'dedicatedDbsSupport',
                        checked,
                      ),
                    0,
                  )
                },
              },
              {
                label: 'Dedicated DBs: Tables DB',
                description:
                  'Spec selector in wizard and "Upgrade database specs" in rows view.',
                variant: 'switch' as const,
                switchValue: features.dedicatedDbsTablesDB,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride(
                        'dedicatedDbsTablesDB',
                        checked,
                      ),
                    0,
                  )
                },
              },
              {
                label: 'Dedicated DBs: Documents DB',
                description: 'Dedicated DBs support for Documents DB.',
                variant: 'switch' as const,
                switchValue: features.dedicatedDbsDocumentsDB,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride(
                        'dedicatedDbsDocumentsDB',
                        checked,
                      ),
                    0,
                  )
                },
              },
              {
                label: 'Dedicated DBs: Vectors DB',
                description: 'Dedicated DBs support for Vectors DB.',
                variant: 'switch' as const,
                switchValue: features.dedicatedDbsVectorsDB,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride(
                        'dedicatedDbsVectorsDB',
                        checked,
                      ),
                    0,
                  )
                },
              },
              {
                label: 'Console user verification',
                description:
                  'Require email verification after signup; redirect to verify-email page on cloud.',
                variant: 'switch' as const,
                switchValue: features.userVerification,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride(
                        'userVerification',
                        checked,
                      ),
                    0,
                  )
                },
              },
              {
                label: 'Organization OAuth apps',
                description:
                  'Org settings OAuth apps tab and /settings/oauth-apps route.',
                variant: 'switch' as const,
                switchValue: features.oauthApps,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () => setDebugProfileFeatureOverride('oauthApps', checked),
                    0,
                  )
                },
              },
              {
                label: 'Organization API keys',
                description:
                  'Org settings API keys tab and /settings/api-keys route.',
                variant: 'switch' as const,
                switchValue: features.orgApiKeys,
                switchOnChange: (checked: boolean) => {
                  setTimeout(
                    () => setDebugProfileFeatureOverride('orgApiKeys', checked),
                    0,
                  )
                },
              },
              {
                label: 'Organization marketplace',
                description:
                  profileId === 'cloud'
                    ? 'Org Marketplace tab (browse and publish apps). Cloud profile only; off by default.'
                    : 'Cloud profile only — switch to Cloud profile to preview.',
                variant: 'switch' as const,
                switchValue:
                  profileId === 'cloud' ? features.marketplace : false,
                disabled: profileId !== 'cloud',
                switchOnChange: (checked: boolean) => {
                  if (profileId !== 'cloud') return
                  setTimeout(
                    () =>
                      setDebugProfileFeatureOverride('marketplace', checked),
                    0,
                  )
                },
              },
              {
                label: 'AI assistant',
                description:
                  'In-app AI assistant chat panel and header button.',
                variant: 'switch' as const,
                switchValue: overrides.showAIAssistant,
                switchOnChange: (checked: boolean) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showAIAssistant: checked,
                  }))
                  setDebugOverride('showAIAssistant', checked)
                },
              },
              {
                label: 'Show native app bar',
                description: 'App bar above header (native OS).',
                variant: 'switch' as const,
                switchValue: overrides.showNativeAppBar,
                switchOnChange: (checked: boolean) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showNativeAppBar: checked,
                  }))
                  setDebugOverride('showNativeAppBar', checked)
                },
              },
              {
                label: 'Success team card',
                description:
                  'Show the success team card on organization overview (custom plans).',
                variant: 'switch' as const,
                switchValue: overrides.showSuccessTeamCard,
                switchOnChange: (checked: boolean) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showSuccessTeamCard: checked,
                  }))
                  setDebugOverride('showSuccessTeamCard', checked)
                },
              },
              {
                label: 'Functions local editor',
                description:
                  'Functions list “Local editor” button and /functions/editor (Monaco, gzip for deploy).',
                variant: 'switch' as const,
                switchValue: overrides.showFunctionsLocalEditor,
                switchOnChange: (checked: boolean) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showFunctionsLocalEditor: checked,
                  }))
                  setDebugOverride('showFunctionsLocalEditor', checked)
                },
              },
              {
                label: 'Reset feature flags',
                description:
                  'Restore profile toggles on this list to canonical defaults and clear local switches (marketplace, AI assistant, native app bar, success team card, functions local editor).',
                onClick: () => {
                  resetDebugProfileFeatureOverrides()
                  resetFeatureFlagsMenuDebugOverrides()
                  setOverrides(loadDebugOverrides())
                  setIsOpen(false)
                },
                icon: <RotateCcw className="h-3 w-3" />,
                rowClassName: 'mt-2 border-t border-[#9B87F5]/20 pt-2',
              },
            ],
          },
          (() => {
            const envEndpoint = getEnvEndpointBaseUrl()
            const isCloudEnvEndpoint = envEndpoint
              ? isCloudEndpointUrl(envEndpoint)
              : false
            const activeEndpointLabel = !endpointPreset
              ? 'Use env var'
              : endpointPreset === 'custom' && endpointCustomUrl
                ? `Custom: ${endpointCustomUrl.replace(/\/v1\/?$/, '')}`
                : endpointPreset !== 'custom' &&
                    ENDPOINT_PRESETS[
                      endpointPreset as keyof typeof ENDPOINT_PRESETS
                    ]
                  ? ENDPOINT_PRESETS[
                      endpointPreset as keyof typeof ENDPOINT_PRESETS
                    ].label
                  : endpointPreset
            const endpointOptions: MenuItem[] = [
              ...(
                Object.entries(ENDPOINT_PRESETS) as [
                  keyof typeof ENDPOINT_PRESETS,
                  (typeof ENDPOINT_PRESETS)[keyof typeof ENDPOINT_PRESETS],
                ][]
              ).map(([id, { label, description }]) => {
                const disabled =
                  (profileId === 'self-hosted' &&
                    (id === 'production' || id === 'stage')) ||
                  (profileId === 'cloud' && id === 'localhost')

                return {
                  label,
                  description: disabled
                    ? profileId === 'cloud'
                      ? 'Unavailable while cloud profile is active'
                      : 'Unavailable while self-hosted profile is active'
                    : description,
                  onClick: disabled
                    ? undefined
                    : () => {
                        applyOverrideAndReload(() =>
                          setDebugEndpointOverride(id),
                        )
                      },
                  active: endpointPreset === id,
                  disabled,
                  icon: <Globe className="h-3 w-3" />,
                }
              }),
              {
                label: 'Custom...',
                description:
                  profileId === 'cloud'
                    ? 'Unavailable while cloud profile is active'
                    : 'Enter a custom API URL',
                onClick:
                  profileId === 'cloud'
                    ? undefined
                    : () => {
                        const url = window.prompt(
                          'Enter API endpoint URL (e.g. https://my-appwrite.example/v1)',
                          endpointPreset === 'custom' && endpointCustomUrl
                            ? endpointCustomUrl
                            : 'https://cloud.appwrite.io/v1',
                        )
                        if (url?.trim()) {
                          applyOverrideAndReload(() =>
                            setDebugEndpointOverride('custom', url.trim()),
                          )
                        }
                      },
                active: endpointPreset === 'custom',
                disabled: profileId === 'cloud',
                icon: <Globe className="h-3 w-3" />,
              },
              {
                label: 'Use env var',
                description:
                  profileId === 'cloud' && !isCloudEnvEndpoint
                    ? 'Unavailable because the env endpoint is not a cloud endpoint'
                    : 'Reset to VITE_APPWRITE_ENDPOINT',
                onClick:
                  profileId === 'cloud' && !isCloudEnvEndpoint
                    ? undefined
                    : () => {
                        applyOverrideAndReload(() =>
                          setDebugEndpointOverride(null),
                        )
                      },
                active: !endpointPreset,
                disabled: profileId === 'cloud' && !isCloudEnvEndpoint,
                icon: <RotateCcw className="h-3 w-3" />,
              },
            ]
            return {
              label: 'Server endpoint',
              description: `${activeEndpointLabel} (active)`,
              icon: <Globe className="h-3 w-3" />,
              submenu: endpointOptions,
            }
          })(),
        ],
      },
      ...(banners.length > 0
        ? [
            {
              title: 'Promos',
              icon: <Sparkles className="h-3.5 w-3.5" />,
              items: [
                {
                  label: 'Clear all banners',
                  onClick: () => {
                    clearAllBanners()
                    setIsOpen(false)
                  },
                  icon: <Trash2 className="h-3 w-3" />,
                },
              ],
            } as MenuSection,
          ]
        : []),
      ...(actions.length > 0
        ? [
            {
              title: 'Custom Actions',
              items: actions.map((action) => ({
                label: action.label,
                onClick: () => {
                  action.onClick()
                  setIsOpen(false)
                },
                icon: action.icon,
              })),
            } as MenuSection,
          ]
        : []),
    ]
  }, [
    theme,
    currentFavicon,
    profileId,
    features.dedicatedDbsSupport,
    features.dedicatedDbsTablesDB,
    features.dedicatedDbsDocumentsDB,
    features.dedicatedDbsVectorsDB,
    features.userVerification,
    features.oauthApps,
    features.orgApiKeys,
    features.marketplace,
    endpointPreset,
    endpointCustomUrl,
    initLowPowerDecision,
    overrides,
    banners.length,
    actions,
    navigate,
    setTheme,
    setFavicon,
    addMockBanner,
    clearAllBanners,
  ])

  const currentSubmenu = useMemo(
    () =>
      activeSubmenu ? resolveActiveSubmenu(sections, activeSubmenu) : null,
    [activeSubmenu, sections],
  )

  if (!isVisible) return null

  return (
    <DismissableLayerBranch className="pointer-events-auto fixed bottom-4 right-4 z-[10060]">
      <Popover open={isOpen} onOpenChange={setIsOpen}>
        <Tooltip>
          <TooltipTrigger asChild>
            <PopoverTrigger asChild>
              <button
                className="flex h-11 w-11 cursor-pointer items-center justify-center rounded-xl border border-violet-500/30 bg-violet-600 text-white shadow-md transition-colors hover:bg-violet-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                aria-label="Debug menu"
              >
                <img
                  src="/icons/appwrite-white.svg"
                  alt=""
                  aria-hidden="true"
                  className="h-6 w-6"
                />
              </button>
            </PopoverTrigger>
          </TooltipTrigger>
          <TooltipContent side="left" sideOffset={8}>
            Debug menu
          </TooltipContent>
        </Tooltip>
        <PopoverContent
          side="top"
          align="end"
          sideOffset={8}
          className={cn(
            'z-[10060] max-h-[85dvh] overflow-hidden rounded-xl border border-[#9B87F5]/25 bg-[#1A1F2C] p-0 shadow-xl',
            currentSubmenu?.submenuVariant === 'profileComparison' ||
              currentSubmenu?.submenuVariant === 'prefsDebug' ||
              currentSubmenu?.submenuVariant === 'seedResources' ||
              currentSubmenu?.submenuVariant === 'initDayMock' ||
              currentSubmenu?.submenuVariant === 'initTicketMock'
              ? 'w-[min(92vw,720px)]'
              : 'w-80',
          )}
        >
          <div className="sticky top-0 z-10 border-b border-[#9B87F5]/20 bg-[#1A1F2C]/95 px-4 py-3 backdrop-blur-sm">
            <div className="flex items-center gap-2">
              {currentSubmenu && (
                <button
                  onClick={() =>
                    setActiveSubmenu(currentSubmenu.parentSubmenuKey)
                  }
                  className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-[#9B87F5]/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#9B87F5]/50"
                  aria-label="Back"
                >
                  <ChevronLeft className="h-4 w-4 text-[#9B87F5]" />
                </button>
              )}
              <span className="text-[13px] font-semibold text-[#E5DEFF]">
                {currentSubmenu ? currentSubmenu.title : 'Debug'}
              </span>
            </div>
          </div>

          <div
            className="overflow-y-auto p-3"
            style={{ maxHeight: 'calc(85dvh - 52px)' }}
          >
            {currentSubmenu ? (
              currentSubmenu.submenuVariant === 'profileComparison' ? (
                <div className="px-1" aria-label={currentSubmenu.title}>
                  <ConsoleProfileComparisonTable activeProfileId={profileId} />
                </div>
              ) : currentSubmenu.submenuVariant === 'prefsDebug' ? (
                <DebugMenuPrefsPanel />
              ) : currentSubmenu.submenuVariant === 'seedResources' ? (
                <DebugMenuSeedResourcesPanel />
              ) : currentSubmenu.submenuVariant === 'initDayMock' ? (
                <DebugMenuInitDayPanel />
              ) : currentSubmenu.submenuVariant === 'initTicketMock' ? (
                <DebugMenuInitTicketPanel />
              ) : (
                <nav className="space-y-0.5" aria-label={currentSubmenu.title}>
                  {currentSubmenu.items.map((item, itemIndex) => {
                    const nestedSubmenuKey = activeSubmenu
                      ? `${activeSubmenu}-${item.label}`
                      : null
                    const hasNestedSubmenu = menuItemHasSubmenu(item)

                    return item.variant === 'switch' ? (
                      <div
                        key={`submenu-${itemIndex}`}
                        className={cn(
                          'flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-[#9B87F5]/10',
                          item.disabled && 'opacity-50',
                        )}
                      >
                        <div className="flex-1 min-w-0">
                          <div className="text-[13px] font-medium text-[#E5DEFF]">
                            {item.label}
                          </div>
                          {item.description && (
                            <div className="mt-0.5 text-[11px] text-[#9B87F5]/80">
                              {item.description}
                            </div>
                          )}
                        </div>
                        <Switch
                          checked={item.switchValue}
                          onCheckedChange={item.switchOnChange}
                          disabled={item.disabled}
                          className="flex-shrink-0"
                        />
                      </div>
                    ) : (
                      <button
                        key={`submenu-${itemIndex}`}
                        type="button"
                        onClick={() => {
                          if (hasNestedSubmenu && nestedSubmenuKey) {
                            setActiveSubmenu(nestedSubmenuKey)
                          } else if (item.onClick) {
                            item.onClick()
                          }
                        }}
                        disabled={item.disabled}
                        className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#9B87F5]/50 ${
                          item.disabled
                            ? 'cursor-not-allowed opacity-50'
                            : item.active
                              ? 'bg-[#9B87F5]/25 text-white'
                              : 'text-[#E5DEFF]/90 hover:bg-[#9B87F5]/15 hover:text-white'
                        } ${item.rowClassName ?? ''}`}
                      >
                        {item.icon && (
                          <span className="flex-shrink-0 text-[#9B87F5]">
                            {item.icon}
                          </span>
                        )}
                        <span className="flex-1">
                          <span className="block font-medium">
                            {item.label}
                          </span>
                          {item.description && (
                            <span className="mt-0.5 block text-[11px] font-normal opacity-80">
                              {item.description}
                            </span>
                          )}
                        </span>
                        {item.badge !== undefined && (
                          <span className="flex-shrink-0 rounded-full bg-[#9B87F5]/30 px-2 py-0.5 text-[11px] font-medium text-[#9B87F5]">
                            {item.badge}
                          </span>
                        )}
                        {hasNestedSubmenu && (
                          <ChevronRight className="h-4 w-4 flex-shrink-0 text-[#9B87F5]/60" />
                        )}
                      </button>
                    )
                  })}
                </nav>
              )
            ) : (
              <nav className="space-y-5" aria-label="Debug options">
                {sections.map((section) => (
                  <div key={section.title}>
                    <div className="mb-2 flex items-center gap-2 px-1">
                      <span className="text-[#9B87F5]">{section.icon}</span>
                      <span className="text-[11px] font-semibold uppercase tracking-wider text-[#9B87F5]/80">
                        {section.title}
                      </span>
                    </div>
                    <div className="space-y-0.5">
                      {section.items.map((item, itemIndex) => {
                        if (item.variant === 'switch') {
                          return (
                            <div
                              key={`${section.title}-${itemIndex}`}
                              className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-[#9B87F5]/10"
                            >
                              <div className="flex-1 min-w-0">
                                <div className="text-[13px] font-medium text-[#E5DEFF]">
                                  {item.label}
                                </div>
                                {item.description && (
                                  <div className="mt-0.5 text-[11px] text-[#9B87F5]/80">
                                    {item.description}
                                  </div>
                                )}
                              </div>
                              <Switch
                                checked={item.switchValue}
                                onCheckedChange={item.switchOnChange}
                                disabled={item.disabled}
                                className="flex-shrink-0"
                              />
                            </div>
                          )
                        }

                        const hasSubmenu = menuItemHasSubmenu(item)
                        const itemKey = `${section.title}-${item.label}`

                        return (
                          <button
                            key={`${section.title}-${itemIndex}`}
                            type="button"
                            onClick={(e) => {
                              e.stopPropagation()
                              if (hasSubmenu) {
                                setActiveSubmenu(itemKey)
                              } else if (item.onClick) {
                                item.onClick()
                              }
                            }}
                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#9B87F5]/50 ${
                              item.active
                                ? 'bg-[#9B87F5]/25 text-white'
                                : 'text-[#E5DEFF]/90 hover:bg-[#9B87F5]/15 hover:text-white'
                            }`}
                          >
                            {item.icon && (
                              <span className="flex-shrink-0 text-[#9B87F5]">
                                {item.icon}
                              </span>
                            )}
                            <span className="flex-1 font-medium">
                              {item.label}
                            </span>
                            {item.badge !== undefined && (
                              <span className="flex-shrink-0 rounded-full bg-[#9B87F5]/30 px-2 py-0.5 text-[11px] font-medium text-[#9B87F5]">
                                {item.badge}
                              </span>
                            )}
                            {hasSubmenu && (
                              <ChevronRight className="h-4 w-4 flex-shrink-0 text-[#9B87F5]/60" />
                            )}
                          </button>
                        )
                      })}
                    </div>
                  </div>
                ))}
              </nav>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </DismissableLayerBranch>
  )
}
