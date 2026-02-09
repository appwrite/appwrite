import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useFile, useFileTokens, Dependencies } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { PermissionsEditor } from '../auth/PermissionsEditor'
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

// Helper function to mask secret (matches API keys pattern)
function maskSecret(secret: string): string {
  return secret.slice(0, 7) + '•'.repeat(24) + secret.slice(-4)
}

export function FileSecurity() {
  const { projectId, bucketId, fileId } = useParams({
    strict: false,
  })
  const queryClient = useQueryClient()

  // State for permissions
  const [filePermissions, setFilePermissions] = useState<string[]>([])

  // State for tokens
  const [createTokenDialogOpen, setCreateTokenDialogOpen] = useState(false)
  const [tokenExpiration, setTokenExpiration] = useState('')
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
  const [tokensPageSize, setTokensPageSize] = useState(25)

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

  // Get endpoint from project region
  const projectEndpoint = useMemo(() => {
    if (!currentProject?.region || currentProject.region === 'unknown') {
      return (
        import.meta.env.VITE_APPWRITE_ENDPOINT || 'https://cloud.appwrite.io/v1'
      )
    }
    const normalizedRegion = currentProject.region
      .trim()
      .toLowerCase()
      .replace(/\s+/g, '')
    return `https://${normalizedRegion}.cloud.appwrite.io/v1`
  }, [currentProject?.region])

  // Initialize state when file loads
  useEffect(() => {
    if (file) {
      setFilePermissions(file.$permissions || [])
    }
  }, [file])

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
    const expiration = tokenExpiration.trim()
    createTokenMutation.mutate(expiration || undefined)
  }

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

  // Build file URL with token
  const getFileUrl = (
    mode: 'preview' | 'view' | 'download',
    tokenSecret: string,
  ): string => {
    if (!projectId || !bucketId || !fileId) return ''
    const baseUrl = `${projectEndpoint}/storage/buckets/${bucketId}/files/${fileId}`
    const tokenParam = `token=${tokenSecret}`

    if (mode === 'preview') {
      return `${baseUrl}/preview?${tokenParam}`
    } else if (mode === 'view') {
      return `${baseUrl}/view?${tokenParam}`
    } else {
      return `${baseUrl}/download?${tokenParam}`
    }
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

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Permissions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
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
          <div className="px-6 py-4">
            <PermissionsEditor
              permissions={filePermissions}
              onPermissionsChange={setFilePermissions}
              withCreate={false}
              projectId={projectId}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
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
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div>
                <h3 className="text-[15px] font-semibold text-foreground">
                  Tokens
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
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
          <div className="px-6 py-4">
            {tokensLoading ? (
              <div className="flex items-center justify-center py-8">
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            ) : tokens.length > 0 ? (
              <div className="space-y-2">
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
                      className="rounded-lg border border-border bg-card p-4 hover:bg-muted/30 transition-colors"
                    >
                      <div className="flex items-start justify-between gap-4">
                        <div className="flex-1 min-w-0 space-y-3">
                          {token.secret && (
                            <div className="flex items-center gap-2 min-w-0">
                              <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wide shrink-0">
                                Secret
                              </span>
                              <code className="rounded bg-muted px-2 py-0.5 font-mono text-[12px] text-muted-foreground">
                                {maskSecret(token.secret)}
                              </code>
                              <button
                                onClick={() => setViewingTokenId(token.$id)}
                                className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground shrink-0"
                                title="View token"
                              >
                                <Eye className="h-3.5 w-3.5" />
                              </button>
                              <button
                                onClick={() =>
                                  copyToClipboard(
                                    token.secret,
                                    `token-secret-${token.$id}`,
                                  )
                                }
                                className="rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground shrink-0"
                                title="Copy secret"
                              >
                                {copiedField === `token-secret-${token.$id}` ? (
                                  <Check className="h-3.5 w-3.5 text-emerald-500" />
                                ) : (
                                  <Copy className="h-3.5 w-3.5" />
                                )}
                              </button>
                            </div>
                          )}

                          <div className="flex items-center gap-6 flex-wrap text-[12px]">
                            <div className="flex items-center gap-2">
                              <span className="text-muted-foreground">
                                Created
                              </span>
                              <DateTooltip
                                date={token.$createdAt}
                                className="text-foreground"
                              />
                            </div>
                            <div className="flex items-center gap-2">
                              <span className="text-muted-foreground">
                                Expires
                              </span>
                              {token.expire ? (
                                <div className="flex items-center gap-2">
                                  <DateTooltip
                                    date={token.expire}
                                    className="text-foreground"
                                  />
                                  {isExpired ? (
                                    <Badge
                                      variant="secondary"
                                      className="text-[10px]"
                                    >
                                      Expired
                                    </Badge>
                                  ) : isExpiringSoon ? (
                                    <Badge
                                      variant="warning"
                                      className="text-[10px]"
                                    >
                                      Expire soon
                                    </Badge>
                                  ) : null}
                                </div>
                              ) : (
                                <span className="text-foreground">Never</span>
                              )}
                            </div>
                            <div className="flex items-center gap-2">
                              <span className="text-muted-foreground">
                                Last accessed
                              </span>
                              {token.accessedAt ? (
                                <DateTooltip
                                  date={token.accessedAt}
                                  className="text-foreground"
                                />
                              ) : (
                                <span className="text-foreground">Never</span>
                              )}
                            </div>
                          </div>
                        </div>

                        <div className="flex items-center gap-2 shrink-0">
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8"
                            onClick={() => handleOpenCopyDialog(token)}
                          >
                            <Copy className="h-3.5 w-3.5 mr-1.5" />
                            Copy URL
                          </Button>
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                              <Button
                                variant="outline"
                                size="sm"
                                className="h-8 w-8 p-0"
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
                    </div>
                  )
                })}
                {tokensTotal > 0 && (
                  <div className="pt-4 border-t border-border">
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
                    />
                  </div>
                )}
              </div>
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
        onOpenChange={setCreateTokenDialogOpen}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create file token</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Create a token to share this file publicly. You can optionally set
              an expiration date.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div>
                <Label
                  htmlFor="expiration"
                  className="text-[13px] font-medium text-foreground"
                >
                  Expiration date (optional)
                </Label>
                <p className="text-[12px] text-muted-foreground mt-0.5 mb-1.5">
                  Leave empty for no expiration
                </p>
                <Input
                  id="expiration"
                  type="datetime-local"
                  value={tokenExpiration}
                  onChange={(e) => setTokenExpiration(e.target.value)}
                />
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => {
                setCreateTokenDialogOpen(false)
                setTokenExpiration('')
              }}
              disabled={createTokenMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              onClick={handleCreateToken}
              disabled={createTokenMutation.isPending}
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
        <DialogContent className="sm:max-w-[600px] p-0 max-h-[90vh] overflow-hidden flex flex-col">
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
