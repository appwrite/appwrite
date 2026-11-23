import { useState } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  AlertCircle,
  Apple,
  Copy,
  Download,
  FileJson,
  KeyRound,
  Loader2,
  Smartphone,
  Sparkles,
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
import type { DistributionSigningIdentity } from '@/lib/appwrite/distribution'
import { canManageDistributionCredentials } from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  DEFAULT_PAGE_SIZE,
  fetchDistributionSigningIdentity,
  useDeleteDistributionSigningIdentity,
  useDistributionSigningIdentities,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { ServiceHeader } from '../../shared/ServiceHeader'
import { CreateSigningIdentity } from '../_components/CreateSigningIdentity'
import { CredentialCreateMenu } from '../_components/CredentialCreateMenu'
import type { CredentialCreateOption } from '../_components/CredentialCreateMenu'
import { DownloadSigningIdentity } from '../_components/DownloadSigningIdentity'
import { ImportAndroidSigningIdentity } from '../_components/ImportAndroidSigningIdentity'
import { ImportIosSigningIdentity } from '../_components/ImportIosSigningIdentity'
import { PlatformIcon, platformLabel } from '../_components/platform'
import { SigningIdentityStatusBadge } from '../_components/status-badges'
import { useTabs } from '../_components/tabs'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

const VISIBLE_IDENTIFIERS = 2

const FINGERPRINT_PREFIX_LENGTH = 16

type Creation = 'ios' | 'android' | 'generate'

const MODE_LABELS: Record<string, string> = {
  imported: 'Imported',
  managed: 'Managed',
}

