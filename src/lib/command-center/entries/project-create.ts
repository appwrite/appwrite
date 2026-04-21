/**
 * Project-scope create commands.
 *
 * These delegate to `ctx.handlers.onProjectCreate` (wired by
 * `KeyboardShortcutsProvider`) so the right modal/wizard opens. They fall
 * back to a simple navigation when no handler is registered.
 */

import {
  Building2,
  Database,
  Folder,
  Globe,
  UserPlus,
  Zap,
} from 'lucide-react'
import {
  canCreateBucket,
  canCreateDatabase,
  canCreateFunction,
  canCreateSite,
  canCreateTeam,
  canCreateUser,
} from '@/lib/console-access-checks'
import { registerCommands } from '../registry'
import type { CommandEntry, CommandContext } from '../types'
import type { CreateResourceType } from '@/components/global/shared/CommandCenter.types'

function makeCreate(opts: {
  id: string
  label: string
  icon: CommandEntry['icon']
  shortcut?: string
  resourceType: CreateResourceType
  fallbackPath: (projectId: string) => string
  permission: (ctx: CommandContext) => boolean
  permissionMessage: string
  keywords?: string[]
}): CommandEntry {
  return {
    id: opts.id,
    scopes: ['project'],
    kind: 'create',
    label: opts.label,
    icon: opts.icon,
    shortcut: opts.shortcut,
    keywords: ['new', 'add', 'create', ...(opts.keywords ?? [])],
    description: `Create a new ${opts.label.replace(/^Create\s+/i, '').toLowerCase()}`,
    disabled: (ctx) => !opts.permission(ctx),
    disabledReason: (ctx) =>
      opts.permission(ctx) ? undefined : opts.permissionMessage,
    perform: (ctx) => {
      if (!opts.permission(ctx)) return
      ctx.closeCommandCenter()
      if (ctx.handlers.onProjectCreate) {
        ctx.handlers.onProjectCreate(opts.resourceType)
      } else if (ctx.projectId) {
        ctx.navigate(opts.fallbackPath(ctx.projectId))
      }
    },
  }
}

const PROJECT_CREATE: CommandEntry[] = [
  makeCreate({
    id: 'project.create.database',
    label: 'Create database',
    icon: Database,
    shortcut: 'C D',
    resourceType: 'database',
    fallbackPath: (id) => `/projects/${id}/databases`,
    permission: (ctx) => canCreateDatabase(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create databases.",
    keywords: ['db'],
  }),
  makeCreate({
    id: 'project.create.bucket',
    label: 'Create bucket',
    icon: Folder,
    shortcut: 'C B',
    resourceType: 'bucket',
    fallbackPath: (id) => `/projects/${id}/storage`,
    permission: (ctx) => canCreateBucket(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create buckets.",
    keywords: ['storage', 'files'],
  }),
  makeCreate({
    id: 'project.create.function',
    label: 'Create function',
    icon: Zap,
    shortcut: 'C F',
    resourceType: 'function',
    fallbackPath: (id) => `/projects/${id}/functions`,
    permission: (ctx) => canCreateFunction(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create functions.",
    keywords: ['serverless', 'lambda'],
  }),
  makeCreate({
    id: 'project.create.site',
    label: 'Create site',
    icon: Globe,
    shortcut: 'C S',
    resourceType: 'site',
    fallbackPath: (id) => `/projects/${id}/sites`,
    permission: (ctx) => canCreateSite(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create sites.",
    keywords: ['hosting', 'deploy', 'website'],
  }),
  makeCreate({
    id: 'project.create.user',
    label: 'Create user',
    icon: UserPlus,
    shortcut: 'C U',
    resourceType: 'user',
    fallbackPath: (id) => `/projects/${id}/auth`,
    permission: (ctx) => canCreateUser(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create users.",
    keywords: ['account', 'auth'],
  }),
  makeCreate({
    id: 'project.create.team',
    label: 'Create team',
    icon: Building2,
    shortcut: 'C T',
    resourceType: 'team',
    fallbackPath: (id) => `/projects/${id}/auth/teams`,
    permission: (ctx) => canCreateTeam(ctx.access, ctx.features),
    permissionMessage: "You don't have permission to create teams.",
    keywords: ['group', 'organization'],
  }),
]

registerCommands(PROJECT_CREATE)
