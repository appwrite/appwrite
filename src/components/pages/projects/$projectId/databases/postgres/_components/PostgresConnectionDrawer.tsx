import { Cable, StopCircle, Unplug } from 'lucide-react'
import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import { CodeBlock } from '@/components/global/shared/CodeBlock'
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
import {
  backendTypeBadgeVariant,
  connectionStateBadgeVariant,
  formatPostgresApplicationName,
  formatPostgresBackendTypeLabel,
  formatPostgresClientAddress,
  formatPostgresConnectionDatabase,
  formatPostgresConnectionStateLabel,
  formatPostgresConnectionUsername,
  formatPostgresDurationSince,
  formatPostgresWaitEvent,
  isLongRunningConnection,
  isPostgresClientBackend,
  type PostgresActiveConnectionRow,
} from '@/lib/postgres-metrics'

type PostgresConnectionDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  connection: PostgresActiveConnectionRow | null
  canManageConnections: boolean
  manageDisabledTooltip?: string
  onCancelQuery: (connection: PostgresActiveConnectionRow) => void
  onTerminateConnection: (connection: PostgresActiveConnectionRow) => void
  isCancelPending: boolean
  isTerminatePending: boolean
}

function DetailField({
  label,
  children,
}: {
  label: string
  children: React.ReactNode
}) {
  return (
    <div>
      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <div className="mt-1.5 text-[13px] text-foreground">{children}</div>
    </div>
  )
}

export function PostgresConnectionDrawer({
  open,
  onOpenChange,
  connection,
  canManageConnections,
  manageDisabledTooltip,
  onCancelQuery,
  onTerminateConnection,
  isCancelPending,
  isTerminatePending,
}: PostgresConnectionDrawerProps) {
  if (!connection) return null

  const longRunning = isLongRunningConnection(connection)
  const isClient = isPostgresClientBackend(connection)
  const canCancel = isClient && connection.state?.toLowerCase() === 'active'
  const canTerminate = isClient
  const clientAddress = formatPostgresClientAddress(
    connection.clientHost,
    connection.clientPort,
  )
  const queryDuration = formatPostgresDurationSince(connection.queryStart)
  const connectionAge = formatPostgresDurationSince(connection.backendStart)
  const usernameLabel = formatPostgresConnectionUsername(
    connection.username,
    connection.backendType,
  )
  const databaseLabel = formatPostgresConnectionDatabase(connection.database)
  const applicationLabel = formatPostgresApplicationName(
    connection.applicationName,
  )
  const stateLabel = formatPostgresConnectionStateLabel(
    connection.state,
    connection.backendType,
  )
  const typeLabel = formatPostgresBackendTypeLabel(connection.backendType)

  const renderActionButton = (
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
        disabled={disabled || isCancelPending || isTerminatePending}
        onClick={onClick}
      >
        {icon}
        {label}
      </Button>
    )

    if (!canManageConnections && manageDisabledTooltip) {
      return (
        <TooltipProvider key={label} delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="inline-flex">{button}</span>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="max-w-xs">
              <p className="text-[12px]">{manageDisabledTooltip}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      )
    }

    return button
  }

  const actionButtons = (
    <div className="flex flex-wrap gap-2">
      {canCancel
        ? renderActionButton(
            'Cancel query',
            <StopCircle className="mr-1.5 h-3.5 w-3.5" />,
            () => onCancelQuery(connection),
            !canManageConnections,
          )
        : null}
      {canTerminate
        ? renderActionButton(
            'Terminate connection',
            <Unplug className="mr-1.5 h-3.5 w-3.5" />,
            () => onTerminateConnection(connection),
            !canManageConnections,
          )
        : null}
    </div>
  )

  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      title="Connection details"
      description={`PID ${connection.pid} · ${usernameLabel}`}
      maxWidth="sm:max-w-xl"
      headerLeading={
        <div
          className={cn(
            'flex h-9 w-9 shrink-0 items-center justify-center rounded-md',
            'bg-blue-500/10 text-blue-600 dark:text-blue-400',
          )}
        >
          <Cable className="h-4 w-4" />
        </div>
      }
      headerActions={actionButtons}
    >
      <div className="space-y-6 px-1 pb-2">
        <div className="flex flex-wrap items-center gap-2">
          <Badge
            variant={backendTypeBadgeVariant(connection.backendType)}
            className="shrink-0 text-[10px]"
          >
            {typeLabel}
          </Badge>
          {stateLabel === '—' ? null : (
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
              Long-running
            </Badge>
          ) : null}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <DetailField label="PID">
            <CopyableId
              id={String(connection.pid)}
              variant="inline"
              size="md"
            />
          </DetailField>
          <DetailField label="Type">
            <span>{typeLabel}</span>
          </DetailField>
          <DetailField label="User">
            <span
              className={cn(
                usernameLabel === 'System'
                  ? 'text-muted-foreground'
                  : 'font-medium',
              )}
            >
              {usernameLabel}
            </span>
          </DetailField>
          <DetailField label="Database">
            <span className="font-mono text-[13px]">{databaseLabel}</span>
          </DetailField>
          <DetailField label="Application">
            <span className="text-muted-foreground">{applicationLabel}</span>
          </DetailField>
          <DetailField label="Client">
            <CopyableId
              id={clientAddress}
              displayText={clientAddress}
              variant="inline"
              size="md"
              maxWidth={240}
            />
          </DetailField>
          <DetailField label="Wait event">
            <span className="font-mono text-[12px] text-muted-foreground">
              {formatPostgresWaitEvent(
                connection.waitEventType,
                connection.waitEvent,
              )}
            </span>
          </DetailField>
          <DetailField label="Query duration">
            <span>{queryDuration}</span>
          </DetailField>
          <DetailField label="Connection age">
            <span>{connectionAge}</span>
          </DetailField>
          <DetailField label="Backend start">
            {connection.backendStart ? (
              <DateTooltip date={connection.backendStart} />
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
          <DetailField label="Query start">
            {connection.queryStart ? (
              <DateTooltip date={connection.queryStart} />
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
          <DetailField label="State change">
            {connection.stateChange ? (
              <DateTooltip date={connection.stateChange} />
            ) : (
              <span className="text-muted-foreground">—</span>
            )}
          </DetailField>
        </div>

        <DetailField label="Query">
          {connection.query ? (
            <CodeBlock
              code={connection.query}
              language="sql"
              className="max-h-[240px] overflow-auto text-[12px]"
            />
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
        </DetailField>
      </div>
    </BaseDrawer>
  )
}
