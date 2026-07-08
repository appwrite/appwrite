/**
 * Command Center registry types.
 *
 * Adding new searchable items to the Command Center is as simple as
 * appending a `CommandEntry` object to one of the per-scope files in
 * `./entries/`. The Command Center UI consumes the registry via
 * `getCommandsForContext(ctx)` and `searchCommands(query, entries)`.
 */

import type { LucideIcon } from 'lucide-react'
import type { ConsoleAccess } from '@/lib/console-roles'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import type { CreateResourceType } from '@/components/global/shared/CommandCenter.types'

/** High-level area of the console that the user is currently in. */
export type CommandScope = 'account' | 'organization' | 'project' | 'docs'

/** Bucket that decides default group label and ordering. */
export type CommandKind =
  | 'navigation' // top-level section in the scope (Auth, Storage, …)
  | 'tab' // tab inside a section (Auth ▸ Templates)
  | 'card' // card/section inside a page (Settings ▸ API endpoint)
  | 'create' // create resource (Create database, Invite member, …)
  | 'action' // ad-hoc action (View logs, Open shortcuts, …)

/** Runtime context passed to entries so they can build URLs / decide visibility. */
export interface CommandContext {
  scope: CommandScope
  projectId?: string | null
  orgId?: string | null
  /** Current route pathname for context-sensitive commands. */
  pathname: string
  features: ConsoleProfileFeatures
  access: ConsoleAccess
  isMobile: boolean
  /** Imperative navigation (history-pushing). */
  navigate: (to: string) => void
  /** Imperative window navigation - useful when leaving the current scope. */
  navigateExternal: (to: string) => void
  closeCommandCenter: () => void
  openShortcutsPage: () => void
  openDocsSearchPage?: () => void
  openFeedbackPage?: () => void
  openSupportPage?: () => void
  /** Optional handlers wired by the parent (e.g. KeyboardShortcutsProvider). */
  handlers: {
    onProjectCreate?: (type: CreateResourceType) => void
    onOrgInviteMember?: () => void
    onOrgCreateProject?: () => void
    onToggleTerminal?: () => void
    onSetTheme?: (theme: 'light' | 'dark' | 'system') => void
    /** Open project Connect dialog on the MCP tab. */
    onOpenConnectMcp?: () => void
  }
}

export interface CommandEntry {
  /** Stable, globally-unique id. Used for React keys + dedupe. */
  id: string
  /** Scopes the entry is available in. */
  scopes: CommandScope[]
  kind: CommandKind
  /** Optional override for the group label (defaults derived from `kind`). */
  group?: string
  label: string
  description?: string
  /** Extra search synonyms (lowercased automatically). */
  keywords?: string[]
  icon?: LucideIcon
  /** Display string e.g. `'G A'` shown as kbd chips. */
  shortcut?: string
  /** Hide the entry entirely (e.g. behind a feature flag). */
  available?: (ctx: CommandContext) => boolean
  /** Show but disabled - pair with `disabledReason`. */
  disabled?: (ctx: CommandContext) => boolean
  disabledReason?: (ctx: CommandContext) => string | undefined
  /**
   * Action to run when the entry is selected. If omitted and `to` is set,
   * the registry will navigate to `to(ctx)`.
   */
  perform?: (ctx: CommandContext) => void
  /**
   * Path to navigate to when the entry is selected. May contain a `#hash`
   * which `useScrollToCard()` uses to scroll the card into view.
   */
  to?: (ctx: CommandContext) => string | null
}

/** Display label for a kind when no `group` override is provided. */
export const DEFAULT_GROUP_LABELS: Record<CommandKind, string> = {
  navigation: 'Navigation',
  tab: 'Tabs',
  card: 'Settings & cards',
  create: 'Create',
  action: 'Actions',
}
