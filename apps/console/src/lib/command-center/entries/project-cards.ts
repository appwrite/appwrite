/**
 * Project-scope card entries - searchable cards / sections inside pages.
 *
 * Each entry navigates to a path with a `#card-<id>` hash. The
 * `useScrollToCard()` hook (mounted by the page that owns the card)
 * scrolls the matching `[data-card-id="<id>"]` element into view and
 * briefly highlights it.
 *
 * Cards are search-only - they don't appear in the default landing list.
 *
 * To make a new card searchable:
 *   1. Add `data-card-id="my-card"` to the wrapping element on the page.
 *   2. Append a `CommandEntry` here with `to: () => '/projects/{id}/...#card-my-card'`.
 */

import { AlertOctagon, ArrowRightLeft, KeyRound, Settings, Tag } from 'lucide-react'
import { canShowProjectSettings } from '@/lib/console-access-checks'
import { registerCommands } from '../registry'
import type { CommandEntry } from '../types'

const PROJECT_CARDS: CommandEntry[] = [
  {
    id: 'project.card.settings.name',
    scopes: ['project'],
    kind: 'card',
    group: 'Settings',
    label: 'Settings · Project name',
    description: 'Rename your project',
    icon: Tag,
    keywords: ['rename', 'name', 'project'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings#card-project-name`,
  },
  {
    id: 'project.card.settings.api-credentials',
    scopes: ['project'],
    kind: 'card',
    group: 'Settings',
    label: 'Settings · API credentials',
    description: 'Project ID and API endpoint for SDKs',
    icon: KeyRound,
    keywords: ['api', 'endpoint', 'project id', 'credentials', 'connection', 'sdk'],
    to: (ctx) => `/projects/${ctx.projectId}/settings#card-api-credentials`,
  },
  {
    id: 'project.card.settings.services',
    scopes: ['project'],
    kind: 'card',
    group: 'Settings',
    label: 'Settings · Services',
    description: 'Enable or disable Appwrite services for this project',
    icon: Settings,
    keywords: ['services', 'enable', 'disable', 'modules', 'features', 'toggle'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings#card-services`,
  },
  {
    id: 'project.card.settings.transfer',
    scopes: ['project'],
    kind: 'card',
    group: 'Settings',
    label: 'Settings · Transfer project',
    description: 'Move this project to a different organization',
    icon: ArrowRightLeft,
    keywords: ['transfer', 'move', 'organization', 'ownership'],
    available: (ctx) =>
      Boolean(ctx.features.multiTenancy) &&
      canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings#card-transfer-project`,
  },
  {
    id: 'project.card.settings.delete',
    scopes: ['project'],
    kind: 'card',
    group: 'Settings',
    label: 'Settings · Delete project',
    description: 'Permanently delete this project and all its data',
    icon: AlertOctagon,
    keywords: ['delete', 'remove', 'destroy', 'danger'],
    available: (ctx) => canShowProjectSettings(ctx.access, ctx.features),
    to: (ctx) => `/projects/${ctx.projectId}/settings#card-delete-project`,
  },
]

registerCommands(PROJECT_CARDS)
