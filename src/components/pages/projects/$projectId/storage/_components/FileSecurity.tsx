import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk, getApiEndpoint } from '@/lib/appwrite/sdk'
import { useFile, useFileTokens, Dependencies } from '@/lib/react-query/hooks'
import { FILE_TOKENS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Badge } from '@/components/ui/badge'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { PermissionsEditor } from '../../auth/PermissionsEditor'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Loader2,
  Plus,
  Copy,
  Trash2,
  Eye,
  Check,
  AlertCircle,
  MoreHorizontal,
} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useProject } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

// Helper function to mask secret (matches API keys pattern)
function maskSecret(secret: string): string {
  return secret.slice(0, 7) + '•'.repeat(24) + secret.slice(-4)
}

type FileTokenExpiryOption =
  | 'never'
  | '1h'
  | '24h'
  | '7d'
  | '30d'
  | 'custom'

const FILE_TOKEN_EXPIRY_OPTIONS: {
  value: FileTokenExpiryOption
  label: string
}[] = [
  { value: 'never', label: 'Never' },
  { value: '1h', label: '1 hour' },
  { value: '24h', label: '24 hours' },
  { value: '7d', label: '7 days' },
  { value: '30d', label: '30 days' },
  { value: 'custom', label: 'Custom' },
]

function formatDatetimeLocalForInput(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
}

function getFileTokenCreateExpirationIso(
  option: FileTokenExpiryOption,
  customLocal: string,
): string | undefined {
  switch (option) {
    case 'never':
      return undefined
    case '1h':
      return new Date(Date.now() + 60 * 60 * 1000).toISOString()
    case '24h':
      return new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString()
    case '7d':
      return new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString()
    case '30d':
      return new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString()
    case 'custom': {
      const trimmed = customLocal.trim()
      if (!trimmed) return undefined
      const d = new Date(trimmed)
      if (Number.isNaN(d.getTime())) return undefined
      return d.toISOString()
    }
  }
}

export type FileSecurityProps = {
  /** Overrides route params when the file is not in the URL (e.g. storage inspector). */
  projectId?: string
  bucketId?: string
  fileId?: string
  /** `panel` uses tighter padding for the bucket files inspector. */
  variant?: 'page' | 'panel'
}

