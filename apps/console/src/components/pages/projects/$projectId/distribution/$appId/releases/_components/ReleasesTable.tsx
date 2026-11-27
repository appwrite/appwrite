import { Link, useNavigate } from '@tanstack/react-router'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Pencil,
  Square,
  Trash2,
} from 'lucide-react'
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
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import type { DistributionRelease } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { fetchDistributionRelease } from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { ReleaseStatusBadge } from '../../../_components/status-badges'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type ReleasesTableProps = {
  projectId: string
  appId: string
  releases: DistributionRelease[]
  canWrite: boolean
  onUpdate: (release: DistributionRelease) => void
  onDelete: (release: DistributionRelease) => void
}

function releasePath(
  projectId: string,
  appId: string,
  releaseId: string,
): string {
  return `/projects/${projectId}/distribution/${appId}/releases/${releaseId}`
}

export function ReleasesTable({
  projectId,
  appId,
  releases,
  canWrite,
  onUpdate,
  onDelete,
}: ReleasesTableProps) {
  const t = useT()
  const navigate = useNavigate()
  const writeReason = canWrite
    ? undefined
    : t("You don't have permission to update releases.")

  const open = (release: DistributionRelease) =>
    void navigate({
      to: '/projects/$projectId/distribution/$appId/releases/$releaseId',
      params: { projectId, appId, releaseId: release.$id },
    })

  return (
    <div className="rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Version')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Created')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Updated')}</TableHead>
            <TableHead className={`${HEAD_CLASS} text-end w-[80px]`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {releases.map((release) => {
            const url = buildConsoleUrl(
              releasePath(projectId, appId, release.$id),
            )
            return (
              <TableRow
                key={release.$id}
                data-testid={`release-${release.$id}`}
                className="cursor-pointer"
                onClick={() => open(release)}
              >
                <TableCell className="px-4 py-3">
                  <Link
                    to="/projects/$projectId/distribution/$appId/releases/$releaseId"
                    params={{ projectId, appId, releaseId: release.$id }}
                    className="font-mono text-[13px] font-medium text-foreground hover:underline"
                    dir="ltr"
                    onClick={(event) => event.stopPropagation()}
                  >
                    {release.version}
                  </Link>
                </TableCell>
                <TableCell className="px-4 py-3">
                  <ReleaseStatusBadge status={release.status} />
                </TableCell>
                <TableCell className="px-4 py-3">
                  <DateTooltip date={release.$createdAt} />
                </TableCell>
                <TableCell className="px-4 py-3">
                  <DateTooltip date={release.$updatedAt} />
                </TableCell>
                <TableCell
                  className="px-4 py-3 text-end"
                  onClick={(event) => event.stopPropagation()}
                >
                  <div className="flex justify-end">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <RowActionsMenuTrigger
                          aria-label={`${t('Actions for')} ${release.version}`}
                        />
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end" className="w-56">
                        <DropdownMenuItem
                          disabled={!canWrite}
                          title={writeReason}
                          onSelect={() =>
                            openDialogAfterOverlayCloses(() =>
                              onUpdate(release),
                            )
                          }
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
                                void copyToClipboard('ID', release.$id)
                              }
                            >
                              <MenuItemContent icon={Copy}>
                                {t('Copy ID')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() => void copyToClipboard('Link', url)}
                            >
                              <MenuItemContent icon={Link2}>
                                {t('Copy link')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() =>
                                void copyResourceAsJson(() =>
                                  fetchDistributionRelease(
                                    projectId,
                                    appId,
                                    release.$id,
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
                        <DropdownMenuItem onClick={() => openInNewTab(url)}>
                          <MenuItemContent icon={ExternalLink}>
                            {t('Open in new tab')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => openInNewWindow(url)}>
                          <MenuItemContent icon={Square}>
                            {t('Open in new window')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          disabled={!canWrite}
                          title={writeReason}
                          onSelect={() =>
                            openDialogAfterOverlayCloses(() =>
                              onDelete(release),
                            )
                          }
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
  )
}
