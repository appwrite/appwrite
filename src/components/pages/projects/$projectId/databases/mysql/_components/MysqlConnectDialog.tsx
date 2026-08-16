import { useEffect, useMemo, useState } from 'react'
import {
  AlertCircle,
  AlertTriangle,
  ExternalLink,
  HelpCircle,
  RefreshCw,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  useMysqlDatabase,
  useMysqlDatabaseCredentials,
  useMysqlDatabasePooler,
  useResetMysqlDatabaseCredentials,
} from '@/lib/react-query/hooks'
import {
  buildMysqlConnectSnippetDisplayCode,
  buildMysqlConnectSnippets,
  buildMysqlConnectionEndpointInfo,
  buildMysqlCopyAllText,
  buildMysqlDrizzleSnippetPartDisplayCode,
  buildMysqlCliSnippetPartDisplayCode,
  MYSQL_CONNECT_TABS,
  MYSQL_CONNECT_SNIPPET_TABS,
  type MysqlConnectSnippetContext,
  type MysqlConnectTab,
  type MysqlConnectionEndpointInfo,
  type MysqlDrizzleSnippetPart,
  type MysqlCliSnippetPart,
} from '@/lib/mysql-connect-snippets'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { maskMysqlConnectionStringPassword } from '@/lib/mysql-connection-string'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { ConnectCodePanel } from '@/components/global/shared/ConnectCodeExample'
import { CodeSnippetCopyButton } from '@/components/global/shared/CodeSnippetCopyButton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent } from '@/components/ui/tabs'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { DedicatedDatabaseCredentials } from '@/lib/databases/dedicated-engine'
import { MysqlCopyableField } from './MysqlCopyableField'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useDatabaseAdminOperationsAccess } from '../../_components/DatabaseOperationsLockContext'
import { useT } from '@/lib/i18n/translate'

const MYSQL_DOCS_URL = '/docs/products/databases'
const CONNECT_TAB_CONTENT_HEIGHT = 'h-[400px]'
const DRIZZLE_SNIPPET_FILES: {
  id: MysqlDrizzleSnippetPart
  label: string
  language: CodeBlockLanguage
}[] = [
  { id: 'env', label: '.env', language: 'env' },
  { id: 'db', label: 'index.ts', language: 'typescript' },
  { id: 'config', label: 'drizzle.config.ts', language: 'typescript' },
]
const MYSQL_CLI_SNIPPET_FILES: {
  id: MysqlCliSnippetPart
  label: string
}[] = [
  { id: 'uri', label: 'Connection string' },
  { id: 'flags', label: 'Host flags' },
  { id: 'commands', label: 'Commands' },
]
const DSN_TOOLTIP =
  'A DSN (Data Source Name) is a single MySQL URI with host, port, database, credentials, and SSL settings. Paste it into ORMs, CLI tools, or any client that accepts a connection string.'
const MYSQL_CLI_TOOLTIP =
  'Install the mysql client (MySQL Community Server, MariaDB client, or your package manager). Paste a connect command into your terminal, enter your password when prompted, then run SQL statements ending with a semicolon.'

type MysqlConnectDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  databaseId: string
}

function ConnectDialogSkeleton() {
  return (
    <div className={cn('space-y-4', CONNECT_TAB_CONTENT_HEIGHT)}>
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
      <Skeleton className="h-9 w-full" />
    </div>
  )
}

async function copyText(successMessage: string, value: string, errorMessage: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success(successMessage)
  } catch {
    toast.error(errorMessage)
  }
}

type MysqlConnectDetailsProps = {
  database: NonNullable<ReturnType<typeof useMysqlDatabase>['database']>
  credentials: DedicatedDatabaseCredentials
  endpointInfo: MysqlConnectionEndpointInfo
  snippets: ReturnType<typeof buildMysqlConnectSnippets> | null
  canResetPassword: boolean
  resetPasswordDisabledTooltip?: string
  resetPasswordPending: boolean
  onResetPassword: () => void
}

