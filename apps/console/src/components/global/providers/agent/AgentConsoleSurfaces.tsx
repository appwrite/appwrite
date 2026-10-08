import { useMemo, useState, type ComponentType } from 'react'
import {
  Box,
  Database,
  ExternalLink,
  File,
  Folder,
  Globe,
  HardDrive,
  Mail,
  Phone,
  Search,
  Table2,
  Users,
  Zap,
} from 'lucide-react'
import { Link, useNavigate } from '@tanstack/react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { DatabaseTypeIcon } from '@/components/pages/projects/$projectId/databases/_components/DatabaseTypeIcon'
import type { DatabaseTypeDisplayHints } from '@/lib/databases/database-type-display'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  collectConsoleCtaActions,
  collectRenderableConsoleActions,
  consoleCtaLabel,
  normalizeConsolePath,
  resolveConsoleListHref,
  resolveConsoleResourceHref,
  type ConsoleCtaAction,
  type ConsoleRenderableAction,
  type ConsoleResourceItem,
} from '@/lib/assistant/console-protocol'
import { applyConsoleAction } from '@/lib/assistant/console-dispatch'
import { useConsoleProtocolHandlers } from '@/hooks/use-console-protocol-effects'
import {
  buildTurnView,
  type AssistantMessageLike,
} from '@/lib/assistant/turn-view'
import {
  buildConsoleUrl,
  openInNewTab as openConsoleInNewTab,
} from '@/lib/utils/context-menu'
import { ConsoleChartView } from './AgentConsoleChart'

type ConsoleLinkMode = {
  openInNewTab: boolean
  projectId?: string | null
}

function useConsoleResourceLink(mode: ConsoleLinkMode) {
  const navigate = useNavigate()

  const openPath = (path: string) => {
    const normalized = normalizeConsolePath(path)
    if (!normalized) return
    if (mode.openInNewTab) {
      openConsoleInNewTab(buildConsoleUrl(normalized))
      return
    }
    void navigate({ to: normalized as never }).catch(() => {
      if (typeof window !== 'undefined') window.location.assign(normalized)
    })
  }

  return { openPath, openInNewTab: mode.openInNewTab }
}
const PREFERRED_FIELD_KEYS = [
  'email',
  'phone',
  'status',
  'enabled',
  'verified',
  'emailVerification',
  'phoneVerification',
  'region',
  'type',
  'provider',
  'runtime',
  'framework',
  'platform',
  'size',
  'members',
  'roles',
  '$createdAt',
  'createdAt',
  'joined',
  '$updatedAt',
  'updatedAt',
  'accessedAt',
  'lastActive',
  'expire',
  'expiresAt',
]

const DATE_FIELD_KEY_HINTS = [
  'createdat',
  'updatedat',
  'accessedat',
  'deletedat',
  'expire',
  'expires',
  'expiresat',
  'expiredat',
  'joined',
  'joinedat',
  'lastactive',
  'lastactivity',
  'lastlogin',
  'lastsignedin',
  'publishedat',
  'scheduledat',
  'startedat',
  'endedat',
  'timestamp',
  'date',
  'datetime',
  'time',
]

const ISO_DATE_RE =
  /^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/

function isDateFieldKey(key: string): boolean {
  const normalized = key
    .trim()
    .toLowerCase()
    .replace(/^\$/, '')
    .replace(/[_\s-]+/g, '')
  if (!normalized) return false
  return DATE_FIELD_KEY_HINTS.some((hint) => normalized === hint)
}

/**
 * Parse protocol date values: ISO strings, Date instances, or unix seconds/ms.
 * Returns null when the value is not a recognizable date.
 */
