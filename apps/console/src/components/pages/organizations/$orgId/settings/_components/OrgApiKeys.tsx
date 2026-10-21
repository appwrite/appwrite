import { useState, useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
import { Info, Key } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { ApiKeysList } from '@/components/pages/projects/$projectId/shared/ApiKeysList'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import {
  fetchOrganizationApiKey,
  useOrganizationApiKeys,
  useCreateOrganizationApiKey,
  useUpdateOrganizationApiKey,
  useDeleteOrganizationApiKey,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreateOrgApiKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { Models } from '@appwrite.io/console'

export function OrgKeysCard() {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [updateDrawerOpen, setUpdateDrawerOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [updateKeyData, setUpdateKeyData] = useState<Models.Key | null>(null)

  const { apiKeys, isLoading } = useOrganizationApiKeys(orgId)
  const showLoading = isLoading && apiKeys.length === 0

  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(orgId)
  const noCreatePermission = !canCreateOrgApiKey(access, features)

  const createMutation = useCreateOrganizationApiKey(orgId)
  const updateMutation = useUpdateOrganizationApiKey(orgId)
  const deleteMutation = useDeleteOrganizationApiKey(orgId)

  const handleCreate = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    createMutation.mutate(data, {
      onSuccess: (createdKey) => {
        toast.success(t('API key created successfully'))
        if (createdKey?.secret) {
          setCreatedKeySecret(createdKey.secret)
        } else {
          setCreateDrawerOpen(false)
        }
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to create API key'))
      },
    })
  }

  const handleUpdate = (keyId: string) => {
    setSelectedKeyId(keyId)
    setUpdateDrawerOpen(true)
  }

  const handleUpdateSubmit = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    if (!selectedKeyId) return

    updateMutation.mutate(
      {
        keyId: selectedKeyId,
        ...data,
      },
      {
        onSuccess: () => {
          toast.success(t('API key updated successfully'))
          setUpdateDrawerOpen(false)
          setSelectedKeyId(null)
        },
        onError: (error: Error) => {
          toast.error(getErrorMessage(error) || t('Failed to update API key'))
        },
      },
    )
  }

  const handleDelete = (keyId: string) => {
    setSelectedKeyId(keyId)
    setDeleteDialogOpen(true)
  }

  const confirmDelete = () => {
    if (!selectedKeyId) return

    const keyId = selectedKeyId
    closeDialogBeforeOverlayUnmount(() => {
      setDeleteDialogOpen(false)
      setSelectedKeyId(null)
    })

    deleteMutation.mutate(keyId, {
      onSuccess: () => {
        toast.success(t('API key deleted successfully'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete API key'))
      },
    })
  }

  const handleCopy = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  const selectedKey = selectedKeyId
    ? apiKeys.find((key) => key.id === selectedKeyId)
    : null

  useEffect(() => {
    if (updateDrawerOpen && selectedKeyId && orgId) {
      const fetchKeyData = async () => {
        try {
          const key = await fetchOrganizationApiKey(orgId, selectedKeyId)
          setUpdateKeyData(key)
        } catch {
          setUpdateKeyData(null)
        }
      }
      void fetchKeyData()
    } else {
      setUpdateKeyData(null)
    }
  }, [updateDrawerOpen, selectedKeyId, orgId])

  const createButton = (
    <Button
      size="sm"
      className="h-9 text-[13px]"
      onClick={() => setCreateDrawerOpen(true)}
      disabled={noCreatePermission}
      {...analyticsAttrs('create-org-api-key')}
    >
      {t('Create API key')}
    </Button>
  )

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Partners keys')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Authenticate Console APIs from your backend. Create and manage projects, members, and domains across this organization.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="border-b border-border bg-blue-500/5 px-6 py-3">
          <Alert
            variant="default"
            className="border-blue-500/30 bg-transparent"
          >
            <Info className="h-4 w-4 shrink-0 text-blue-500" />
            <AlertTitle className="text-[13px] font-medium text-blue-600 dark:text-blue-400">
              {t('Project keys vs Partners keys')}
            </AlertTitle>
            <AlertDescription className="text-[12px] leading-snug text-blue-600/80 dark:text-blue-400/80">
              {t(
                'Partners keys cannot access data inside a project. For databases, storage, users, and functions, create a project key instead.',
              )}{' '}
              {t(
                'Open a project, go to API keys in the project sidebar, and create a key with the scopes your backend needs.',
              )}
            </AlertDescription>
          </Alert>
        </div>
        {showLoading ? (
          <div className="px-6 py-4">
            <div className="space-y-3">
              {Array.from({ length: 2 }).map((_, i) => (
                <div key={i} className="flex items-center gap-2">
                  <div className="h-4 w-4 shrink-0 animate-pulse rounded bg-muted" />
                  <div className="h-4 w-32 animate-pulse rounded bg-muted" />
                </div>
              ))}
            </div>
          </div>
        ) : apiKeys.length === 0 ? (
          <div className="px-6 py-4">
            <EmptyState
              icon={Key}
              title={t('No Partners keys yet')}
              description={t(
                'Create your first Partners key to authenticate Console APIs from your backend.',
              )}
              isEmpty
              variant="default"
              className="py-2"
            />
          </div>
        ) : (
          <ApiKeysList
            apiKeys={apiKeys}
            isLoading={false}
            onUpdate={handleUpdate}
            onDelete={handleDelete}
            onCopy={handleCopy}
            copiedField={copiedField}
            showActions={true}
            organizationId={orgId}
            embedded
          />
        )}
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          {noCreatePermission ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">{createButton}</span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {t("You don't have permission to create API keys.")}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            createButton
          )}
        </div>
      </div>

      <ApiKeyDrawer
        open={createDrawerOpen}
        onOpenChange={(open) => {
          setCreateDrawerOpen(open)
          if (!open) setCreatedKeySecret(null)
        }}
        onSubmit={handleCreate}
        isLoading={createMutation.isPending}
        createdKeySecret={createdKeySecret}
        onCopy={handleCopy}
        copiedField={copiedField}
        scopeCatalog="organization"
      />

      <ApiKeyDrawer
        open={updateDrawerOpen}
        onOpenChange={(open) => {
          setUpdateDrawerOpen(open)
          if (!open) {
            setSelectedKeyId(null)
            setUpdateKeyData(null)
          }
        }}
        onSubmit={handleUpdateSubmit}
        isLoading={updateMutation.isPending}
        apiKey={updateKeyData}
        onCopy={handleCopy}
        copiedField={copiedField}
        scopeCatalog="organization"
      />

      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Delete API key')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} "{selectedKey?.name}"?{' '}
              {t('This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
