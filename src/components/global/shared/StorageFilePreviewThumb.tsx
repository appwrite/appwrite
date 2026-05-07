import { useMemo } from 'react'
import type { LucideIcon } from 'lucide-react'
import {
  Archive,
  File,
  FileText,
  Film,
  Image,
  Music,
} from 'lucide-react'
import { ImageFormat } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { cn } from '@/lib/utils'
import { useAvifSupport } from '@/lib/avif-support'

/** Muted treatment for non-image previews (matches Storage bucket file UI). */
export const STORAGE_FILE_PREVIEW_ICON_CLASS =
  'bg-muted text-muted-foreground'

export function getStorageFileIcon(
  mimeType: string | undefined | null,
): LucideIcon {
  const type = mimeType ?? ''
  if (type.startsWith('image/')) return Image
  if (type.startsWith('video/')) return Film
  if (type.startsWith('audio/')) return Music
  if (type.includes('pdf') || type.includes('document')) return FileText
  if (type.includes('zip') || type.includes('archive')) return Archive
  return File
}

function withStoragePreviewAdminMode(previewUrl: string): string {
  return (
    previewUrl +
    (previewUrl.includes('?') ? '&' : '?') +
    'mode=admin'
  )
}

export type StorageFilePreviewThumbVariant = 'table' | 'grid'

export interface StorageFilePreviewThumbProps {
  projectId: string
  bucketId: string
  fileId: string
  mimeType?: string | null
  /** Used for image `alt` */
  name?: string | null
  variant: StorageFilePreviewThumbVariant
  pending?: boolean
  className?: string
}

/**
 * Project Storage file thumbnail: image preview via `getFilePreview` + `mode=admin`,
 * or mime-type icon fallback. Same rules as Storage bucket file list / grid.
 */
export function StorageFilePreviewThumb({
  projectId,
  bucketId,
  fileId,
  mimeType,
  name,
  variant,
  pending = false,
  className,
}: StorageFilePreviewThumbProps) {
  const avifSupported = useAvifSupport()
  const FileIcon = getStorageFileIcon(mimeType)
  const iconClass = STORAGE_FILE_PREVIEW_ICON_CLASS

  const canUseImagePreview =
    !!mimeType?.startsWith('image/') &&
    !!projectId &&
    !!bucketId &&
    !!fileId

  /** Grid hides image preview while upload is pending; table still shows it when mime is image. */
  const useImagePreview =
    canUseImagePreview && (variant === 'table' || !pending)

  const previewSrc = useMemo(() => {
    if (!useImagePreview) return null
    const raw = sdk.forProject(projectId).storage.getFilePreview({
      bucketId,
      fileId,
      width: variant === 'grid' ? 400 : 80,
      output: avifSupported ? ImageFormat.Avif : undefined,
    })
    return withStoragePreviewAdminMode(raw)
  }, [
    projectId,
    bucketId,
    fileId,
    useImagePreview,
    avifSupported,
    variant,
  ])

  if (variant === 'grid') {
    if (previewSrc) {
      return (
        <div
          className={cn(
            'h-32 w-full overflow-hidden border-b border-border',
            className,
          )}
        >
          <img
            src={previewSrc}
            alt={name ?? ''}
            className="h-full w-full object-cover"
          />
        </div>
      )
    }
    return (
      <div
        className={cn(
          'flex h-32 w-full items-center justify-center border-b border-border',
          iconClass,
          className,
        )}
      >
        <FileIcon className="h-12 w-12" />
      </div>
    )
  }

  // table
  const imgClass =
    'h-10 w-10 rounded-md object-cover border border-border'
  if (previewSrc) {
    return (
      <img
        src={previewSrc}
        alt={name ?? ''}
        className={cn(imgClass, className)}
      />
    )
  }
  return (
    <div
      className={cn(
        'flex h-10 w-10 items-center justify-center rounded-md',
        iconClass,
        className,
      )}
    >
      <FileIcon className="h-5 w-5" />
    </div>
  )
}
