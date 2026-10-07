import { Folder, Loader2, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { StorageFilePreviewThumb } from '@/components/global/shared/StorageFilePreviewThumb'
import { useFile } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export function parseStorageCompoundId(compound: string): {
  bucketId: string
  fileId: string
} | null {
  const i = compound.indexOf(':')
  if (i <= 0 || i === compound.length - 1) return null
  return { bucketId: compound.slice(0, i), fileId: compound.slice(i + 1) }
}

export interface EmailAttachmentRowProps {
  projectId: string
  compoundId: string
  buckets: readonly { $id: string; name: string }[]
  isDraft: boolean
  onRemove: () => void
}

export function EmailAttachmentRow({
  projectId,
  compoundId,
  buckets,
  isDraft,
  onRemove,
}: EmailAttachmentRowProps) {
  const t = useT()
  const parsed = parseStorageCompoundId(compoundId)
  const bucketLabel = parsed
    ? buckets.find((b) => b.$id === parsed.bucketId)?.name ?? parsed.bucketId
    : compoundId

  const { data: file, isLoading, isError } = useFile(
    projectId,
    parsed?.bucketId,
    parsed?.fileId,
  )

  if (!parsed) {
    return (
      <li className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Folder className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13px] font-medium text-foreground">
            {t('Invalid attachment')}
          </p>
          <p className="truncate font-mono text-[11px] text-muted-foreground">
            {compoundId}
          </p>
        </div>
        {isDraft && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-8 w-8 shrink-0 p-0"
            onClick={onRemove}
          >
            <X className="h-4 w-4" />
          </Button>
        )}
      </li>
    )
  }

  const displayName =
    !isLoading && !isError && file?.name
      ? file.name
      : isLoading
        ? undefined
        : compoundId
  const mimeType = file?.mimeType

  return (
    <li className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
      <div className="relative shrink-0">
        {isLoading ? (
          <div className="flex h-10 w-10 items-center justify-center rounded-md border border-border bg-muted/40">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <StorageFilePreviewThumb
            projectId={projectId}
            bucketId={parsed.bucketId}
            fileId={parsed.fileId}
            mimeType={mimeType}
            name={file?.name}
            variant="table"
            pending={false}
          />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <p
          className={cn(
            'truncate text-[13px] font-medium text-foreground',
            isLoading && 'text-muted-foreground',
          )}
          title={displayName}
        >
          {isLoading ? t('Loading file…') : displayName}
        </p>
        <div className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[12px] text-muted-foreground">
          <Folder className="h-3.5 w-3.5 shrink-0" aria-hidden />
          <span className="truncate" title={bucketLabel}>
            {bucketLabel}
          </span>
        </div>
      </div>
      {isDraft && (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 w-8 shrink-0 p-0"
          onClick={onRemove}
        >
          <X className="h-4 w-4" />
        </Button>
      )}
    </li>
  )
}
