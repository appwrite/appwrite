import { Link } from '@tanstack/react-router'
import { Copy, Download, FileJson, Hammer, Trash2, Upload } from 'lucide-react'
import { toast } from 'sonner'
import {
  ContextMenuIcon,
  MenuItemContent,
} from '@/components/global/shared/ContextMenuIcon'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenu,
  DropdownMenuContent,
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
import { analyticsAttrs } from '@/lib/analytics-actions'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import type { DistributionArtifact } from '@/lib/appwrite/distribution'
import { sdk } from '@/lib/appwrite/sdk'
import { artifactVersion } from '@/lib/distribution/publish'
import { isArtifactPending } from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'
import { fetchDistributionArtifact } from '@/lib/react-query/hooks'
import { formatBinaryBytes } from '@/lib/utils/byte-display-unit'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import {
  CONTEXT_MENU_PARTS,
  DROPDOWN_MENU_PARTS,
  type ActionMenuParts,
} from '../../../_components/action-menu'
import { PlatformIcon, platformLabel } from '../../../_components/platform'
import { ArtifactStatusBadge } from '../../../_components/status-badges'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type ArtifactsTableProps = {
  projectId: string
  appId: string
  artifacts: DistributionArtifact[]
  canEdit: boolean
  onDelete: (artifact: DistributionArtifact) => void
}

export function ArtifactsTable({
  projectId,
  appId,
  artifacts,
  canEdit,
  onDelete,
}: ArtifactsTableProps) {
  const t = useT()

  const download = (artifact: DistributionArtifact) => {
    const url = withAdminMode(
      sdk.forProject(projectId).distribution.getArtifactDownload({
        appId,
        artifactId: artifact.$id,
      }),
    )
    window.open(url, '_blank')
    toast.success(t('Download started'))
  }

  const actions = (artifact: DistributionArtifact, parts: ActionMenuParts) => {
    const { Item, Separator, Sub, SubTrigger, SubContent } = parts
    const ready = artifact.status === 'ready'
    return (
      <>
        <Item
          disabled={!ready}
          title={
            ready ? undefined : t('Only a ready artifact can be downloaded.')
          }
          onSelect={() => download(artifact)}
          {...analyticsAttrs('distribution-download-artifact')}
        >
          <MenuItemContent icon={Download}>{t('Download')}</MenuItemContent>
        </Item>
        <Separator />
        <Sub>
          <SubTrigger>
            <ContextMenuIcon icon={Copy} />
            {t('Copy')}
          </SubTrigger>
          <SubContent>
            <Item onSelect={() => void copyToClipboard('ID', artifact.$id)}>
              <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
            </Item>
            <Item onSelect={() => void copyToClipboard('Name', artifact.name)}>
              <MenuItemContent icon={Copy}>{t('Copy name')}</MenuItemContent>
            </Item>
            <Item
              onSelect={() =>
                void copyResourceAsJson(() =>
                  fetchDistributionArtifact(projectId, appId, artifact.$id),
                )
              }
            >
              <MenuItemContent icon={FileJson}>
                {t('Copy as JSON')}
              </MenuItemContent>
            </Item>
          </SubContent>
        </Sub>
        <Separator />
        <Item
          disabled={!canEdit}
          title={
            canEdit
              ? undefined
              : t("You don't have permission to perform this action.")
          }
          onSelect={() =>
            openDialogAfterOverlayCloses(() => onDelete(artifact))
          }
        >
          <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
        </Item>
      </>
    )
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Platform')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Version')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Source')}</TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Size')}
            </TableHead>
            <TableHead className={HEAD_CLASS}>{t('Signed')}</TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Created')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Expires')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} w-[60px] text-end`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {artifacts.map((artifact) => (
            <ContextMenu key={artifact.$id}>
              <ContextMenuTrigger asChild>
                <TableRow
                  data-testid={`artifact-${artifact.$id}`}
                  className="border-b border-border/50"
                >
                  <TableCell className="px-4 py-3 max-w-[260px]">
                    <p
                      className="truncate text-[13px] font-medium text-foreground"
                      dir="auto"
                      title={artifact.name}
                    >
                      {artifact.name}
                    </p>
                    {artifact.identifier ? (
                      <p
                        className="mt-0.5 truncate font-mono text-[12px] text-muted-foreground"
                        dir="ltr"
                        title={artifact.identifier}
                      >
                        {artifact.identifier}
                      </p>
                    ) : null}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {artifact.platform ? (
                      <span className="flex items-center gap-1.5 text-[13px]">
                        <PlatformIcon
                          platform={artifact.platform}
                          className="text-muted-foreground"
                        />
                        {platformLabel(artifact.platform)}
                      </span>
                    ) : (
                      <span className="text-[13px] text-muted-foreground">
                        -
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <ArtifactVersion artifact={artifact} />
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="space-y-1">
                      <ArtifactStatusBadge status={artifact.status} />
                      {artifact.status === 'invalid' &&
                      artifact.errorMessage ? (
                        <p
                          className="max-w-[260px] whitespace-normal text-[12px] text-muted-foreground"
                          title={artifact.errorMessage}
                        >
                          {artifact.errorMessage}
                        </p>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {artifact.source === 'build' && artifact.buildId ? (
                      <Link
                        to="/projects/$projectId/distribution/$appId/builds/$buildId"
                        params={{ projectId, appId, buildId: artifact.buildId }}
                        className="inline-flex items-center gap-1.5 text-[13px] link-neutral"
                        title={artifact.buildId}
                      >
                        <Hammer className="h-3.5 w-3.5 shrink-0" />
                        {t('Build')}
                      </Link>
                    ) : (
                      <span className="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground">
                        <Upload className="h-3.5 w-3.5 shrink-0" />
                        {t('Upload')}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <span className="whitespace-nowrap text-[13px] tabular-nums">
                      {formatBinaryBytes(artifact.size)}
                    </span>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <ArtifactSigned artifact={artifact} />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <DateTooltip
                      date={artifact.$createdAt}
                      className="whitespace-nowrap text-[12px] text-muted-foreground font-mono"
                    />
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    {artifact.expiresAt ? (
                      <DateTooltip
                        date={artifact.expiresAt}
                        className="whitespace-nowrap text-[12px] text-muted-foreground font-mono"
                      />
                    ) : (
                      <span className="text-muted-foreground">-</span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <div className="flex justify-end">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <RowActionsMenuTrigger
                            aria-label={`${t('Actions for')} ${artifact.name}`}
                          />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                          {actions(artifact, DROPDOWN_MENU_PARTS)}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </TableCell>
                </TableRow>
              </ContextMenuTrigger>
              <ContextMenuContent className="w-56">
                {actions(artifact, CONTEXT_MENU_PARTS)}
              </ContextMenuContent>
            </ContextMenu>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function ArtifactVersion({ artifact }: { artifact: DistributionArtifact }) {
  const version = artifactVersion(artifact)
  if (!version) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  return (
    <span className="whitespace-nowrap text-[13px]" dir="ltr">
      {version}
    </span>
  )
}

function ArtifactSigned({ artifact }: { artifact: DistributionArtifact }) {
  const t = useT()
  if (isArtifactPending(artifact.status)) {
    return <span className="text-[13px] text-muted-foreground">-</span>
  }
  return artifact.signed ? (
    <Badge variant="success" className="text-[10px] shrink-0">
      {t('Signed')}
    </Badge>
  ) : (
    <Badge variant="warning" className="text-[10px] shrink-0">
      {t('Unsigned')}
    </Badge>
  )
}
