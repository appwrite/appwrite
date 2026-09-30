import { useState, useCallback } from 'react'
import { Browser, Flag, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { HostnameFaviconIcon } from '@/components/global/shared/HostnameFaviconIcon'
import { cn } from '@/lib/utils'
import { formatIpForDisplay } from '@/lib/format-ip'
import {
  formatActivityEventJson,
  getActivityCountryCode,
  getActivityCountryDisplayName,
  hasHumanEmail,
  isMcpSdkActivity,
  userTypeBadge,
} from '@/components/pages/projects/$projectId/activity/activity-utils'
import { sdk } from '@/lib/appwrite/sdk'
import { useCountryLookups } from '@/lib/react-query/hooks'
import { UserTypeAvatar } from '@/components/pages/projects/$projectId/activity/UserTypeAvatar'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  Activity,
  Database,
  FileText,
  Folder,
  Globe,
  Link2,
  LogIn,
  LogOut,
  Monitor,
  Pencil,
  Plus,
  Server,
  Trash2,
  Upload,
  User,
  Zap,
  ListChecks,
} from '@/lib/icons'
import type { ActivityUiResourceType } from '@/lib/activity-resource-path'
import { useT } from '@/lib/i18n/translate'

type ActionType =
  | 'create'
  | 'update'
  | 'delete'
  | 'execute'
  | 'upload'
  | 'login'
  | 'logout'
  | 'view'

type ResourceType = ActivityUiResourceType

export interface ActivityDrawerDisplay {
  action: ActionType
  resourceType: ResourceType
  resourceName: string
}

interface ActivityLogDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  event: Models.ActivityEvent | null
  display: ActivityDrawerDisplay | null
}

const actionLabels: Record<ActionType, string> = {
  create: 'Created',
  update: 'Updated',
  delete: 'Deleted',
  execute: 'Executed',
  upload: 'Uploaded',
  login: 'Logged in',
  logout: 'Logged out',
  view: 'Viewed',
}

const actionIcons: Record<ActionType, React.ReactNode> = {
  create: <Plus className="h-4 w-4" />,
  update: <Pencil className="h-4 w-4" />,
  delete: <Trash2 className="h-4 w-4" />,
  execute: <Zap className="h-4 w-4" />,
  upload: <Upload className="h-4 w-4" />,
  login: <LogIn className="h-4 w-4" />,
  logout: <LogOut className="h-4 w-4" />,
  view: <Activity className="h-4 w-4" />,
}

const actionColors: Record<ActionType, string> = {
  create: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
  update: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  delete: 'bg-red-500/10 text-red-600 dark:text-red-400',
  execute: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  upload: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  login: 'bg-cyan-500/10 text-cyan-600 dark:text-cyan-400',
  logout: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  view: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
}

const resourceIcons: Record<ResourceType, React.ReactNode> = {
  document: <FileText className="h-4 w-4" />,
  collection: <Folder className="h-4 w-4" />,
  database: <Database className="h-4 w-4" />,
  file: <FileText className="h-4 w-4" />,
  bucket: <Folder className="h-4 w-4" />,
  function: <Zap className="h-4 w-4" />,
  user: <User className="h-4 w-4" />,
  team: <User className="h-4 w-4" />,
  site: <Globe className="h-4 w-4" />,
  rule: <ListChecks className="h-4 w-4" />,
  project: <Server className="h-4 w-4" />,
}

/** Same layout as the activity table Resource column: icon left, id/name, then type. */
function ActivityResourcePrimary({
  resourceType,
  resourceId,
  resourceLabel,
}: {
  resourceType: ResourceType
  resourceId: string | null | undefined
  resourceLabel: string
}) {
  const t = useT()
  const primary = resourceId?.trim() || resourceLabel.trim() || '-'
  return (
    <div className="flex min-w-0 items-center gap-2.5">
      <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
        {resourceIcons[resourceType]}
      </div>
      <div className="min-w-0 flex flex-col gap-1">
        <p className="break-words text-[13px] font-medium leading-snug text-foreground">
          {primary}
        </p>
        <p className="text-[11px] capitalize text-muted-foreground">
          {t(resourceType)}
        </p>
      </div>
    </div>
  )
}