function MysqlConnectDetails({
  database,
  credentials,
  endpointInfo,
  snippets,
  canResetPassword,
  resetPasswordDisabledTooltip,
  resetPasswordPending,
  onResetPassword,
}: MysqlConnectDetailsProps) {
  const t = useT()

  const databaseName =
    credentials.database || credentials.tcpDatabase || undefined

  const handleCopyAll = async () => {
    await copyText(
      t('Credentials copied'),
      buildMysqlCopyAllText(credentials, endpointInfo),
      t('Failed to copy'),
    )
  }

  const handleCopyEnv = async () => {
    if (!snippets?.env) return
    await copyText(t('.env entry copied'), snippets.env, t('Failed to copy'))
  }

  return (
    <div className="-m-1 flex h-full flex-col gap-4 overflow-y-auto p-1">
      <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_6.5rem]">
        <MysqlCopyableField
          label={t('Host')}
          value={credentials.host || database.hostname || ''}
        />
        <MysqlCopyableField
          label={t('Port')}
          value={
            credentials.port
              ? String(credentials.port)
              : database.connectionPort
                ? String(database.connectionPort)
                : ''
          }
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <MysqlCopyableField
          label={t('Username')}
          value={credentials.username}
        />
        <MysqlCopyableField
          label={t('Password')}
          value={credentials.password}
          masked
          labelAction={
            <button
              type="button"
              className="shrink-0 text-[11px] font-medium text-muted-foreground transition-colors hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
              disabled={!canResetPassword || resetPasswordPending}
              title={resetPasswordDisabledTooltip}
              onClick={onResetPassword}
            >
              {t('Reset password')}
            </button>
          }
        />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {databaseName ? (
          <MysqlCopyableField label={t('Database')} value={databaseName} />
        ) : null}
        <MysqlCopyableField
          label={t('SSL')}
          value={endpointInfo.sslLabel}
          mono={false}
        />
      </div>
      {endpointInfo.poolerEnabled ? (
        <p className="text-[12px] text-muted-foreground">
          {t('Host and port use the pooler endpoint.')}
        </p>
      ) : null}
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => void handleCopyEnv()}
          disabled={!snippets?.env}
        >
          {t('Copy .env')}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => void handleCopyAll()}
        >
          {t('Copy all')}
        </Button>
      </div>
    </div>
  )
}

type MysqlConnectDsnProps = {
  credentials: DedicatedDatabaseCredentials
}