export function FileSecurity({
  projectId: projectIdProp,
  bucketId: bucketIdProp,
  fileId: fileIdProp,
  variant = 'page',
}: FileSecurityProps = {}) {
  const params = useParams({ strict: false }) as {
    projectId?: string
    bucketId?: string
    fileId?: string
  }
  const projectId = projectIdProp ?? params.projectId
  const bucketId = bucketIdProp ?? params.bucketId
  const fileId = fileIdProp ?? params.fileId
  const queryClient = useQueryClient()

  // State for permissions
  const [filePermissions, setFilePermissions] = useState<string[]>([])

  // State for tokens
  const [createTokenDialogOpen, setCreateTokenDialogOpen] = useState(false)
  const [tokenExpiration, setTokenExpiration] = useState('')
  const [tokenExpiryOption, setTokenExpiryOption] =
    useState<FileTokenExpiryOption>('never')
  const [viewingTokenId, setViewingTokenId] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [deleteTokenDialogOpen, setDeleteTokenDialogOpen] = useState(false)
  const [tokenToDelete, setTokenToDelete] = useState<string | null>(null)
  const [copyTokenDialogOpen, setCopyTokenDialogOpen] = useState(false)
  const [tokenForCopy, setTokenForCopy] = useState<Models.ResourceToken | null>(
    null,
  )
  const [copyUrlMode, setCopyUrlMode] = useState<
    'preview' | 'view' | 'download'
  >('preview')
  const [tokensPage, setTokensPage] = useState(1)
  const [tokensPageSize, setTokensPageSize] = useState(
    FILE_TOKENS_DEFAULT_PAGE_SIZE,
  )

  // Fetch file data
  const { data: file, isLoading: fileLoading } = useFile(
    projectId,
    bucketId,
    fileId,
  )

  // Fetch file tokens with pagination
  const { data: tokensData, isLoading: tokensLoading } = useFileTokens(
    projectId,
    bucketId,
    fileId,
    tokensPage - 1, // Convert to 0-indexed
    tokensPageSize,
  )

  // Fetch project for endpoint
  const { project: currentProject } = useProject(projectId)

  const tokens = tokensData?.tokens || []
  const tokensTotal = tokensData?.total || 0

  // Get endpoint from project region (centralized in SDK)
  const projectEndpoint = useMemo(
    () => getApiEndpoint(currentProject?.region),
    [currentProject?.region],
  )

  // Initialize state when file loads (guard file.$id === fileId so we never
  // show another file's permissions during a fast switch / cache edge case).
  useEffect(() => {
    if (file && fileId && file.$id === fileId) {
      setFilePermissions(file.$permissions || [])
    } else if (!file) {
      setFilePermissions([])
    }
  }, [file, fileId])

  // Helper to compare arrays
  const arraysEqual = (a: string[], b: string[]): boolean => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Update file permissions mutation
  const updateFilePermissionsMutation = useMutation({
    mutationFn: async (permissions: string[]) => {
      if (!projectId || !bucketId || !fileId) {
        throw new Error('Project ID, Bucket ID, and File ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateFile({
        bucketId,
        fileId,
        permissions,
      })
    },
    onSuccess: () => {
      toast.success('File permissions have been updated')
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleFilePermissionsUpdate = () => {
    if (!arraysEqual(filePermissions, file?.$permissions || [])) {
      updateFilePermissionsMutation.mutate(filePermissions)
    }
  }

  // Create token mutation
  const createTokenMutation = useMutation({
    mutationFn: async (expiration?: string) => {
      if (!projectId || !bucketId || !fileId) {
        throw new Error('Project ID, Bucket ID, and File ID are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.tokens.createFileToken({
        bucketId,
        fileId,
        expire: expiration || undefined,
      })
    },
    onSuccess: () => {
      toast.success('Token has been created')
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE_TOKENS })
      setCreateTokenDialogOpen(false)
      setTokenExpiration('')
      setTokenExpiryOption('never')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Delete token mutation
  const deleteTokenMutation = useMutation({
    mutationFn: async (tokenId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.tokens.delete({ tokenId })
    },
    onSuccess: () => {
      toast.success('Token has been deleted')
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE_TOKENS })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleCreateToken = () => {
    const expiration = getFileTokenCreateExpirationIso(
      tokenExpiryOption,
      tokenExpiration,
    )
    if (
      tokenExpiryOption === 'custom' &&
      tokenExpiration.trim() &&
      expiration === undefined
    ) {
      toast.error('Invalid expiration date')
      return
    }
    createTokenMutation.mutate(expiration)
  }

  const createTokenExpiryInvalid =
    tokenExpiryOption === 'custom' &&
    (!tokenExpiration.trim() ||
      Number.isNaN(new Date(tokenExpiration).getTime()))

  const copyToClipboard = (text: string, field?: string) => {
    navigator.clipboard.writeText(text)
    if (field) {
      setCopiedField(field)
      setTimeout(() => setCopiedField(null), 2000)
    } else {
      toast.success('Copied to clipboard')
    }
  }

  const viewingToken = tokens.find(
    (t: Models.ResourceToken) => t.$id === viewingTokenId,
  )

  // Build file URL with token (public REST URL — must include `project` query param)
  const getFileUrl = (
    mode: 'preview' | 'view' | 'download',
    tokenSecret: string,
  ): string => {
    if (!projectId || !bucketId || !fileId) return ''
    const baseUrl = `${projectEndpoint}/storage/buckets/${bucketId}/files/${fileId}`
    const params = new URLSearchParams({
      project: projectId,
      token: tokenSecret,
    })
    const qs = params.toString()

    if (mode === 'preview') {
      return `${baseUrl}/preview?${qs}`
    }
    if (mode === 'view') {
      return `${baseUrl}/view?${qs}`
    }
    return `${baseUrl}/download?${qs}`
  }

  const handleOpenCopyDialog = (token: Models.ResourceToken) => {
    setTokenForCopy(token)
    setCopyTokenDialogOpen(true)
    setCopyUrlMode('preview')
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

  const cardPad = variant === 'panel' ? 'px-4 py-3' : 'px-6 py-4'
  const tokenRowPad = variant === 'panel' ? 'px-3 py-2.5' : 'px-4 py-3'

  return (
    <div className={cn('w-full', variant === 'page' && 'px-4 py-4 sm:px-6')}>
      <div className={cn(variant === 'panel' ? 'space-y-4' : 'space-y-6')}>
        {/* Permissions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className={cardPad}>
            <h3 className="text-[15px] font-semibold text-foreground">
              Permissions
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              Choose who can access this file.{' '}
              <a
                href="https://appwrite.io/docs/permissions"
                target="_blank"
                rel="noopener noreferrer"
                className="text-primary hover:underline"
              >
                Learn more
              </a>
              .
            </p>
          </div>
          <div className="border-t border-border" />
          <div className={cardPad}>
            <PermissionsEditor
              permissions={filePermissions}
              onPermissionsChange={setFilePermissions}
              withCreate={false}
              projectId={projectId}
            />
          </div>
          <div className={cn(cardPad, 'border-t border-border bg-muted/30')}>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                arraysEqual(filePermissions, file.$permissions || []) ||
                updateFilePermissionsMutation.isPending
              }
              onClick={handleFilePermissionsUpdate}
            >
              Update
            </Button>
          </div>
        </div>

        {/* Tokens */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className={cardPad}>
            <div
              className={cn(
                'flex gap-3',
                variant === 'panel'
                  ? 'flex-col items-start'
                  : 'flex-row items-center justify-between',
              )}
            >
              <div
                className={cn(variant === 'panel' && 'w-full min-w-0')}
              >
                <h3 className="text-[15px] font-semibold text-foreground">
                  Tokens
                </h3>
                <p
                  className={cn(
                    'text-muted-foreground mt-2',
                    variant === 'panel'
                      ? 'text-[12px] leading-snug line-clamp-4'
                      : 'text-[13px]',
                  )}
                >
                  File tokens allow you to share files publicly with anyone
                  without configuring bucket or file permissions. They work
                  around browser restrictions on third-party cookies and can be
                  set to expire on a specific date or work indefinitely.{' '}
                  <a
                    href="https://appwrite.io/docs/products/storage/file-tokens"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-primary hover:underline"
                  >
                    Learn more
                  </a>
                  .
                </p>
              </div>
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => setCreateTokenDialogOpen(true)}
              >
                <Plus className="h-3.5 w-3.5 mr-1.5" />
                Create token
              </Button>
            </div>
          </div>
          <div className="border-t border-border" />
          <div className={cardPad}>
            {tokensLoading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            ) : tokens.length > 0 ? (
              <>
                <div className="overflow-hidden rounded-lg border border-border">
                  <div className="divide-y divide-border">
                    {tokens.map((token: Models.ResourceToken) => {
                    const now = new Date()
                    const expireDate = token.expire
                      ? new Date(token.expire)
                      : null
                    const isExpired = expireDate && expireDate < now
                    const isExpiringSoon =
                      expireDate &&
                      !isExpired &&
                      expireDate.getTime() - now.getTime() <=
                        7 * 24 * 60 * 60 * 1000 // 7 days

                    return (
                      <div
                        key={token.$id}
                        className={cn(
                          'flex items-center gap-2 sm:gap-3 transition-colors hover:bg-muted/30',
                          tokenRowPad,
                        )}
                      >
                        <div className="min-w-0 flex-1 space-y-1">
                          {token.secret ? (
                            <div className="flex min-w-0 items-center gap-1.5">
                              <code className="max-w-[min(100%,220px)] truncate rounded bg-muted px-2 py-0.5 font-mono text-[12px] text-muted-foreground sm:max-w-md">
                                {maskSecret(token.secret)}
                              </code>
                              <button
                                type="button"
                                onClick={() => setViewingTokenId(token.$id)}
                                className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                title="View token"
                              >
                                <Eye className="h-3.5 w-3.5" />
                              </button>
                              <button
                                type="button"
                                onClick={() =>
                                  copyToClipboard(
                                    token.secret,
                                    `token-secret-${token.$id}`,
                                  )
                                }
                                className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                title="Copy secret"
                              >
                                {copiedField ===
                                `token-secret-${token.$id}` ? (
                                  <Check className="h-3.5 w-3.5 text-emerald-500" />
                                ) : (
                                  <Copy className="h-3.5 w-3.5" />
                                )}
                              </button>
                            </div>
                          ) : null}

                          <div className="min-w-0 overflow-x-auto">
                            <div
                              className={cn(
                                'flex w-max max-w-none flex-nowrap items-center gap-x-2 text-muted-foreground sm:gap-x-2.5',
                                variant === 'panel'
                                  ? 'text-[11px]'
                                  : 'text-[12px]',
                              )}
                            >
                              <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap">
                                <span>Created</span>
                                <DateTooltip
                                  date={token.$createdAt}
                                  className={cn(
                                    'text-muted-foreground',
                                    variant === 'panel'
                                      ? 'text-[11px]'
                                      : 'text-[12px]',
                                  )}
                                />
                              </span>
                              <span
                                aria-hidden
                                className="shrink-0 text-muted-foreground/40"
                              >
                                ·
                              </span>
                              <span className="inline-flex shrink-0 flex-nowrap items-center gap-1 whitespace-nowrap">
                                <span>Expires</span>
                                {token.expire ? (
                                  <>
                                    <DateTooltip
                                      date={token.expire}
                                      className={cn(
                                        'text-muted-foreground',
                                        variant === 'panel'
                                          ? 'text-[11px]'
                                          : 'text-[12px]',
                                      )}
                                    />
                                    {isExpired ? (
                                      <Badge
                                        variant="error"
                                        className="text-[10px] shrink-0"
                                      >
                                        Expired
                                      </Badge>
                                    ) : isExpiringSoon ? (
                                      <Badge
                                        variant="warning"
                                        className="text-[10px] shrink-0"
                                      >
                                        Expires soon
                                      </Badge>
                                    ) : null}
                                  </>
                                ) : (
                                  <span className="text-foreground">Never</span>
                                )}
                              </span>
                              <span
                                aria-hidden
                                className="shrink-0 text-muted-foreground/40"
                              >
                                ·
                              </span>
                              <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap">
                                <span>Accessed</span>
                                {token.accessedAt ? (
                                  <DateTooltip
                                    date={token.accessedAt}
                                    className={cn(
                                      'text-muted-foreground',
                                      variant === 'panel'
                                        ? 'text-[11px]'
                                        : 'text-[12px]',
                                    )}
                                  />
                                ) : (
                                  <span className="text-foreground">Never</span>
                                )}
                              </span>
                            </div>
                          </div>
                        </div>

                        <div className="flex shrink-0 items-center gap-0.5">
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-muted-foreground"
                                aria-label="Copy URL"
                                onClick={() => handleOpenCopyDialog(token)}
                              >
                                <Copy className="h-3.5 w-3.5" />
                              </Button>
                            </TooltipTrigger>
                            <TooltipContent>Copy URL</TooltipContent>
                          </Tooltip>
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 text-muted-foreground"
                                aria-label="More"
                              >
                                <MoreHorizontal className="h-4 w-4" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                              <DropdownMenuItem
                                onClick={() =>
                                  copyToClipboard(
                                    token.$id,
                                    `token-id-${token.$id}`,
                                  )
                                }
                              >
                                {copiedField === `token-id-${token.$id}` ? (
                                  <>
                                    <Check className="h-3.5 w-3.5 mr-1.5 text-emerald-500" />
                                    Copied
                                  </>
                                ) : (
                                  <>
                                    <Copy className="h-3.5 w-3.5 mr-1.5" />
                                    Copy ID
                                  </>
                                )}
                              </DropdownMenuItem>
                              <DropdownMenuItem
                                className="text-destructive"
                                onClick={() => {
                                  setTokenToDelete(token.$id)
                                  setDeleteTokenDialogOpen(true)
                                }}
                              >
                                <Trash2 className="h-3.5 w-3.5 mr-1.5" />
                                Delete
                              </DropdownMenuItem>
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </div>
                      </div>
                    )
                  })}
                  </div>
                </div>
                {tokensTotal > 0 && (
                  <div className="mt-2">
                    <Pagination
                      currentPage={tokensPage}
                      totalItems={tokensTotal}
                      pageSize={tokensPageSize}
                      pageSizeOptions={[10, 25, 50, 100]}
                      onPageChange={setTokensPage}
                      onPageSizeChange={(size) => {
                        setTokensPageSize(size)
                        setTokensPage(1)
                      }}
                      itemLabel="tokens"
                      className="py-0"
                    />
                  </div>
                )}
              </>
            ) : (
              <div className="rounded-lg border border-border bg-card py-8 text-center">
                <p className="text-[13px] text-muted-foreground">
                  No tokens found. Create a token to share this file publicly.
                </p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Create Token Dialog */}
      <Dialog
        open={createTokenDialogOpen}
        onOpenChange={(open) => {
          setCreateTokenDialogOpen(open)
          if (!open) {
            setTokenExpiration('')
            setTokenExpiryOption('never')
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create file token</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Create a token to share this file publicly. Choose when the token
              should expire.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label className="text-[13px] font-medium text-foreground">
                  Expiration
                </Label>
                <RadioGroup
                  value={tokenExpiryOption}
                  onValueChange={(value) => {
                    const next = value as FileTokenExpiryOption
                    setTokenExpiryOption(next)
                    if (next === 'custom' && !tokenExpiration.trim()) {
                      setTokenExpiration(
                        formatDatetimeLocalForInput(
                          new Date(Date.now() + 24 * 60 * 60 * 1000),
                        ),
                      )
                    }
                  }}
                  disabled={createTokenMutation.isPending}
                  className="grid grid-cols-2 gap-3"
                >
                  {FILE_TOKEN_EXPIRY_OPTIONS.map((option) => {
                    const isSelected = tokenExpiryOption === option.value
                    return (
                      <div key={option.value}>
                        <RadioGroupItem
                          value={option.value}
                          id={`file-token-expire-${option.value}`}
                          className="peer sr-only"
                        />
                        <Label
                          htmlFor={`file-token-expire-${option.value}`}
                          className={cn(
                            'flex cursor-pointer items-center justify-center rounded-lg border border-border bg-card px-4 py-3 text-sm font-medium transition-all',
                            'hover:border-primary/50 hover:bg-accent/50',
                            isSelected && 'border-primary bg-accent',
                            createTokenMutation.isPending &&
                              'cursor-not-allowed opacity-50',
                          )}
                        >
                          {option.label}
                        </Label>
                      </div>
                    )
                  })}
                </RadioGroup>
                {tokenExpiryOption === 'custom' && (
                  <div className="pt-2">
                    <Label
                      htmlFor="file-token-expiration-custom"
                      className="text-[12px] font-medium text-muted-foreground"
                    >
                      Date and time
                    </Label>
                    <Input
                      id="file-token-expiration-custom"
                      type="datetime-local"
                      value={tokenExpiration}
                      onChange={(e) => setTokenExpiration(e.target.value)}
                      disabled={createTokenMutation.isPending}
                      className={cn(
                        'mt-1.5',
                        createTokenExpiryInvalid && 'border-destructive',
                      )}
                    />
                    {createTokenExpiryInvalid && (
                      <p className="text-[12px] text-destructive mt-1">
                        Enter a valid date and time
                      </p>
                    )}
                  </div>
                )}
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => {
                setCreateTokenDialogOpen(false)
                setTokenExpiration('')
                setTokenExpiryOption('never')
              }}
              disabled={createTokenMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              onClick={handleCreateToken}
              disabled={
                createTokenMutation.isPending || createTokenExpiryInvalid
              }
            >
              Create token
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Token View Modal */}
      <Dialog
        open={viewingTokenId !== null}
        onOpenChange={(open) => !open && setViewingTokenId(null)}
      >
        <DialogContent className="sm:max-w-[600px] p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>File Token</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Copy the full token below. Keep it secure and never share it
              publicly.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">
                Token
              </label>
              <textarea
                readOnly
                value={viewingToken?.secret || ''}
                className="w-full min-h-[100px] rounded-md border border-border bg-muted px-3 py-2 font-mono text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                onClick={(e) => (e.target as HTMLTextAreaElement).select()}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setViewingTokenId(null)}>
              Close
            </Button>
            <Button
              variant="outline"
              onClick={() => {
                if (viewingToken?.secret) {
                  copyToClipboard(viewingToken.secret, 'tokenModal')
                }
              }}
              className="gap-2"
            >
              {copiedField === 'tokenModal' ? (
                <>
                  <Check className="h-4 w-4" />
                  Copied
                </>
              ) : (
                <>
                  <Copy className="h-4 w-4" />
                  Copy
                </>
              )}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete Token Confirmation Dialog */}
      <Dialog
        open={deleteTokenDialogOpen}
        onOpenChange={(open) => {
          setDeleteTokenDialogOpen(open)
          if (!open) {
            setTokenToDelete(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete token</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete this token? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => {
                setDeleteTokenDialogOpen(false)
                setTokenToDelete(null)
              }}
              disabled={deleteTokenMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (tokenToDelete) {
                  deleteTokenMutation.mutate(tokenToDelete, {
                    onSuccess: () => {
                      setDeleteTokenDialogOpen(false)
                      setTokenToDelete(null)
                    },
                  })
                }
              }}
              disabled={deleteTokenMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Copy Token/URL Dialog */}
      <Dialog
        open={copyTokenDialogOpen}
        onOpenChange={(open) => {
          setCopyTokenDialogOpen(open)
          if (!open) {
            setTokenForCopy(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-[600px] p-0 max-h-[90dvh] overflow-hidden flex flex-col">
          <DialogHeader className="px-6 pt-6 text-left shrink-0">
            <DialogTitle>Copy File URL</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Use the token-based URL below to access this file securely.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border shrink-0" />

          <div className="px-6 pb-4 pt-0 overflow-y-auto flex-1 min-h-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between gap-2 flex-wrap">
                  <Label className="text-sm font-medium text-foreground">
                    URL
                  </Label>
                  <div className="flex items-center gap-2 flex-shrink-0">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        if (tokenForCopy?.secret) {
                          setCopyUrlMode('preview')
                          copyToClipboard(
                            getFileUrl('preview', tokenForCopy.secret),
                            'copyUrl',
                          )
                        }
                      }}
                      className="h-7 text-[11px]"
                    >
                      {copiedField === 'copyUrl' &&
                      copyUrlMode === 'preview' ? (
                        <>
                          <Check className="h-3 w-3 mr-1 text-emerald-500" />
                          Copied
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 mr-1" />
                          Preview
                        </>
                      )}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        if (tokenForCopy?.secret) {
                          setCopyUrlMode('view')
                          copyToClipboard(
                            getFileUrl('view', tokenForCopy.secret),
                            'copyUrl',
                          )
                        }
                      }}
                      className="h-7 text-[11px]"
                    >
                      {copiedField === 'copyUrl' && copyUrlMode === 'view' ? (
                        <>
                          <Check className="h-3 w-3 mr-1 text-emerald-500" />
                          Copied
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 mr-1" />
                          View
                        </>
                      )}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        if (tokenForCopy?.secret) {
                          setCopyUrlMode('download')
                          copyToClipboard(
                            getFileUrl('download', tokenForCopy.secret),
                            'copyUrl',
                          )
                        }
                      }}
                      className="h-7 text-[11px]"
                    >
                      {copiedField === 'copyUrl' &&
                      copyUrlMode === 'download' ? (
                        <>
                          <Check className="h-3 w-3 mr-1 text-emerald-500" />
                          Copied
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 mr-1" />
                          Download
                        </>
                      )}
                    </Button>
                  </div>
                </div>
                <Textarea
                  readOnly
                  value={
                    tokenForCopy?.secret
                      ? getFileUrl(copyUrlMode, tokenForCopy.secret)
                      : ''
                  }
                  className="font-mono text-[12px] min-h-[80px] resize-none break-all"
                  onClick={(e) => (e.target as HTMLTextAreaElement).select()}
                />
                <p className="text-[11px] text-muted-foreground">
                  {copyUrlMode === 'preview' &&
                    'Apply transformations or filters. Good for thumbnails or previews.'}
                  {copyUrlMode === 'view' &&
                    'Display the file in the browser. Good for images and documents.'}
                  {copyUrlMode === 'download' &&
                    'Download the file directly. Good for files that need to be saved.'}
                </p>
              </div>

              {tokenForCopy && !tokenForCopy.expire && (
                <Alert
                  variant="destructive"
                  className="bg-destructive/10 border-destructive/20"
                >
                  <AlertCircle className="h-4 w-4 text-destructive" />
                  <AlertDescription className="text-[12px] text-destructive">
                    <span className="font-semibold">No expiration date.</span>{' '}
                    This token doesn't expire. Be cautious when sharing links.
                  </AlertDescription>
                </Alert>
              )}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end shrink-0">
            <Button
              variant="outline"
              onClick={() => {
                setCopyTokenDialogOpen(false)
                setTokenForCopy(null)
              }}
            >
              Close
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
