import { useMemo, useRef, useState } from 'react'
import {
  ChevronRight,
  Download,
  File as FileIcon,
  Folder,
  RefreshCw,
  Trash2,
  Upload,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
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
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { sdk } from '@/lib/appwrite/sdk'
import type { SandboxFile } from '@/lib/appwrite/sandboxes'
import {
  useDeleteSandboxFile,
  useSandboxFiles,
  useUploadSandboxFile,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/** Cloud refuses larger uploads (`Files/Create.php`). */
const UPLOAD_MAX_BYTES = 5 * 1024 * 1024

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

interface FilesProps {
  projectId: string
  sandboxId: string
  canWrite: boolean
}

/** Browse the sandbox workspace: paths are relative to it, `''` is its root. */
export function Files({ projectId, sandboxId, canWrite }: FilesProps) {
  const t = useT()
  const [segments, setSegments] = useState<string[]>([])
  const [deleteTarget, setDeleteTarget] = useState<SandboxFile | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const path = segments.join('/')
  const pathOf = (name: string) => (path ? `${path}/${name}` : name)

  const { files, isLoading, isFetching, error, refetch } = useSandboxFiles(
    projectId,
    sandboxId,
    path,
  )
  const uploadMutation = useUploadSandboxFile(projectId, sandboxId)
  const deleteMutation = useDeleteSandboxFile(projectId, sandboxId)

  const sorted = useMemo(
    () =>
      [...files].sort(
        (a, b) =>
          Number(b.type === 'directory') - Number(a.type === 'directory') ||
          a.name.localeCompare(b.name),
      ),
    [files],
  )

  const handleUpload = async (file: File) => {
    if (file.size > UPLOAD_MAX_BYTES) {
      toast.error(t('Files can be at most 5 MB.'))
      return
    }
    try {
      await uploadMutation.mutateAsync({ path: pathOf(file.name), file })
      toast.success(t('File uploaded'))
    } catch (uploadError) {
      toast.error(getErrorMessage(uploadError))
    }
  }

  const handleDownload = async (file: SandboxFile) => {
    try {
      const data = await sdk
        .forProject(projectId)
        .sandboxes.getFileDownload({ sandboxId, path: pathOf(file.name) })
      const url = URL.createObjectURL(new Blob([data]))
      const link = document.createElement('a')
      link.href = url
      link.download = file.name
      link.click()
      URL.revokeObjectURL(url)
    } catch (downloadError) {
      toast.error(getErrorMessage(downloadError))
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteMutation.mutateAsync(pathOf(deleteTarget.name))
      toast.success(
        deleteTarget.type === 'directory'
          ? t('Folder deleted')
          : t('File deleted'),
      )
      setDeleteTarget(null)
    } catch (deleteError) {
      toast.error(getErrorMessage(deleteError))
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <nav
          aria-label={t('Path')}
          className="flex min-w-0 flex-wrap items-center gap-1 font-mono text-[13px]"
        >
          <button
            type="button"
            className="rounded px-1 text-muted-foreground hover:text-foreground"
            onClick={() => setSegments([])}
          >
            {t('Workspace')}
          </button>
          {segments.map((segment, index) => (
            <span key={index} className="flex items-center gap-1">
              <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
              <button
                type="button"
                className="truncate rounded px-1 text-muted-foreground hover:text-foreground"
                onClick={() => setSegments(segments.slice(0, index + 1))}
              >
                {segment}
              </button>
            </span>
          ))}
        </nav>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-8 w-8 p-0"
            aria-label={t('Refresh')}
            disabled={isFetching}
            onClick={() => void refetch()}
          >
            <RefreshCw className="h-3.5 w-3.5" />
          </Button>
          <Button
            size="sm"
            className="h-8 text-[13px]"
            disabled={!canWrite || uploadMutation.isPending}
            onClick={() => inputRef.current?.click()}
          >
            <Upload className="h-3.5 w-3.5 me-1.5" />
            {t('Upload file')}
          </Button>
          <input
            ref={inputRef}
            type="file"
            className="hidden"
            onChange={(event) => {
              const file = event.target.files?.[0]
              event.target.value = ''
              if (file) void handleUpload(file)
            }}
          />
        </div>
      </div>

      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
              <TableHead className={`${HEAD_CLASS} w-[140px]`}>
                {t('Size')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} text-end w-[100px]`} />
            </TableRow>
          </TableHeader>
          <TableBody>
            {segments.length > 0 && (
              <TableRow
                className="cursor-pointer border-b border-border/50 hover:bg-muted/30"
                onClick={() => setSegments(segments.slice(0, -1))}
              >
                <TableCell
                  colSpan={3}
                  className="px-4 py-2.5 font-mono text-[13px] text-muted-foreground"
                >
                  ..
                </TableCell>
              </TableRow>
            )}
            {sorted.map((file) => (
              <TableRow
                key={file.name}
                className={`border-b border-border/50 hover:bg-muted/30 ${file.type === 'directory' ? 'cursor-pointer' : ''}`}
                onClick={(event) => {
                  if (file.type !== 'directory') return
                  const target = event.target as HTMLElement
                  if (target.closest('button, [role="menuitem"]')) return
                  setSegments([...segments, file.name])
                }}
              >
                <TableCell className="px-4 py-2.5">
                  <span className="flex min-w-0 items-center gap-2">
                    {file.type === 'directory' ? (
                      <Folder className="h-4 w-4 shrink-0 text-muted-foreground" />
                    ) : (
                      <FileIcon className="h-4 w-4 shrink-0 text-muted-foreground" />
                    )}
                    <span className="truncate font-mono text-[13px] text-foreground">
                      {file.name}
                    </span>
                  </span>
                </TableCell>
                <TableCell className="px-4 py-2.5 font-mono text-[12px] text-muted-foreground">
                  {file.type === 'file' ? formatDecimalBytes(file.size) : ''}
                </TableCell>
                <TableCell className="px-4 py-2.5 text-end">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <RowActionsMenuTrigger />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      {file.type === 'file' && (
                        <DropdownMenuItem
                          onClick={() => void handleDownload(file)}
                        >
                          <MenuItemContent icon={Download}>
                            {t('Download')}
                          </MenuItemContent>
                        </DropdownMenuItem>
                      )}
                      <DropdownMenuItem
                        disabled={!canWrite}
                        onClick={() => setDeleteTarget(file)}
                      >
                        <MenuItemContent icon={Trash2}>
                          {t('Delete')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>
            ))}
            {!isLoading && sorted.length === 0 && (
              <TableRow className="hover:bg-transparent">
                <TableCell
                  colSpan={3}
                  className="px-4 py-8 text-center text-[13px] text-muted-foreground"
                >
                  {error ? getErrorMessage(error) : t('This folder is empty.')}
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      <ConfirmActionDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={
          deleteTarget?.type === 'directory'
            ? t('Delete folder')
            : t('Delete file')
        }
        description={
          deleteTarget?.type === 'directory'
            ? t('The folder and everything in it are deleted.')
            : t('The file is deleted from the sandbox.')
        }
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
