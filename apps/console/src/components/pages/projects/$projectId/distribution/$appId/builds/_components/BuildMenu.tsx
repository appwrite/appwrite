import type { ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { toast } from 'sonner'
import {
  Copy,
  ExternalLink,
  FileJson,
  FileText,
  Link2,
  RefreshCw,
  Square,
  Trash2,
  XCircle,
} from 'lucide-react'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
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
import { analyticsAttrs } from '@/lib/analytics-actions'
import type {
  DistributionBuild,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  cancelBlocker,
  deleteBlocker,
  retryBlocker,
  retryBuildParams,
  type BuildPermissions,
} from '@/lib/distribution/builds'
import { isBuildActive } from '@/lib/distribution/status'
import { useT } from '@/lib/i18n/translate'
import {
  fetchDistributionBuild,
  useCreateDistributionBuild,
} from '@/lib/react-query/hooks'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import {
  CONTEXT_MENU_PARTS,
  DROPDOWN_MENU_PARTS,
  type ActionMenuParts,
} from '../../../_components/action-menu'

export type BuildActionHandlers = {
  onCancel: (build: DistributionBuild) => void
  onRetry: (build: DistributionBuild) => void
  onDelete: (build: DistributionBuild) => void
}

export function useRetryBuild(
  projectId: string,
  targets: DistributionTarget[],
) {
  const t = useT()
  const navigate = useNavigate()
  const createBuild = useCreateDistributionBuild(projectId)

  const retry = (build: DistributionBuild) => {
    const target = targets.find((item) => item.$id === build.targetId)
    const params = retryBuildParams(build, target)
    if (!params || createBuild.isPending) return
    createBuild.mutate(params, {
      onSuccess: (created) => {
        toast.success(t('Build started'))
        void navigate({
          to: '/projects/$projectId/distribution/$appId/builds/$buildId',
          params: { projectId, appId: params.appId, buildId: created.$id },
        })
      },
      onError: (error) =>
        toast.error(getErrorMessage(error, t('Failed to start build'))),
    })
  }

  return { retry, isRetrying: createBuild.isPending }
}

type BuildMenuProps = BuildActionHandlers & {
  projectId: string
  appId: string
  build: DistributionBuild
  permissions: BuildPermissions
}

function BuildMenuItems({
  parts: { Item, Separator, Sub, SubTrigger, SubContent },
  projectId,
  appId,
  build,
  permissions,
  onCancel,
  onRetry,
  onDelete,
}: BuildMenuProps & { parts: ActionMenuParts }) {
  const t = useT()
  const navigate = useNavigate()
  const href = buildConsoleUrl(
    `/projects/${projectId}/distribution/${appId}/builds/${build.$id}`,
  )
  const cancelReason = cancelBlocker(permissions)
  const retryReason = retryBlocker(build, permissions)
  const deleteReason = deleteBlocker(build, permissions)
  const translate = (reason: string | null) => (reason ? t(reason) : undefined)

  return (
    <>
      <Item
        onSelect={() =>
          navigate({
            to: '/projects/$projectId/distribution/$appId/builds/$buildId',
            params: { projectId, appId, buildId: build.$id },
          })
        }
      >
        <ContextMenuIcon icon={FileText} />
        {t('Logs')}
      </Item>
      <Separator />
      <Sub>
        <SubTrigger>
          <ContextMenuIcon icon={Copy} />
          {t('Copy')}
        </SubTrigger>
        <SubContent>
          <Item onSelect={() => void copyToClipboard('ID', build.$id)}>
            <ContextMenuIcon icon={Copy} />
            {t('Copy ID')}
          </Item>
          <Item onSelect={() => void copyToClipboard('Link', href)}>
            <ContextMenuIcon icon={Link2} />
            {t('Copy link')}
          </Item>
          <Item
            onSelect={() =>
              void copyResourceAsJson(
                () => fetchDistributionBuild(projectId, appId, build.$id),
                { fallback: build },
              )
            }
          >
            <ContextMenuIcon icon={FileJson} />
            {t('Copy as JSON')}
          </Item>
        </SubContent>
      </Sub>
      <Item onSelect={() => openInNewTab(href)}>
        <ContextMenuIcon icon={ExternalLink} />
        {t('Open in new tab')}
      </Item>
      <Item onSelect={() => openInNewWindow(href)}>
        <ContextMenuIcon icon={Square} />
        {t('Open in new window')}
      </Item>
      <Separator />
      {isBuildActive(build.status) ? (
        <Item
          {...analyticsAttrs('distribution-cancel-build')}
          disabled={!!cancelReason}
          title={translate(cancelReason)}
          onSelect={() => openDialogAfterOverlayCloses(() => onCancel(build))}
        >
          <ContextMenuIcon icon={XCircle} />
          {t('Cancel build')}
        </Item>
      ) : null}
      <Item
        {...analyticsAttrs('distribution-retry-build')}
        disabled={!!retryReason}
        title={translate(retryReason)}
        onSelect={() => onRetry(build)}
      >
        <ContextMenuIcon icon={RefreshCw} />
        {t('Retry')}
      </Item>
      <Separator />
      <Item
        disabled={!!deleteReason}
        title={translate(deleteReason)}
        onSelect={() => openDialogAfterOverlayCloses(() => onDelete(build))}
      >
        <ContextMenuIcon icon={Trash2} />
        {t('Delete')}
      </Item>
    </>
  )
}

export function BuildContextMenu({
  children,
  ...props
}: BuildMenuProps & { children: ReactNode }) {
  return (
    <ContextMenu>
      <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
      <ContextMenuContent className="w-56">
        <BuildMenuItems parts={CONTEXT_MENU_PARTS} {...props} />
      </ContextMenuContent>
    </ContextMenu>
  )
}

export function BuildRowMenu(props: BuildMenuProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <RowActionsMenuTrigger
          onClick={(event) => event.stopPropagation()}
          onPointerDown={(event) => event.stopPropagation()}
        />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="z-[200] w-56">
        <BuildMenuItems parts={DROPDOWN_MENU_PARTS} {...props} />
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
