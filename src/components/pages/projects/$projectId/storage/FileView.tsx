import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, useLocation } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useFile, useBucket, Dependencies } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { FileSecurity } from './FileSecurity'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  ArrowLeft,
  Loader2,
  Trash2,
  Download,
  Copy,
  MoreHorizontal,
  Plus,
  ExternalLink,
  File,
  AlertCircle,
} from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import { formatDateTime } from '@/lib/date-utils'
import { cn } from '@/lib/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Link } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { ID } from '@appwrite.io/console'

export function FileView() {
  const { projectId, bucketId, fileId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Fetch file data
  const { data: file, isLoading: fileLoading } = useFile(
    projectId,
    bucketId,
    fileId,
  )

  // Fetch bucket data to check file level security
  const { data: bucket } = useBucket(projectId, bucketId)

  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [imageLoaded, setImageLoaded] = useState(false)

  // Reset image loaded state when file changes
  useEffect(() => {
    setImageLoaded(false)
  }, [fileId])

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const fileIndex = pathParts.findIndex(
      (part, idx) => part === 'files' && pathParts[idx + 1] === fileId,
    )

    if (fileIndex >= 0 && pathParts[fileIndex + 2]) {
      const tabFromPath = pathParts[fileIndex + 2]
      if (tabFromPath === 'security') {
        return 'security'
      }
    }

    // Default to overview for index route
    return 'overview'
  }, [location.pathname, fileId])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'overview',
        label: 'Overview',
        to: '/projects/$projectId/storage/$bucketId/files/$fileId',
        params: {
          projectId: projectId as string,
          bucketId: bucketId as string,
          fileId: fileId as string,
        },
      },
      {
        id: 'security',
        label: 'Security',
        to: '/projects/$projectId/storage/$bucketId/files/$fileId/security',
        params: {
          projectId: projectId as string,
          bucketId: bucketId as string,
          fileId: fileId as string,
        },
      },
    ],
    [projectId, bucketId, fileId],
  )

  // Delete file mutation
  const deleteFileMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !bucketId || !fileId) {
        throw new Error('Project ID, Bucket ID, and File ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.deleteFile({ bucketId, fileId })
    },
    onSuccess: () => {
      toast.success('File has been deleted')
      queryClient.invalidateQueries({ queryKey: Dependencies.FILES })
      setDeleteDialogOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId/',
        params: { projectId: projectId!, bucketId: bucketId! },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleDeleteFile = () => {
    deleteFileMutation.mutate()
  }

  const handleDownload = () => {
    if (!projectId || !bucketId || !fileId) return
    const projectSdk = sdk.forProject(projectId)
    // Get file download URL (returns string)
    const url = projectSdk.storage.getFileDownload({
      bucketId,
      fileId,
    })
    // Add mode=admin for console preview
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  const handlePreview = () => {
    if (!projectId || !bucketId || !fileId) return
    const projectSdk = sdk.forProject(projectId)
    // Get file preview URL (returns string)
    const url = projectSdk.storage.getFilePreview({
      bucketId,
      fileId,
    })
    // Add mode=admin for console preview
    const urlWithMode = url + (url.includes('?') ? '&' : '?') + 'mode=admin'
    window.open(urlWithMode, '_blank')
  }

  if (fileLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!file) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">File not found</p>
      </div>
    )
  }

  const isPending =
    file.chunksTotal > 0 && file.chunksUploaded < file.chunksTotal

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/storage/$bucketId/',
      params: { projectId: projectId!, bucketId: bucketId! },
    })
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span>{file.name}</span>
            {isPending && (
              <Badge variant="secondary" className="text-[11px]">
                Pending
              </Badge>
            )}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        showFilters={false}
        fullWidthBorder
        contentAfterBorder={
          activeTab === 'security' && bucket && !bucket.fileSecurity ? (
            <div className="border-b border-border bg-amber-500/5">
              <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                <Alert
                  variant="default"
                  className="border-amber-500/30 bg-transparent"
                >
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    File level security is disabled
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                    <span className="inline">
                      File-level permissions are only effective when file level
                      security is enabled at the bucket level.{' '}
                      <Link
                        to="/projects/$projectId/storage/$bucketId/security"
                        params={{ projectId: projectId!, bucketId: bucketId! }}
                        className="font-medium underline hover:no-underline inline"
                      >
                        Enable file level security
                      </Link>{' '}
                      in the bucket Security tab to apply file-specific
                      permissions.
                    </span>
                  </AlertDescription>
                </Alert>
              </div>
            </div>
          ) : undefined
        }
      />

      {/* Content */}
      <div className="mx-auto w-full max-w-7xl flex-1">
        {activeTab === 'overview' && (
          <div className="px-4 py-4 sm:px-6">
            <div className="space-y-6">
              {/* File Information */}
              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-foreground">
                    {file.name}
                  </h3>
                </div>
                <div className="border-t border-border" />
                <div className="px-6 py-4">
                  <div className="flex flex-col gap-6 sm:flex-row">
                    {/* Preview Image - Left */}
                    {!isPending && file.mimeType?.startsWith('image/') && (
                      <div className="flex-shrink-0 w-full max-w-[300px]">
                        {projectId &&
                          bucketId &&
                          fileId &&
                          (() => {
                            const previewUrl = sdk
                              .forProject(projectId)
                              .storage.getFilePreview({
                                bucketId,
                                fileId,
                                width: 400,
                              })
                            // Add mode=admin for console preview
                            const urlWithMode =
                              previewUrl +
                              (previewUrl.includes('?') ? '&' : '?') +
                              'mode=admin'
                            return (
                              <div className="aspect-square w-full overflow-hidden rounded-lg border border-border">
                                <img
                                  src={urlWithMode}
                                  alt={file.name}
                                  onLoad={() => setImageLoaded(true)}
                                  className={cn(
                                    'h-full w-full object-cover transition-opacity duration-500',
                                    imageLoaded ? 'opacity-100' : 'opacity-0',
                                  )}
                                />
                              </div>
                            )
                          })()}
                      </div>
                    )}
                    {/* Metadata - Right */}
                    <div className="flex-1 space-y-4 min-w-0">
                      <div>
                        <Label className="text-[13px] font-medium text-foreground">
                          File ID
                        </Label>
                        <div className="mt-1.5">
                          <CopyableId id={file.$id} size="sm" />
                        </div>
                      </div>
                      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Type
                          </Label>
                          <p className="text-[13px] text-muted-foreground mt-1.5">
                            {file.mimeType}
                          </p>
                        </div>
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Size
                          </Label>
                          <p className="text-[13px] text-muted-foreground mt-1.5">
                            {formatBytes(file.sizeOriginal)}
                          </p>
                        </div>
                      </div>
                      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Created
                          </Label>
                          <div className="mt-1.5">
                            <DateTooltip
                              date={new Date(file.$createdAt)}
                              className="text-[13px] text-muted-foreground"
                            />
                          </div>
                        </div>
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Updated
                          </Label>
                          <div className="mt-1.5">
                            <DateTooltip
                              date={new Date(file.$updatedAt)}
                              className="text-[13px] text-muted-foreground"
                            />
                          </div>
                        </div>
                      </div>
                      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Encryption
                          </Label>
                          <p className="text-[13px] text-muted-foreground mt-1.5">
                            {file.encryption === true ? 'Enabled' : 'Disabled'}
                          </p>
                        </div>
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Compression
                          </Label>
                          <p className="text-[13px] text-muted-foreground mt-1.5">
                            {file.compression === 'none' || !file.compression
                              ? 'None'
                              : file.compression === 'gzip'
                                ? 'Gzip'
                                : file.compression === 'zstd'
                                  ? 'Zstd'
                                  : file.compression.charAt(0).toUpperCase() +
                                    file.compression.slice(1)}
                          </p>
                        </div>
                      </div>
                      {file.signature && (
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            MD5 Signature
                          </Label>
                          <div className="mt-1.5">
                            <CopyableId
                              id={file.signature}
                              size="sm"
                              maxWidth={200}
                            />
                          </div>
                        </div>
                      )}
                      {isPending && (
                        <div>
                          <Label className="text-[13px] font-medium text-foreground">
                            Upload Progress
                          </Label>
                          <p className="text-[13px] text-muted-foreground mt-1.5">
                            {file.chunksUploaded} of {file.chunksTotal} chunks
                            uploaded
                          </p>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
                {!isPending && (
                  <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                      onClick={handlePreview}
                    >
                      <ExternalLink className="h-3.5 w-3.5 mr-1.5" />
                      Preview
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 text-[13px]"
                      onClick={handleDownload}
                    >
                      <Download className="h-3.5 w-3.5 mr-1.5" />
                      Download
                    </Button>
                  </div>
                )}
              </div>

              {/* Delete File */}
              <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
                <div className="px-6 py-4">
                  <h3 className="text-[15px] font-semibold text-red-600 dark:text-red-400">
                    Delete file
                  </h3>
                  <p className="text-[13px] text-muted-foreground mt-2">
                    Permanently delete this file. This action cannot be undone.
                  </p>
                </div>
                <div className="border-t border-red-500/20" />
                <div className="px-6 py-4">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                      <File className="h-5 w-5 text-muted-foreground" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-[14px] font-medium text-foreground truncate">
                        {file.name}
                      </p>
                      <p className="text-[12px] text-muted-foreground">
                        Last updated:{' '}
                        <DateTooltip
                          date={new Date(file.$updatedAt)}
                          showFormattedDate
                          className="text-foreground"
                        />
                      </p>
                    </div>
                  </div>
                </div>
                <div className="px-6 py-4 border-t border-red-500/20 bg-red-500/5">
                  <Dialog
                    open={deleteDialogOpen}
                    onOpenChange={setDeleteDialogOpen}
                  >
                    <DialogTrigger asChild>
                      <Button
                        variant="destructive"
                        size="sm"
                        className="h-9 text-[13px]"
                      >
                        Delete
                      </Button>
                    </DialogTrigger>
                    <DialogContent className="sm:max-w-md p-0">
                      <DialogHeader className="px-6 pt-6 text-left">
                        <DialogTitle>Delete file</DialogTitle>
                        <DialogDescription className="text-[13px] mt-2">
                          Are you sure you want to delete{' '}
                          <strong>{file.name}</strong>? This action cannot be
                          undone.
                        </DialogDescription>
                      </DialogHeader>
                      <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => setDeleteDialogOpen(false)}
                          disabled={deleteFileMutation.isPending}
                        >
                          Cancel
                        </Button>
                        <Button
                          variant="destructive"
                          size="sm"
                          className="h-9 text-[13px]"
                          disabled={deleteFileMutation.isPending}
                          onClick={handleDeleteFile}
                        >
                          Delete
                        </Button>
                      </div>
                    </DialogContent>
                  </Dialog>
                </div>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'security' && <FileSecurity />}
      </div>
    </div>
  )
}
