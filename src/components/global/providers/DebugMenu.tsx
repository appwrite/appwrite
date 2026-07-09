import { useState, useEffect, useMemo, useRef, useCallback, type ComponentProps } from 'react'
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
  Keyboard,
  Terminal,
  Search,
  X,
  Languages,
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
import { DebugMenuSwitch } from '@/components/global/providers/DebugMenuSwitch'
import { Input } from '@/components/ui/input'
import { useTheme } from 'next-themes'
import {
  loadDebugOverrides,
  resetFeatureFlagsMenuDebugOverrides,
  resetFeatureFlagsMenuDebugOverride,
  FEATURE_FLAGS_MENU_DEBUG_DEFAULTS,
  setDebugOverride,
  subscribeToDebugOverrides,
  type DebugOverrides,
  type FeatureFlagsMenuDebugKey,
  type KeyboardLayoutOverride,
  type MockCloudStatusAlert,
} from '@/lib/debug-overrides'
import {
  OVERVIEW_CHART_TAB_ORDER,
  OVERVIEW_CHART_TAB_DISABLE_KEYS,
  OVERVIEW_CHART_TAB_LABELS,
} from '@/lib/overview-chart-tabs'
import { isMacPlatform } from '@/lib/keyboard-shortcuts/display'
import { formatInitMockCurrentDay } from '@/lib/init/mock-current-day'
import { formatInitMockTicketType } from '@/lib/init/ticket-types'
import { useFavicon, type FaviconVariant } from '@/hooks/use-favicon'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  setDebugProfileOverride,
  setDebugProfileFeatureOverride,
  resetDebugProfileFeatureOverrides,
  resetDebugProfileFeatureOverride,
  getCanonicalProfileFeatures,
  CONSOLE_PROFILES,
  CONSOLE_PROFILE_FEATURE_LABELS,
} from '@/lib/console-profiles'
import {
  setDebugEndpointOverride,
  ENDPOINT_PRESETS,
  type EndpointPresetId,
} from '@/lib/debug-endpoint'
import { useDebugEndpoint } from '@/hooks/use-debug-endpoint'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { Branch as DismissableLayerBranch } from '@radix-ui/react-dismissable-layer'
import { cn } from '@/lib/utils'
import type {
  ConsoleProfileFeatures,
  ConsoleProfileId,
} from '@/lib/console-profiles'
import { DebugMenuPrefsPanel } from '@/components/global/providers/DebugMenuPrefsPanel'
import { DebugMenuInitDayPanel } from '@/components/global/providers/DebugMenuInitDayPanel'
import { DebugMenuInitTicketPanel } from '@/components/global/providers/DebugMenuInitTicketPanel'
import { DebugMenuSeedResourcesPanel } from '@/components/global/providers/DebugMenuSeedResourcesPanel'
import { DebugMenuTerminalPanel } from '@/components/global/providers/DebugMenuTerminalPanel'
import {
  useInitLowPowerAnimationDecision,
  type InitLowPowerAnimationDecision,
} from '@/lib/init/use-init-low-power-animations'
import {
  clampDebugMenuPosition,
  DEBUG_MENU_EDGE_OFFSET_PX,
  getDebugMenuPopoverPlacement,
  getDebugMenuTooltipSide,
  readDebugMenuPosition,
  writeDebugMenuPosition,
  type DebugMenuPosition,
} from '@/lib/debug-menu-position'
import { getEnglishCatalog } from '@/lib/i18n'

const DEBUG_MENU_DRAG_THRESHOLD_PX = 6
/** Debug menu stays English + LTR regardless of app language (developer tooling). */
const DEBUG_MENU_LANGUAGE_COPY = getEnglishCatalog().app.debugMenu.language

function DebugMenuBrandMark({ className }: { className?: string }) {
  return (
    <svg
      width="24"
      height="24"
      viewBox="0 0 24 24"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      className={cn('pointer-events-none h-6 w-6 shrink-0', className)}
      aria-hidden
    >
      <path
        fill="currentColor"
        d="M24.4429 16.4322V21.9096H10.7519C6.76318 21.9096 3.28044 19.7067 1.4171 16.4322C1.14622 15.9561 0.909137 15.4567 0.710264 14.9383C0.319864 13.9225 0.0744552 12.8325 0 11.6952V10.2143C0.0161646 9.96089 0.0416361 9.70942 0.0749451 9.46095C0.143032 8.95105 0.245898 8.45211 0.381093 7.96711C1.66006 3.36909 5.81877 0 10.7519 0C15.6851 0 19.8433 3.36909 21.1223 7.96711H15.2682C14.3072 6.4683 12.6437 5.4774 10.7519 5.4774C8.86017 5.4774 7.19668 6.4683 6.23562 7.96711C5.9427 8.42274 5.71542 8.92516 5.56651 9.46095C5.43425 9.93599 5.36371 10.4369 5.36371 10.9548C5.36371 12.5248 6.01324 13.94 7.05463 14.9383C8.01961 15.865 9.32061 16.4322 10.7519 16.4322H24.4429Z"
      />
      <path
        fill="currentColor"
        d="M24.4429 9.46094V14.9383H14.4492C15.4906 13.94 16.1401 12.5248 16.1401 10.9548C16.1401 10.4369 16.0696 9.93598 15.9373 9.46094H24.4429Z"
      />
    </svg>
  )
}
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
  /** Canonical default for feature-flag switches (shown next to the label). */
  defaultValue?: boolean
  onResetToDefault?: () => void
  description?: string
  submenu?: MenuItem[]
  /** Opens the profile comparison table instead of a submenu list. */
  submenuVariant?:
    | 'profileComparison'
    | 'prefsDebug'
    | 'initDayMock'
    | 'initTicketMock'
    | 'seedResources'
    | 'terminalSettings'
  /** Extra classes on submenu row buttons (e.g. separator above reset actions). */
  rowClassName?: string
  /** Feature flags submenu: group label for categorized lists. */
  category?: string
}

