import { useState, useEffect, useMemo, useRef, useCallback, type ComponentProps } from 'react'
import {
  Bug,
  ChevronRight,
  Megaphone,
  Trash2,
  Plus,
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
  Monitor,
  Terminal,
  History,
  Search,
  X,
  Languages,
  HeartHandshake,
  MessageSquareQuote,
  Variable,
  Mail,
  Code2,
  KeyRound,
  ShieldCheck,
  Folder,
  MonitorSmartphone,
  ExternalLink,
  Link2,
  Network,
  GitBranch,
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
  resetInitMenuDebugOverrides,
  FEATURE_FLAGS_MENU_DEBUG_DEFAULTS,
  setDebugOverride,
  subscribeToDebugOverrides,
  type DebugOverrides,
  type FeatureFlagsMenuDebugKey,
  type MockCloudStatusAlert,
} from '@/lib/debug-overrides'
import { getPreLaunchDefault } from '@/lib/pre-launch'
import {
  detectUserOs,
  getUserOsLabel,
  USER_OS_LABELS,
  type UserOsOverride,
} from '@/lib/user-os'
import { formatInitMockCurrentDay } from '@/lib/init/mock-current-day'
import { formatInitMockTicketType } from '@/lib/init/ticket-types'
import type { FaviconStatus } from '@/hooks/use-favicon'
import {
  formatFaviconStatusSummary,
  getFaviconStatus,
  subscribeFaviconStatus,
} from '@/lib/favicon'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  setDebugProfileOverride,
  setDebugProfileFeatureOverride,
  resetDebugProfileFeatureOverrides,
  resetDebugProfileFeatureOverride,
  getCanonicalProfileFeatures,
  getEnvProfileId,
  hasDebugProfileOverride,
  CONSOLE_PROFILES,
  CONSOLE_PROFILE_FEATURE_LABELS,
} from '@/lib/console-profiles'
import {
  setDebugEndpointOverride,
  removeCustomDebugEndpoint,
  ENDPOINT_PRESETS,
  type EndpointPresetId,
} from '@/lib/debug-endpoint'
import { useDebugEndpoint } from '@/hooks/use-debug-endpoint'
import {
  getEnvMcpEndpointUrl,
  setDebugMcpEndpointOverride,
  MCP_ENDPOINT_PRESETS,
  type McpEndpointPresetId,
} from '@/lib/debug-mcp-endpoint'
import { useDebugMcpEndpoint } from '@/hooks/use-debug-mcp-endpoint'
import { McpIcon } from '@/components/global/shared/McpIcon'
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
import { DebugMenuRecentResourcesPanel } from '@/components/global/providers/DebugMenuRecentResourcesPanel'
import { DebugMenuCommunityShareExamplesPanel } from '@/components/global/providers/DebugMenuCommunityShareExamplesPanel'
import { DebugMenuEnvPanel } from '@/components/global/providers/DebugMenuEnvPanel'
import { DebugMenuFaviconPanel } from '@/components/global/providers/DebugMenuFaviconPanel'
import { DebugMenuIpPanel } from '@/components/global/providers/DebugMenuIpPanel'
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
import { sendSentryDebugTestError } from '@/lib/sentry/init-client'
import {
  COMMUNITY_SUPPORT_REMINDER_MS,
  COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD,
} from '@/lib/community/support-prompt'
import { toast } from 'sonner'

const COMMUNITY_SUPPORT_REMINDER_DAYS = Math.round(
  COMMUNITY_SUPPORT_REMINDER_MS / (24 * 60 * 60 * 1000),
)
/** Debug-only cadence note for the community support wizard. */
const COMMUNITY_SUPPORT_WIZARD_CADENCE = `Shows the "A note from the team" wizard after ${COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD} unique console days, then again every ~${COMMUNITY_SUPPORT_REMINDER_DAYS} days until the user picks an action.`

const DEBUG_MENU_DRAG_THRESHOLD_PX = 6
/** Debug menu stays English + LTR regardless of app language (developer tooling). */
const DEBUG_MENU_LANGUAGE_COPY = getEnglishCatalog().app.debugMenu.language

/** Scroll only the menu list. `scrollIntoView` also moves the document while the popover is still off-screen. */
function scrollMenuItemIntoView(
  container: HTMLElement,
  element: HTMLElement,
) {
  const containerRect = container.getBoundingClientRect()
  const elementRect = element.getBoundingClientRect()
  if (elementRect.bottom > containerRect.bottom) {
    container.scrollTop += elementRect.bottom - containerRect.bottom
  } else if (elementRect.top < containerRect.top) {
    container.scrollTop -= containerRect.top - elementRect.top
  }
}

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
  /** Optional note shown at the top of this item's submenu list. */
  submenuNote?: string
  /** Opens the profile comparison table instead of a submenu list. */
  submenuVariant?:
    | 'profileComparison'
    | 'communityShareExamples'
    | 'prefsDebug'
    | 'initDayMock'
    | 'initTicketMock'
    | 'seedResources'
    | 'terminalSettings'
    | 'recentResources'
    | 'envStatus'
    | 'faviconStatus'
    | 'clientIp'
  /** Extra classes on submenu row buttons (e.g. separator above reset actions). */
  rowClassName?: string
  /** Feature flags submenu: group label for categorized lists. */
  category?: string
  /** Optional secondary remove action (e.g. saved custom endpoints). */
  onRemove?: () => void
  /** Accessible label for the remove button. */
  removeLabel?: string
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

function matchesMenuItemSearch(item: MenuItem, query: string): boolean {
  const trimmed = query.trim()
  if (!trimmed) return true

  const q = trimmed.toLowerCase()
  if (item.label.toLowerCase().includes(q)) return true
  if (item.description?.toLowerCase().includes(q)) return true
  if (item.category?.toLowerCase().includes(q)) return true
  if (item.submenu?.some((child) => matchesMenuItemSearch(child, query))) {
    return true
  }
  return false
}

function matchesFeatureFlagSearch(item: MenuItem, query: string): boolean {
  const trimmed = query.trim()
  if (!trimmed) return true
  if (item.label === 'Reset all feature flags') return true

  return matchesMenuItemSearch(item, query)
}

function filterFeatureFlagMenuItems(
  items: MenuItem[],
  query: string,
): MenuItem[] {
  if (!query.trim()) return items
  return items.filter((item) => matchesFeatureFlagSearch(item, query))
}

function filterMenuSections(
  sections: MenuSection[],
  query: string,
): MenuSection[] {
  if (!query.trim()) return sections
  return sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => matchesMenuItemSearch(item, query)),
    }))
    .filter((section) => section.items.length > 0)
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

function DebugMenuSwitchRow({
  item,
  id,
  highlighted = false,
  navIndex,
  onHighlight,
}: {
  item: MenuItem
  id?: string
  highlighted?: boolean
  navIndex?: number
  onHighlight?: () => void
}) {
  const showReset = isFeatureFlagOverridden(item)

  return (
    <div
      id={id}
      role="option"
      aria-selected={highlighted}
      data-debug-nav-index={navIndex}
      onMouseEnter={onHighlight}
      className={cn(
        'flex items-center justify-between gap-3 rounded-lg px-3 py-2.5 transition-colors',
        highlighted
          ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--muted))] text-foreground'
          : 'hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_10%,transparent)]',
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
          <div className="mt-0.5 whitespace-pre-line text-[11px] text-[var(--network-globe-edge)]/80">
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
                tabIndex={-1}
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
          tabIndex={-1}
        />
      </div>
    </div>
  )
}