function parseConsoleDateValue(
  value: string | number | boolean | null | undefined,
  options?: { keyHint?: string },
): Date | null {
  if (value === null || value === undefined || value === '' || typeof value === 'boolean') {
    return null
  }

  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value
  }

  if (typeof value === 'number') {
    if (!Number.isFinite(value)) return null
    // Heuristic: 10-digit = seconds, 13-digit = ms.
    const ms = value < 1e12 ? value * 1000 : value
    // Guard against tiny numbers (booleans-as-numbers, counts).
    if (ms < 1e11) return null
    const date = new Date(ms)
    return Number.isNaN(date.getTime()) ? null : date
  }

  const text = String(value).trim()
  if (!text) return null

  // Numeric string timestamps.
  if (/^\d{10,13}$/.test(text)) {
    const numeric = Number(text)
    const ms = text.length <= 10 ? numeric * 1000 : numeric
    const date = new Date(ms)
    return Number.isNaN(date.getTime()) ? null : date
  }

  const keySuggestsDate = options?.keyHint
    ? isDateFieldKey(options.keyHint)
    : false

  if (!keySuggestsDate && !ISO_DATE_RE.test(text)) {
    return null
  }

  const date = new Date(text)
  if (Number.isNaN(date.getTime())) return null

  // Reject absurd years from loose parsing.
  const year = date.getUTCFullYear()
  if (year < 1970 || year > 2100) return null
  return date
}

function MetaValue({
  label,
  value,
}: {
  label: string
  value: string | number | boolean | null | undefined
}) {
  const date = parseConsoleDateValue(value, { keyHint: label })
  return (
    <span className="inline-flex min-w-0 items-center gap-1 shrink-0">
      <span className="text-muted-foreground/70">{label}</span>{' '}
      {date ? (
        <DateTooltip
          date={date}
          className="text-[12px] font-medium text-foreground font-mono"
        />
      ) : (
        <span className="font-medium text-foreground">
          {formatFieldValue(value)}
        </span>
      )}
    </span>
  )
}

const SKIP_AUTO_FIELD_KEYS = new Set([
  'id',
  '$id',
  'resourceid',
  'resource_id',
  'name',
  'title',
])

const PEOPLE_RESOURCE_TYPES = new Set([
  'user',
  'users',
  'team',
  'teams',
  'member',
  'members',
  'membership',
])

type DerivedColumn = { key: string; label: string; from: 'fields' | 'metadata' }

type LucideIcon = ComponentType<{ className?: string }>

const DATABASE_RESOURCE_TYPES = new Set([
  'database',
  'databases',
  'db',
  'tablesdb',
  'documentsdb',
  'vectorsdb',
  'legacy',
  'postgres',
  'postgresql',
  'mysql',
  'mariadb',
  'mongo',
  'mongodb',
  'nativedb',
])

function fieldHint(
  fields: Record<string, unknown> | null | undefined,
  keys: string[],
): string | undefined {
  if (!fields) return undefined
  const lowerKeyMap = new Map(
    Object.entries(fields).map(([key, value]) => [key.toLowerCase(), value]),
  )
  for (const key of keys) {
    const value = lowerKeyMap.get(key.toLowerCase())
    if (typeof value === 'string' && value.trim()) return value.trim()
  }
  return undefined
}

/** Hints for DatabaseTypeIcon when the resource is a database (product or engine). */
function databaseTypeHints(
  resourceType: string,
  fields?: Record<string, unknown> | null,
): DatabaseTypeDisplayHints | null {
  const key = resourceType.trim().toLowerCase()
  if (!DATABASE_RESOURCE_TYPES.has(key) && !key.includes('database')) {
    return null
  }

  const fromFields = fieldHint(fields, ['type', 'dbKind', 'kind', 'apiType'])
  const apiType =
    fromFields ||
    (key !== 'database' && key !== 'databases' && key !== 'db' ? key : undefined)
  const engine = fieldHint(fields, ['engine'])
  const product = fieldHint(fields, ['product', 'api'])

  return { apiType, engine, product }
}

function resourceTypeIcon(resourceType: string): LucideIcon {
  const key = resourceType.trim().toLowerCase()
  if (PEOPLE_RESOURCE_TYPES.has(key)) return Users
  if (DATABASE_RESOURCE_TYPES.has(key) || key.includes('database')) {
    return Database
  }
  if (key.includes('table') || key.includes('collection')) return Table2
  if (key.includes('bucket') || key.includes('storage')) return HardDrive
  if (key.includes('file')) return File
  if (key.includes('folder')) return Folder
  if (key.includes('function')) return Zap
  if (key.includes('site')) return Globe
  return Box
}