interface MenuSection {
  title: string
  icon?: React.ReactNode
  items: MenuItem[]
}

function formatFeatureFlagDefaultLabel(defaultValue: boolean): string {
  return defaultValue ? 'Default: On' : 'Default: Off'
}

function isFeatureFlagOverridden(item: MenuItem): boolean {
  return (
    item.defaultValue !== undefined &&
    item.switchValue !== undefined &&
    item.switchValue !== item.defaultValue &&
    Boolean(item.onResetToDefault)
  )
}

function matchesFeatureFlagSearch(item: MenuItem, query: string): boolean {
  const trimmed = query.trim()
  if (!trimmed) return true
  if (item.label === 'Reset all feature flags') return true

  const q = trimmed.toLowerCase()
  return (
    item.label.toLowerCase().includes(q) ||
    (item.description?.toLowerCase().includes(q) ?? false) ||
    (item.category?.toLowerCase().includes(q) ?? false)
  )
}

function filterFeatureFlagMenuItems(
  items: MenuItem[],
  query: string,
): MenuItem[] {
  if (!query.trim()) return items
  return items.filter((item) => matchesFeatureFlagSearch(item, query))
}

function groupFeatureFlagMenuItems(items: MenuItem[]): Array<{
  category: string | null
  items: MenuItem[]
}> {
  const categoryOrder: string[] = []
  const categoryGroups = new Map<string, MenuItem[]>()
  const uncategorized: MenuItem[] = []

  for (const item of items) {
    if (!item.category) {
      uncategorized.push(item)
      continue
    }

    if (!categoryGroups.has(item.category)) {
      categoryOrder.push(item.category)
      categoryGroups.set(item.category, [])
    }
    categoryGroups.get(item.category)?.push(item)
  }

  const groups = categoryOrder.map((category) => ({
    category,
    items: categoryGroups.get(category) ?? [],
  }))

  if (uncategorized.length > 0) {
    groups.push({ category: null, items: uncategorized })
  }

  return groups
}

function DebugMenuSwitchRow({ item }: { item: MenuItem }) {
  const showReset = isFeatureFlagOverridden(item)

  return (
    <div
      className={cn(
        'flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]',
        item.disabled && 'opacity-50',
        item.rowClassName,
      )}
    >
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5">
          <div className="text-[13px] font-medium text-foreground">
            {item.label}
          </div>
          {item.defaultValue !== undefined && (
            <span className="text-[11px] font-normal text-[var(--network-globe-edge)]/70">
              {formatFeatureFlagDefaultLabel(item.defaultValue)}
            </span>
          )}
        </div>
        {item.description && (
          <div className="mt-0.5 text-[11px] text-[var(--network-globe-edge)]/80">
            {item.description}
          </div>
        )}
      </div>
      <div className="flex flex-shrink-0 items-center gap-1.5">
        {showReset && (
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={() => item.onResetToDefault?.()}
                className="flex h-7 w-7 items-center justify-center rounded-md text-[var(--network-globe-edge)]/80 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40"
                aria-label={`Reset ${item.label} to default`}
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="left">Reset to default</TooltipContent>
          </Tooltip>
        )}
        <DebugMenuSwitch
          checked={item.switchValue}
          onCheckedChange={item.switchOnChange}
          disabled={item.disabled}
          className="flex-shrink-0"
        />
      </div>
    </div>
  )
}