function MysqlConnectDsn({ credentials }: MysqlConnectDsnProps) {
  const t = useT()
  const maskedConnectionString = credentials.connectionString
    ? maskMysqlConnectionStringPassword(
        credentials.connectionString,
        credentials.password,
      )
    : ''

  if (!credentials.connectionString) {
    return (
      <div className="flex h-full items-center justify-center text-[13px] text-muted-foreground">
        {t('Connection string unavailable.')}
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col gap-4">
      <div className="flex items-center gap-1.5">
        <p className="text-[13px] text-muted-foreground">
          {t('MySQL connection URI for clients that accept a single connection string.')}
        </p>
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="inline-flex shrink-0 items-center justify-center"
                onClick={(event) => event.stopPropagation()}
                aria-label={t('What is a DSN?')}
              >
                <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs">
              <p className="text-[12px] leading-relaxed">{t(DSN_TOOLTIP)}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border">
        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2">
          <span className="text-[12px] font-medium text-muted-foreground">
            DSN
          </span>
          <CodeSnippetCopyButton content={credentials.connectionString} />
        </div>
        <ConnectCodePanel
          code={maskedConnectionString}
          language="plaintext"
          headless
          fixedHeight="100%"
          wrapLines
          className="min-h-0 flex-1"
        />
      </div>

      <div className="flex shrink-0 items-start gap-2 rounded-md border border-amber-500/30 bg-amber-500/5 px-2.5 py-2">
        <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
        <p className="text-[11px] leading-relaxed text-amber-800/90 dark:text-amber-200/90">
          {t('Treat this URI like a password. Do not commit it to source control or share it publicly.')}
        </p>
      </div>
    </div>
  )
}

type MysqlConnectDrizzleProps = {
  context: MysqlConnectSnippetContext
  copyContent: string
}

function MysqlConnectDrizzle({
  context,
  copyContent,
}: MysqlConnectDrizzleProps) {
  const [activeFile, setActiveFile] =
    useState<MysqlDrizzleSnippetPart>('env')

  const activeFileConfig =
    DRIZZLE_SNIPPET_FILES.find((file) => file.id === activeFile) ??
    DRIZZLE_SNIPPET_FILES[0]
  const displayCode = buildMysqlDrizzleSnippetPartDisplayCode(
    activeFile,
    context,
  )

  return (
    <div className="flex h-full flex-col overflow-hidden rounded-xl border border-border">
      <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2">
        <div className="flex min-w-0 flex-1 flex-wrap gap-1.5">
          {DRIZZLE_SNIPPET_FILES.map((file) => (
            <button
              key={file.id}
              type="button"
              onClick={() => setActiveFile(file.id)}
              className={cn(
                'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                activeFile === file.id
                  ? 'bg-muted text-foreground'
                  : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground',
              )}
            >
              {file.label}
            </button>
          ))}
        </div>
        <CodeSnippetCopyButton content={copyContent} />
      </div>
      <ConnectCodePanel
        code={displayCode}
        language={activeFileConfig.language}
        headless
        fixedHeight="100%"
        wrapLines
        className="min-h-0 flex-1"
      />
    </div>
  )
}

type MysqlConnectCliProps = {
  context: MysqlConnectSnippetContext
  copyContent: string
}

function MysqlConnectCli({
  context,
  copyContent,
}: MysqlConnectCliProps) {
  const t = useT()
  const [activeSection, setActiveSection] =
    useState<MysqlCliSnippetPart>('uri')
  const displayCode = buildMysqlCliSnippetPartDisplayCode(
    activeSection,
    context,
  )

  return (
    <div className="flex h-full flex-col gap-3">
      <div className="flex shrink-0 items-start gap-1.5">
        <p className="text-[13px] leading-relaxed text-muted-foreground">
          {t('mysql is the official MySQL command-line client. Use it to run SQL, inspect schemas, and explore your database from the terminal.')}
        </p>
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="inline-flex shrink-0 items-center justify-center"
                onClick={(event) => event.stopPropagation()}
                aria-label={t('What is mysql?')}
              >
                <HelpCircle className="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" />
              </button>
            </TooltipTrigger>
            <TooltipContent side="top" className="max-w-xs">
              <p className="text-[12px] leading-relaxed">{t(MYSQL_CLI_TOOLTIP)}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-border">
        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2">
          <div className="flex min-w-0 flex-1 flex-wrap gap-1.5">
            {MYSQL_CLI_SNIPPET_FILES.map((file) => (
              <button
                key={file.id}
                type="button"
                onClick={() => setActiveSection(file.id)}
                className={cn(
                  'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                  activeSection === file.id
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground',
                )}
              >
                {t(file.label)}
              </button>
            ))}
          </div>
          <CodeSnippetCopyButton content={copyContent} />
        </div>
        <ConnectCodePanel
          code={displayCode}
          language="bash"
          headless
          fixedHeight="100%"
          wrapLines
          className="min-h-0 flex-1"
        />
      </div>

      <p className="shrink-0 text-[11px] leading-relaxed text-muted-foreground">
        After connecting, mysql shows a{' '}
        <code className="rounded bg-muted px-1 py-0.5 font-mono text-[10px]">
          mysql&gt;
        </code>{' '}
        prompt. Type SQL ending with a semicolon, or run{' '}
        <code className="rounded bg-muted px-1 py-0.5 font-mono text-[10px]">
          SHOW TABLES;
        </code>{' '}
        to list tables.
      </p>
    </div>
  )
}

export function MysqlConnectDialog({
  open,
  onOpenChange,
  projectId,
  databaseId,
}: MysqlConnectDialogProps) {
  const t = useT()
  const [methodTab, setMethodTab] = useState<MysqlConnectTab>('details')
  const [resetPasswordOpen, setResetPasswordOpen] = useState(false)
  const {
    canWrite: canResetPassword,
    writeTooltip: resetPasswordDisabledTooltip,
  } = useDatabaseAdminOperationsAccess({
    permissionDeniedTooltip: t(
      "You don't have permission to reset the database password.",
    ),
  })

  const { database, isLoading: databaseLoading } = useMysqlDatabase(
    projectId,
    databaseId,
  )
  const {
    credentials,
    isLoading: credentialsLoading,
    error: credentialsError,
    refetch: refetchCredentials,
  } = useMysqlDatabaseCredentials(projectId, databaseId)
  const { pooler } = useMysqlDatabasePooler(projectId, databaseId)
  const resetPasswordMutation = useResetMysqlDatabaseCredentials(
    projectId,
    databaseId,
  )

  const isLoading =
    (databaseLoading && !database) ||
    (credentialsLoading && !credentials)

  const credentialsErrorMessage = credentialsError
    ? getErrorMessage(credentialsError)
    : null

  const endpointInfo = useMemo(
    () =>
      credentials
        ? buildMysqlConnectionEndpointInfo(credentials, pooler)
        : null,
    [credentials, pooler],
  )

  const snippets = useMemo(
    () =>
      credentials && endpointInfo
        ? buildMysqlConnectSnippets({ credentials, endpointInfo })
        : null,
    [credentials, endpointInfo],
  )

  useEffect(() => {
    if (open) return
    setMethodTab('details')
    setResetPasswordOpen(false)
  }, [open])

  const handleResetPassword = () => {
    resetPasswordMutation.mutate(undefined, {
      onSuccess: () => {
        setResetPasswordOpen(false)
        toast.success(t('Database password reset'))
      },
      onError: (error) => {
        toast.error(
          getErrorMessage(error, t('Failed to reset database password')),
        )
      },
    })
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[min(90dvh,800px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Credentials')}</DialogTitle>
          <DialogDescription className="mt-2 text-[13px]">
            {t('Credentials and connection strings for external clients, ORMs, and CLI tools.')}
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="shrink-0 px-6 py-4">
            <ConnectDialogSkeleton />
          </div>
        ) : credentialsErrorMessage && !credentials ? (
          <div className="shrink-0 px-6 py-4">
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertTitle>{t('Failed to load credentials')}</AlertTitle>
              <AlertDescription className="text-[13px]">
                {credentialsErrorMessage}
              </AlertDescription>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-4 h-9 gap-1.5 text-[13px]"
                onClick={() => void refetchCredentials()}
              >
                <RefreshCw className="h-3.5 w-3.5 shrink-0" />
                {t('Retry')}
              </Button>
            </Alert>
          </div>
        ) : credentials && endpointInfo && database && snippets ? (
          <Tabs
            value={methodTab}
            onValueChange={(value) =>
              setMethodTab(value as MysqlConnectTab)
            }
            className="flex shrink-0 flex-col"
          >
            <div
              className="shrink-0 flex gap-0 overflow-x-auto border-b border-border px-6"
              role="tablist"
            >
              {MYSQL_CONNECT_TABS.map((tab) => {
                const isActive = methodTab === tab.id
                return (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    aria-selected={isActive}
                    onClick={() => setMethodTab(tab.id)}
                    className={cn(
                      'relative flex shrink-0 cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                      isActive
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground/80',
                    )}
                  >
                    {t(tab.label)}
                    {isActive ? (
                      <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
                    ) : null}
                  </button>
                )
              })}
            </div>

            <div className="shrink-0 px-6 py-4">
              <TabsContent
                value="details"
                className={cn('mt-0 data-[state=inactive]:hidden', CONNECT_TAB_CONTENT_HEIGHT)}
              >
                <MysqlConnectDetails
                  database={database}
                  credentials={credentials}
                  endpointInfo={endpointInfo}
                  snippets={snippets}
                  canResetPassword={canResetPassword}
                  resetPasswordDisabledTooltip={resetPasswordDisabledTooltip}
                  resetPasswordPending={resetPasswordMutation.isPending}
                  onResetPassword={() => setResetPasswordOpen(true)}
                />
              </TabsContent>

              <TabsContent
                value="dsn"
                className={cn('mt-0 data-[state=inactive]:hidden', CONNECT_TAB_CONTENT_HEIGHT)}
              >
                <MysqlConnectDsn credentials={credentials} />
              </TabsContent>

              {MYSQL_CONNECT_SNIPPET_TABS.map((tab) => {
                if (tab.id === 'drizzle') {
                  return (
                    <TabsContent
                      key={tab.id}
                      value={tab.id}
                      className={cn(
                        'mt-0 data-[state=inactive]:hidden',
                        CONNECT_TAB_CONTENT_HEIGHT,
                      )}
                    >
                      <MysqlConnectDrizzle
                        context={{ credentials, endpointInfo }}
                        copyContent={snippets.drizzle}
                      />
                    </TabsContent>
                  )
                }

                if (tab.id === 'mysql') {
                  return (
                    <TabsContent
                      key={tab.id}
                      value={tab.id}
                      className={cn(
                        'mt-0 data-[state=inactive]:hidden',
                        CONNECT_TAB_CONTENT_HEIGHT,
                      )}
                    >
                      <MysqlConnectCli
                        context={{ credentials, endpointInfo }}
                        copyContent={snippets.mysql}
                      />
                    </TabsContent>
                  )
                }

                const copyContent = snippets[tab.id]
                const displayCode = buildMysqlConnectSnippetDisplayCode(
                  tab.id,
                  { credentials, endpointInfo },
                )
                const panelLabel =
                  tab.id === 'prisma' ? `${tab.label} setup` : `${tab.label} example`

                return (
                <TabsContent
                  key={tab.id}
                  value={tab.id}
                  className={cn('mt-0 data-[state=inactive]:hidden', CONNECT_TAB_CONTENT_HEIGHT)}
                >
                  <div className="flex h-full flex-col overflow-hidden rounded-xl border border-border">
                    <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2">
                      <span className="text-[12px] font-medium text-muted-foreground">
                        {panelLabel}
                      </span>
                      <CodeSnippetCopyButton content={copyContent} />
                    </div>
                    <ConnectCodePanel
                      code={displayCode}
                      language={tab.language}
                      headless
                      fixedHeight="100%"
                      wrapLines={tab.id === 'prisma'}
                      className="min-h-0 flex-1"
                    />
                  </div>
                </TabsContent>
                )
              })}
            </div>
          </Tabs>
        ) : null}

        <div className="shrink-0 border-t border-border bg-muted/30 px-6 py-4">
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 gap-1.5 text-[13px]"
              asChild
            >
              <DocsRouteLink href={MYSQL_DOCS_URL}>
                {t('Docs')}
                <ExternalLink className="h-3.5 w-3.5 shrink-0" />
              </DocsRouteLink>
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => onOpenChange(false)}
            >
              {t('Done')}
            </Button>
          </div>
        </div>
      </DialogContent>
      </Dialog>

      <AlertDialog open={resetPasswordOpen} onOpenChange={setResetPasswordOpen}>
        <AlertDialogContent className="sm:max-w-md p-0">
          <AlertDialogHeader className="px-6 pt-6 pb-4 text-left">
            <AlertDialogTitle>{t('Reset database password?')}</AlertDialogTitle>
            <AlertDialogDescription className="text-[13px] mt-2">
              {t(
                'A new password will be generated immediately. Apps and clients using the current password will stop connecting until you update them.',
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter className="px-6 py-4 border-t border-border bg-muted/30 flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogCancel disabled={resetPasswordMutation.isPending}>
              {t('Cancel')}
            </AlertDialogCancel>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={resetPasswordMutation.isPending}
              onClick={handleResetPassword}
            >
              {t('Reset database password')}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