function ResourceTypeAvatar({
  resourceType,
  fields,
  name,
}: {
  resourceType: string
  fields?: Record<string, unknown> | null
  name: string
}) {
  const isPerson = PEOPLE_RESOURCE_TYPES.has(resourceType.trim().toLowerCase())
  if (isPerson) {
    return <InitialsAvatar name={name} size="sm" className="shrink-0" />
  }

  const dbHints = databaseTypeHints(resourceType, fields)
  if (dbHints) {
    return (
      <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        <DatabaseTypeIcon {...dbHints} className="h-3.5 w-3.5" />
      </div>
    )
  }

  const Icon = resourceTypeIcon(resourceType)
  return (
    <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
      <Icon className="h-3.5 w-3.5" />
    </div>
  )
}

function mutationLabel(
  mutation: 'create' | 'update' | 'delete',
  t: (text: string) => string,
): string {
  if (mutation === 'create') return t('Created')
  if (mutation === 'update') return t('Updated')
  return t('Deleted')
}

function mutationBadgeVariant(
  mutation: 'create' | 'update' | 'delete',
): 'success' | 'processing' | 'error' {
  if (mutation === 'create') return 'success'
  if (mutation === 'update') return 'processing'
  return 'error'
}

function humanizeKey(key: string): string {
  const spaced = key
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .trim()
  if (!spaced) return key
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

function formatFieldValue(
  value: string | number | boolean | null | undefined,
): string {
  if (value === null || value === undefined || value === '') return '-'
  if (typeof value === 'boolean') return value ? 'true' : 'false'
  return String(value)
}

function metadataKey(label: string): string {
  return label.trim().toLowerCase().replace(/\s+/g, '_')
}

function readField(
  item: ConsoleResourceItem,
  key: string,
): string | number | boolean | null | undefined {
  if (item.fields?.[key] !== undefined) return item.fields[key]
  if (!item.fields) return undefined
  const match = Object.entries(item.fields).find(
    ([k]) => k.toLowerCase() === key.toLowerCase(),
  )
  return match?.[1]
}

function readMetadata(item: ConsoleResourceItem, key: string): string | undefined {
  return item.metadata?.find((entry) => metadataKey(entry.label) === key)?.value
}

function itemMatchesFilter(item: ConsoleResourceItem, query: string): boolean {
  if (!query) return true
  const q = query.toLowerCase()
  if (item.title.toLowerCase().includes(q)) return true
  if (item.subtitle?.toLowerCase().includes(q)) return true
  if (item.resourceId.toLowerCase().includes(q)) return true
  if (item.status?.toLowerCase().includes(q)) return true
  if (
    item.metadata?.some((m) =>
      `${m.label} ${m.value}`.toLowerCase().includes(q),
    )
  ) {
    return true
  }
  if (
    item.fields &&
    Object.values(item.fields).some((v) =>
      String(v ?? '')
        .toLowerCase()
        .includes(q),
    )
  ) {
    return true
  }
  return false
}

function deriveListColumns(
  items: ConsoleResourceItem[],
  explicit?: Array<{ key: string; label: string }>,
): DerivedColumn[] {
  if (explicit?.length) {
    return explicit.map((col) => ({ ...col, from: 'fields' as const }))
  }

  const fieldCounts = new Map<string, number>()
  const metadataCounts = new Map<string, { count: number; label: string }>()
  const hasItemStatus = items.some((row) => !!row.status)

  for (const item of items) {
    if (item.fields) {
      for (const [key, value] of Object.entries(item.fields)) {
        if (value == null || value === '') continue
        const normalized = key.trim()
        if (!normalized || SKIP_AUTO_FIELD_KEYS.has(normalized.toLowerCase())) {
          continue
        }
        if (normalized.toLowerCase() === 'status' && hasItemStatus) continue
        fieldCounts.set(normalized, (fieldCounts.get(normalized) ?? 0) + 1)
      }
    }
    if (item.metadata) {
      for (const entry of item.metadata) {
        const label = entry.label?.trim()
        const value = entry.value?.trim()
        if (!label || !value) continue
        const key = metadataKey(label)
        if (SKIP_AUTO_FIELD_KEYS.has(key)) continue
        if (key === 'status' && hasItemStatus) continue
        if (fieldCounts.has(key) || fieldCounts.has(label)) continue
        const existing = metadataCounts.get(key)
        metadataCounts.set(key, {
          count: (existing?.count ?? 0) + 1,
          label,
        })
      }
    }
  }

  // If nothing typed was provided, promote subtitle into a Contact-style column.
  const hasSubtitle = items.some((item) => !!item.subtitle?.trim())
  if (fieldCounts.size === 0 && metadataCounts.size === 0 && hasSubtitle) {
    return [{ key: '__subtitle', label: 'Details', from: 'metadata' }]
  }

  const fieldKeys = [...fieldCounts.entries()]
    .sort((a, b) => {
      const prefA = PREFERRED_FIELD_KEYS.indexOf(a[0])
      const prefB = PREFERRED_FIELD_KEYS.indexOf(b[0])
      if (prefA !== -1 || prefB !== -1) {
        if (prefA === -1) return 1
        if (prefB === -1) return -1
        if (prefA !== prefB) return prefA - prefB
      }
      if (b[1] !== a[1]) return b[1] - a[1]
      return a[0].localeCompare(b[0])
    })
    .map(([key]) => key)

  const columns: DerivedColumn[] = fieldKeys.slice(0, 4).map((key) => ({
    key,
    label: humanizeKey(key),
    from: 'fields',
  }))

  if (columns.length < 4) {
    const metaKeys = [...metadataCounts.entries()]
      .sort((a, b) => b[1].count - a[1].count)
      .map(([key, meta]) => ({ key, label: meta.label }))
    for (const meta of metaKeys) {
      if (columns.length >= 4) break
      if (columns.some((col) => col.key === meta.key)) continue
      columns.push({ key: meta.key, label: meta.label, from: 'metadata' })
    }
  }

  return columns
}

function cellRawValue(
  item: ConsoleResourceItem,
  column: DerivedColumn,
): string | number | boolean | null | undefined {
  if (column.key === '__subtitle') return item.subtitle ?? null

  if (column.from === 'fields') {
    const direct = readField(item, column.key)
    if (direct !== undefined) return direct
  }

  const meta = readMetadata(item, column.key)
  if (meta !== undefined) return meta

  const fieldFallback = readField(item, column.key)
  if (fieldFallback !== undefined) return fieldFallback

  return null
}

function statusBadgeVariant(
  status: string,
): 'success' | 'warning' | 'error' | 'info' {
  const value = status.trim().toLowerCase()
  if (
    ['verified', 'active', 'enabled', 'ready', 'success', 'completed'].includes(
      value,
    )
  ) {
    return 'success'
  }
  if (
    ['unverified', 'pending', 'processing', 'building', 'disabled'].includes(
      value,
    )
  ) {
    return 'warning'
  }
  if (
    ['blocked', 'failed', 'error', 'deleted', 'expired'].includes(value)
  ) {
    return 'error'
  }
  return 'info'
}

function ColumnCell({
  item,
  column,
}: {
  item: ConsoleResourceItem
  column: DerivedColumn
}) {
  const t = useT()
  const value = cellRawValue(item, column)
  const key = column.key.toLowerCase()

  if (value === null || value === undefined || value === '') {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }

  const parsedDate = parseConsoleDateValue(value, { keyHint: column.key })
  if (parsedDate) {
    return (
      <DateTooltip
        date={parsedDate}
        className="text-[12px] text-muted-foreground font-mono"
      />
    )
  }

  if (key === 'email' || (typeof value === 'string' && value.includes('@'))) {
    return (
      <div className="flex min-w-0 items-center gap-1.5">
        <Mail className="h-3 w-3 shrink-0 text-muted-foreground/60" />
        <span className="truncate text-[12px] font-medium text-foreground">
          {String(value)}
        </span>
      </div>
    )
  }

  if (key === 'phone') {
    return (
      <div className="flex min-w-0 items-center gap-1.5">
        <Phone className="h-3 w-3 shrink-0 text-muted-foreground/60" />
        <span className="truncate text-[12px] font-medium text-foreground">
          {String(value)}
        </span>
      </div>
    )
  }

  if (
    typeof value === 'boolean' ||
    key.includes('verification') ||
    key === 'enabled' ||
    key === 'verified'
  ) {
    const truthy =
      typeof value === 'boolean'
        ? value
        : ['true', '1', 'yes', 'verified', 'enabled'].includes(
            String(value).toLowerCase(),
          )
    const isEnabledKey = key === 'enabled'
    return (
      <Badge
        variant={truthy ? 'success' : 'warning'}
        className="text-[10px] shrink-0"
      >
        {isEnabledKey
          ? truthy
            ? t('Enabled')
            : t('Disabled')
          : truthy
            ? t('Verified')
            : t('Unverified')}
      </Badge>
    )
  }

  if (key === 'status' || key === '__status') {
    const label = String(value)
    return (
      <Badge
        variant={statusBadgeVariant(label)}
        className="text-[10px] shrink-0"
      >
        {label}
      </Badge>
    )
  }

  return (
    <span className="line-clamp-2 break-words text-[13px] text-muted-foreground">
      {formatFieldValue(value)}
    </span>
  )
}

function ResourceNameCell({
  item,
  resourceType,
  href,
  openInNewTab,
}: {
  item: ConsoleResourceItem
  resourceType: string
  href?: string
  openInNewTab?: boolean
}) {
  return (
    <div className="flex min-w-0 items-start gap-3">
      <ResourceTypeAvatar
        resourceType={resourceType}
        fields={item.fields}
        name={item.title || item.subtitle || item.resourceId}
      />
      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 items-center gap-1.5">
          <p className="truncate text-[13px] font-medium text-foreground">
            {item.title}
          </p>
          {item.status ? (
            <Badge
              variant={statusBadgeVariant(item.status)}
              className="text-[10px] shrink-0"
            >
              {item.status}
            </Badge>
          ) : null}
        </div>
        <div className="mt-0.5" onClick={(e) => e.stopPropagation()}>
          <CopyableId id={item.resourceId} size="xs" />
        </div>
      </div>
      {href && openInNewTab ? (
        <ExternalLink
          className="mt-1 h-3.5 w-3.5 shrink-0 text-muted-foreground"
          aria-hidden
        />
      ) : null}
    </div>
  )
}

function ConsoleResourceCardView({
  action,
  linkMode,
}: {
  action: Extract<ConsoleRenderableAction, { type: 'resource' }> & {
    key: string
  }
  linkMode: ConsoleLinkMode
}) {
  const t = useT()
  const { openPath, openInNewTab } = useConsoleResourceLink(linkMode)
  const path = resolveConsoleResourceHref(
    action,
    action.resourceType,
    linkMode.projectId,
  )
  const meta = action.metadata?.filter(
    (entry) => metadataKey(entry.label) !== 'id',
  )
  const fieldEntries = action.fields
    ? Object.entries(action.fields).filter(([key, value]) => {
        if (value == null || value === '') return false
        return !SKIP_AUTO_FIELD_KEYS.has(key.toLowerCase())
      })
    : []

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <div
        role={path ? 'link' : undefined}
        tabIndex={path ? 0 : undefined}
        onClick={path ? () => openPath(path) : undefined}
        onKeyDown={
          path
            ? (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                  e.preventDefault()
                  openPath(path)
                }
              }
            : undefined
        }
        className={cn(
          'flex w-full items-start gap-3 px-4 py-3 text-start transition-colors',
          path ? 'cursor-pointer hover:bg-muted/30' : 'cursor-default',
        )}
      >
        <ResourceTypeAvatar
          resourceType={action.resourceType}
          fields={action.fields}
          name={action.title || action.subtitle || action.resourceId}
        />
        <div className="min-w-0 flex-1">
          <div className="flex min-w-0 flex-wrap items-center gap-1.5">
            <p className="truncate text-[13px] font-medium text-foreground">
              {action.title}
            </p>
            <Badge
              variant={mutationBadgeVariant(action.mutation)}
              className="text-[10px] shrink-0"
            >
              {mutationLabel(action.mutation, t)}
            </Badge>
            {action.status ? (
              <Badge
                variant={statusBadgeVariant(action.status)}
                className="text-[10px] shrink-0"
              >
                {action.status}
              </Badge>
            ) : null}
          </div>
          {action.subtitle ? (
            <p className="mt-0.5 truncate text-[12px] text-muted-foreground">
              {action.subtitle}
            </p>
          ) : null}
          <div
            className="mt-0.5"
            onClick={(e) => e.stopPropagation()}
            onKeyDown={(e) => e.stopPropagation()}
          >
            <CopyableId id={action.resourceId} size="xs" />
          </div>
        </div>
        {path && openInNewTab ? (
          <ExternalLink
            className="mt-1 h-3.5 w-3.5 shrink-0 text-muted-foreground"
            aria-hidden
          />
        ) : null}
      </div>
      {(meta && meta.length > 0) || fieldEntries.length > 0 ? (
        <div className="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 border-t border-border px-4 py-2.5 text-[12px] text-muted-foreground">
          {meta?.map((entry) => (
            <MetaValue
              key={`${entry.label}:${entry.value}`}
              label={entry.label}
              value={entry.value}
            />
          ))}
          {fieldEntries.slice(0, 4).map(([key, value]) => (
            <MetaValue key={key} label={humanizeKey(key)} value={value} />
          ))}
        </div>
      ) : null}
    </div>
  )
}