function debugMenuItemRowClassName({
  disabled,
  active,
  highlighted,
  rowClassName,
}: {
  disabled?: boolean
  active?: boolean
  highlighted?: boolean
  rowClassName?: string
}) {
  return cn(
    'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-start text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40',
    disabled
      ? 'cursor-not-allowed opacity-50'
      : highlighted || active
        ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--muted))] text-foreground'
        : 'text-foreground/90 hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)] hover:text-foreground',
    rowClassName,
  )
}

function renderDebugSubmenuItemRow(
  item: MenuItem,
  itemIndex: number,
  keyPrefix: string,
  nestedSubmenuParentKey: string | null,
  setActiveSubmenu: (key: string | null) => void,
  options?: {
    id?: string
    highlighted?: boolean
    navIndex?: number
    onHighlight?: () => void
  },
) {
  const nestedSubmenuKey = nestedSubmenuParentKey
    ? `${nestedSubmenuParentKey}-${item.label}`
    : null
  const hasNestedSubmenu = menuItemHasSubmenu(item)
  const key = `${keyPrefix}-${itemIndex}`

  if (item.variant === 'switch') {
    return (
      <DebugMenuSwitchRow
        key={key}
        item={item}
        id={options?.id}
        highlighted={options?.highlighted}
        navIndex={options?.navIndex}
        onHighlight={options?.onHighlight}
      />
    )
  }

  const handleSelect = () => {
    if (hasNestedSubmenu && nestedSubmenuKey) {
      setActiveSubmenu(nestedSubmenuKey)
    } else if (item.onClick) {
      item.onClick()
    }
  }

  const rowClassName = debugMenuItemRowClassName({
    disabled: item.disabled,
    active: item.active,
    highlighted: options?.highlighted,
    rowClassName: item.rowClassName,
  })

  const content = (
    <>
      {item.icon && (
        <span className="flex-shrink-0 text-[var(--network-globe-edge)]">{item.icon}</span>
      )}
      <span className="min-w-0 flex-1">
        <span className="block font-medium">{item.label}</span>
        {item.description && (
          <span
            className="mt-0.5 block whitespace-pre-line break-all text-[11px] font-normal opacity-80"
            title={item.description}
          >
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
    </>
  )

  if (item.onRemove) {
    return (
      <div
        key={key}
        id={options?.id}
        role="option"
        aria-selected={options?.highlighted ?? false}
        data-debug-nav-index={options?.navIndex}
        onMouseEnter={options?.onHighlight}
        className={cn(
          'flex items-center gap-1 rounded-lg transition-colors',
          options?.highlighted || item.active
            ? 'bg-[color-mix(in_srgb,var(--network-globe-edge)_18%,var(--muted))]'
            : 'hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_12%,transparent)]',
          item.rowClassName,
        )}
      >
        <button
          type="button"
          tabIndex={-1}
          onClick={handleSelect}
          disabled={item.disabled}
          className={cn(
            'flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5 text-start text-[13px] transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40',
            item.disabled
              ? 'cursor-not-allowed opacity-50'
              : 'text-foreground/90 hover:text-foreground',
          )}
        >
          {content}
        </button>
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              tabIndex={-1}
              onClick={(event) => {
                event.stopPropagation()
                item.onRemove?.()
              }}
              className="mr-1.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-md text-[var(--network-globe-edge)]/80 transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40"
              aria-label={item.removeLabel ?? `Remove ${item.label}`}
            >
              <Trash2 className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="left">
            {item.removeLabel ?? 'Remove'}
          </TooltipContent>
        </Tooltip>
      </div>
    )
  }

  return (
    <button
      key={key}
      id={options?.id}
      type="button"
      role="option"
      aria-selected={options?.highlighted ?? false}
      data-debug-nav-index={options?.navIndex}
      tabIndex={-1}
      onMouseEnter={options?.onHighlight}
      onClick={handleSelect}
      disabled={item.disabled}
      className={rowClassName}
    >
      {content}
    </button>
  )
}

type DebugNavigableEntry = {
  id: string
  item: MenuItem
  /** Key used when opening this item's submenu. */
  submenuKey: string | null
}

function isDebugPanelSubmenuVariant(
  variant: MenuItem['submenuVariant'] | undefined,
): boolean {
  return (
    variant === 'profileComparison' ||
    variant === 'communityShareExamples' ||
    variant === 'prefsDebug' ||
    variant === 'initDayMock' ||
    variant === 'initTicketMock' ||
    variant === 'seedResources' ||
    variant === 'terminalSettings' ||
    variant === 'recentResources' ||
    variant === 'envStatus' ||
    variant === 'faviconStatus' ||
    variant === 'clientIp'
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
  const defaultValue =
    key === 'preLaunch'
      ? getPreLaunchDefault()
      : FEATURE_FLAGS_MENU_DEBUG_DEFAULTS[key]

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
  /** Optional note shown above the submenu item list. */
  note?: string
}

function menuItemHasSubmenu(item: MenuItem): boolean {
  return (
    Boolean(item.submenu?.length) ||
    item.submenuVariant === 'profileComparison' ||
    item.submenuVariant === 'communityShareExamples' ||
    item.submenuVariant === 'prefsDebug' ||
    item.submenuVariant === 'initDayMock' ||
    item.submenuVariant === 'initTicketMock' ||
    item.submenuVariant === 'seedResources' ||
    item.submenuVariant === 'terminalSettings' ||
    item.submenuVariant === 'recentResources' ||
    item.submenuVariant === 'envStatus' ||
    item.submenuVariant === 'faviconStatus' ||
    item.submenuVariant === 'clientIp'
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
        note: item.submenuNote,
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
  const parts: string[] = [
    formatInitMockCurrentDay(overrides.mockInitCurrentDay),
  ]
  if (overrides.mockInitTicketType !== null) {
    parts.push(formatInitMockTicketType(overrides.mockInitTicketType))
  }
  if (overrides.previewInitReactionConfetti) {
    parts.push('Confetti preview on')
  }
  if (overrides.initLowPowerAnimations !== 'auto') {
    parts.push(`Low power ${overrides.initLowPowerAnimations}`)
  }
  return parts.join(' · ')
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
  const { isDebugModeOpen: isVisible, closeDebugMode } = useDebugMode()
  const queryClient = useQueryClient()
  const [isOpen, setIsOpen] = useState(false)
  const [overrides, setOverrides] = useState<DebugOverrides>(loadDebugOverrides)
  const { addMockBanner, clearAllBanners, banners } = usePromoBanner()
  const [faviconStatus, setFaviconStatus] = useState<FaviconStatus>(() =>
    getFaviconStatus(),
  )
  const { theme, setTheme } = useTheme()
  const [activeSubmenu, setActiveSubmenu] = useState<string | null>(null)
  const [menuSearch, setMenuSearch] = useState('')
  const [featureFlagsSearch, setFeatureFlagsSearch] = useState('')
  const [highlightedIndex, setHighlightedIndex] = useState(-1)
  const searchInputRef = useRef<HTMLInputElement>(null)
  const menuListRef = useRef<HTMLDivElement>(null)
  const languageCopy = DEBUG_MENU_LANGUAGE_COPY
  const { profileId, features } = useConsoleProfile()
  const {
    preset: endpointPreset,
    customUrl: endpointCustomUrl,
    customEndpoints: endpointCustomEndpoints,
    effectiveUrl: endpointEffectiveUrl,
    envUrl: endpointEnvUrl,
  } = useDebugEndpoint()
  const {
    preset: mcpEndpointPreset,
    customUrl: mcpEndpointCustomUrl,
    effectiveUrl: mcpEndpointEffectiveUrl,
  } = useDebugMcpEndpoint()
  const profileFromOverride = hasDebugProfileOverride()
  const envProfileId = getEnvProfileId()
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

  const applyOverrideAndGoHome = useCallback((action: () => void) => {
    setIsOpen(false)
    setTimeout(() => {
      action()
      window.location.assign('/')
    }, 0)
  }, [])

  useEffect(() => {
    const unsubscribe = subscribeToDebugOverrides(setOverrides)
    return () => {
      unsubscribe?.()
    }
  }, [])

  // Keep favicon status summary in sync for the Appearance menu label.
  useEffect(() => {
    if (!isOpen) return
    return subscribeFaviconStatus(setFaviconStatus)
  }, [isOpen])

  // Reset submenu and search when popover closes
  useEffect(() => {
    if (!isOpen) {
      setActiveSubmenu(null)
      setMenuSearch('')
      setFeatureFlagsSearch('')
      setHighlightedIndex(-1)
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

    const detectedOs = detectUserOs()
    const userOsDescription =
      overrides.userOs === 'auto'
        ? `Auto (${getUserOsLabel(detectedOs)})`
        : USER_OS_LABELS[overrides.userOs]

    const userOsOptions: MenuItem[] = (
      [
        {
          label: 'Auto',
          value: 'auto' as const,
          description: `Detect from device (${getUserOsLabel(detectedOs)})`,
        },
        {
          label: 'macOS',
          value: 'macos' as const,
          description: 'macOS UI defaults and ⌘ shortcuts',
        },
        {
          label: 'Windows',
          value: 'windows' as const,
          description: 'Windows UI defaults and Ctrl shortcuts',
        },
        {
          label: 'Linux',
          value: 'linux' as const,
          description: 'Linux UI defaults and Ctrl shortcuts',
        },
      ] satisfies ReadonlyArray<{
        label: string
        value: UserOsOverride
        description: string
      }>
    ).map((option) => ({
      label: option.label,
      description: option.description,
      onClick: () => {
        setOverrides((prev) => ({ ...prev, userOs: option.value }))
        setDebugOverride('userOs', option.value)
      },
      active: overrides.userOs === option.value,
      icon: <Monitor className="h-3 w-3" />,
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

    const activeEndpointUrl = endpointEffectiveUrl ?? endpointEnvUrl ?? '—'
    const activeEndpointBadge = !endpointPreset
      ? 'Env'
      : endpointPreset === 'custom'
        ? 'Custom'
        : ENDPOINT_PRESETS[endpointPreset as keyof typeof ENDPOINT_PRESETS]
            ?.label ?? endpointPreset

    const activeMcpEndpointUrl = mcpEndpointEffectiveUrl || getEnvMcpEndpointUrl()
    const activeMcpEndpointBadge = !mcpEndpointPreset
      ? 'Env'
      : mcpEndpointPreset === 'custom'
        ? 'Custom'
        : MCP_ENDPOINT_PRESETS[
            mcpEndpointPreset as keyof typeof MCP_ENDPOINT_PRESETS
          ]?.label ?? mcpEndpointPreset

    const activeProfileLabel = CONSOLE_PROFILES[profileId].label
    const activeProfileBadge = profileFromOverride ? 'Override' : 'Env'
    const activeProfileDescription = profileFromOverride
      ? `${activeProfileLabel} (debug override)`
      : `${activeProfileLabel} (VITE_CONSOLE_PROFILE → ${CONSOLE_PROFILES[envProfileId].label})`

    const profileOptions: MenuItem[] = [
      {
        label: 'Cloud',
        description: CONSOLE_PROFILES.cloud.description,
        active: profileFromOverride && profileId === 'cloud',
        icon: <Cloud className="h-3 w-3" />,
        onClick: () => {
          applyOverrideAndGoHome(() => setDebugProfileOverride('cloud'))
        },
      },
      {
        label: 'Self-hosted',
        description: CONSOLE_PROFILES['self-hosted'].description,
        active: profileFromOverride && profileId === 'self-hosted',
        icon: <Server className="h-3 w-3" />,
        onClick: () => {
          applyOverrideAndGoHome(() =>
            setDebugProfileOverride('self-hosted'),
          )
        },
      },
      {
        label: 'Use env var',
        description: `Current env: ${CONSOLE_PROFILES[envProfileId].label}`,
        active: !profileFromOverride,
        onClick: () => {
          applyOverrideAndGoHome(() => setDebugProfileOverride(null))
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
        label: 'Day',
        description: formatInitMockCurrentDay(overrides.mockInitCurrentDay),
        icon: <CalendarDays className="h-3 w-3" />,
        submenuVariant: 'initDayMock',
      },
      {
        label: 'Ticket',
        description: formatInitMockTicketType(overrides.mockInitTicketType),
        icon: <Ticket className="h-3 w-3" />,
        submenuVariant: 'initTicketMock',
      },
      {
        label: 'Confetti',
        description: overrides.previewInitReactionConfetti
          ? 'Triggers with 1 user on the same reaction'
          : 'Needs 5 users on the same reaction',
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
        label: 'Low power',
        description: getLowPowerDecisionDescription(initLowPowerDecision),
        icon: <Sparkles className="h-3 w-3" />,
        submenu: lowPowerAnimationSubmenu,
      },
      {
        label: 'Reset settings',
        description:
          'Restore day, ticket mock, confetti preview, and low power to defaults.',
        onClick: () => {
          resetInitMenuDebugOverrides()
          setOverrides(loadDebugOverrides())
          setIsOpen(false)
        },
        icon: <RotateCcw className="h-3 w-3" />,
        rowClassName:
          'mt-2 border-t border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] pt-2',
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
            description: formatFaviconStatusSummary(faviconStatus),
            icon: <Image className="h-3 w-3" />,
            submenuVariant: 'faviconStatus',
          },
          {
            label: 'Operating system',
            description: userOsDescription,
            icon: <Monitor className="h-3 w-3" />,
            submenu: userOsOptions,
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
            label: 'Demos',
            description: 'Preview alerts, banners, loaders, OAuth2, Git, and pages.',
            icon: <Bug className="h-3 w-3" />,
            submenu: [
              {
                label: 'Status alert',
                description:
                  overrides.mockCloudStatusAlert === 'live'
                    ? 'Live'
                    : overrides.mockCloudStatusAlert === 'operational'
                      ? 'None'
                      : overrides.mockCloudStatusAlert.charAt(0).toUpperCase() +
                        overrides.mockCloudStatusAlert.slice(1),
                icon: <Cloud className="h-3 w-3" />,
                submenu: [
                  {
                    label: 'Live',
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
                        label: 'None',
                        value: 'operational',
                        description: 'Normal operational state with no alert.',
                      },
                      {
                        label: 'Degraded',
                        value: 'degraded',
                        description: 'Degraded-service alert.',
                      },
                      {
                        label: 'Downtime',
                        value: 'downtime',
                        description: 'Outage alert.',
                      },
                      {
                        label: 'Maintenance',
                        value: 'maintenance',
                        description: 'Maintenance alert.',
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
                label: 'Promo banner',
                description:
                  banners.length > 0
                    ? `${banners.length} active`
                    : 'None active',
                icon: <Megaphone className="h-3 w-3" />,
                badge: banners.length > 0 ? banners.length : undefined,
                submenu: [
                  {
                    label: 'Add',
                    description: 'Add a mock promo banner.',
                    onClick: () => {
                      addMockBanner()
                      setIsOpen(false)
                    },
                    icon: <Megaphone className="h-3 w-3" />,
                  },
                  ...(banners.length > 0
                    ? [
                        {
                          label: 'Clear all',
                          description: 'Remove all promo banners.',
                          onClick: () => {
                            clearAllBanners()
                            setIsOpen(false)
                          },
                          icon: <Trash2 className="h-3 w-3" />,
                        },
                      ]
                    : []),
                ],
              },
              {
                label: 'Fullscreen loader',
                description: overrides.showFullscreenLoader ? 'On' : 'Off',
                icon: <Loader2 className="h-3 w-3" />,
                submenu: [
                  {
                    label: 'On',
                    description:
                      'Keep the initial loader visible to preview it.',
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
                    label: 'Off',
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
                description: 'Preview the error page.',
                onClick: () => {
                  navigate({ to: '/debug/error-preview' })
                  setIsOpen(false)
                },
                icon: <Bug className="h-3 w-3" />,
              },
              {
                label: 'Test Sentry',
                description: 'Force-init and send a test exception.',
                onClick: () => {
                  setIsOpen(false)
                  void (async () => {
                    const result = await sendSentryDebugTestError()
                    if (result.ok) {
                      toast.success(
                        `Sentry test flushed (${result.eventId}). Check Issues filtered to environment "development".`,
                      )
                      return
                    }
                    toast.error(
                      result.eventId
                        ? `${result.reason} Event: ${result.eventId}`
                        : result.reason,
                    )
                  })()
                },
                icon: <AlertTriangle className="h-3 w-3" />,
              },
              {
                label: 'Org setup',
                description: 'Preview organization creation progress.',
                onClick: () => {
                  navigate({ to: '/debug/org-setup-preview' })
                  setIsOpen(false)
                },
                icon: <Loader2 className="h-3 w-3" />,
              },
              {
                label: 'Verify email',
                description: 'Preview the email verification page.',
                onClick: () => {
                  navigate({ to: '/debug/verify-email-preview' })
                  setIsOpen(false)
                },
                icon: <Mail className="h-3 w-3" />,
              },
              {
                label: 'OAuth2',
                description: 'Preview consent, device, outcome, and relay screens.',
                icon: <KeyRound className="h-3 w-3" />,
                submenu: [
                  {
                    label: 'All screens',
                    description: 'Open the OAuth2 preview with a screen picker.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'consent' },
                      })
                      setIsOpen(false)
                    },
                    icon: <KeyRound className="h-3 w-3" />,
                  },
                  {
                    label: 'Consent',
                    description: 'Standard authorization consent.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'consent' },
                      })
                      setIsOpen(false)
                    },
                    icon: <ShieldCheck className="h-3 w-3" />,
                  },
                  {
                    label: 'Consent (MCP)',
                    description: 'MCP grant with scope narrowing.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'consent-mcp' },
                      })
                      setIsOpen(false)
                    },
                    icon: <McpIcon className="h-3 w-3" />,
                  },
                  {
                    label: 'Consent (resources)',
                    description: 'Project and organization resource pickers.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'consent-resources' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Folder className="h-3 w-3" />,
                  },
                  {
                    label: 'Device code',
                    description: 'Enter a device authorization code.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'device-enter-code' },
                      })
                      setIsOpen(false)
                    },
                    icon: <MonitorSmartphone className="h-3 w-3" />,
                  },
                  {
                    label: 'Device confirm',
                    description: 'Confirm a prefilled device code.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'device-confirm-code' },
                      })
                      setIsOpen(false)
                    },
                    icon: <MonitorSmartphone className="h-3 w-3" />,
                  },
                  {
                    label: 'Device consent',
                    description: 'Device-flow consent screen.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'device-consent' },
                      })
                      setIsOpen(false)
                    },
                    icon: <ShieldCheck className="h-3 w-3" />,
                  },
                  {
                    label: 'Access granted',
                    description: 'Authorization approved outcome.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'outcome-approved' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Check className="h-3 w-3" />,
                  },
                  {
                    label: 'Device connected',
                    description: 'Device-flow approved outcome.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'outcome-approved-device' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Check className="h-3 w-3" />,
                  },
                  {
                    label: 'Access granted (deep link)',
                    description: 'Approved with native deep-link retry.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'outcome-approved-deeplink' },
                      })
                      setIsOpen(false)
                    },
                    icon: <ExternalLink className="h-3 w-3" />,
                  },
                  {
                    label: 'Request cancelled',
                    description: 'Denied / cancelled outcome.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'outcome-denied' },
                      })
                      setIsOpen(false)
                    },
                    icon: <X className="h-3 w-3" />,
                  },
                  {
                    label: 'Authorization failed',
                    description: 'Invalid or expired request error.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'error' },
                      })
                      setIsOpen(false)
                    },
                    icon: <AlertTriangle className="h-3 w-3" />,
                  },
                  {
                    label: 'Loading',
                    description: 'Consent / device loading spinner.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'loading' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Loader2 className="h-3 w-3" />,
                  },
                  {
                    label: 'Relay success',
                    description: 'Native OAuth callback success.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'relay-success' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Check className="h-3 w-3" />,
                  },
                  {
                    label: 'Relay failure',
                    description: 'Native OAuth callback failure.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'relay-failure' },
                      })
                      setIsOpen(false)
                    },
                    icon: <AlertTriangle className="h-3 w-3" />,
                  },
                  {
                    label: 'Relay missing URL',
                    description: 'Missing project redirect URL.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'relay-missing' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Link2 className="h-3 w-3" />,
                  },
                  {
                    label: 'Relay error',
                    description: 'OAuth error payload without project.',
                    onClick: () => {
                      navigate({
                        to: '/debug/oauth2-preview',
                        search: { screen: 'relay-error' },
                      })
                      setIsOpen(false)
                    },
                    icon: <AlertTriangle className="h-3 w-3" />,
                  },
                ],
              },
              {
                label: 'Git authorization',
                description: 'Preview the GitHub contributor approval page.',
                icon: <GitBranch className="h-3 w-3" />,
                submenu: [
                  {
                    label: 'All screens',
                    description: 'Open the Git authorization preview.',
                    onClick: () => {
                      navigate({
                        to: '/debug/authorize-contributor-preview',
                        search: { status: 'awaiting' },
                      })
                      setIsOpen(false)
                    },
                    icon: <GitBranch className="h-3 w-3" />,
                  },
                  {
                    label: 'Awaiting',
                    description: 'PR deployment waiting for owner approval.',
                    onClick: () => {
                      navigate({
                        to: '/debug/authorize-contributor-preview',
                        search: { status: 'awaiting' },
                      })
                      setIsOpen(false)
                    },
                    icon: <GitBranch className="h-3 w-3" />,
                  },
                  {
                    label: 'Approved',
                    description: 'Successful authorization outcome.',
                    onClick: () => {
                      navigate({
                        to: '/debug/authorize-contributor-preview',
                        search: { status: 'success' },
                      })
                      setIsOpen(false)
                    },
                    icon: <Check className="h-3 w-3" />,
                  },
                  {
                    label: 'Failed',
                    description: 'Authorization error from the API.',
                    onClick: () => {
                      navigate({
                        to: '/debug/authorize-contributor-preview',
                        search: { status: 'error' },
                      })
                      setIsOpen(false)
                    },
                    icon: <AlertTriangle className="h-3 w-3" />,
                  },
                ],
              },
              {
                label: 'Functions editor',
                description: 'Preview the Functions local editor.',
                onClick: () => {
                  navigate({ to: '/debug/code-editor-preview' })
                  setIsOpen(false)
                },
                icon: <Code2 className="h-3 w-3" />,
              },
              {
                label: 'Community support',
                description: 'Wizard preview and X share examples',
                icon: <HeartHandshake className="h-3 w-3" />,
                submenuNote: COMMUNITY_SUPPORT_WIZARD_CADENCE,
                submenu: [
                  {
                    label: 'Preview wizard',
                    description: overrides.previewCommunitySupportWizard
                      ? 'Previewing'
                      : 'Force-show the fullscreen wizard',
                    active: overrides.previewCommunitySupportWizard,
                    onClick: () => {
                      setOverrides((prev) => ({
                        ...prev,
                        previewCommunitySupportWizard: true,
                      }))
                      setDebugOverride('previewCommunitySupportWizard', true)
                      setIsOpen(false)
                    },
                    icon: <HeartHandshake className="h-3 w-3" />,
                  },
                  {
                    label: 'X share examples',
                    description: 'Review all Cloud and self-hosted share drafts',
                    icon: <MessageSquareQuote className="h-3 w-3" />,
                    submenuVariant: 'communityShareExamples',
                  },
                ],
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
            label: 'Prefs',
            description: 'View and edit account and team prefs.',
            icon: <Braces className="h-3 w-3" />,
            submenuVariant: 'prefsDebug',
          },
          {
            label: 'Env',
            description: 'Check if env vars are set (values never shown).',
            icon: <Variable className="h-3 w-3" />,
            submenuVariant: 'envStatus',
          },
          {
            label: 'IP',
            description: 'Compare browser IP with the IP SSR saw.',
            icon: <Network className="h-3 w-3" />,
            submenuVariant: 'clientIp',
          },
          {
            label: 'Terminal',
            description: 'View and clear the browser CLI cache.',
            icon: <Terminal className="h-3 w-3" />,
            submenuVariant: 'terminalSettings',
          },
          {
            label: 'Recents',
            description: 'View and reset Command Center history.',
            icon: <History className="h-3 w-3" />,
            submenuVariant: 'recentResources',
          },
          {
            label: 'Seed',
            description: 'Create mock agent models, memories, projects, and more.',
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
            label: 'Flags',
            description: 'Override console profile features.',
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
                'Database PITR restore',
                'Restore PITR button on backups and the restore card in dedicated DB PITR settings.',
                'databasePitrRestore',
                profileId,
                features.databasePitrRestore,
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
                'Extra OAuth login',
                'Show Google, GitLab, Bitbucket, and Cursor on console sign-in and sign-up. GitHub stays available.',
                'extraOAuthLogin',
                profileId,
                features.extraOAuthLogin,
                { category: 'Auth & security' },
              ),
              createProfileFeatureFlagItem(
                'Extra VCS OAuth',
                'Show GitLab, Bitbucket, and Origin on Git connect. GitHub stays available.',
                'extraVcsOAuth',
                profileId,
                features.extraVcsOAuth,
                { category: 'Auth & security' },
              ),
              createProfileFeatureFlagItem(
                'Cookie banner',
                'Show the locale-gated cookie consent banner and footer cookie settings.',
                'cookieBanner',
                profileId,
                features.cookieBanner,
                { category: 'Auth & security' },
              ),
              createProfileFeatureFlagItem(
                'Firewall',
                'Show the project Firewall section, routes, rules, analytics, and logs.',
                'firewall',
                profileId,
                features.firewall,
                { category: 'Auth & security' },
              ),
              createProfileFeatureFlagItem(
                'Storage S3',
                'Show the Connect S3 tab, storage sidebar S3 card, and S3 API docs.',
                'storageS3',
                profileId,
                features.storageS3,
                { category: 'Storage' },
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
                'Domain buy/transfer',
                'Buy domain and transfer-in in the console, plus Domains marketing/docs. Org Domains tab (add domain, DNS) stays available when off.',
                'domains',
                profileId,
                features.domains,
                { category: 'Organization' },
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
                'Blog drafts',
                'List draft blog posts above "Explore by topic" and open draft post pages (noindex).',
                'blogDrafts',
                profileId,
                features.blogDrafts,
                { category: 'Docs' },
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
                'Agent',
                'In-app AI agent chat, header button, /agent routes, and Agent docs.',
                'agent',
                profileId,
                features.agent,
                { category: 'UI & tools' },
              ),
              createProfileFeatureFlagItem(
                'Notifications',
                'Console notifications center (header bell and inbox popover).',
                'notifications',
                profileId,
                features.notifications,
                { category: 'UI & tools' },
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
                'Pre-launch',
                'Lock the site to /init. Root redirects there; other pages are blocked. Sign-in stays open and returns to /init. On by default.',
                'preLaunch',
                overrides.preLaunch,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    preLaunch: checked,
                  }))
                  setDebugOverride('preLaunch', checked)
                },
                undefined,
                'Site',
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
              ...(import.meta.env.DEV
                ? [
                    createDebugFeatureFlagItem(
                      'Construction',
                      'Header construction bar in Vite DEV. Default from VITE_CONSTRUCTION (unset = on).',
                      'showConstruction',
                      overrides.showConstruction,
                      (checked) => {
                        setOverrides((prev) => ({
                          ...prev,
                          showConstruction: checked,
                        }))
                        setDebugOverride('showConstruction', checked)
                      },
                      undefined,
                      'UI & tools',
                    ),
                  ]
                : []),
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
                'Preview onboarding complete',
                'Force Get started progress to 100% to preview advocacy copy and Star on GitHub.',
                'previewOnboardingComplete',
                overrides.previewOnboardingComplete,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    previewOnboardingComplete: checked,
                  }))
                  setDebugOverride('previewOnboardingComplete', checked)
                },
                undefined,
                'UI & tools',
              ),
              createDebugFeatureFlagItem(
                'Preview community support wizard',
                `Force-show the skippable "A note from the team" wizard. ${COMMUNITY_SUPPORT_WIZARD_CADENCE}`,
                'previewCommunitySupportWizard',
                overrides.previewCommunitySupportWizard,
                (checked) => {
                  setOverrides((prev) => ({
                    ...prev,
                    previewCommunitySupportWizard: checked,
                  }))
                  setDebugOverride('previewCommunitySupportWizard', checked)
                },
                undefined,
                'UI & tools',
              ),
              {
                label: 'Reset all feature flags',
                description:
                  'Restore every flag on this list to its default for your current profile.',
                onClick: () => {
                  resetDebugProfileFeatureOverrides()
                  resetFeatureFlagsMenuDebugOverrides()
                  setOverrides(loadDebugOverrides())
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
            description: activeProfileDescription,
            badge: activeProfileBadge,
            icon:
              profileId === 'cloud' ? (
                <Cloud className="h-3 w-3" />
              ) : (
                <Server className="h-3 w-3" />
              ),
            submenu: profileOptions,
          },
          (() => {
            const customEndpointItems: MenuItem[] = endpointCustomEndpoints.map(
              (url) => {
                let hostLabel = url
                try {
                  hostLabel = new URL(url).host
                } catch {
                  // keep full URL as label
                }
                return {
                  label: hostLabel,
                  description: url,
                  onClick: () => {
                    applyOverrideAndGoHome(() =>
                      setDebugEndpointOverride('custom', url),
                    )
                  },
                  active:
                    endpointPreset === 'custom' && endpointCustomUrl === url,
                  icon: <Globe className="h-3 w-3" />,
                  removeLabel: 'Remove custom endpoint',
                  onRemove: () => {
                    const wasActive = removeCustomDebugEndpoint(url)
                    if (wasActive) {
                      // Override already cleared; reload so clients pick up env endpoint.
                      applyOverrideAndGoHome(() => undefined)
                    }
                  },
                }
              },
            )

            const endpointOptions: MenuItem[] = [
              ...(
                Object.entries(ENDPOINT_PRESETS) as [
                  Exclude<EndpointPresetId, 'custom'>,
                  (typeof ENDPOINT_PRESETS)[keyof typeof ENDPOINT_PRESETS],
                ][]
              ).map(([id, { label, url, description }]) => ({
                label,
                description: `${url} · ${description}`,
                onClick: () => {
                  applyOverrideAndGoHome(() => setDebugEndpointOverride(id))
                },
                active: endpointPreset === id,
                icon: <Globe className="h-3 w-3" />,
              })),
              ...customEndpointItems,
              {
                label: 'Add custom...',
                description: 'Save a custom API URL to this list',
                onClick: () => {
                  const url = window.prompt(
                    'Enter API endpoint URL (e.g. https://my-appwrite.example/v1)',
                    endpointPreset === 'custom' && endpointCustomUrl
                      ? endpointCustomUrl
                      : activeEndpointUrl !== '—'
                        ? activeEndpointUrl
                        : 'http://localhost/v1',
                  )
                  if (url?.trim()) {
                    applyOverrideAndGoHome(() =>
                      setDebugEndpointOverride('custom', url.trim()),
                    )
                  }
                },
                icon: <Plus className="h-3 w-3" />,
                rowClassName:
                  'mt-2 border-t border-[color-mix(in_srgb,var(--network-globe-edge)_20%,var(--border))] pt-2',
              },
              {
                label: 'Use env var',
                description: endpointEnvUrl
                  ? `VITE_APPWRITE_ENDPOINT → ${endpointEnvUrl}`
                  : 'Reset to VITE_APPWRITE_ENDPOINT',
                onClick: () => {
                  applyOverrideAndGoHome(() => setDebugEndpointOverride(null))
                },
                active: !endpointPreset,
                icon: <RotateCcw className="h-3 w-3" />,
              },
            ]
            return {
              label: 'Server endpoint',
              description: activeEndpointUrl,
              badge: activeEndpointBadge,
              icon: <Globe className="h-3 w-3" />,
              submenu: endpointOptions,
            }
          })(),
          (() => {
            const envMcpUrl = getEnvMcpEndpointUrl()
            const mcpEndpointOptions: MenuItem[] = [
              ...(
                Object.entries(MCP_ENDPOINT_PRESETS) as [
                  Exclude<McpEndpointPresetId, 'custom'>,
                  (typeof MCP_ENDPOINT_PRESETS)[keyof typeof MCP_ENDPOINT_PRESETS],
                ][]
              ).map(([id, { label, url, description }]) => ({
                label,
                description: `${url} · ${description}`,
                onClick: () => {
                  setDebugMcpEndpointOverride(id)
                  setIsOpen(false)
                },
                active: mcpEndpointPreset === id,
                icon: <McpIcon className="h-3 w-3" />,
              })),
              {
                label: 'Custom...',
                description:
                  mcpEndpointPreset === 'custom' && mcpEndpointCustomUrl
                    ? mcpEndpointCustomUrl
                    : 'Enter a custom MCP URL',
                onClick: () => {
                  const url = window.prompt(
                    'Enter Appwrite MCP endpoint URL (e.g. http://localhost:8100/)',
                    mcpEndpointPreset === 'custom' && mcpEndpointCustomUrl
                      ? mcpEndpointCustomUrl
                      : activeMcpEndpointUrl || 'http://localhost:8100/',
                  )
                  if (url?.trim()) {
                    setDebugMcpEndpointOverride('custom', url.trim())
                    setIsOpen(false)
                  }
                },
                active: mcpEndpointPreset === 'custom',
                icon: <McpIcon className="h-3 w-3" />,
              },
              {
                label: 'Use env var',
                description: `VITE_APPWRITE_MCP_URL → ${envMcpUrl}`,
                onClick: () => {
                  setDebugMcpEndpointOverride(null)
                  setIsOpen(false)
                },
                active: !mcpEndpointPreset,
                icon: <RotateCcw className="h-3 w-3" />,
              },
            ]
            return {
              label: 'MCP endpoint',
              description: activeMcpEndpointUrl,
              badge: activeMcpEndpointBadge,
              icon: <McpIcon className="h-3 w-3" />,
              submenu: mcpEndpointOptions,
            }
          })(),
        ],
      },
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
    faviconStatus,
    profileId,
    features.dedicatedDbsSupport,
    features.dedicatedDbsDocumentsDB,
    features.dedicatedDbsVectorsDB,
    features.nativeDbsPostgres,
    features.nativeDbsMySQL,
    features.nativeDbsMongo,
    features.databasePitrRestore,
    features.userVerification,
    features.extraOAuthLogin,
    features.extraVcsOAuth,
    features.cookieBanner,
    features.blogDrafts,
    features.oauthApps,
    features.oauth2Server,
    features.orgApiKeys,
    features.domains,
    features.marketplace,
    features.partnersDocs,
    features.agent,
    features.notifications,
    features.firewall,
    features.storageS3,
    features.init,
    endpointPreset,
    endpointCustomUrl,
    endpointCustomEndpoints,
    endpointEffectiveUrl,
    endpointEnvUrl,
    mcpEndpointPreset,
    mcpEndpointCustomUrl,
    mcpEndpointEffectiveUrl,
    profileFromOverride,
    envProfileId,
    initLowPowerDecision,
    overrides,
    banners.length,
    actions,
    navigate,
    setTheme,
    addMockBanner,
    clearAllBanners,
    languageCopy,
    applyOverrideAndGoHome,
  ])

  const currentSubmenu = useMemo(
    () =>
      activeSubmenu ? resolveActiveSubmenu(sections, activeSubmenu) : null,
    [activeSubmenu, sections],
  )

  const filteredSections = useMemo(
    () => filterMenuSections(sections, menuSearch),
    [sections, menuSearch],
  )

  const isFeatureFlagsSubmenu = currentSubmenu?.title === 'Flags'
  const isPanelSubmenu = isDebugPanelSubmenuVariant(
    currentSubmenu?.submenuVariant,
  )
  const hasSearchField = !currentSubmenu || isFeatureFlagsSubmenu

  const filteredFeatureFlagItems = useMemo(() => {
    if (!isFeatureFlagsSubmenu || !currentSubmenu) return []
    return filterFeatureFlagMenuItems(currentSubmenu.items, featureFlagsSearch)
  }, [currentSubmenu, featureFlagsSearch, isFeatureFlagsSubmenu])

  const groupedFeatureFlagItems = useMemo(() => {
    if (!isFeatureFlagsSubmenu) return []
    return groupFeatureFlagMenuItems(filteredFeatureFlagItems)
  }, [filteredFeatureFlagItems, isFeatureFlagsSubmenu])

  const navigableItems = useMemo((): DebugNavigableEntry[] => {
    if (!currentSubmenu) {
      return filteredSections.flatMap((section) =>
        section.items.map((item) => ({
          id: `root__${section.title}__${item.label}`,
          item,
          submenuKey: menuItemHasSubmenu(item)
            ? `${section.title}-${item.label}`
            : null,
        })),
      )
    }

    if (isPanelSubmenu) return []

    if (isFeatureFlagsSubmenu) {
      return filteredFeatureFlagItems.map((item) => ({
        id: `flags__${item.category ?? 'general'}__${item.label}`,
        item,
        submenuKey: null,
      }))
    }

    return currentSubmenu.items.map((item) => ({
      id: `submenu__${activeSubmenu}__${item.label}`,
      item,
      submenuKey:
        menuItemHasSubmenu(item) && activeSubmenu
          ? `${activeSubmenu}-${item.label}`
          : null,
    }))
  }, [
    activeSubmenu,
    currentSubmenu,
    filteredFeatureFlagItems,
    filteredSections,
    isFeatureFlagsSubmenu,
    isPanelSubmenu,
  ])

  const navigableIndexById = useMemo(() => {
    const map = new Map<string, number>()
    navigableItems.forEach((entry, index) => {
      map.set(entry.id, index)
    })
    return map
  }, [navigableItems])

  const navigableIdsKey = useMemo(
    () => navigableItems.map((entry) => entry.id).join('\0'),
    [navigableItems],
  )

  useEffect(() => {
    if (!isFeatureFlagsSubmenu) {
      setFeatureFlagsSearch('')
    }
  }, [isFeatureFlagsSubmenu])

  useEffect(() => {
    setHighlightedIndex(navigableItems.length > 0 ? 0 : -1)
  }, [navigableIdsKey, navigableItems.length])

  useEffect(() => {
    if (!isOpen) return
    const frame = window.requestAnimationFrame(() => {
      if (hasSearchField) {
        searchInputRef.current?.focus({ preventScroll: true })
        return
      }
      if (!isPanelSubmenu) {
        menuListRef.current?.focus({ preventScroll: true })
      }
    })
    return () => window.cancelAnimationFrame(frame)
  }, [isOpen, activeSubmenu, hasSearchField, isPanelSubmenu])

  const handleOpenAutoFocus = useCallback((event: Event) => {
    event.preventDefault()
    if (hasSearchField) {
      searchInputRef.current?.focus({ preventScroll: true })
      return
    }
    if (!isPanelSubmenu) {
      menuListRef.current?.focus({ preventScroll: true })
    }
  }, [hasSearchField, isPanelSubmenu])

  useEffect(() => {
    if (highlightedIndex < 0) return
    const root = menuListRef.current
    if (!root) return
    const el = root.querySelector<HTMLElement>(
      `[data-debug-nav-index="${highlightedIndex}"]`,
    )
    if (el) scrollMenuItemIntoView(root, el)
  }, [highlightedIndex, navigableIdsKey])

  const activateNavigableEntry = useCallback(
    (entry: DebugNavigableEntry) => {
      const { item, submenuKey } = entry
      if (item.disabled) return

      if (item.variant === 'switch') {
        item.switchOnChange?.(!item.switchValue)
        return
      }

      if (submenuKey && menuItemHasSubmenu(item)) {
        setActiveSubmenu(submenuKey)
        return
      }

      item.onClick?.()
    },
    [],
  )

  const handleMenuKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      const target = event.target as HTMLElement | null
      const isEditableTarget =
        target?.tagName === 'TEXTAREA' ||
        target?.tagName === 'INPUT' ||
        Boolean(target?.isContentEditable)

      // Panel UIs (prefs, env, etc.) keep native typing; Escape still navigates back.
      if (
        isPanelSubmenu &&
        isEditableTarget &&
        target !== searchInputRef.current &&
        event.key !== 'Escape'
      ) {
        return
      }

      const moveHighlight = (delta: number) => {
        if (navigableItems.length === 0) return
        event.preventDefault()
        setHighlightedIndex((prev) => {
          const start = prev < 0 ? (delta > 0 ? -1 : 0) : prev
          const next =
            (start + delta + navigableItems.length) % navigableItems.length
          return next
        })
      }

      switch (event.key) {
        case 'ArrowDown':
          moveHighlight(1)
          break
        case 'ArrowUp':
          moveHighlight(-1)
          break
        case 'Home':
          if (target === searchInputRef.current) return
          if (navigableItems.length === 0) return
          event.preventDefault()
          setHighlightedIndex(0)
          break
        case 'End':
          if (target === searchInputRef.current) return
          if (navigableItems.length === 0) return
          event.preventDefault()
          setHighlightedIndex(navigableItems.length - 1)
          break
        case 'ArrowRight': {
          const entry = navigableItems[highlightedIndex]
          if (!entry || !entry.submenuKey || !menuItemHasSubmenu(entry.item)) {
            return
          }
          event.preventDefault()
          activateNavigableEntry(entry)
          break
        }
        case 'ArrowLeft': {
          if (!currentSubmenu) return
          // Don't steal caret movement while editing search text.
          if (
            target === searchInputRef.current &&
            (searchInputRef.current?.value.length ?? 0) > 0
          ) {
            return
          }
          event.preventDefault()
          setActiveSubmenu(currentSubmenu.parentSubmenuKey)
          break
        }
        case 'Backspace': {
          if (target !== searchInputRef.current) return
          if ((searchInputRef.current?.value.length ?? 0) > 0) return
          if (!currentSubmenu) return
          event.preventDefault()
          setActiveSubmenu(currentSubmenu.parentSubmenuKey)
          break
        }
        case 'Enter': {
          const entry = navigableItems[highlightedIndex]
          if (!entry) return
          event.preventDefault()
          activateNavigableEntry(entry)
          break
        }
        case ' ': {
          const entry = navigableItems[highlightedIndex]
          if (!entry || entry.item.variant !== 'switch') return
          // Keep Space typing in the search field.
          if (target === searchInputRef.current) return
          event.preventDefault()
          activateNavigableEntry(entry)
          break
        }
        default:
          break
      }
    },
    [
      activateNavigableEntry,
      currentSubmenu,
      highlightedIndex,
      isPanelSubmenu,
      navigableItems,
    ],
  )

  const handleEscapeKeyDown = useCallback(
    (event: Event) => {
      if (isFeatureFlagsSubmenu && featureFlagsSearch) {
        event.preventDefault()
        setFeatureFlagsSearch('')
        return
      }
      if (!currentSubmenu && menuSearch) {
        event.preventDefault()
        setMenuSearch('')
        return
      }
      if (currentSubmenu) {
        event.preventDefault()
        setActiveSubmenu(currentSubmenu.parentSubmenuKey)
      }
    },
    [
      currentSubmenu,
      featureFlagsSearch,
      isFeatureFlagsSubmenu,
      menuSearch,
    ],
  )

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
        <div className="relative">
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
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                onClick={(event) => {
                  event.preventDefault()
                  event.stopPropagation()
                  setIsOpen(false)
                  closeDebugMode()
                }}
                className={cn(
                  'absolute -end-1.5 -top-1.5 z-20 flex h-5 w-5 items-center justify-center rounded-full',
                  'border border-border bg-background text-muted-foreground shadow-sm',
                  'hover:bg-muted hover:text-foreground',
                  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/30',
                )}
                aria-label="Close debug mode"
              >
                <X className="h-3 w-3" />
              </button>
            </TooltipTrigger>
            <TooltipContent side={tooltipSide} sideOffset={8}>
              Close debug mode
            </TooltipContent>
          </Tooltip>
        </div>
        <PopoverContent
          dir="ltr"
          lang="en"
          side={popoverPlacement.side}
          align={popoverPlacement.align}
          sideOffset={8}
          collisionPadding={DEBUG_MENU_EDGE_OFFSET_PX}
          className={cn(
            'z-[10060] flex max-h-[min(85dvh,var(--radix-popper-available-height,100dvh))] flex-col overflow-hidden rounded-xl border border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-popover p-0 shadow-xl',
            // Neutralize default popover zoom/slide (they grow from the trigger). Fade is unchanged.
            '![--tw-enter-scale:1] ![--tw-exit-scale:1] ![--tw-enter-translate-x:0px] ![--tw-enter-translate-y:0px] ![--tw-exit-translate-x:0px] ![--tw-exit-translate-y:0px]',
            currentSubmenu?.submenuVariant === 'profileComparison' ||
              currentSubmenu?.submenuVariant === 'communityShareExamples' ||
              currentSubmenu?.submenuVariant === 'prefsDebug' ||
              currentSubmenu?.submenuVariant === 'seedResources' ||
              currentSubmenu?.submenuVariant === 'initDayMock' ||
              currentSubmenu?.submenuVariant === 'initTicketMock' ||
              currentSubmenu?.submenuVariant === 'terminalSettings' ||
              currentSubmenu?.submenuVariant === 'recentResources' ||
              currentSubmenu?.submenuVariant === 'envStatus' ||
              currentSubmenu?.submenuVariant === 'faviconStatus' ||
              currentSubmenu?.submenuVariant === 'clientIp'
              ? 'w-[min(92vw,720px)]'
              : 'w-80',
          )}
          onWheelCapture={(event) => {
            event.stopPropagation()
          }}
          onOpenAutoFocus={handleOpenAutoFocus}
          onKeyDown={handleMenuKeyDown}
          onEscapeKeyDown={handleEscapeKeyDown}
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
                <span className="min-w-0 flex-1 text-[13px] font-semibold text-foreground">
                  {currentSubmenu ? currentSubmenu.title : 'Debug'}
                </span>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      onClick={() => {
                        setIsOpen(false)
                        closeDebugMode()
                      }}
                      className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg transition-colors hover:bg-[color-mix(in_srgb,var(--network-globe-edge)_15%,transparent)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--network-globe-edge)]/40"
                      aria-label="Close debug mode"
                    >
                      <X className="h-4 w-4 text-[var(--network-globe-edge)]" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="left">Close debug mode</TooltipContent>
                </Tooltip>
              </div>
            </div>
            {!currentSubmenu || isFeatureFlagsSubmenu ? (
              <div className="px-4 pb-3">
                <div className="relative">
                  <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-[var(--network-globe-edge)]/60" />
                  <Input
                    ref={searchInputRef}
                    role="combobox"
                    aria-expanded
                    aria-controls="debug-menu-listbox"
                    aria-autocomplete="list"
                    aria-activedescendant={
                      highlightedIndex >= 0 && navigableItems[highlightedIndex]
                        ? navigableItems[highlightedIndex].id
                        : undefined
                    }
                    value={
                      isFeatureFlagsSubmenu ? featureFlagsSearch : menuSearch
                    }
                    onChange={(event) => {
                      if (isFeatureFlagsSubmenu) {
                        setFeatureFlagsSearch(event.target.value)
                      } else {
                        setMenuSearch(event.target.value)
                      }
                    }}
                    placeholder={
                      isFeatureFlagsSubmenu
                        ? 'Search flags...'
                        : 'Search menu...'
                    }
                    className="h-8 border-[color-mix(in_srgb,var(--network-globe-edge)_25%,var(--border))] bg-muted/40 ps-8 pe-8 text-[12px] text-foreground placeholder:text-[var(--network-globe-edge)]/50"
                  />
                  {(isFeatureFlagsSubmenu
                    ? featureFlagsSearch
                    : menuSearch) ? (
                    <button
                      type="button"
                      tabIndex={-1}
                      onClick={() => {
                        if (isFeatureFlagsSubmenu) {
                          setFeatureFlagsSearch('')
                        } else {
                          setMenuSearch('')
                        }
                        searchInputRef.current?.focus()
                      }}
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

          <div
            ref={menuListRef}
            id="debug-menu-listbox"
            role="listbox"
            tabIndex={hasSearchField ? -1 : 0}
            aria-label={currentSubmenu ? currentSubmenu.title : 'Debug options'}
            className="min-h-0 flex-1 overflow-y-auto overscroll-contain p-3 outline-none"
          >
            {currentSubmenu ? (
              currentSubmenu.submenuVariant === 'profileComparison' ? (
                <div className="px-1" aria-label={currentSubmenu.title}>
                  <ConsoleProfileComparisonTable activeProfileId={profileId} />
                </div>
              ) : currentSubmenu.submenuVariant === 'communityShareExamples' ? (
                <DebugMenuCommunityShareExamplesPanel
                  activeProfileId={profileId}
                />
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
              ) : currentSubmenu.submenuVariant === 'recentResources' ? (
                <DebugMenuRecentResourcesPanel />
              ) : currentSubmenu.submenuVariant === 'envStatus' ? (
                <DebugMenuEnvPanel />
              ) : currentSubmenu.submenuVariant === 'faviconStatus' ? (
                <DebugMenuFaviconPanel />
              ) : currentSubmenu.submenuVariant === 'clientIp' ? (
                <DebugMenuIpPanel />
              ) : (
                <div className="space-y-0.5">
                  {currentSubmenu.note ? (
                    <p className="mb-2 px-3 text-[11px] leading-relaxed text-[var(--network-globe-edge)]/90">
                      {currentSubmenu.note}
                    </p>
                  ) : null}
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
                          {group.items.map((item, itemIndex) => {
                            const entryId = `flags__${item.category ?? 'general'}__${item.label}`
                            const navIndex = navigableIndexById.get(entryId) ?? -1
                            return renderDebugSubmenuItemRow(
                              item,
                              itemIndex,
                              `feature-flag-${groupIndex}`,
                              activeSubmenu,
                              setActiveSubmenu,
                              {
                                id: entryId,
                                navIndex,
                                highlighted: navIndex === highlightedIndex,
                                onHighlight: () => {
                                  if (navIndex >= 0) setHighlightedIndex(navIndex)
                                },
                              },
                            )
                          })}
                        </div>
                      ))
                    : currentSubmenu.items.map((item, itemIndex) => {
                        const entryId = `submenu__${activeSubmenu}__${item.label}`
                        const navIndex = navigableIndexById.get(entryId) ?? -1
                        return renderDebugSubmenuItemRow(
                          item,
                          itemIndex,
                          'submenu',
                          activeSubmenu,
                          setActiveSubmenu,
                          {
                            id: entryId,
                            navIndex,
                            highlighted: navIndex === highlightedIndex,
                            onHighlight: () => {
                              if (navIndex >= 0) setHighlightedIndex(navIndex)
                            },
                          },
                        )
                      })}
                </div>
              )
            ) : (
              <div className="space-y-5">
                {menuSearch.trim() && filteredSections.length === 0 ? (
                  <p className="px-3 py-2 text-[11px] text-[var(--network-globe-edge)]/70">
                    No matching items
                  </p>
                ) : null}
                {filteredSections.map((section) => (
                  <div key={section.title}>
                    <div className="mb-2 flex items-center gap-2 px-1">
                      <span className="text-[var(--network-globe-edge)]">{section.icon}</span>
                      <span className="text-[11px] font-semibold uppercase tracking-wider text-[var(--network-globe-edge)]/80">
                        {section.title}
                      </span>
                    </div>
                    <div className="space-y-0.5">
                      {section.items.map((item, itemIndex) => {
                        const entryId = `root__${section.title}__${item.label}`
                        const navIndex = navigableIndexById.get(entryId) ?? -1
                        const highlighted = navIndex === highlightedIndex
                        const onHighlight = () => {
                          if (navIndex >= 0) setHighlightedIndex(navIndex)
                        }

                        if (item.variant === 'switch') {
                          return (
                            <DebugMenuSwitchRow
                              key={`${section.title}-${itemIndex}`}
                              item={item}
                              id={entryId}
                              navIndex={navIndex}
                              highlighted={highlighted}
                              onHighlight={onHighlight}
                            />
                          )
                        }

                        const hasSubmenu = menuItemHasSubmenu(item)
                        const itemKey = `${section.title}-${item.label}`

                        return (
                          <button
                            key={`${section.title}-${itemIndex}`}
                            id={entryId}
                            type="button"
                            role="option"
                            aria-selected={highlighted}
                            data-debug-nav-index={navIndex}
                            tabIndex={-1}
                            onMouseEnter={onHighlight}
                            onClick={(e) => {
                              e.stopPropagation()
                              if (hasSubmenu) {
                                setActiveSubmenu(itemKey)
                              } else if (item.onClick) {
                                item.onClick()
                              }
                            }}
                            className={debugMenuItemRowClassName({
                              active: item.active,
                              highlighted,
                            })}
                          >
                            {item.icon && (
                              <span className="flex-shrink-0 text-[var(--network-globe-edge)]">
                                {item.icon}
                              </span>
                            )}
                            <span className="min-w-0 flex-1">
                              <span className="block font-medium">
                                {item.label}
                              </span>
                              {item.description ? (
                                <span
                                  className="mt-0.5 block whitespace-pre-line break-all text-[11px] font-normal opacity-80"
                                  title={item.description}
                                >
                                  {item.description}
                                </span>
                              ) : null}
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
              </div>
            )}
          </div>
        </PopoverContent>
      </Popover>
    </DismissableLayerBranch>
  )
}
