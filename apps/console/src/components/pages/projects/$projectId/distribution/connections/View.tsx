import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  AlertCircle,
  Apple,
  Copy,
  FileJson,
  Loader2,
  Pencil,
  PlugZap,
  Smartphone,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { DistributionConnection } from '@/lib/appwrite/distribution'
import { canManageDistributionCredentials } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  DEFAULT_PAGE_SIZE,
  fetchDistributionConnection,
  useDeleteDistributionConnection,
  useDistributionConnections,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { CreateAppleConnection } from '../_components/CreateAppleConnection'
import { CreateGoogleConnection } from '../_components/CreateGoogleConnection'
import { CredentialCreateMenu } from '../_components/CredentialCreateMenu'
import type { CredentialCreateOption } from '../_components/CredentialCreateMenu'
import { providerLabel } from '../_components/platform'
import { ConnectionStatusBadge } from '../_components/status-badges'
import { useTabs } from '../_components/tabs'
import { UpdateConnection } from '../_components/UpdateConnection'

const SEARCH_DEBOUNCE_MS = 300

const PROVIDER_ICON = {
  apple: Apple,
  google: Smartphone,
}

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type Fact = { label: string; value: string }

function AccountFacts({ facts }: { facts: Fact[] }) {
  const shown = facts.filter((fact) => fact.value)
  if (shown.length === 0) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  return (
    <dl className="space-y-0.5 text-[12px]">
      {shown.map((fact) => (
        <div key={fact.label} className="flex min-w-0 gap-1.5">
          <dt className="shrink-0 text-muted-foreground">{fact.label}</dt>
          <dd
            className="min-w-0 truncate font-mono text-foreground"
            dir="ltr"
            title={fact.value}
          >
            {fact.value}
          </dd>
        </div>
      ))}
    </dl>
  )
}