function ConsoleResourceListView({
  action,
  linkMode,
}: {
  action: Extract<ConsoleRenderableAction, { type: 'resource_list' }> & {
    key: string
  }
  linkMode: ConsoleLinkMode
}) {
  const t = useT()
  const { openPath, openInNewTab } = useConsoleResourceLink(linkMode)
  const [filter, setFilter] = useState('')
  const items = Array.isArray(action.items) ? action.items : []
  const total = action.total ?? items.length
  const projectId = action.projectId || linkMode.projectId
  const filtered = useMemo(
    () => items.filter((item) => itemMatchesFilter(item, filter.trim())),
    [items, filter],
  )
  const columns = useMemo(
    () => deriveListColumns(items, action.columns),
    [action.columns, items],
  )
  const listHref = resolveConsoleListHref(
    action.listHref,
    action.resourceType,
    projectId,
  )
  const nameHeader =
    PEOPLE_RESOURCE_TYPES.has(action.resourceType.trim().toLowerCase())
      ? t('User')
      : t('Name')

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <div className="flex items-center gap-2 border-b border-border px-4 py-3">
        <div className="min-w-0 flex-1">
          <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <h4 className="truncate text-[13px] font-semibold text-foreground">
              {action.title ||
                (total === 1 ? t('1 result') : `${total} ${t('results')}`)}
            </h4>
            {action.title ? (
              <span className="text-[12px] tabular-nums text-muted-foreground">
                {total === 1 ? t('1 result') : `${total} ${t('results')}`}
              </span>
            ) : null}
          </div>
          {action.description ? (
            <p className="mt-1 text-[12px] text-muted-foreground">
              {action.description}
            </p>
          ) : null}
        </div>
        {listHref ? (
          openInNewTab ? (
            <Button
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[12px]"
              asChild
            >
              <a
                href={buildConsoleUrl(listHref)}
                target="_blank"
                rel="noopener noreferrer"
              >
                <ExternalLink className="me-1.5 h-3.5 w-3.5" />
                {t('View all')}
              </a>
            </Button>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-8 shrink-0 text-[12px]"
              asChild
            >
              <Link to={listHref as never}>
                <ExternalLink className="me-1.5 h-3.5 w-3.5" />
                {t('View all')}
              </Link>
            </Button>
          )
        ) : null}
      </div>

      <div className="border-b border-border px-4 py-2.5">
        <div className="relative">
          <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
            placeholder={t('Filter...')}
            className="h-8 ps-8 text-[12px]"
          />
        </div>
      </div>

      {filtered.length === 0 ? (
        <p className="px-4 py-8 text-center text-[13px] text-muted-foreground">
          {items.length === 0
            ? action.emptyMessage || t('No results')
            : t('No results match your filter')}
        </p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {nameHeader}
              </TableHead>
              {columns.map((col) => (
                <TableHead
                  key={col.key}
                  className={cn(
                    'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider',
                    isDateFieldKey(col.key) && 'text-end',
                  )}
                >
                  {col.key === '__subtitle'
                    ? t('Details')
                    : col.key.toLowerCase() === 'email' ||
                        col.key.toLowerCase() === 'phone'
                      ? t('Contact')
                      : col.label}
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {filtered.map((item) => {
              const path = resolveConsoleResourceHref(
                item,
                action.resourceType,
                projectId,
              )
              return (
                <TableRow
                  key={item.resourceId}
                  className={cn(
                    'transition-colors border-b border-border/50',
                    path
                      ? 'cursor-pointer hover:bg-muted/30'
                      : 'hover:bg-transparent',
                  )}
                  onClick={
                    path
                      ? (e) => {
                          const target = e.target as HTMLElement
                          if (
                            target.closest('button') ||
                            target.closest('a')
                          ) {
                            return
                          }
                          openPath(path)
                        }
                      : undefined
                  }
                >
                  <TableCell className="px-4 py-3">
                    <ResourceNameCell
                      item={item}
                      resourceType={action.resourceType}
                      href={path}
                      openInNewTab={openInNewTab}
                    />
                  </TableCell>
                  {columns.map((col) => (
                    <TableCell
                      key={col.key}
                      className={cn(
                        'px-4 py-3',
                        isDateFieldKey(col.key) && 'text-end',
                      )}
                    >
                      <ColumnCell item={item} column={col} />
                    </TableCell>
                  ))}
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}

      {total > items.length ? (
        <div className="border-t border-border px-4 py-3 text-[12px] text-muted-foreground">
          {t('Showing')} {items.length} {t('of')} {total}
          {listHref ? (
            <>
              {' · '}
              {openInNewTab ? (
                <a
                  href={buildConsoleUrl(listHref)}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-foreground underline-offset-2 hover:underline"
                >
                  {t('View all')}
                </a>
              ) : (
                <Link
                  to={listHref as never}
                  className="text-foreground underline-offset-2 hover:underline"
                >
                  {t('View all')}
                </Link>
              )}
            </>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

export function AgentConsoleSurfaces({
  message,
  openInNewTab = false,
  projectId,
  organizationId,
}: {
  message: AssistantMessageLike
  /** Standalone `/agent` page: open resource links in a new browser tab. */
  openInNewTab?: boolean
  projectId?: string | null
  organizationId?: string | null
}) {
  const t = useT()
  const handlers = useConsoleProtocolHandlers({ projectId, organizationId })
  const linkMode = useMemo<ConsoleLinkMode>(
    () => ({ openInNewTab, projectId }),
    [openInNewTab, projectId],
  )
  const { resourceActions, ctaActions } = useMemo(() => {
    const turn = buildTurnView(message)
    const tools = turn.toolOrder
      .map((key) => turn.tools[key])
      .filter(Boolean)
      .map((tool) => ({
        ...tool,
        messageId: turn.messageId,
      }))
    return {
      resourceActions: collectRenderableConsoleActions(tools).filter(
        (action) => {
          if (action.type === 'resource_list') {
            return Array.isArray(action.items) && action.items.length > 0
          }
          if (action.type === 'chart') {
            return Array.isArray(action.metrics) && action.metrics.length > 0
          }
          return true
        },
      ),
      ctaActions: collectConsoleCtaActions(tools),
    }
  }, [message])

  if (resourceActions.length === 0 && ctaActions.length === 0) return null

  return (
    <div className="space-y-3">
      {resourceActions.map((action) => {
        switch (action.type) {
          case 'resource':
            return (
              <ConsoleResourceCardView
                key={action.key}
                action={action}
                linkMode={linkMode}
              />
            )
          case 'chart':
            return (
              <ConsoleChartView
                key={action.key}
                action={action}
                projectId={projectId}
                openInNewTab={openInNewTab}
              />
            )
          case 'resource_list':
            return (
              <ConsoleResourceListView
                key={action.key}
                action={action}
                linkMode={linkMode}
              />
            )
          default:
            return null
        }
      })}
      {ctaActions.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {ctaActions.map((action) => (
            <ConsoleSideEffectCta
              key={action.key}
              action={action}
              label={t(consoleCtaLabel(action))}
              onRun={() => applyConsoleAction(action, handlers)}
            />
          ))}
        </div>
      ) : null}
    </div>
  )
}

function ConsoleSideEffectCta({
  action,
  label,
  onRun,
}: {
  action: ConsoleCtaAction
  label: string
  onRun: () => void
}) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 text-[12px]"
      onClick={onRun}
      data-console-cta={action.type}
    >
      {label}
    </Button>
  )
}