function DetailSection({
  title,
  children,
  bodyClassName,
}: {
  title: string
  children: React.ReactNode
  /** Override default `px-4 py-3` body padding (e.g. `p-0` for flush code blocks). */
  bodyClassName?: string
}) {
  return (
    <section className="rounded-xl border border-border bg-card/40 overflow-hidden">
      <div className="border-b border-border bg-muted/20 px-4 py-2.5">
        <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {title}
        </h3>
      </div>
      <div className={cn('px-4 py-3', bodyClassName)}>{children}</div>
    </section>
  )
}

function DetailField({
  label,
  children,
  className,
}: {
  label: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <p className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div className="mt-1">{children}</div>
    </div>
  )
}

function formatValue(value: string | undefined | null): string {
  const v = value?.trim()
  return v && v.length > 0 ? v : '-'
}

/** Console avatars (flags, browsers): one frame size + chrome everywhere. */
const AVATAR_SERVICE_FRAME =
  'flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/50 bg-muted/30'

const AVATAR_SERVICE_IMG = 'h-full w-full object-contain p-0.5'

const AVATAR_SERVICE_FETCH_PX = 40

function ActivityBrowserIcon({ code }: { code?: string }) {
  const [failed, setFailed] = useState(false)
  const trimmed = code?.trim() ?? ''
  if (!trimmed || failed) {
    return (
      <div className={AVATAR_SERVICE_FRAME} aria-hidden>
        <Monitor className="h-3.5 w-3.5 text-muted-foreground" />
      </div>
    )
  }
  return (
    <div className={AVATAR_SERVICE_FRAME}>
      <img
        src={sdk.forConsole.avatars.getBrowser({
          code: trimmed as Browser,
          width: AVATAR_SERVICE_FETCH_PX,
          height: AVATAR_SERVICE_FETCH_PX,
        })}
        alt=""
        className={AVATAR_SERVICE_IMG}
        onError={() => setFailed(true)}
      />
    </div>
  )
}