export function View() {
  const t = useT()
  const { projectId = '' } = useParams({ strict: false })
  const tabs = useTabs(projectId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canManage = canManageDistributionCredentials(access, features)
  const permissionTooltip = t(
    "You don't have permission to manage store connections.",
  )

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [creating, setCreating] = useState<'apple' | 'google' | null>(null)
  const [updating, setUpdating] = useState<DistributionConnection | null>(null)
  const [deleting, setDeleting] = useState<DistributionConnection | null>(null)

  useEffect(() => {
    const term = searchInput.trim()
    if (term === search) return
    const timeout = setTimeout(() => {
      setSearch(term)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput, search])

  const { connections, total, isLoading, error, refetch } =
    useDistributionConnections(projectId, page - 1, pageSize, search)
  const remove = useDeleteDistributionConnection(projectId)

  const createOptions: CredentialCreateOption[] = [
    {
      id: 'apple',
      label: t('App Store Connect'),
      icon: Apple,
      onSelect: () => setCreating('apple'),
    },
    {
      id: 'google',
      label: t('Google Play'),
      icon: Smartphone,
      onSelect: () => setCreating('google'),
    },
  ]
  const createMenu = (layout: 'toolbar' | 'inline') => (
    <CredentialCreateMenu
      label={t('Create connection')}
      options={createOptions}
      disabledTooltip={canManage ? undefined : permissionTooltip}
      layout={layout}
    />
  )

  const handleDelete = () => {
    if (!deleting) return
    remove.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Connection deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        setDeleting(null)
        toast.error(
          getAppwriteErrorInfo(error).type === 'distribution_connection_in_use'
            ? t(
                'An app target still uses this connection. Choose another connection for the target, then try again.',
              )
            : getErrorMessage(error, t('Failed to delete connection')),
        )
      },
    })
  }

  const accountFacts = (connection: DistributionConnection): Fact[] => {
    const { account } = connection
    if (connection.provider === 'apple') {
      const team =
        account.teamName && account.teamId
          ? `${account.teamName} (${account.teamId})`
          : (account.teamName ?? account.teamId ?? '')
      return [
        { label: t('Team'), value: team },
        { label: t('Key ID'), value: account.keyId ?? '' },
        { label: t('Issuer ID'), value: account.issuerId ?? '' },
      ]
    }
    return [
      { label: t('Service account'), value: account.clientEmail ?? '' },
      { label: t('Google Cloud project'), value: account.projectId ?? '' },
    ]
  }

  const firstRun = !isLoading && !error && total === 0 && !search
  const [beforeName, afterName = ''] = t(
    'Delete {name}? Appwrite stops using its credentials immediately. This action cannot be undone.',
  ).split('{name}')

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title={t('Distribution')}
        tabs={tabs}
        activeTab="connections"
        searchPlaceholder={t('Search connections...')}
        searchValue={searchInput}
        onSearchChange={setSearchInput}
        beforeCreateButtons={createMenu('toolbar')}
        hideToolbar={firstRun}
        fullWidthBorder
      />
      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
        {isLoading && connections.length === 0 ? (
          <div className="flex h-64 items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : error && connections.length === 0 ? (
          <EmptyState
            icon={AlertCircle}
            title={t('Failed to load connections')}
            description={getErrorMessage(error)}
            variant="card"
            iconSize="md"
            className="mt-6"
            action={
              <Button
                variant="outline"
                size="sm"
                onClick={() => void refetch()}
              >
                {t('Try again')}
              </Button>
            }
          />
        ) : firstRun ? (
          <EmptyState
            icon={PlugZap}
            title={t('Connect a store account')}
            description={t(
              'Add an App Store Connect API key or a Google Play service account so Appwrite can upload builds and manage signing.',
            )}
            action={createMenu('inline')}
            variant="card"
            className="mt-6"
          />
        ) : connections.length === 0 ? (
          <EmptyState icon={PlugZap} hasFilters variant="card" />
        ) : (
          <>
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Provider')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Account')}</TableHead>
                    <TableHead className={`${HEAD_CLASS} text-end`}>
                      {t('Last checked')}
                    </TableHead>
                    <TableHead className={`${HEAD_CLASS} text-end w-[80px]`} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {connections.map((connection) => {
                    const Icon = PROVIDER_ICON[connection.provider] ?? PlugZap
                    return (
                      <TableRow
                        key={connection.$id}
                        data-testid={`connection-${connection.$id}`}
                      >
                        <TableCell className="px-4 py-3">
                          <span className="text-[13px] font-medium">
                            {connection.name}
                          </span>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <span className="flex items-center gap-1.5 text-[13px]">
                            <Icon className="h-4 w-4 text-muted-foreground" />
                            {t(providerLabel(connection.provider))}
                          </span>
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <div className="space-y-1">
                            <ConnectionStatusBadge status={connection.status} />
                            {connection.error ? (
                              <p
                                className="max-w-[260px] whitespace-normal text-[12px] text-muted-foreground"
                                title={connection.error}
                              >
                                {connection.error}
                              </p>
                            ) : null}
                          </div>
                        </TableCell>
                        <TableCell className="px-4 py-3 max-w-[320px]">
                          <AccountFacts facts={accountFacts(connection)} />
                        </TableCell>
                        <TableCell className="px-4 py-3 text-end">
                          {connection.checkedAt ? (
                            <DateTooltip date={connection.checkedAt} />
                          ) : (
                            <span className="text-muted-foreground">-</span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3 text-end">
                          <div className="flex justify-end">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <RowActionsMenuTrigger
                                  aria-label={`${t('Actions for')} ${connection.name}`}
                                />
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuItem
                                  disabled={!canManage}
                                  onClick={() => setUpdating(connection)}
                                >
                                  <MenuItemContent icon={Pencil}>
                                    {t('Update')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuSub>
                                  <DropdownMenuSubTrigger>
                                    <MenuItemIcon icon={Copy} />
                                    {t('Copy')}
                                  </DropdownMenuSubTrigger>
                                  <DropdownMenuSubContent>
                                    <DropdownMenuItem
                                      onClick={() =>
                                        void copyToClipboard(
                                          'ID',
                                          connection.$id,
                                        )
                                      }
                                    >
                                      <MenuItemContent icon={Copy}>
                                        {t('Copy ID')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      onClick={() =>
                                        void copyToClipboard(
                                          'Name',
                                          connection.name,
                                        )
                                      }
                                    >
                                      <MenuItemContent icon={Copy}>
                                        {t('Copy name')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                      onClick={() =>
                                        void copyResourceAsJson(() =>
                                          fetchDistributionConnection(
                                            projectId,
                                            connection.$id,
                                          ),
                                        )
                                      }
                                    >
                                      <MenuItemContent icon={FileJson}>
                                        {t('Copy as JSON')}
                                      </MenuItemContent>
                                    </DropdownMenuItem>
                                  </DropdownMenuSubContent>
                                </DropdownMenuSub>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  disabled={!canManage}
                                  onClick={() => setDeleting(connection)}
                                >
                                  <MenuItemContent icon={Trash2}>
                                    {t('Delete')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={page}
              totalItems={total}
              pageSize={pageSize}
              onPageChange={setPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setPage(1)
              }}
              itemLabel={t('connections')}
            />
          </>
        )}
      </div>

      <CreateAppleConnection
        projectId={projectId}
        open={creating === 'apple'}
        onOpenChange={(open) => setCreating(open ? 'apple' : null)}
      />
      <CreateGoogleConnection
        projectId={projectId}
        open={creating === 'google'}
        onOpenChange={(open) => setCreating(open ? 'google' : null)}
      />
      <UpdateConnection
        projectId={projectId}
        connection={updating}
        onOpenChange={(open) => {
          if (!open) setUpdating(null)
        }}
      />
      <ConfirmActionDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        title={t('Delete connection')}
        description={
          <>
            {beforeName}
            <strong>{deleting?.name}</strong>
            {afterName}
          </>
        }
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={remove.isPending}
      />
    </div>
  )
}
