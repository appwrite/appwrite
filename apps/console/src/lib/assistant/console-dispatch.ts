import type { QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  CONSOLE_REFRESH_SCOPE_KEYS,
  isSideEffectConsoleAction,
  normalizeCardId,
  normalizeConsolePath,
  scrollToConsoleCard,
  type ConsoleAction,
  type ConsoleDialog,
  type ConsoleEnvelope,
  type CreateResourceType,
} from '@/lib/assistant/console-protocol'

export type ConsoleProtocolHandlers = {
  setTheme: (theme: 'light' | 'dark' | 'system') => void
  navigate: (opts: {
    path: string
    hash?: string
    replace?: boolean
  }) => void | Promise<unknown>
  openCreate: (resource: CreateResourceType, projectId?: string) => void
  openDialog: (dialog: ConsoleDialog, projectId?: string) => void
  showPane: (content: 'agent' | 'docs' | 'none') => void
  toggleTerminal: () => void
  refreshScopes: (scopes: string[]) => void | Promise<unknown>
}

type NavigateFn = (opts: {
  to: string
  params?: Record<string, string>
  search?: Record<string, unknown>
  replace?: boolean
}) => unknown

export function createConsoleRefreshHandler(
  queryClient: QueryClient,
  projectId?: string | null,
): (scopes: string[]) => Promise<void> {
  return async (scopes) => {
    const unique = [
      ...new Set(scopes.map((s) => s.trim().toLowerCase()).filter(Boolean)),
    ]
    await Promise.all(
      unique.map(async (scope) => {
        const prefixes = CONSOLE_REFRESH_SCOPE_KEYS[scope] ?? [scope]
        for (const prefix of prefixes) {
          if (projectId && (prefix === 'project' || scope === 'project')) {
            await queryClient.refetchQueries({
              queryKey: ['project', projectId],
            })
            continue
          }
          if (projectId) {
            await queryClient.refetchQueries({
              queryKey: [prefix, 'project', projectId],
            })
          }
          await queryClient.refetchQueries({ queryKey: [prefix] })
        }
      }),
    )
  }
}

export function createOpenCreateHandler(
  navigate: NavigateFn,
  defaultProjectId?: string | null,
): (resource: CreateResourceType, projectId?: string) => void {
  return (resource, projectId) => {
    const id = projectId?.trim() || defaultProjectId?.trim()
    if (!id) return

    if (resource === 'database') {
      void navigate({
        to: '/projects/$projectId/databases',
        params: { projectId: id },
        search: { create: 'database' },
      })
      return
    }
    if (resource === 'bucket') {
      void navigate({
        to: '/projects/$projectId/storage',
        params: { projectId: id },
        search: { create: 'bucket' },
      })
      return
    }
    if (resource === 'user') {
      void navigate({
        to: '/projects/$projectId/auth',
        params: { projectId: id },
        search: { create: 'user' },
      })
      return
    }
    if (resource === 'team') {
      void navigate({
        to: '/projects/$projectId/auth',
        params: { projectId: id },
        search: { create: 'team' },
      })
      return
    }
    if (resource === 'function') {
      void navigate({
        to: '/projects/$projectId/functions/create',
        params: { projectId: id },
      })
      return
    }
    if (resource === 'site') {
      void navigate({
        to: '/projects/$projectId/sites/create',
        params: { projectId: id },
      })
    }
  }
}

export function applyConsoleAction(
  action: ConsoleAction,
  handlers: ConsoleProtocolHandlers,
): void {
  switch (action.type) {
    case 'set_theme': {
      if (
        action.theme === 'light' ||
        action.theme === 'dark' ||
        action.theme === 'system'
      ) {
        handlers.setTheme(action.theme)
      }
      return
    }
    case 'navigate': {
      if (typeof action.path !== 'string' || !action.path.startsWith('/')) return
      handlers.navigate({
        path: normalizeConsolePath(action.path),
        hash: action.hash,
        replace: action.replace,
      })
      return
    }
    case 'open_create': {
      handlers.openCreate(action.resource, action.projectId)
      return
    }
    case 'open_dialog': {
      handlers.openDialog(action.dialog, action.projectId)
      return
    }
    case 'toast': {
      const opts = action.description
        ? { description: action.description }
        : undefined
      if (action.level === 'success') toast.success(action.message, opts)
      else if (action.level === 'error') toast.error(action.message, opts)
      else if (action.level === 'warning') toast.warning(action.message, opts)
      else toast.info(action.message, opts)
      return
    }
    case 'show_pane': {
      handlers.showPane(action.content)
      return
    }
    case 'toggle_terminal': {
      handlers.toggleTerminal()
      return
    }
    case 'scroll_to_card': {
      if (typeof action.cardId !== 'string' || !action.cardId.trim()) return
      scrollToConsoleCard(normalizeCardId(action.cardId))
      return
    }
    case 'refresh': {
      if (!Array.isArray(action.scopes) || action.scopes.length === 0) return
      void handlers.refreshScopes(action.scopes)
      return
    }
    case 'resource':
    case 'resource_list':
    case 'chart':
      // Rendered in chat UI — not side-effects.
      return
    default:
      // Unknown action types: skip (forward-compat).
      return
  }
}

export function applyConsoleEnvelope(
  envelope: ConsoleEnvelope,
  handlers: ConsoleProtocolHandlers,
): void {
  for (const action of envelope.actions) {
    if (!action || typeof action !== 'object' || !('type' in action)) continue
    if (!isSideEffectConsoleAction(action as ConsoleAction)) continue
    applyConsoleAction(action as ConsoleAction, handlers)
  }
}
