import { useState } from 'react'
import { Check, Copy, HelpCircle, Loader2, AlertTriangle } from 'lucide-react'
import { usePostgresDatabaseCredentials } from '@/lib/react-query/hooks'
import { maskPostgresConnectionStringPassword } from '@/lib/postgres-connection-string'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Label } from '@/components/ui/label'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { PostgresCopyableField } from './PostgresCopyableField'

type PostgresConnectionCredentialFieldsProps = {
  projectId: string
  databaseId: string
}

const DSN_TOOLTIP =
  'A DSN (Data Source Name) is a single PostgreSQL URI with host, port, database, credentials, and SSL settings. Paste it into ORMs, CLI tools, or any client that accepts a connection string.'

export function PostgresConnectionCredentialFields({
  projectId,
  databaseId,
}: PostgresConnectionCredentialFieldsProps) {
  const [dsnCopied, setDsnCopied] = useState(false)
  const { credentials, isLoading, error } = usePostgresDatabaseCredentials(
    projectId,
    databaseId,
  )

  if (isLoading && !credentials) {
    return (
      <div className="flex items-center gap-2 py-2 text-[13px] text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        Loading credentials…
      </div>
    )
  }

  if (error && !credentials) {
    return (
      <p className="text-[13px] text-destructive">{getErrorMessage(error)}</p>
    )
  }

  if (!credentials) return null

  const databaseName = credentials.database || credentials.tcpDatabase
  const connectionString = credentials.connectionString ?? ''
  const maskedConnectionString = maskPostgresConnectionStringPassword(
    connectionString,
    credentials.password,
  )

  const handleCopyDsn = async () => {
    if (!connectionString) return
    await navigator.clipboard.writeText(connectionString)
    setDsnCopied(true)
    setTimeout(() => setDsnCopied(false), 2000)
  }

  return (
    <div className="grid gap-4">
      <div className="grid gap-4 sm:grid-cols-2">
        <PostgresCopyableField label="Username" value={credentials.username} />
        <PostgresCopyableField
          label="Password"
          value={credentials.password}
          masked
        />
      </div>
      {databaseName ? (
        <PostgresCopyableField label="Database" value={databaseName} />
      ) : null}
      {connectionString ? (
        <div>
          <div className="mb-1.5 flex items-center gap-1.5">
            <Label className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              DSN
            </Label>
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    className="inline-flex items-center justify-center"
                    onClick={(event) => event.stopPropagation()}
                    aria-label="What is a DSN?"
                  >
                    <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
                  </button>
                </TooltipTrigger>
                <TooltipContent side="top" className="max-w-xs">
                  <p className="text-[12px] leading-relaxed">{DSN_TOOLTIP}</p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </div>
          <div className="relative min-h-9 rounded-lg border border-border bg-muted/20">
            <code className="block min-w-0 break-all px-3 py-2.5 pr-10 font-mono text-[12px] leading-relaxed text-foreground">
              {maskedConnectionString}
            </code>
            <button
              type="button"
              onClick={() => void handleCopyDsn()}
              className="absolute right-2 top-1 flex h-7 w-7 items-center justify-center rounded-md transition-colors hover:bg-accent"
              aria-label="Copy DSN"
            >
              {dsnCopied ? (
                <Check className="h-4 w-4 text-emerald-500" />
              ) : (
                <Copy className="h-4 w-4 text-muted-foreground" />
              )}
            </button>
          </div>
          <div className="mt-2 flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 px-2.5 py-2">
            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
            <p className="text-[11px] leading-relaxed text-amber-800/90 dark:text-amber-200/90">
              Treat this URI like a password. Do not commit it to source control
              or share it publicly.
            </p>
          </div>
        </div>
      ) : null}
    </div>
  )
}