export function ActivityLogDrawer({
  open,
  onOpenChange,
  event,
  display,
}: ActivityLogDrawerProps) {
  const t = useT()
  const { lookups: countryLookups } = useCountryLookups()
  const handleCopyActivityPermalink = useCallback(() => {
    const id = event?.$id
    if (!id) return
    const url = new URL(window.location.href)
    url.searchParams.set('event', id)
    void navigator.clipboard.writeText(url.toString()).then(
      () => {
        toast.success(t('Link to this activity copied'))
      },
      () => {
        toast.error(t('Could not copy link'))
      },
    )
  }, [event?.$id, t])

  if (!event || !display) return null

  const badge = userTypeBadge(event.actorType)
  const action = display.action
  const resourceType = display.resourceType

  const secondaryLine = hasHumanEmail(event.actorType)
    ? formatValue(event.actorEmail)
    : formatValue(event.actorId)

  const rawJson = formatActivityEventJson(event)

  const clientSummary = [
    event.clientName,
    event.clientVersion ? `v${event.clientVersion}` : '',
  ]
    .filter(Boolean)
    .join(' ')

  const osSummary = [
    event.osName || event.osCode,
    event.osVersion ? `(${event.osVersion})` : '',
  ]
    .filter(Boolean)
    .join(' ')

  const deviceSummary = [
    event.deviceBrand,
    event.deviceModel || event.deviceName,
  ]
    .filter(Boolean)
    .join(' · ')

  const countryNameLine =
    getActivityCountryDisplayName(event, countryLookups) ?? ''

  const countryCode = getActivityCountryCode(event) ?? ''
  const flagUrl =
    countryCode.length === 2
      ? sdk.forConsole.avatars.getFlag({
          code: countryCode as Flag,
          width: AVATAR_SERVICE_FETCH_PX,
          height: AVATAR_SERVICE_FETCH_PX,
          quality: 100,
        })
      : null

  const flagAlt = countryNameLine
    ? `${countryNameLine} flag`
    : countryCode
      ? `${countryCode.toUpperCase()} flag`
      : t('Location unknown')

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Activity log')}
      description={`${t('Details for activity')} ${event.$id}`}
      maxWidth="sm:max-w-xl"
      side="right"
      headerActions={
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 shrink-0 p-0"
                onClick={handleCopyActivityPermalink}
              >
                <Link2 className="h-4 w-4" />
                <span className="sr-only">{t('Copy link to this activity')}</span>
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">
              {t('Copy link to this activity')}
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      }
    >
      <>
        <div className="border-t border-border shrink-0" />
        <div className="flex flex-1 flex-col min-h-0">
          <div className="flex-1 overflow-y-auto">
            <div className="space-y-5 px-6 py-6">
              {/* Hero summary */}
              <div className="rounded-xl border border-border bg-gradient-to-br from-muted/40 via-background to-background p-4">
                <div className="flex flex-wrap items-start gap-4">
                  <div
                    className={cn(
                      'flex h-12 w-12 shrink-0 items-center justify-center rounded-lg',
                      actionColors[action],
                    )}
                  >
                    {actionIcons[action]}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[16px] font-semibold tracking-tight text-foreground">
                        {t(actionLabels[action])}
                      </span>
                      <span className="rounded-md border border-border bg-background/80 px-2 py-0.5 font-mono text-[11px] text-muted-foreground">
                        {event.event}
                      </span>
                    </div>
                    <div className="mt-1.5">
                      <ActivityResourcePrimary
                        resourceType={resourceType}
                        resourceId={event.resourceId}
                        resourceLabel={display.resourceName}
                      />
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                      <span
                        className={cn(
                          'rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider',
                          badge.tone,
                        )}
                      >
                        {t(badge.label)}
                      </span>
                      <span className="text-[12px] text-muted-foreground">
                        <DateTooltip
                          date={event.time}
                          showFormattedDate
                          className="text-[12px]"
                        />
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <DetailSection title={t('Actor')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('Name')}>
                    <div className="flex items-center gap-3">
                      <UserTypeAvatar
                        actorType={event.actorType}
                        actorName={
                          event.actorName?.trim() ||
                          event.actorEmail?.trim() ||
                          t('Unknown')
                        }
                      />
                      <div className="flex min-w-0 items-center gap-1.5">
                        <p className="min-w-0 truncate text-[13px] font-medium text-foreground">
                          {formatValue(event.actorName)}
                        </p>
                        {isMcpSdkActivity(event) ? (
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span
                                className="inline-flex shrink-0 text-muted-foreground"
                                aria-label={t('Via MCP')}
                              >
                                <McpIcon className="h-3.5 w-3.5" />
                              </span>
                            </TooltipTrigger>
                            <TooltipContent side="top">
                              {t('Via MCP')}
                            </TooltipContent>
                          </Tooltip>
                        ) : null}
                      </div>
                    </div>
                  </DetailField>
                  <DetailField
                    label={hasHumanEmail(event.actorType) ? t('Email') : t('Actor ID')}
                  >
                    <p
                      className={cn(
                        'truncate text-[13px] text-muted-foreground',
                        !hasHumanEmail(event.actorType) && 'font-mono',
                      )}
                    >
                      {secondaryLine}
                    </p>
                  </DetailField>
                  <DetailField label={t('Actor type')}>
                    <span
                      className={cn(
                        'inline-flex rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider',
                        badge.tone,
                      )}
                    >
                      {formatValue(event.actorType)}
                    </span>
                  </DetailField>
                  <DetailField label={t('Actor ID')}>
                    {event.actorId?.trim() ? (
                      <CopyableId id={event.actorId} size="xs" maxWidth={220} />
                    ) : (
                      <p className="font-mono text-[12px] text-muted-foreground">
                        -
                      </p>
                    )}
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Resource')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('Resource')} className="sm:col-span-2">
                    <ActivityResourcePrimary
                      resourceType={resourceType}
                      resourceId={event.resourceId}
                      resourceLabel={display.resourceName}
                    />
                  </DetailField>
                  {event.resourceType?.trim() ? (
                    <DetailField
                      label={t('Resource type (API)')}
                      className="sm:col-span-2"
                    >
                      <p className="font-mono text-[12px] text-muted-foreground">
                        {event.resourceType}
                      </p>
                    </DetailField>
                  ) : null}
                  <DetailField label={t('Resource path')} className="sm:col-span-2">
                    <p className="break-all rounded-md border border-border bg-muted/30 px-2.5 py-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                      {formatValue(event.resource)}
                    </p>
                  </DetailField>
                  <DetailField label={t('Resource parent')} className="sm:col-span-2">
                    <p className="break-all rounded-md border border-border bg-muted/30 px-2.5 py-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                      {formatValue(event.resourceParent)}
                    </p>
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Request context')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('IP address')}>
                    {event.ip?.trim() ? (
                      <CopyableId
                        id={event.ip.trim()}
                        displayText={
                          formatIpForDisplay(event.ip.trim(), 44) ??
                          event.ip.trim()
                        }
                        size="md"
                        maxWidth={360}
                        className="max-w-full"
                      />
                    ) : (
                      <p className="font-mono text-[12px] text-foreground">-</p>
                    )}
                  </DetailField>
                  <DetailField label={t('Hostname')}>
                    <div className="flex items-center gap-2">
                      <HostnameFaviconIcon hostname={event.hostname} size="md" />
                      <p className="min-w-0 break-all font-mono text-[12px] text-muted-foreground">
                        {formatValue(event.hostname)}
                      </p>
                    </div>
                  </DetailField>
                  <DetailField label={t('User agent')} className="sm:col-span-2">
                    <p className="break-all rounded-md border border-border bg-muted/30 px-2.5 py-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
                      {formatValue(event.userAgent)}
                    </p>
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Client & device')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('Client')}>
                    <div className="flex items-start gap-2">
                      <ActivityBrowserIcon code={event.clientCode} />
                      <div className="min-w-0">
                        <p className="text-[13px] text-foreground">
                          {clientSummary || '-'}
                        </p>
                        <p className="mt-0.5 font-mono text-[11px] text-muted-foreground">
                          {event.clientType || event.clientCode
                            ? [event.clientType, event.clientCode]
                                .filter(Boolean)
                                .join(' · ')
                            : '-'}
                        </p>
                      </div>
                    </div>
                  </DetailField>
                  <DetailField label={t('Engine')}>
                    <p className="font-mono text-[12px] text-muted-foreground">
                      {[event.clientEngine, event.clientEngineVersion]
                        .filter((s) => s?.trim())
                        .join(' ') || '-'}
                    </p>
                  </DetailField>
                  <DetailField label={t('Operating system')}>
                    <p className="text-[13px] text-foreground">{osSummary || '-'}</p>
                  </DetailField>
                  <DetailField label={t('Device')}>
                    <p className="text-[13px] text-foreground">
                      {deviceSummary || '-'}
                    </p>
                  </DetailField>
                  <DetailField label={t('Location')} className="sm:col-span-2">
                    <div className="flex items-start gap-2.5">
                      {flagUrl ? (
                        <div
                          className={AVATAR_SERVICE_FRAME}
                          role="img"
                          aria-label={flagAlt}
                        >
                          <img
                            src={flagUrl}
                            alt=""
                            className={AVATAR_SERVICE_IMG}
                          />
                        </div>
                      ) : (
                        <div className={AVATAR_SERVICE_FRAME} aria-hidden>
                          <Globe className="h-3.5 w-3.5 text-muted-foreground" />
                        </div>
                      )}
                      <div className="min-w-0 flex flex-col gap-0.5">
                        <p className="text-[13px] text-foreground">
                          {countryNameLine || '-'}
                        </p>
                      </div>
                    </div>
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Scope')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('Project ID')}>
                    <CopyableId id={event.projectId} size="xs" maxWidth={220} />
                  </DetailField>
                  <DetailField label={t('Team ID')}>
                    <CopyableId id={event.teamId} size="xs" maxWidth={220} />
                  </DetailField>
                  <DetailField label={t('Event ID')} className="sm:col-span-2">
                    <CopyableId id={event.$id} size="xs" maxWidth={280} />
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Raw payload')} bodyClassName="p-0">
                <CodeBlock
                  code={rawJson}
                  language="json"
                  variant="headless"
                  copyInside
                />
              </DetailSection>
            </div>
          </div>
        </div>
      </>
    </BaseDrawer>
  )
}
