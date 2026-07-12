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
  TooltipTrigger} from '@/components/ui/tooltip'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { DateTimePicker } from '@/components/global/shared/DateTimePicker'
import { Pagination } from '@/components/global/shared/Pagination'
import { PermissionsEditor } from '../../auth/PermissionsEditor'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Loader2,
  Plus,
  Copy,
  Trash2,
  Eye,
  Check,
  AlertCircle,
  Link2} from 'lucide-react'
import type { Models } from '@appwrite.io/console'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useProject } from '@/lib/react-query/hooks'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

// Helper function to mask secret (compact hint of prefix / suffix)
function maskSecret(secret: string): string {
  if (!secret) return '•••••'
  if (secret.length <= 8) {
    return `${secret[0]}${'•'.repeat(4)}${secret[secret.length - 1]}`
  }
  return `${secret.slice(0, 4)}${'•'.repeat(8)}${secret.slice(-3)}`
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

function getFileTokenCreateExpirationIso(
  option: FileTokenExpiryOption,
  customIso: string,
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
      const trimmed = customIso.trim()
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
  /**
   * When set, only render that block (inspector tabs). Default `all` shows
   * permissions and tokens like the standalone file security page.
   */
  panelSection?: 'all' | 'permissions' | 'tokens'
}

export function FileSecurity({
  projectId: projectIdProp,
  bucketId: bucketIdProp,
  fileId: fileIdProp,
  variant = 'page',
  panelSection = 'all'}: FileSecurityProps = {}) {
  const params = useParams({ strict: false }) as {
    projectId?: string
    bucketId?: string
    fileId?: string
  }
  const t = useT()
  const projectId = projectIdProp ?? params.projectId
  const bucketId = bucketIdProp ?? params.bucketId
  const fileId = fileIdProp ?? params.fileId
  /** Inspector single-tab layout: no bordered cards, padding from parent */
  const cardlessPanel = variant === 'panel' && panelSection !== 'all'
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
        permissions})
    },
    onSuccess: () => {
      toast.success(t('File permissions have been updated'))
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    }})

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
        expire: expiration || undefined})
    },
    onSuccess: () => {
      toast.success(t('Token has been created'))
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE_TOKENS })
      setCreateTokenDialogOpen(false)
      setTokenExpiration('')
      setTokenExpiryOption('never')
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    }})

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
      toast.success(t('Token has been deleted'))
      queryClient.invalidateQueries({ queryKey: Dependencies.FILE_TOKENS })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    }})

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
      toast.error(t('Invalid expiration date'))
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
      toast.success(t('Copied to clipboard'))
    }
  }

  const viewingToken = tokens.find(
    (item: Models.ResourceToken) => item.$id === viewingTokenId,
  )

  // Build file URL with token (public REST URL - must include `project` query param)
  const getFileUrl = (
    mode: 'preview' | 'view' | 'download',
    tokenSecret: string,
  ): string => {
    if (!projectId || !bucketId || !fileId) return ''
    const baseUrl = `${projectEndpoint}/storage/buckets/${bucketId}/files/${fileId}`
    const params = new URLSearchParams({
      project: projectId,
      token: tokenSecret})
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
      <div
        className={cn(
          'py-12 text-center',
          !cardlessPanel && 'rounded-lg border border-border bg-card',
        )}
      >
        <p className="text-[13px] text-muted-foreground">
          {t('File not found')}
        </p>
      </div>
    )
  }

  const cardPad = variant === 'panel' ? 'px-3 py-2' : 'px-6 py-4'
  const tokenRowPad = cardlessPanel
    ? 'px-3 py-3.5'
    : variant === 'panel'
      ? 'px-3 py-3'
      : 'px-4 py-3.5'
  const panelTone = variant === 'panel' || cardlessPanel

  const showPermissions = panelSection === 'all' || panelSection === 'permissions'
  const showTokens = panelSection === 'all' || panelSection === 'tokens'

  function renderPermissionsDescription(opts?: { afterHeading?: boolean }) {
    const afterHeading = opts?.afterHeading ?? false
    return (
      <p
        className={cn(
          'text-muted-foreground',
          variant === 'panel' || cardlessPanel ? 'text-[12px] leading-snug' : 'text-[13px]',
          afterHeading && (variant === 'panel' || cardlessPanel ? 'mt-1' : 'mt-2'),
        )}
      >
        {t('Choose who can access this file.')}{' '}
        <DocsRouteLink className="link-neutral" href="/docs/permissions">
          {t('Learn more')}
        </DocsRouteLink>
        .
      </p>
    )
  }

  const permissionsIntroWithTitle = (
    <>
      <h3
        className={cn(
          'font-semibold text-foreground',
          variant === 'panel' || cardlessPanel ? 'text-[14px]' : 'text-[15px]',
        )}
      >
        {t('Permissions')}
      </h3>
      {renderPermissionsDescription({ afterHeading: true })}
    </>
  )

  function renderTokensDescription(opts?: { afterHeading?: boolean }) {
    const afterHeading = opts?.afterHeading ?? false
    return (
      <p
        className={cn(
          'text-muted-foreground',
          variant === 'panel' || cardlessPanel ? 'text-[12px] leading-snug' : 'text-[13px]',
          afterHeading && 'mt-2',
        )}
      >
        {t(
          'File tokens allow you to share files publicly with anyone without configuring bucket or file permissions. They work around browser restrictions on third-party cookies and can be set to expire on a specific date or work indefinitely.',
        )}{' '}
        <DocsRouteLink className="link-neutral" href="/docs/products/storage/file-tokens">
          {t('Learn more')}
        </DocsRouteLink>
        .
      </p>
    )
  }

  const tokensIntroCardless = (
    <div className="space-y-3">
      {renderTokensDescription()}
      <Button
        size="sm"
        className="h-8 w-fit text-[12px]"
        onClick={() => setCreateTokenDialogOpen(true)}
      >
        <Plus className="h-3.5 w-3.5 me-1.5" />
        {t('Create token')}
      </Button>
    </div>
  )

  const tokensIntroWithTitle = (
    <div
      className={cn(
        'flex gap-3',
        variant === 'page'
          ? 'flex-row items-center justify-between'
          : 'flex-col items-stretch sm:flex-row sm:items-start sm:justify-between',
      )}
    >
      <div className={cn(variant === 'panel' && 'min-w-0 flex-1')}>
        <h3
          className={cn(
            'font-semibold text-foreground',
            variant === 'panel' || cardlessPanel ? 'text-[14px]' : 'text-[15px]',
          )}
        >
          {t('Tokens')}
        </h3>
        {renderTokensDescription({ afterHeading: true })}
      </div>
      <Button
        size="sm"
        className={
          variant === 'panel' || cardlessPanel ? 'h-8 shrink-0 text-[12px]' : 'h-9 text-[13px]'
        }
        onClick={() => setCreateTokenDialogOpen(true)}
      >
        <Plus className="h-3.5 w-3.5 me-1.5" />
        {t('Create token')}
      </Button>
    </div>
  )

  const permissionsEditorBlock = (
    <PermissionsEditor
      permissions={filePermissions}
      onPermissionsChange={setFilePermissions}
      withCreate={false}
      projectId={projectId}
      compact={variant === 'panel'}
    />
  )

  const updatePermissionsButton = (
    <Button
      size="sm"
      className={
        variant === 'panel' || cardlessPanel ? 'h-8 text-[12px]' : 'h-9 text-[13px]'
      }
      disabled={
        arraysEqual(filePermissions, file.$permissions || []) ||
        updateFilePermissionsMutation.isPending
      }
      onClick={handleFilePermissionsUpdate}
    >
      {t('Update')}
    </Button>
  )

  function renderTokenRow(token: Models.ResourceToken) {
    const now = new Date()
    const expireDate = token.expire ? new Date(token.expire) : null
    const isExpired = expireDate && expireDate < now
    const isExpiringSoon =
      expireDate &&
      !isExpired &&
      expireDate.getTime() - now.getTime() <= 7 * 24 * 60 * 60 * 1000

    return (
      <div
        key={token.$id}
        className={cn(
          'flex flex-col gap-3 transition-colors hover:bg-muted/30',
          tokenRowPad,
        )}
      >
        <div className="flex items-center gap-2 sm:gap-3">
          <div className="min-w-0 flex-1">
            {token.secret ? (
              <div className="flex min-w-0 items-center gap-1.5">
                <code className="max-w-[min(100%,220px)] truncate rounded bg-muted px-2 py-0.5 font-mono text-[12px] text-muted-foreground sm:max-w-md">
                  {maskSecret(token.secret)}
                </code>
                <button
                  type="button"
                  onClick={() => setViewingTokenId(token.$id)}
                  className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                  title={t('View token')}
                >
                  <Eye className="h-3.5 w-3.5" />
                </button>
                <button
                  type="button"
                  onClick={() =>
                    copyToClipboard(token.secret, `token-secret-${token.$id}`)
                  }
                  className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                  title={t('Copy secret')}
                >
                  {copiedField === `token-secret-${token.$id}` ? (
                    <Check className="h-3.5 w-3.5 text-emerald-500" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                </button>
              </div>
            ) : null}
          </div>
          <div className="flex shrink-0 items-center gap-0.5">
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-muted-foreground"
                  aria-label={t('Copy URL')}
                  onClick={() => handleOpenCopyDialog(token)}
                >
                  <Link2 className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent>{t('Copy URL')}</TooltipContent>
            </Tooltip>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <RowActionsMenuTrigger aria-label={t('Token actions')} />
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  onClick={() =>
                    copyToClipboard(token.$id, `token-id-${token.$id}`)
                  }
                >
                  <MenuItemContent
                    icon={copiedField === `token-id-${token.$id}` ? Check : Copy}
                  >
                    {copiedField === `token-id-${token.$id}`
                      ? t('Copied')
                      : t('Copy ID')}
                  </MenuItemContent>
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() => {
                    setTokenToDelete(token.$id)
                    setDeleteTokenDialogOpen(true)
                  }}
                >
                  <MenuItemContent icon={Trash2}>
                    {t('Delete')}
                  </MenuItemContent>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <div className="min-w-0">
          <div
            className={cn(
              'flex flex-wrap items-baseline gap-x-2 gap-y-1.5 py-1.5 leading-relaxed text-muted-foreground sm:gap-x-2.5',
              panelTone ? 'text-[11px]' : 'text-[12px]',
            )}
          >
            <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap">
              <span>{t('Created')}</span>
              <DateTooltip
                date={token.$createdAt}
                className={cn(
                  'text-muted-foreground',
                  panelTone ? 'text-[11px]' : 'text-[12px]',
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
              <span>{t('Expires')}</span>
              {token.expire ? (
                <>
                  <DateTooltip
                    date={token.expire}
                    className={cn(
                      'text-muted-foreground',
                      panelTone ? 'text-[11px]' : 'text-[12px]',
                    )}
                  />
                  {isExpired ? (
                    <Badge variant="error" className="text-[10px] shrink-0">
                      {t('Expired')}
                    </Badge>
                  ) : isExpiringSoon ? (
                    <Badge variant="warning" className="text-[10px] shrink-0">
                      {t('Expires soon')}
                    </Badge>
                  ) : null}
                </>
              ) : (
                <span className="text-foreground">{t('Never')}</span>
              )}
            </span>
            <span
              aria-hidden
              className="shrink-0 text-muted-foreground/40"
            >
              ·
            </span>
            <span className="inline-flex shrink-0 items-center gap-1 whitespace-nowrap">
              <span>{t('Accessed')}</span>
              {token.accessedAt ? (
                <DateTooltip
                  date={token.accessedAt}
                  className={cn(
                    'text-muted-foreground',
                    panelTone ? 'text-[11px]' : 'text-[12px]',
                  )}
                />
              ) : (
                <span className="text-foreground">{t('Never')}</span>
              )}
            </span>
          </div>
        </div>
      </div>
    )
  }

  const tokensListSection = (
    <>
      {tokensLoading ? (
        <div className="flex items-center justify-center py-8">
          <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        </div>
      ) : tokens.length > 0 ? (
        <>
          {cardlessPanel ? (
            <div className="divide-y divide-border">{tokens.map(renderTokenRow)}</div>
          ) : (
            <div className="overflow-hidden rounded-lg border border-border">
              <div className="divide-y divide-border">{tokens.map(renderTokenRow)}</div>
            </div>
          )}
          {tokensTotal > 0 ? (
            <div className={cardlessPanel ? 'mt-4' : 'mt-2'}>
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
                itemLabel={t('tokens')}
                className="py-0"
              />
            </div>
          ) : null}
        </>
      ) : (
        <div
          className={cn(
            'py-8 text-center',
            !cardlessPanel && 'rounded-lg border border-border bg-card',
          )}
        >
          <p className="text-[13px] text-muted-foreground">
            {t('No tokens found. Create a token to share this file publicly.')}
          </p>
        </div>
      )}
    </>
  )

  return (
    <div className={cn('w-full', variant === 'page' && 'px-4 py-4 sm:px-6')}>
      <div
        className={cn(
          cardlessPanel ? 'space-y-4' : variant === 'panel' ? 'space-y-3' : 'space-y-6',
        )}
      >
        {/* Permissions */}
        {showPermissions ? (
          cardlessPanel ? (
            <div className="space-y-4">
              <div>{renderPermissionsDescription()}</div>
              <div className="border-t border-border pt-4">{permissionsEditorBlock}</div>
              <div className="border-t border-border pt-4">{updatePermissionsButton}</div>
            </div>
          ) : (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className={cardPad}>{permissionsIntroWithTitle}</div>
              <div className="border-t border-border" />
              <div className={cardPad}>{permissionsEditorBlock}</div>
              <div className={cn(cardPad, 'border-t border-border bg-muted/30')}>
                {updatePermissionsButton}
              </div>
            </div>
          )
        ) : null}

        {/* Tokens */}
        {showTokens ? (
          cardlessPanel ? (
            <div className="space-y-4">
              {tokensIntroCardless}
              <div className="border-t border-border pt-4">{tokensListSection}</div>
            </div>
          ) : (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className={cardPad}>{tokensIntroWithTitle}</div>
              <div className="border-t border-border" />
              <div className={cardPad}>{tokensListSection}</div>
            </div>
          )
        ) : null}
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Create file token')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Create a token to share this file publicly. Choose when the token should expire.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label className="text-[13px] font-medium text-foreground">
                  {t('Expiration')}
                </Label>
                <RadioGroup
                  value={tokenExpiryOption}
                  onValueChange={(value) => {
                    const next = value as FileTokenExpiryOption
                    setTokenExpiryOption(next)
                    if (next === 'custom' && !tokenExpiration.trim()) {
                      setTokenExpiration(
                        new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(),
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
                          {t(option.label)}
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
                      {t('Date and time')}
                    </Label>
                    <DateTimePicker
                      id="file-token-expiration-custom"
                      value={tokenExpiration || null}
                      onChange={(value) => setTokenExpiration(value ?? '')}
                      disabled={createTokenMutation.isPending}
                      clearable
                      className={cn(
                        'mt-1.5',
                        createTokenExpiryInvalid && 'border-destructive',
                      )}
                    />
                    {createTokenExpiryInvalid && (
                      <p className="text-[12px] text-destructive mt-1">
                        {t('Enter a valid date and time')}
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
              {t('Cancel')}
            </Button>
            <Button
              onClick={handleCreateToken}
              disabled={
                createTokenMutation.isPending || createTokenExpiryInvalid
              }
            >
              {t('Create token')}
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('File Token')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Copy the full token below. Keep it secure and never share it publicly.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <label className="text-sm font-medium text-foreground">
                {t('Token')}
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
              {t('Close')}
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
                  {t('Copied')}
                </>
              ) : (
                <>
                  <Copy className="h-4 w-4" />
                  {t('Copy')}
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete token')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Are you sure you want to delete this token? This action cannot be undone.',
              )}
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
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                if (tokenToDelete) {
                  deleteTokenMutation.mutate(tokenToDelete, {
                    onSuccess: () => {
                      setDeleteTokenDialogOpen(false)
                      setTokenToDelete(null)
                    }})
                }
              }}
              disabled={deleteTokenMutation.isPending}
            >
              {t('Delete')}
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
          <DialogHeader className="px-6 pt-6 text-start shrink-0">
            <DialogTitle>{t('Copy File URL')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Use the token-based URL below to access this file securely.')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border shrink-0" />

          <div className="px-6 pb-4 pt-0 overflow-y-auto flex-1 min-h-0">
            <div className="space-y-4">
              <div className="space-y-2">
                <div className="flex items-center justify-between gap-2 flex-wrap">
                  <Label className="text-sm font-medium text-foreground">
                    {t('URL')}
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
                          <Check className="h-3 w-3 me-1 text-emerald-500" />
                          {t('Copied')}
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 me-1" />
                          {t('Preview')}
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
                          <Check className="h-3 w-3 me-1 text-emerald-500" />
                          {t('Copied')}
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 me-1" />
                          {t('View')}
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
                          <Check className="h-3 w-3 me-1 text-emerald-500" />
                          {t('Copied')}
                        </>
                      ) : (
                        <>
                          <Copy className="h-3 w-3 me-1" />
                          {t('Download')}
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
                    t(
                      'Apply transformations or filters. Good for thumbnails or previews.',
                    )}
                  {copyUrlMode === 'view' &&
                    t(
                      'Display the file in the browser. Good for images and documents.',
                    )}
                  {copyUrlMode === 'download' &&
                    t(
                      'Download the file directly. Good for files that need to be saved.',
                    )}
                </p>
              </div>

              {tokenForCopy && !tokenForCopy.expire && (
                <Alert
                  variant="destructive"
                  className="bg-destructive/10 border-destructive/20"
                >
                  <AlertCircle className="h-4 w-4 text-destructive" />
                  <AlertDescription className="text-[12px] text-destructive">
                    <span className="font-semibold">
                      {t('No expiration date.')}
                    </span>{' '}
                    {t("This token doesn't expire. Be cautious when sharing links.")}
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
              {t('Close')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
