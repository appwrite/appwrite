import { useCallback } from 'react'
import { Cable, Copy, SearchCode, StopCircle, Unplug } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { copyToClipboard } from '@/lib/utils/context-menu'
import {
  backendTypeBadgeVariant,
  connectionStateBadgeVariant,
  formatMysqlApplicationName,
  formatMysqlClientAddress,
  formatMysqlConnectionDatabase,
  formatMysqlConnectionUsername,
  formatMysqlDurationSince,
  formatMysqlWaitEvent,
  isLongRunningConnection,
  isMysqlClientBackend,
  type MysqlActiveConnectionRow,
} from '@/lib/mysql-metrics'
import { useT } from '@/lib/i18n/translate'
import {
  localizeMysqlBackendTypeLabel,
  localizeMysqlConnectionStateLabel,
} from '@/lib/i18n/resource-status-labels'

type MysqlConnectionDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  connection: MysqlActiveConnectionRow | null
  canManageConnections: boolean
  manageDisabledTooltip?: string
  onOpenInSqlEditor: (sql: string) => void
  onCancelQuery: (connection: MysqlActiveConnectionRow) => void
  onTerminateConnection: (connection: MysqlActiveConnectionRow) => void
  isCancelPending: boolean
  isTerminatePending: boolean
}

