import { useState } from 'react'
import {
  Captions,
  Copy,
  ExternalLink,
  FileJson,
  Layers,
  Link2,
  MonitorPlay,
  Settings,
  Square,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { useNavigate } from '@tanstack/react-router'
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

export type VideoTab = 'overview' | 'renditions' | 'subtitles' | 'settings'

export const VIDEO_TAB_ICONS = {
  overview: MonitorPlay,
  renditions: Layers,
  subtitles: Captions,
  settings: Settings,
} as const

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
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const deleteMutation = useDeleteVideo(projectId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canShowVideoSettings(access, features)

  const videoHref = buildConsoleUrl(
    `/projects/${projectId}/videos/${video.$id}`,
  )

  const navigateToTab = (tab: VideoTab) => {
    const base = `/projects/${projectId}/videos/${video.$id}`
    navigate({
      to: (tab === 'overview'
        ? base
        : `${base}/${tab}`) as '/projects/$projectId/videos/$videoId',
      params: { projectId, videoId: video.$id },
    })
  }

  const handleConfirmDelete = () => {
    closeDialogBeforeOverlayUnmount(() => setDeleteDialogOpen(false))
    deleteMutation.mutate(video.$id, {
      onSuccess: () => {
        toast.success(t('Video deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete video'))
      },
    })
  }

  const tabs: Array<{ id: VideoTab; label: string }> = [
    { id: 'overview', label: t('Overview') },
    { id: 'renditions', label: t('Renditions') },
    { id: 'subtitles', label: t('Subtitles') },
    ...(canWrite ? [{ id: 'settings' as const, label: t('Settings') }] : []),
  ]

  return (
    <>
      <ContextMenu>
        <ContextMenuTrigger asChild>{children}</ContextMenuTrigger>
        <ContextMenuContent className="w-56">
          {tabs.map((tab) => (
            <ContextMenuItem
              key={tab.id}
              onSelect={() => navigateToTab(tab.id)}
            >
              <ContextMenuIcon icon={VIDEO_TAB_ICONS[tab.id]} />
              {tab.label}
            </ContextMenuItem>
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
        title="Delete video"
        description={
          <>
            {t(
              'Are you sure you want to delete this video? Its renditions, subtitles, and previews are removed. The source file in Storage is kept.',
            )}
          </>
        }
        confirmValue={video.name?.trim() || video.$id}
        confirmPlaceholder="Enter video name"
        onConfirm={handleConfirmDelete}
        isConfirming={deleteMutation.isPending}
      />
    </>
  )
}
