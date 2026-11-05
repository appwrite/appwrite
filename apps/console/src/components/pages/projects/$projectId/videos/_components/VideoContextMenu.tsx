import { Fragment, useState } from 'react'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Radio,
  Square,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useLocation, useNavigate } from '@tanstack/react-router'
import {
  VIDEO_INSPECTOR_ACTION,
  VIDEO_TABS,
  videoTabPath,
  type VideoTab,
} from './video-tabs'
import { useVideoInspector } from './player/VideoInspectorContext'
import { getVideoMasterManifestUrl } from '@/lib/videos/urls'
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
  ContextMenuTrigger,
} from '@/components/ui/context-menu'
import {
  fetchProjectVideo,
  useDeleteVideo,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { canShowVideoSettings } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import { ContextMenuIcon } from '@/components/global/shared/ContextMenuIcon'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import {
  closeDialogBeforeOverlayUnmount,
  openDialogAfterOverlayCloses,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

interface VideoContextMenuProps {
  projectId: string
  video: { $id: string; name?: string | null }
  children: React.ReactNode
}

export function VideoContextMenu({
  projectId,
  video,
  children,
}: VideoContextMenuProps) {
  const t = useT()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteVideo(projectId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canShowVideoSettings(access, features)
  const inspector = useVideoInspector()

  const videoHref = buildConsoleUrl(
    `/projects/${projectId}/videos/${video.$id}`,
  )

  const navigateToTab = (tab: VideoTab) => {
    navigate({
      to: videoTabPath(tab),
      params: { projectId, videoId: video.$id },
    })
  }

  const openInspector = () => {
    inspector?.open({
      projectId,
      videoId: video.$id,
      videoName: video.name ?? '',
    })
    navigateToTab('overview')
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(video.$id, {
      onSuccess: () => {
        toast.success(t('Video deleted'))
        if (pathname.split('/').includes(video.$id)) {
          navigate({ to: '/projects/$projectId/videos', params: { projectId } })
        }
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete video'))
      },
    })
  }

  const tabs = VIDEO_TABS.filter((tab) => tab.id !== 'settings' || canWrite)
  const lastInspectorGroupTab = [...tabs]
    .reverse()
    .find((tab) => tab.group === VIDEO_INSPECTOR_ACTION.group)

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          {tabs.map((tab) => (
            <Fragment key={tab.id}>
              <ContextMenuItem onSelect={() => navigateToTab(tab.id)}>
                <ContextMenuIcon icon={tab.icon} />
                {t(tab.label)}
              </ContextMenuItem>
              {inspector && tab === lastInspectorGroupTab ? (
                <ContextMenuItem onSelect={openInspector}>
                  <ContextMenuIcon icon={VIDEO_INSPECTOR_ACTION.icon} />
                  {t(VIDEO_INSPECTOR_ACTION.label)}
                </ContextMenuItem>
              ) : null}
            </Fragment>
          ))}
          <ContextMenuSeparator />
          <ContextMenuSub>
            <ContextMenuSubTrigger>
              <ContextMenuIcon icon={Copy} />
              {t('Copy')}
            </ContextMenuSubTrigger>
            <ContextMenuSubContent>
              <ContextMenuItem
                onSelect={() => copyToClipboard('ID', video.$id)}
              >
                <ContextMenuIcon icon={Copy} />
                {t('Copy ID')}
              </ContextMenuItem>
              {video.name ? (
                <ContextMenuItem
                  onSelect={() => copyToClipboard('Name', video.name)}
                >
                  <ContextMenuIcon icon={Copy} />
                  {t('Copy name')}
                </ContextMenuItem>
              ) : null}
              <ContextMenuItem
                onSelect={() => copyToClipboard('Link', videoHref)}
              >
                <ContextMenuIcon icon={Link2} />
                {t('Copy link')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  copyToClipboard(
                    t('HLS manifest URL'),
                    getVideoMasterManifestUrl(projectId, video.$id, 'hls'),
                  )
                }
              >
                <ContextMenuIcon icon={Radio} />
                {t('Copy HLS manifest URL')}
              </ContextMenuItem>
              <ContextMenuItem
                onSelect={() =>
                  void copyResourceAsJson(() =>
                    fetchProjectVideo(projectId, video.$id),
                  )
                }
              >
                <ContextMenuIcon icon={FileJson} />
                {t('Copy as JSON')}
              </ContextMenuItem>
            </ContextMenuSubContent>
          </ContextMenuSub>
          <ContextMenuSeparator />
          <ContextMenuItem onSelect={() => openInNewTab(videoHref)}>
            <ContextMenuIcon icon={ExternalLink} />
            {t('Open in new tab')}
          </ContextMenuItem>
          <ContextMenuItem onSelect={() => openInNewWindow(videoHref)}>
            <ContextMenuIcon icon={Square} />
            {t('Open in new window')}
          </ContextMenuItem>
          {canWrite ? (
            <>
              <ContextMenuSeparator />
              <ContextMenuItem
                onSelect={() =>
                  openDialogAfterOverlayCloses(() => setDeleteDialogOpen(true))
                }
              >
                <ContextMenuIcon icon={Trash2} />
                {t('Delete')}
              </ContextMenuItem>
            </>
          ) : null}
        </ContextMenuContent>
      </ContextMenu>

      <ConfirmNameDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        title={t('Delete video')}
        description={
          <>
            {t(
              'Are you sure you want to delete this video? Its renditions, subtitles, and previews are removed. The source file in Storage is kept.',
            )}
          </>
        }
        confirmValue={video.name?.trim() || video.$id}
        confirmPlaceholder={t('Enter video name')}
        onConfirm={handleConfirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