function renderDebugSubmenuItemRow(
  item: MenuItem,
  itemIndex: number,
  keyPrefix: string,
  nestedSubmenuParentKey: string | null,
  setActiveSubmenu: (key: string | null) => void,
) {
  const nestedSubmenuKey = nestedSubmenuParentKey
    ? `${nestedSubmenuParentKey}-${item.label}`
    : null
  const hasNestedSubmenu = menuItemHasSubmenu(item)
  const key = `${keyPrefix}-${itemIndex}`

  if (item.variant === 'switch') {
    return <DebugMenuSwitchRow key={key} item={item} />
  }

  return (
    <button
      key={key}
      type="button"
      onClick={() => {
        if (hasNestedSubmenu && nestedSubmenuKey) {
          setActiveSubmenu(nestedSubmenuKey)
        } else if (item.onClick) {
          item.onClick()
        }
      }}
      disabled={item.disabled}
      className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40 ${
        item.disabled
          ? 'cursor-not-allowed opacity-50'
          : item.active
            ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--muted))] text-foreground'
            : 'text-foreground/90 hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground'
      } ${item.rowClassName ?? ''}`}
    >
      {item.icon && (
        <span className="flex-shrink-0 text-[var(--network-globe-edge)]">{item.icon}</span>
      )}
      <span className="flex-1">
        <span className="block font-medium">{item.label}</span>
        {item.description && (
          <span className="mt-0.5 block text-[11px] font-normal opacity-80">
            {item.description}
          </span>
        )}
      </span>
      {item.badge !== undefined && (
        <span className="flex-shrink-0 rounded-full bg-[color-mix(in_srgb,var(--network-globe-edge)_22%,transparent)] px-2 py-0.5 text-[11px] font-medium text-[var(--network-globe-edge)]">
          {item.badge}
        </span>
      )}
      {hasNestedSubmenu && (
        <ChevronRight className="h-4 w-4 flex-shrink-0 text-[var(--network-globe-edge)]/60" />
      )}
    </button>
  )
}

function createProfileFeatureFlagItem(
  label: string,
  description: string,
  key: keyof ConsoleProfileFeatures,
  profileId: ConsoleProfileId,
  currentValue: boolean,
  options?: { disabled?: boolean; category?: string },
): MenuItem {
  const defaultValue = getCanonicalProfileFeatures(profileId)[key]

  return {
    label,
    description,
    category: options?.category,
    variant: 'switch',
    switchValue: currentValue,
    defaultValue,
    disabled: options?.disabled,
    switchOnChange: (checked: boolean) => {
      setTimeout(() => setDebugProfileFeatureOverride(key, checked), 0)
    },
    onResetToDefault: () => {
      resetDebugProfileFeatureOverride(key)
    },
  }
}

function createDebugFeatureFlagItem(
  label: string,
  description: string,
  key: FeatureFlagsMenuDebugKey,
  currentValue: boolean,
  onChange: (checked: boolean) => void,
  onReset?: () => void,
  category?: string,
): MenuItem {
  const defaultValue = FEATURE_FLAGS_MENU_DEBUG_DEFAULTS[key]

  return {
    label,
    description,
    category,
    variant: 'switch',
    switchValue: currentValue,
    defaultValue,
    switchOnChange: onChange,
    onResetToDefault: () => {
      resetFeatureFlagsMenuDebugOverride(key)
      onReset?.()
    },
  }
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
    item.submenuVariant === 'seedResources' ||
    item.submenuVariant === 'terminalSettings'
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
      <p className="px-1 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/90">
        Canonical defaults from{' '}
        <code className="rounded bg-muted/50 px-1 py-0.5 text-[10px]">
          CONSOLE_PROFILES
        </code>
        . Debug feature overrides are not reflected here.
      </p>
      <div className="overflow-x-auto rounded-lg border border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))]">
        <table className="w-full border-collapse text-start text-[11px]">
          <thead>
            <TableRow className="border-b border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-muted/40">
              <TableHead className="min-w-[140px]">Feature</TableHead>
              {PROFILE_IDS.map((id) => (
                <TableHead
                  key={id}
                  className={cn(
                    'w-[88px] text-center font-semibold uppercase tracking-wider',
                    id === activeProfileId && 'bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] text-foreground',
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
                className="border-b border-[color-mix(in_srgb,var(--network-globe-edge)_10%,var(--border))] last:border-0"
              >
                <TableCell className="text-foreground/95">
                  {CONSOLE_PROFILE_FEATURE_LABELS[key]}
                </TableCell>
                {PROFILE_IDS.map((id) => {
                  const on = CONSOLE_PROFILES[id].features[key]
                  return (
                    <TableCell
                      key={id}
                      className={cn(
                        'text-center',
                        id === activeProfileId && 'bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]',
                      )}
                    >
                      {on ? (
                        <Check
                          className="mx-auto h-3.5 w-3.5 text-emerald-400"
                          aria-label="On"
                        />
                      ) : (
                        <Minus
                          className="mx-auto h-3.5 w-3.5 text-[var(--network-globe-edge)]/35"
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
  return <tr className={cn('hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_5%,transparent)]', className)} {...props} />
}

function TableHead({ className, ...props }: ComponentProps<'th'>) {
  return (
    <th
      className={cn(
        'px-2 py-2 text-[10px] font-semibold text-[var(--network-globe-edge)]/90',
        className,
      )}
      {...props}
    />
  )
}

function TableCell({ className, ...props }: ComponentProps<'td'>) {
  return (
    <td
      className={cn('px-2 py-1.5 align-middle text-foreground', className)}
      {...props}
    />
  )
}

export function DebugMenu({ actions = [] }: DebugMenuProps) {
  const { isDebugModeOpen: isVisible } = useDebugMode()
  const queryClient = useQueryClient()
  const [isOpen, setIsOpen] = useState(false)
  const [overrides, setOverrides] = useState<DebugOverrides>(loadDebugOverrides)
  const { addMockBanner, clearAllBanners, banners } = usePromoBanner()
  const { setFavicon, getCurrentFavicon } = useFavicon()
  const [currentFavicon, setCurrentFavicon] = useState<string | null>(null)
  const { theme, setTheme } = useTheme()
  const [activeSubmenu, setActiveSubmenu] = useState<string | null>(null)
  const [featureFlagsSearch, setFeatureFlagsSearch] = useState('')
  const languageCopy = DEBUG_MENU_LANGUAGE_COPY
  const { profileId, features } = useConsoleProfile()
  const { preset: endpointPreset, customUrl: endpointCustomUrl } =
    useDebugEndpoint()
  const navigate = useNavigate()
  const initLowPowerDecision = useInitLowPowerAnimationDecision()
  const [position, setPosition] = useState<DebugMenuPosition>(() =>
    readDebugMenuPosition(),
  )
  const [isDragging, setIsDragging] = useState(false)
  const [dragPosition, setDragPosition] = useState<DebugMenuPosition | null>(null)
  const dragStateRef = useRef({
    pointerId: -1,
    startX: 0,
    startY: 0,
    moved: false,
  })
  const suppressClickRef = useRef(false)

  useEffect(() => {
    const handleResize = () => {
      setPosition(readDebugMenuPosition())
    }

    window.addEventListener('resize', handleResize)
    return () => window.removeEventListener('resize', handleResize)
  }, [])

  const displayPosition =
    isDragging && dragPosition
      ? clampDebugMenuPosition(
          dragPosition,
          window.innerWidth,
          window.innerHeight,
        )
      : position

  const popoverPlacement = useMemo(
    () =>
      getDebugMenuPopoverPlacement(
        displayPosition,
        window.innerWidth,
        window.innerHeight,
      ),
    [displayPosition],
  )
  const tooltipSide = useMemo(
    () => getDebugMenuTooltipSide(displayPosition, window.innerWidth),
    [displayPosition],
  )

  const handleDragPointerDown = useCallback(
    (event: React.PointerEvent<HTMLButtonElement>) => {
      if (isOpen) return

      dragStateRef.current = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        moved: false,
      }
      event.currentTarget.setPointerCapture(event.pointerId)
    },
    [isOpen],
  )

  const handleDragPointerMove = useCallback(
    (event: React.PointerEvent<HTMLButtonElement>) => {
      const dragState = dragStateRef.current
      if (dragState.pointerId !== event.pointerId) return

      const deltaX = event.clientX - dragState.startX
      const deltaY = event.clientY - dragState.startY

      if (
        !dragState.moved &&
        Math.hypot(deltaX, deltaY) >= DEBUG_MENU_DRAG_THRESHOLD_PX
      ) {
        dragState.moved = true
        setIsDragging(true)
        setIsOpen(false)
      }

      if (dragState.moved) {
        setDragPosition(
          clampDebugMenuPosition(
            { x: event.clientX, y: event.clientY },
            window.innerWidth,
            window.innerHeight,
          ),
        )
      }
    },
    [],
  )

  const finishDrag = useCallback(
    (event: React.PointerEvent<HTMLButtonElement>) => {
      const dragState = dragStateRef.current
      if (dragState.pointerId !== event.pointerId) return

      event.currentTarget.releasePointerCapture(event.pointerId)
      dragState.pointerId = -1

      if (dragState.moved) {
        const clamped = clampDebugMenuPosition(
          { x: event.clientX, y: event.clientY },
          window.innerWidth,
          window.innerHeight,
        )
        setPosition(clamped)
        writeDebugMenuPosition(clamped)
        suppressClickRef.current = true
      }

      dragState.moved = false
      setIsDragging(false)
      setDragPosition(null)
    },
    [],
  )

  const handleDragClick = useCallback((event: React.MouseEvent<HTMLButtonElement>) => {
    if (suppressClickRef.current) {
      event.preventDefault()
      event.stopPropagation()
      suppressClickRef.current = false
    }
  }, [])

  const applyOverrideAndReload = (action: () => void) => {
    setIsOpen(false)
    setTimeout(() => {
      action()
      window.location.reload()
    }, 0)
  }

  const applyProfileOverrideAndGoHome = (action: () => void) => {
    setIsOpen(false)
    setTimeout(() => {
      action()
      window.location.assign('/')
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
      setFeatureFlagsSearch('')
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
      { label: '🏛️ Legacy', themeValue: 'legacy' },
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

    const keyboardLayoutDescription =
      overrides.keyboardLayout === 'auto'
        ? `Auto (${isMacPlatform() ? 'macOS' : 'Windows'})`
        : overrides.keyboardLayout === 'macos'
          ? 'macOS layout'
          : 'Windows layout'

    const keyboardLayoutOptions: MenuItem[] = (
      [
        {
          label: 'Auto',
          value: 'auto' as const,
          description: 'Detect from device',
        },
        {
          label: 'macOS layout',
          value: 'macos' as const,
          description: 'Show macOS keyboard and ⌘ shortcuts',
        },
        {
          label: 'Windows layout',
          value: 'windows' as const,
          description: 'Show Windows keyboard and Ctrl shortcuts',
        },
      ] satisfies ReadonlyArray<{
        label: string
        value: KeyboardLayoutOverride
        description: string
      }>
    ).map((option) => ({
      label: option.label,
      description: option.description,
      onClick: () => {
        setOverrides((prev) => ({ ...prev, keyboardLayout: option.value }))
        setDebugOverride('keyboardLayout', option.value)
      },
      active: overrides.keyboardLayout === option.value,
      icon: <Keyboard className="h-3 w-3" />,
    }))

    const pageDirectionDescription =
      overrides.pageDirection === 'rtl'
        ? 'Right-to-left (RTL)'
        : 'Left-to-right (LTR)'

    const pageDirectionOptions: MenuItem[] = (
      [
        {
          label: 'LTR (default)',
          value: 'ltr' as const,
          description: 'Left-to-right layout',
        },
        {
          label: 'RTL',
          value: 'rtl' as const,
          description: 'Right-to-left layout for i18n testing',
        },
      ] as const
    ).map((option) => ({
      label: option.label,
      description: option.description,
      onClick: () => {
        setOverrides((prev) => ({ ...prev, pageDirection: option.value }))
        setDebugOverride('pageDirection', option.value)
      },
      active: overrides.pageDirection === option.value,
      icon: <Languages className="h-3 w-3" />,
    }))

    const languageDescription =
      overrides.language === 'he'
        ? languageCopy.activeHebrew
        : overrides.language === 'ja'
          ? languageCopy.activeJapanese
          : languageCopy.activeEnglish

    const languageOptions: MenuItem[] = (
      [
        {
          label: languageCopy.englishLabel,
          value: 'en' as const,
          description: languageCopy.englishDescription,
        },
        {
          label: languageCopy.hebrewLabel,
          value: 'he' as const,
          description: languageCopy.hebrewDescription,
        },
        {
          label: languageCopy.japaneseLabel,
          value: 'ja' as const,
          description: languageCopy.japaneseDescription,
        },
      ] as const
    ).map((option) => ({
      label: option.label,
      description: option.description,
      onClick: () => {
        setOverrides((prev) => ({
          ...prev,
          language: option.value,
          ...(option.value === 'he'
            ? { pageDirection: 'rtl' as const }
            : option.value === 'en' || option.value === 'ja'
              ? { pageDirection: 'ltr' as const }
              : {}),
        }))
        setDebugOverride('language', option.value)
        if (option.value === 'he') {
          setDebugOverride('pageDirection', 'rtl')
        } else if (option.value === 'en' || option.value === 'ja') {
          setDebugOverride('pageDirection', 'ltr')
        }
      },
      active: overrides.language === option.value,
      icon: <Languages className="h-3 w-3" />,
    }))

    const activeEndpointLabel = !endpointPreset
      ? 'Use env var'
      : endpointPreset === 'custom' && endpointCustomUrl
        ? `Custom: ${endpointCustomUrl.replace(/\/v1\/?$/, '')}`
        : endpointPreset !== 'custom' &&
            ENDPOINT_PRESETS[endpointPreset as keyof typeof ENDPOINT_PRESETS]
          ? ENDPOINT_PRESETS[endpointPreset as keyof typeof ENDPOINT_PRESETS]
              .label
          : endpointPreset

    const profileOptions: MenuItem[] = [
      {
        label: 'Cloud',
        description: CONSOLE_PROFILES.cloud.description,
        active: profileId === 'cloud',
        icon: <Cloud className="h-3 w-3" />,
        onClick: () => {
          applyProfileOverrideAndGoHome(() => setDebugProfileOverride('cloud'))
        },
      },
      {
        label: 'Self-hosted',
        description: CONSOLE_PROFILES['self-hosted'].description,
        active: profileId === 'self-hosted',
        icon: <Server className="h-3 w-3" />,
        onClick: () => {
          applyProfileOverrideAndGoHome(() =>
            setDebugProfileOverride('self-hosted'),
          )
        },
      },
      {
        label: 'Use env var',
        description: 'Reset to VITE_CONSOLE_PROFILE',
        onClick: () => {
          applyProfileOverrideAndGoHome(() => setDebugProfileOverride(null))
        },
        icon: <RotateCcw className="h-3 w-3" />,
      },
      {
        label: 'Compare profiles',
        description: 'Canonical Cloud vs self-hosted feature flags',
        icon: <Columns2 className="h-3 w-3" />,
        submenuVariant: 'profileComparison',
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
        rowClassName: 'mt-2 border-t border-[color-mix(in_srgb,var(--network-globe-edge)_15%,var(--border))] pt-3',
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
            label: 'Demo pages and comps',
            description: 'Preview debug-only pages and components.',
            icon: <Bug className="h-3 w-3" />,
            submenu: [
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
        ],
      },
      {
        title: 'Settings',
        icon: <Settings className="h-3.5 w-3.5" />,
        items: [
          {
            label: 'Keyboard layout',
            description: keyboardLayoutDescription,
            icon: <Keyboard className="h-3 w-3" />,
            submenu: keyboardLayoutOptions,
          },
          {
            label: 'Page direction',
            description: pageDirectionDescription,
            icon: <Languages className="h-3 w-3" />,
            submenu: pageDirectionOptions,
          },
          {
            label: languageCopy.label,
            description: languageDescription,
            icon: <Languages className="h-3 w-3" />,
            submenu: languageOptions,
          },
          {
            label: 'User & team prefs',
            description:
              'Account prefs and team (org) prefs: view, edit JSON, set/delete keys, reset',
            icon: <Braces className="h-3 w-3" />,
            submenuVariant: 'prefsDebug',
          },
          {
            label: 'Terminal settings',
            description:
              'Browser CLI cache: view status and clear the local Appwrite CLI install',
            icon: <Terminal className="h-3 w-3" />,
            submenuVariant: 'terminalSettings',
          },
          {
            label: 'Seed resources',
            description:
              'Create projects, mock memberships, empty DBs, buckets, and domains in the current context.',
            icon: <Boxes className="h-3 w-3" />,
            submenuVariant: 'seedResources',
          },
          ...(features.init
            ? [
                {
                  label: 'Init',
                  description: getInitSubmenuDescription(overrides),
                  icon: <CalendarDays className="h-3 w-3" />,
                  submenu: initSubmenuItems,
                },
              ]
            : []),
          {
            label: 'Feature flags',
            description:
              'Console profile overrides (dedicated DBs, org features, and more)',
            icon: <FlaskConical className="h-3 w-3" />,
            submenu: [
              createProfileFeatureFlagItem(
                'Dedicated DBs support (global)',
                'Use fullscreen create wizard and show spec upgrade for supported DB types.',
                'dedicatedDbsSupport',
                profileId,
                features.dedicatedDbsSupport,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Dedicated DBs: Documents DB',
                'Dedicated DBs support for Documents DB.',
                'dedicatedDbsDocumentsDB',
                profileId,
                features.dedicatedDbsDocumentsDB,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Dedicated DBs: Vectors DB',
                'Dedicated DBs support for Vectors DB.',
                'dedicatedDbsVectorsDB',
                profileId,
                features.dedicatedDbsVectorsDB,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Native DBs: PostgreSQL',
                'Enable dedicated PostgreSQL databases in the create wizard.',
                'nativeDbsPostgres',
                profileId,
                features.nativeDbsPostgres,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Native DBs: MySQL',
                'Enable dedicated MySQL databases in the create wizard.',
                'nativeDbsMySQL',
                profileId,
                features.nativeDbsMySQL,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Native DBs: MongoDB',
                'Enable dedicated MongoDB databases in the databases list.',
                'nativeDbsMongo',
                profileId,
                features.nativeDbsMongo,
                { category: 'Databases' },
              ),
              createProfileFeatureFlagItem(
                'Console user verification',
                'Require email verification after signup; redirect to verify-email page on cloud.',
                'userVerification',
                profileId,
                features.userVerification,
                { category: 'Auth & security' },
              ),
              createProfileFeatureFlagItem(
                'Project OAuth2 server',
                profileId === 'cloud'
                  ? 'Project settings OAuth2 authorization server card on overview. Cloud profile only.'
                  : 'Cloud profile only. Switch to Cloud profile to preview.',
                'oauth2Server',
                profileId,
                profileId === 'cloud' ? features.oauth2Server : false,
                {
                  disabled: profileId !== 'cloud',
                  category: 'Auth & security',
                },
              ),
              createProfileFeatureFlagItem(
                'Organization OAuth apps',
                'Org settings OAuth apps tab and /settings/oauth-apps route.',
                'oauthApps',
                profileId,
                features.oauthApps,
                { category: 'Organization' },
              ),
              createProfileFeatureFlagItem(
                'Organization API keys',
                'Org settings API keys tab and /settings/api-keys route.',
                'orgApiKeys',
                profileId,
                features.orgApiKeys,
                { category: 'Organization' },
              ),
              createProfileFeatureFlagItem(
                'Partners docs',
                'Partner documentation hub, audience switcher, and /docs/partners routes.',
                'partnersDocs',
                profileId,
                features.partnersDocs,
                { category: 'Docs' },
              ),
              createProfileFeatureFlagItem(
                'Organization marketplace',
                profileId === 'cloud'
                  ? 'Org Marketplace tab (browse and publish apps). Cloud profile only.'
                  : 'Cloud profile only. Switch to Cloud profile to preview.',
                'marketplace',
                profileId,
                profileId === 'cloud' ? features.marketplace : false,
                {
                  disabled: profileId !== 'cloud',
                  category: 'Organization',
                },
              ),
              createDebugFeatureFlagItem(
                'Activity chart',
                'Show the activity log volume chart above the activity table.',
                'showActivityChart',
                overrides.showActivityChart,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showActivityChart: checked,
                  }))
                  setDebugOverride('showActivityChart', checked)
                },
                undefined,
                'Usage & analytics',
              ),
              createDebugFeatureFlagItem(
                'AI assistant',
                'In-app AI assistant chat panel and header button.',
                'showAIAssistant',
                overrides.showAIAssistant,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showAIAssistant: checked,
                  }))
                  setDebugOverride('showAIAssistant', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Show native app bar',
                'App bar above header (native OS).',
                'showNativeAppBar',
                overrides.showNativeAppBar,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showNativeAppBar: checked,
                  }))
                  setDebugOverride('showNativeAppBar', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Success team card',
                'Show the success team card on organization overview (custom plans).',
                'showSuccessTeamCard',
                overrides.showSuccessTeamCard,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showSuccessTeamCard: checked,
                  }))
                  setDebugOverride('showSuccessTeamCard', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Functions local editor',
                'Functions list “Local editor” button and /functions/editor (Monaco, gzip for deploy).',
                'showFunctionsLocalEditor',
                overrides.showFunctionsLocalEditor,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    showFunctionsLocalEditor: checked,
                  }))
                  setDebugOverride('showFunctionsLocalEditor', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Unlock onboarding',
                'Unlock all Get started product sections without completing Connect.',
                'unlockOnboardingLocks',
                overrides.unlockOnboardingLocks,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    unlockOnboardingLocks: checked,
                  }))
                  setDebugOverride('unlockOnboardingLocks', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Disable usage breakdown queries',
                'Skip dimension-based usage API calls on the project overview (top endpoints, buckets, functions/sites). Charts and KPIs still load.',
                'disableUsageBreakdownQueries',
                overrides.disableUsageBreakdownQueries,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    disableUsageBreakdownQueries: checked,
                  }))
                  setDebugOverride('disableUsageBreakdownQueries', checked)
                  void queryClient.invalidateQueries({
                    predicate: (query) =>
                      query.queryKey[0] === 'usage-events' ||
                      query.queryKey[0] === 'usage-gauges' ||
                      query.queryKey[0] === 'usage-breakdown',
                  })
                },
                () => {
                  setOverrides(loadDebugOverrides())
                  void queryClient.invalidateQueries({
                    predicate: (query) =>
                      query.queryKey[0] === 'usage-events' ||
                      query.queryKey[0] === 'usage-gauges' ||
                      query.queryKey[0] === 'usage-breakdown',
                  })
                },
                'Usage & analytics',
              ),
              ...OVERVIEW_CHART_TAB_ORDER.map((tabId) => {
                const disableKey = OVERVIEW_CHART_TAB_DISABLE_KEYS[tabId]
                const label = OVERVIEW_CHART_TAB_LABELS[tabId]
                return createDebugFeatureFlagItem(
                  `Disable overview ${label.toLowerCase()} chart`,
                  `Hide the ${label} tab and usage queries on the project overview.`,
                  disableKey,
                  overrides[disableKey],
                  (checked) => {
                    setOverrides((prev) => ({
                      ...prev,
                      [disableKey]: checked,
                    }))
                    setDebugOverride(disableKey, checked)
                    void queryClient.invalidateQueries({
                      predicate: (query) =>
                        query.queryKey[0] === 'usage-events' ||
                        query.queryKey[0] === 'usage-gauges' ||
                        query.queryKey[0] === 'usage-breakdown',
                    })
                  },
                  () => {
                    setOverrides(loadDebugOverrides())
                    void queryClient.invalidateQueries({
                      predicate: (query) =>
                        query.queryKey[0] === 'usage-events' ||
                        query.queryKey[0] === 'usage-gauges' ||
                        query.queryKey[0] === 'usage-breakdown',
                    })
                  },
                  'Usage & analytics',
                )
              }),
              {
                label: 'Reset all feature flags',
                description:
                  'Restore every flag on this list to its default for your current profile.',
                onClick: () => {
                  resetDebugProfileFeatureOverrides()
                  resetFeatureFlagsMenuDebugOverrides()
                  setOverrides(loadDebugOverrides())
                  void queryClient.invalidateQueries({
                    predicate: (query) =>
                      query.queryKey[0] === 'usage-events' ||
                      query.queryKey[0] === 'usage-gauges' ||
                      query.queryKey[0] === 'usage-breakdown',
                  })
                  setIsOpen(false)
                },
                icon: <RotateCcw className="h-3 w-3" />,
                rowClassName: 'mt-2 border-t border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] pt-2',
              },
            ],
          },
        ],
      },
      {
        title: 'Environment',
        icon: <Globe className="h-3.5 w-3.5" />,
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
          (() => {
            const endpointOptions: MenuItem[] = [
              ...(
                Object.entries(ENDPOINT_PRESETS) as [
                  Exclude<EndpointPresetId, 'custom'>,
                  (typeof ENDPOINT_PRESETS)[keyof typeof ENDPOINT_PRESETS],
                ][]
              ).map(([id, { label, description }]) => ({
                label,
                description,
                onClick: () => {
                  applyOverrideAndReload(() => setDebugEndpointOverride(id))
                },
                active: endpointPreset === id,
                icon: <Globe className="h-3 w-3" />,
              })),
              {
                label: 'Custom...',
                description: 'Enter a custom API URL',
                onClick: () => {
                  const url = window.prompt(
                    'Enter API endpoint URL (e.g. https://my-appwrite.example/v1)',
                    endpointPreset === 'custom' && endpointCustomUrl
                      ? endpointCustomUrl
                      : 'http://localhost/v1',
                  )
                  if (url?.trim()) {
                    applyOverrideAndReload(() =>
                      setDebugEndpointOverride('custom', url.trim()),
                    )
                  }
                },
                active: endpointPreset === 'custom',
                icon: <Globe className="h-3 w-3" />,
              },
              {
                label: 'Use env var',
                description: 'Reset to VITE_APPWRITE_ENDPOINT',
                onClick: () => {
                  applyOverrideAndReload(() => setDebugEndpointOverride(null))
                },
                active: !endpointPreset,
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
    features.dedicatedDbsDocumentsDB,
    features.dedicatedDbsVectorsDB,
    features.nativeDbsPostgres,
    features.nativeDbsMySQL,
    features.nativeDbsMongo,
    features.userVerification,
    features.oauthApps,
    features.oauth2Server,
    features.orgApiKeys,
    features.marketplace,
    features.init,
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
    languageCopy,
  ])

  const currentSubmenu = useMemo(
    () =>
      activeSubmenu ? resolveActiveSubmenu(sections, activeSubmenu) : null,
    [activeSubmenu, sections],
  )

  const isFeatureFlagsSubmenu = currentSubmenu?.title === 'Feature flags'

  const filteredFeatureFlagItems = useMemo(() => {
    if (!isFeatureFlagsSubmenu || !currentSubmenu) return []
    return filterFeatureFlagMenuItems(currentSubmenu.items, featureFlagsSearch)
  }, [currentSubmenu, featureFlagsSearch, isFeatureFlagsSubmenu])

  const groupedFeatureFlagItems = useMemo(() => {
    if (!isFeatureFlagsSubmenu) return []
    return groupFeatureFlagMenuItems(filteredFeatureFlagItems)
  }, [filteredFeatureFlagItems, isFeatureFlagsSubmenu])

  useEffect(() => {
    if (!isFeatureFlagsSubmenu) {
      setFeatureFlagsSearch('')
    }
  }, [isFeatureFlagsSubmenu])

  if (!isVisible) return null

  return (
    <DismissableLayerBranch
      dir="ltr"
      lang="en"
      className="pointer-events-auto fixed z-[10060]"
      style={{
        left: displayPosition.x,
        top: displayPosition.y,
        transform: 'translate(-50%, -50%)',
      }}
    >
      <Popover open={isOpen} onOpenChange={setIsOpen}>
        <Tooltip open={isDragging ? false : undefined}>
          <TooltipTrigger asChild>
            <PopoverTrigger asChild>
              <button
                className={cn(
                  'relative flex h-11 w-11 select-none items-center justify-center overflow-hidden rounded-xl bg-[color-mix(in_srgb,var(--network-globe-edge)_22%,var(--background))] shadow-sm transition-colors',
                  'hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_30%,var(--background))]',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/30 focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                  isDragging ? 'cursor-grabbing touch-none' : 'cursor-grab',
                )}
                aria-label="Debug menu"
                onPointerDown={handleDragPointerDown}
                onPointerMove={handleDragPointerMove}
                onPointerUp={finishDrag}
                onPointerCancel={finishDrag}
                onClick={handleDragClick}
                onDragStart={(event) => event.preventDefault()}
              >
                <DebugMenuBrandMark className="relative z-10 text-foreground" />
              </button>
            </PopoverTrigger>
          </TooltipTrigger>
          <TooltipContent side={tooltipSide} sideOffset={8}>
            Debug menu
          </TooltipContent>
        </Tooltip>
        <PopoverContent
          dir="ltr"
          lang="en"
          side={popoverPlacement.side}
          align={popoverPlacement.align}
          sideOffset={8}
          collisionPadding={DEBUG_MENU_EDGE_OFFSET_PX}
          className={cn(
            'z-[10060] flex max-h-[min(85dvh,var(--radix-popper-available-height,100dvh))] flex-col overflow-hidden rounded-xl border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-popover p-0 shadow-xl',
            currentSubmenu?.submenuVariant === 'profileComparison' ||
              currentSubmenu?.submenuVariant === 'prefsDebug' ||
              currentSubmenu?.submenuVariant === 'seedResources' ||
              currentSubmenu?.submenuVariant === 'initDayMock' ||
              currentSubmenu?.submenuVariant === 'initTicketMock' ||
              currentSubmenu?.submenuVariant === 'terminalSettings'
              ? 'w-[min(92vw,720px)]'
              : 'w-80',
          )}
          onWheelCapture={(event) => {
            event.stopPropagation()
          }}
        >
          <div className="shrink-0 border-b border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] bg-popover/95 backdrop-blur-sm">
            <div className="px-4 py-3">
              <div className="flex items-center gap-2">
                {currentSubmenu && (
                  <button
                    onClick={() =>
                      setActiveSubmenu(currentSubmenu.parentSubmenuKey)
                    }
                    className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40"
                    aria-label="Back"
                  >
                    <ChevronLeft className="h-4 w-4 text-[var(--network-globe-edge)]" />
                  </button>
                )}
                <span className="text-[13px] font-semibold text-foreground">
                  {currentSubmenu ? currentSubmenu.title : 'Debug'}
                </span>
              </div>
            </div>
            {isFeatureFlagsSubmenu ? (
              <div className="px-4 pb-3">
                <div className="relative">
                  <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--network-globe-edge)]/60" />
                  <Input
                    autoFocus
                    value={featureFlagsSearch}
                    onChange={(event) =>
                      setFeatureFlagsSearch(event.target.value)
                    }
                    placeholder="Search flags..."
                    className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 ps-8 pe-8 text-[12px] text-foreground placeholder:text-[var(--network-globe-edge)]/50"
                  />
                  {featureFlagsSearch ? (
                    <button
                      type="button"
                      onClick={() => setFeatureFlagsSearch('')}
                      className="absolute end-2 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded text-[var(--network-globe-edge)]/70 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground"
                      aria-label="Clear search"
                    >
                      <X className="h-3.5 w-3.5" />
                    </button>
                  ) : null}
                </div>
              </div>
            ) : null}
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3">
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
              ) : currentSubmenu.submenuVariant === 'terminalSettings' ? (
                <DebugMenuTerminalPanel />
              ) : (
                <nav
                  className="space-y-0.5"
                  aria-label={currentSubmenu.title}
                >
                  {isFeatureFlagsSubmenu &&
                  featureFlagsSearch.trim() &&
                  filteredFeatureFlagItems.every(
                    (item) => item.label === 'Reset all feature flags',
                  ) ? (
                    <p className="px-3 py-2 text-[11px] text-[var(--network-globe-edge)]/70">
                      No matching flags
                    </p>
                  ) : null}
                  {isFeatureFlagsSubmenu
                    ? groupedFeatureFlagItems.map((group, groupIndex) => (
                        <div key={`feature-flag-group-${groupIndex}`}>
                          {group.category ? (
                            <div
                              className={cn(
                                'px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80',
                                groupIndex === 0 ? 'pt-0' : 'pt-3',
                              )}
                            >
                              {group.category}
                            </div>
                          ) : null}
                          {group.items.map((item, itemIndex) =>
                            renderDebugSubmenuItemRow(
                              item,
                              itemIndex,
                              `feature-flag-${groupIndex}`,
                              activeSubmenu,
                              setActiveSubmenu,
                            ),
                          )}
                        </div>
                      ))
                    : currentSubmenu.items.map((item, itemIndex) =>
                        renderDebugSubmenuItemRow(
                          item,
                          itemIndex,
                          'submenu',
                          activeSubmenu,
                          setActiveSubmenu,
                        ),
                      )}
                </nav>
              )
            ) : (
              <nav className="space-y-5" aria-label="Debug options">
                {sections.map((section) => (
                  <div key={section.title}>
                    <div className="mb-2 flex items-center gap-2 px-1">
                      <span className="text-[var(--network-globe-edge)]">{section.icon}</span>
                      <span className="text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
                        {section.title}
                      </span>
                    </div>
                    <div className="space-y-0.5">
                      {section.items.map((item, itemIndex) => {
                        if (item.variant === 'switch') {
                          return (
                            <DebugMenuSwitchRow
                              key={`${section.title}-${itemIndex}`}
                              item={item}
                            />
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
                            className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40 ${
                              item.active
                                ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--muted))] text-foreground'
                                : 'text-foreground/90 hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground'
                            }`}
                          >
                            {item.icon && (
                              <span className="flex-shrink-0 text-[var(--network-globe-edge)]">
                                {item.icon}
                              </span>
                            )}
                            <span className="flex-1 font-medium">
                              {item.label}
                            </span>
                            {item.badge !== undefined && (
                              <span className="flex-shrink-0 rounded-full bg-[color-mix(in_srgb,var(--network-globe-edge)_22%,transparent)] px-2 py-0.5 text-[11px] font-medium text-[var(--network-globe-edge)]">
                                {item.badge}
                              </span>
                            )}
                            {hasSubmenu && (
                              <ChevronRight className="h-4 w-4 flex-shrink-0 text-[var(--network-globe-edge)]/60" />
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