function DetailSection({
  title,
  children,
  bodyClassName,
}: {
  title: string
  children: React.ReactNode
  bodyClassName?: string
}) {
  return (
    <section className="overflow-hidden rounded-xl border border-border bg-card/40">
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

export function MysqlConnectionDrawer({
  open,
  onOpenChange,
  connection,
  canManageConnections,
  manageDisabledTooltip,
  onOpenInSqlEditor,
  onCancelQuery,
  onTerminateConnection,
  isCancelPending,
  isTerminatePending,
}: MysqlConnectionDrawerProps) {
  const t = useT()
  const handleCopyPid = useCallback(() => {
    if (!connection) return
    void copyToClipboard('PID', String(connection.pid))
  }, [connection])

  if (!connection) return null

  const longRunning = isLongRunningConnection(connection)
  const isClient = isMysqlClientBackend(connection)
  const canCancel = isClient && connection.state?.toLowerCase() === 'active'
  const canTerminate = isClient
  const actionPending = isCancelPending || isTerminatePending
  const query = connection.query?.trim() || null

  const clientAddress = formatMysqlClientAddress(
    connection.clientHost,
    connection.clientPort,
  )
  const queryDuration = formatMysqlDurationSince(connection.queryStart)
  const connectionAge = formatMysqlDurationSince(connection.backendStart)
  const usernameLabel = formatMysqlConnectionUsername(
    connection.username,
    connection.backendType,
  )
  const databaseLabel = formatMysqlConnectionDatabase(connection.database)
  const applicationLabel = formatMysqlApplicationName(
    connection.applicationName,
  )
  const stateLabel = localizeMysqlConnectionStateLabel(
    connection.state,
    connection.backendType,
    t,
  )
  const typeLabel = localizeMysqlBackendTypeLabel(connection.backendType, t)
  const waitEventLabel = formatMysqlWaitEvent(
    connection.waitEventType,
    connection.waitEvent,
  )

  const showActionFooter = isClient && (canCancel || canTerminate)

  const renderManageButton = (
    label: string,
    icon: React.ReactNode,
    onClick: () => void,
    disabled: boolean,
  ) => {
    const button = (
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-9 text-[13px]"
        disabled={disabled || actionPending}
        onClick={onClick}
      >
        {icon}
        {label}
      </Button>
    )

    if (!canManageConnections && manageDisabledTooltip) {
      return (
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex">{button}</span>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs text-[12px]">
              <p>{t(manageDisabledTooltip)}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      )
    }

    return button
  }

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t('Connection details')}
      description={`Details for connection PID ${connection.pid}`}
      maxWidth="sm:max-w-xl"
      side="right"
      contentClassName="overflow-hidden"
      headerActions={
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-8 w-8 shrink-0 p-0"
                onClick={handleCopyPid}
              >
                <Copy className="h-4 w-4" />
                <span className="sr-only">{t('Copy PID')}</span>
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom">{t('Copy PID')}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      }
    >
      <>
        <div className="shrink-0 border-t border-border" />
        <div className="flex min-h-0 flex-1 flex-col">
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="space-y-5 px-6 py-6">
              <div className="rounded-xl border border-border bg-gradient-to-br from-muted/40 via-background to-background p-4">
                <div className="flex flex-wrap items-start gap-4">
                  <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Cable className="h-5 w-5" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-[16px] font-semibold tracking-tight text-foreground">
                        PID {connection.pid}
                      </span>
                      <Badge
                        variant={backendTypeBadgeVariant(connection.backendType)}
                        className="shrink-0 text-[10px]"
                      >
                        {typeLabel}
                      </Badge>
                      {stateLabel === '-' ? null : (
                        <Badge
                          variant={connectionStateBadgeVariant(
                            connection.state,
                            connection.backendType,
                          )}
                          className="shrink-0 text-[10px]"
                        >
                          {stateLabel}
                        </Badge>
                      )}
                      {longRunning ? (
                        <Badge variant="warning" className="shrink-0 text-[10px]">
                          {t('Long-running')}
                        </Badge>
                      ) : null}
                    </div>
                    <p className="mt-1.5 text-[13px] text-muted-foreground">
                      <span
                        className={cn(
                          usernameLabel !== 'System' &&
                            usernameLabel !== '-' &&
                            'font-medium text-foreground',
                        )}
                      >
                        {usernameLabel}
                      </span>
                      {databaseLabel !== '-' ? (
                        <>
                          <span className="mx-1.5 text-border">·</span>
                          <span className="font-mono">{databaseLabel}</span>
                        </>
                      ) : null}
                    </p>
                    <p className="mt-1 font-mono text-[12px] text-muted-foreground">
                      {clientAddress}
                    </p>
                    {connection.backendStart ? (
                      <p className="mt-3 text-[12px] text-muted-foreground">
                        <DateTooltip
                          date={connection.backendStart}
                          showFormattedDate
                          className="text-[12px]"
                        />
                      </p>
                    ) : null}
                  </div>
                </div>
              </div>

              <DetailSection title={t('Session')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label="PID">
                    <CopyableId
                      id={String(connection.pid)}
                      size="xs"
                      maxWidth={220}
                    />
                  </DetailField>
                  <DetailField label={t('Type')}>
                    <Badge
                      variant={backendTypeBadgeVariant(connection.backendType)}
                      className="text-[10px]"
                    >
                      {typeLabel}
                    </Badge>
                  </DetailField>
                  <DetailField label={t('User')}>
                    <p
                      className={cn(
                        'text-[13px]',
                        usernameLabel === 'System' || usernameLabel === '-'
                          ? 'text-muted-foreground'
                          : 'font-medium text-foreground',
                      )}
                    >
                      {usernameLabel}
                    </p>
                  </DetailField>
                  <DetailField label={t('Database')}>
                    <p className="font-mono text-[12px] text-muted-foreground">
                      {databaseLabel}
                    </p>
                  </DetailField>
                  <DetailField label={t('Application')}>
                    <p className="text-[13px] text-muted-foreground">
                      {applicationLabel}
                    </p>
                  </DetailField>
                  <DetailField label={t('Client')}>
                    <CopyableId
                      id={clientAddress}
                      displayText={clientAddress}
                      size="xs"
                      maxWidth={280}
                      className="max-w-full"
                    />
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Activity')}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <DetailField label={t('Connection state')}>
                    {stateLabel === '-' ? (
                      <p className="text-[13px] text-muted-foreground">-</p>
                    ) : (
                      <Badge
                        variant={connectionStateBadgeVariant(
                          connection.state,
                          connection.backendType,
                        )}
                        className="text-[10px]"
                      >
                        {stateLabel}
                      </Badge>
                    )}
                  </DetailField>
                  <DetailField label={t('Wait event')}>
                    <p className="font-mono text-[12px] text-muted-foreground">
                      {waitEventLabel}
                    </p>
                  </DetailField>
                  <DetailField label={t('Query duration')}>
                    <p className="text-[13px] text-foreground">{queryDuration}</p>
                  </DetailField>
                  <DetailField label={t('Connection age')}>
                    <p className="text-[13px] text-foreground">{connectionAge}</p>
                  </DetailField>
                  <DetailField label={t('Backend start')}>
                    {connection.backendStart ? (
                      <DateTooltip date={connection.backendStart} />
                    ) : (
                      <p className="text-[13px] text-muted-foreground">-</p>
                    )}
                  </DetailField>
                  <DetailField label={t('Query start')}>
                    {connection.queryStart ? (
                      <DateTooltip date={connection.queryStart} />
                    ) : (
                      <p className="text-[13px] text-muted-foreground">-</p>
                    )}
                  </DetailField>
                  <DetailField label={t('State change')} className="sm:col-span-2">
                    {connection.stateChange ? (
                      <DateTooltip date={connection.stateChange} />
                    ) : (
                      <p className="text-[13px] text-muted-foreground">-</p>
                    )}
                  </DetailField>
                </div>
              </DetailSection>

              <DetailSection title={t('Query')} bodyClassName="p-0">
                {query ? (
                  <ConnectCodeExample
                    code={query}
                    language="sql"
                    headless
                    actions={
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 shrink-0 gap-1 text-[12px] text-muted-foreground"
                        onClick={() => onOpenInSqlEditor(query)}
                      >
                        <SearchCode className="h-3.5 w-3.5" />
                        {t('Open in SQL editor')}
                      </Button>
                    }
                  />
                ) : (
                  <p className="px-4 py-3 text-[13px] text-muted-foreground">
                    {t('No query running on this connection.')}
                  </p>
                )}
              </DetailSection>
            </div>
          </div>

          {showActionFooter ? (
            <div className="flex shrink-0 flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
              {canCancel
                ? renderManageButton(
                    'Cancel query',
                    <StopCircle className="me-1.5 h-3.5 w-3.5" />,
                    () => onCancelQuery(connection),
                    !canManageConnections,
                  )
                : null}
              {canTerminate
                ? renderManageButton(
                    'Terminate connection',
                    <Unplug className="me-1.5 h-3.5 w-3.5" />,
                    () => onTerminateConnection(connection),
                    !canManageConnections,
                  )
                : null}
            </div>
          ) : null}
        </div>
      </>
    </BaseDrawer>
  )
}
