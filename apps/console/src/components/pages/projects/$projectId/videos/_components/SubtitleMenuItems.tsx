import type { ReactNode } from 'react'
import type { Models } from '@appwrite.io/console'
import {
  Copy,
  Download,
  ExternalLink,
  FileJson,
  FileText,
  Link2,
  ListVideo,
  Square,
  SquarePen,
  Star,
  StarOff,
  Trash2,
  type LucideIcon,
} from 'lucide-react'
import { MenuItemIcon } from '@/components/global/shared/ContextMenuIcon'
import {
  ContextMenuItem,
  ContextMenuSeparator,
  ContextMenuSub,
  ContextMenuSubContent,
  ContextMenuSubTrigger,
} from '@/components/ui/context-menu'
import {
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu'
import { sdk } from '@/lib/appwrite/sdk'
import { withAdminMode } from '@/lib/appwrite/admin-resource-url'
import { useT } from '@/lib/i18n/translate'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
} from '@/lib/utils/context-menu'
import {
  SUBTITLE_DOWNLOAD_FORMATS,
  type SubtitleDownloadFormat,
} from '@/lib/videos/subtitle-download'
import { getVideoCaptionManifestUrl } from '@/lib/videos/urls'

const CAPTION_OUTPUTS = [
  { id: 'hls', label: 'Copy HLS caption URL' },
  { id: 'dash', label: 'Copy DASH caption URL' },
  { id: 'cmaf', label: 'Copy CMAF caption URL' },
] as const

const PRIMITIVES = {
  context: {
    Item: ContextMenuItem,
    Separator: ContextMenuSeparator,
    Sub: ContextMenuSub,
    SubTrigger: ContextMenuSubTrigger,
    SubContent: ContextMenuSubContent,
  },
  dropdown: {
    Item: DropdownMenuItem,
    Separator: DropdownMenuSeparator,
    Sub: DropdownMenuSub,
    SubTrigger: DropdownMenuSubTrigger,
    SubContent: DropdownMenuSubContent,
  },
} as const

/**
 * Subtitle actions shared by the row context menu and the row ⋯ menu, so both
 * list the same items in the same order.
 */
export function SubtitleMenuItems({
  variant,
  projectId,
  videoId,
  caption,
  canWrite,
  onOpen,
  onToggleDefault,
  onDownload,
  onDelete,
}: {
  variant: 'context' | 'dropdown'
  projectId: string
  videoId: string
  caption: Models.VideoCaption
  canWrite: boolean
  onOpen: () => void
  onToggleDefault: () => void
  onDownload: (format: SubtitleDownloadFormat) => void
  onDelete: () => void
}) {
  const t = useT()
  const { Item, Separator, Sub, SubTrigger, SubContent } = PRIMITIVES[variant]
  const ready = caption.status === 'ready'
  const hasFile = !caption.embedded && !!caption.fileId
  const editorHref = buildConsoleUrl(
    `/projects/${projectId}/videos/${videoId}/captions/${caption.$id}`,
  )

  const row = (icon: LucideIcon, label: ReactNode) => (
    <>
      <MenuItemIcon icon={icon} />
      {label}
    </>
  )

  return (
    <>
      <Item onSelect={onOpen}>{row(SquarePen, t('Open in editor'))}</Item>
      <Separator />
      <Item disabled={!canWrite} onSelect={onToggleDefault}>
        {caption.default
          ? row(StarOff, t('Remove default'))
          : row(Star, t('Make default'))}
      </Item>
      <Separator />
      <Sub>
        <SubTrigger>{row(Copy, t('Copy'))}</SubTrigger>
        <SubContent className="w-60">
          <Item onSelect={() => copyToClipboard(t('ID'), caption.$id)}>
            {row(Copy, t('Copy ID'))}
          </Item>
          <Item onSelect={() => copyToClipboard(t('Name'), caption.name)}>
            {row(Copy, t('Copy name'))}
          </Item>
          <Item onSelect={() => copyToClipboard(t('Link'), editorHref)}>
            {row(Link2, t('Copy link'))}
          </Item>
          {CAPTION_OUTPUTS.map((output) => (
            <Item
              key={output.id}
              disabled={!ready}
              onSelect={() =>
                copyToClipboard(
                  t('Caption URL'),
                  getVideoCaptionManifestUrl(
                    projectId,
                    videoId,
                    caption.$id,
                    output.id,
                  ),
                )
              }
            >
              {row(ListVideo, t(output.label))}
            </Item>
          ))}
          <Item onSelect={() => void copyResourceAsJson(() => caption)}>
            {row(FileJson, t('Copy as JSON'))}
          </Item>
        </SubContent>
      </Sub>
      <Sub>
        <SubTrigger disabled={!hasFile && !ready}>
          {row(Download, t('Download'))}
        </SubTrigger>
        <SubContent className="w-52">
          {SUBTITLE_DOWNLOAD_FORMATS.map((format) => (
            <Item key={format.id} onSelect={() => onDownload(format.id)}>
              {row(FileText, t(format.label))}
            </Item>
          ))}
        </SubContent>
      </Sub>
      <Separator />
      {hasFile ? (
        <Item
          onSelect={() =>
            openInNewTab(
              withAdminMode(
                sdk.forProject(projectId).storage.getFileView({
                  bucketId: caption.bucketId,
                  fileId: caption.fileId,
                }),
              ),
            )
          }
        >
          {row(FileText, t('Open caption file'))}
        </Item>
      ) : null}
      <Item onSelect={() => openInNewTab(editorHref)}>
        {row(ExternalLink, t('Open in new tab'))}
      </Item>
      <Item onSelect={() => openInNewWindow(editorHref)}>
        {row(Square, t('Open in new window'))}
      </Item>
      <Separator />
      <Item disabled={!canWrite} onSelect={onDelete}>
        {row(Trash2, t('Delete'))}
      </Item>
    </>
  )
}