function Identifiers({ identifiers }: { identifiers: string[] }) {
  if (identifiers.length === 0) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  const hidden = identifiers.length - VISIBLE_IDENTIFIERS
  return (
    <div className="space-y-0.5" title={identifiers.join('\n')}>
      {identifiers.slice(0, VISIBLE_IDENTIFIERS).map((identifier) => (
        <p
          key={identifier}
          className="truncate font-mono text-[12px] rtl:text-right"
          dir="ltr"
        >
          {identifier}
        </p>
      ))}
      {hidden > 0 ? (
        <p className="text-[12px] text-muted-foreground">+{hidden}</p>
      ) : null}
    </div>
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
    "You don't have permission to manage signing identities.",
  )

  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [dialog, setDialog] = useState<Creation | null>(null)
  const [downloading, setDownloading] =
    useState<DistributionSigningIdentity | null>(null)
  const [deleting, setDeleting] = useState<DistributionSigningIdentity | null>(
    null,
  )

  const { signingIdentities, total, isLoading, error, refetch } =
    useDistributionSigningIdentities(projectId, page - 1, pageSize)
  const remove = useDeleteDistributionSigningIdentity(projectId)

  const createOptions: CredentialCreateOption[] = [
    {
      id: 'ios',
      label: t('Import iOS certificate'),
      icon: Apple,
      onSelect: () => setDialog('ios'),
    },
    {
      id: 'android',
      label: t('Import Android keystore'),
      icon: Smartphone,
      onSelect: () => setDialog('android'),
    },
    {
      id: 'generate',
      label: t('Generate with Appwrite'),
      icon: Sparkles,
      onSelect: () => setDialog('generate'),
    },
  ]
  const createMenu = (layout: 'toolbar' | 'inline') => (
    <CredentialCreateMenu
      label={t('Add signing identity')}
      options={createOptions}
      disabledTooltip={canManage ? undefined : permissionTooltip}
      layout={layout}
    />
  )
  const dialogProps = (target: Creation) => ({
    projectId,
    open: dialog === target,
    onOpenChange: (open: boolean) => setDialog(open ? target : null),
  })

  const handleDelete = () => {
    if (!deleting) return
    remove.mutate(deleting.$id, {
      onSuccess: () => {
        toast.success(t('Signing identity deleted'))
        setDeleting(null)
      },
      onError: (error) => {
        setDeleting(null)
        toast.error(
          getAppwriteErrorInfo(error).type ===
            'distribution_signing_identity_in_use'
            ? t(
                'An app target still uses this signing identity. Choose another one for the target, then try again.',
              )
            : getErrorMessage(error, t('Failed to delete signing identity')),
        )
      },
    })
  }

  const firstRun = !isLoading && !error && total === 0
  const [beforeName, afterName = ''] = t(
    'Delete {name}? Appwrite deletes its encrypted files. Download them first if you still need them. This action cannot be undone.',
  ).split('{name}')

  return (
    <div className="flex h-full flex-col">
      <ServiceHeader
        title={t('Distribution')}
        tabs={tabs}
        activeTab="signing"
        beforeCreateButtons={createMenu('toolbar')}
        hideToolbar={firstRun}
        fullWidthBorder
      />
      <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 pb-4 sm:px-6 sm:pb-6">
        {isLoading && signingIdentities.length === 0 ? (
          <div className="flex h-64 items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : error && signingIdentities.length === 0 ? (
          <EmptyState
            icon={AlertCircle}
            title={t('Failed to load signing identities')}
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
            icon={KeyRound}
            title={t('No signing identities')}
            description={t(
              'Import your certificates and keystores, or let Appwrite generate and renew them for you.',
            )}
            action={createMenu('inline')}
            variant="card"
            className="mt-6"
          />
        ) : (
          <>
            <div className="rounded-lg border border-border bg-card">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Platform')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Mode')}</TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                    <TableHead className={HEAD_CLASS}>
                      {t('Identifiers')}
                    </TableHead>
                    <TableHead className={`${HEAD_CLASS} text-end`}>
                      {t('Expires')}
                    </TableHead>
                    <TableHead className={`${HEAD_CLASS} text-end w-[80px]`} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {signingIdentities.map((signingIdentity) => (
                    <TableRow
                      key={signingIdentity.$id}
                      data-testid={`signing-${signingIdentity.$id}`}
                    >
                      <TableCell className="px-4 py-3">
                        <p className="text-[13px] font-medium">
                          {signingIdentity.name}
                        </p>
                        {signingIdentity.fingerprint ? (
                          <p
                            className="font-mono text-[11px] text-muted-foreground rtl:text-right"
                            dir="ltr"
                            title={signingIdentity.fingerprint}
                          >
                            SHA-256{' '}
                            {signingIdentity.fingerprint.slice(
                              0,
                              FINGERPRINT_PREFIX_LENGTH,
                            )}
                            …
                          </p>
                        ) : null}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <span className="flex items-center gap-1.5 text-[13px]">
                          <PlatformIcon
                            platform={signingIdentity.platform}
                            className="text-muted-foreground"
                          />
                          {platformLabel(signingIdentity.platform)}
                        </span>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-[13px]">
                        {MODE_LABELS[signingIdentity.mode]
                          ? t(MODE_LABELS[signingIdentity.mode])
                          : signingIdentity.mode}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <SigningIdentityStatusBadge
                          status={signingIdentity.status}
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 max-w-[260px]">
                        <Identifiers
                          identifiers={signingIdentity.identifiers}
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        {signingIdentity.expiresAt ? (
                          <DateTooltip date={signingIdentity.expiresAt} />
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <div className="flex justify-end">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <RowActionsMenuTrigger
                                aria-label={`${t('Actions for')} ${signingIdentity.name}`}
                              />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                              <DropdownMenuItem
                                disabled={!canManage}
                                onClick={() => setDownloading(signingIdentity)}
                              >
                                <MenuItemContent icon={Download}>
                                  {t('Download')}
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
                                        signingIdentity.$id,
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
                                        signingIdentity.name,
                                      )
                                    }
                                  >
                                    <MenuItemContent icon={Copy}>
                                      {t('Copy name')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    disabled={!signingIdentity.fingerprint}
                                    onClick={() =>
                                      void copyToClipboard(
                                        'Fingerprint',
                                        signingIdentity.fingerprint,
                                      )
                                    }
                                  >
                                    <MenuItemContent icon={Copy}>
                                      {t('Copy fingerprint')}
                                    </MenuItemContent>
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    onClick={() =>
                                      void copyResourceAsJson(() =>
                                        fetchDistributionSigningIdentity(
                                          projectId,
                                          signingIdentity.$id,
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
                                onClick={() => setDeleting(signingIdentity)}
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
                  ))}
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
              itemLabel={t('signing identities')}
            />
          </>
        )}
      </div>

      <ImportIosSigningIdentity {...dialogProps('ios')} />
      <ImportAndroidSigningIdentity {...dialogProps('android')} />
      <CreateSigningIdentity {...dialogProps('generate')} />
      <DownloadSigningIdentity
        projectId={projectId}
        signingIdentity={downloading}
        onOpenChange={(open) => {
          if (!open) setDownloading(null)
        }}
      />
      <ConfirmActionDialog
        open={deleting !== null}
        onOpenChange={(open) => {
          if (!open) setDeleting(null)
        }}
        title={t('Delete signing identity')}
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
