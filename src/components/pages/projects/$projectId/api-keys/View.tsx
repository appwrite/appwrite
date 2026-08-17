import { useState, useEffect } from 'react'
import { Key } from 'lucide-react'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { ServiceHeader } from '../shared/ServiceHeader'
import { ApiKeysList } from '../shared/ApiKeysList'
import { ApiKeyDrawer } from './ApiKeyDrawer'
import {
  useApiKeys,
  useCreateApiKey,
  useUpdateApiKey,
  useDeleteApiKey,
  fetchApiKeys,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canCreateKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import type { ApiKey } from '../shared/ApiKeysList'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { LanguageIcon } from '@/components/global/shared/LanguageIcon'
import type { Models } from '@appwrite.io/console'

const supportedLanguages = [
  { id: 'node', name: 'Node.js' },
  { id: 'python', name: 'Python' },
  { id: 'php', name: 'PHP' },
  { id: 'ruby', name: 'Ruby' },
  { id: 'go', name: 'Go' },
  { id: 'deno', name: 'Deno' },
  { id: 'bun', name: 'Bun' },
  { id: 'dart', name: 'Dart' },
  { id: 'swift', name: 'Swift' },
  { id: 'kotlin', name: 'Kotlin' },
  { id: 'java', name: 'Java' },
  { id: 'dotnet', name: '.NET' },
] as const

export type ApiKeysInitialData = {
  project?: Awaited<
    ReturnType<typeof import('@/lib/react-query/hooks').fetchProject>
  >
  apiKeys: ApiKey[]
  /** Raw listKeys response from loader; passed to useApiKeys to avoid duplicate fetch */
  apiKeysRaw?: { keys?: unknown[] } | null
}

type ViewProps = {
  initialData?: ApiKeysInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId } = useParams({
    strict: false,
  })
  const [searchValue, setSearchValue] = useState('')
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [updateDrawerOpen, setUpdateDrawerOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)

  // Fetch API keys; pass loader prefetch as initialData to avoid duplicate fetch
  const { apiKeys: apiKeysFromHook, isLoading } = useApiKeys(projectId, {
    initialData: initialData?.apiKeysRaw,
  })
  const apiKeys =
    apiKeysFromHook?.length || initialData?.apiKeysRaw
      ? apiKeysFromHook
      : (initialData?.apiKeys ?? [])
  const showLoading = isLoading && apiKeys.length === 0 && !initialData

  // Filter API keys based on search
  const filteredApiKeys = apiKeys.filter((key) =>
    key.name.toLowerCase().includes(searchValue.toLowerCase()),
  )

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateKey(access, features)

  // Clear selection when search changes
  useEffect(() => {
    setSelectedKeyId(null)
    setDeleteDialogOpen(false)
  }, [searchValue])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
  }

  // Create mutation
  const createMutation = useCreateApiKey(projectId)

  // Update mutation
  const updateMutation = useUpdateApiKey(projectId)

  // Delete mutation
  const deleteMutation = useDeleteApiKey(projectId)

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
          // Keep drawer open so user can copy the key
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

  // Get the full key data for update (we need to fetch it from the API)
  const [updateKeyData, setUpdateKeyData] = useState<Models.Key | null>(null)

  useEffect(() => {
    if (updateDrawerOpen && selectedKeyId) {
      // Fetch the full key data for update
      const fetchKeyData = async () => {
        try {
          const response = await fetchApiKeys(projectId!)
          const key = response.keys.find(
            (k: Models.Key) => k.$id === selectedKeyId,
          )
          setUpdateKeyData(key || null)
        } catch (error) {
          console.error('Failed to fetch key data:', error)
          setUpdateKeyData(null)
        }
      }
      fetchKeyData()
    } else {
      setUpdateKeyData(null)
    }
  }, [updateDrawerOpen, selectedKeyId, projectId])

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('API keys')}
        searchPlaceholder={t('Search API keys...')}
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel={t('Create API key')}
        createAnalyticsAction="create-api-key"
        onCreate={() => setCreateDrawerOpen(true)}
        createDisabled={noCreatePermission}
        createDisabledTooltip={
          noCreatePermission
            ? t("You don't have permission to create API keys.")
            : undefined
        }
        showFilters={false}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {showLoading ? (
          <div className="rounded-xl border border-border bg-card/50">
            <div className="divide-y divide-border">
              {Array.from({ length: 2 }).map((_, i) => (
                <div
                  key={i}
                  className="flex items-center justify-between gap-3 p-4"
                >
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <div className="h-4 w-4 shrink-0 animate-pulse rounded bg-muted" />
                      <div className="h-4 w-24 animate-pulse rounded bg-muted" />
                      <div className="h-4 w-16 animate-pulse rounded-full bg-muted" />
                    </div>
                    <div className="mt-1.5 flex items-center gap-2">
                      <div className="h-5 w-32 animate-pulse rounded bg-muted" />
                      <div className="h-3.5 w-3.5 animate-pulse rounded bg-muted" />
                      <div className="h-3.5 w-3.5 animate-pulse rounded bg-muted" />
                      <div className="ms-auto h-3 w-24 animate-pulse rounded bg-muted" />
                    </div>
                  </div>
                  <div className="h-6 w-6 shrink-0 animate-pulse rounded bg-muted" />
                </div>
              ))}
            </div>
          </div>
        ) : filteredApiKeys.length === 0 ? (
          apiKeys.length === 0 ? (
            <EmptyState icon={Key} variant="card" isEmpty={true}>
              <div className="flex flex-col items-center text-center">
                <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                  <Key className="h-6 w-6 text-muted-foreground" />
                </div>
                <h3 className="mb-2 text-[15px] font-medium text-foreground">
                  {t('No API keys created')}
                </h3>
                <p className="mb-6 max-w-sm text-[13px] text-muted-foreground">
                  {t(
                    'Create an API key to authenticate your applications and access Appwrite services. API keys provide secure access to your project resources.', // pragma: allowlist secret
                  )}
                </p>
                <div className="w-full">
                  <div className="mb-4 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
                    <div className="h-px flex-1 bg-border" />
                    <span className="font-medium text-foreground/80">
                      {t('Get started with your language of choice')}
                    </span>
                    <div className="h-px flex-1 bg-border" />
                  </div>
                  <div className="flex w-full flex-wrap justify-center gap-2">
                    {supportedLanguages.map(({ id, name }) => (
                      <Button
                        key={id}
                        onClick={() =>
                          !noCreatePermission && setCreateDrawerOpen(true)
                        }
                        variant="outline"
                        size="lg"
                        disabled={noCreatePermission}
                        title={
                          noCreatePermission
                            ? t("You don't have permission to create API keys.")
                            : undefined
                        }
                      >
                        <LanguageIcon language={id} size="sm" />
                        <span>{name}</span>
                      </Button>
                    ))}
                  </div>
                </div>
              </div>
            </EmptyState>
          ) : (
            <EmptyState
              icon={Key}
              variant="card"
              isEmpty={false}
              hasFilters={true}
              title={t('No API keys match your search')}
              description={t('Try a different search term.')}
            />
          )
        ) : (
          <ApiKeysList
            apiKeys={filteredApiKeys}
            isLoading={false}
            onUpdate={handleUpdate}
            onDelete={handleDelete}
            onCopy={handleCopy}
            copiedField={copiedField}
            showActions={true}
            projectId={projectId}
          />
        )}
      </div>

      {/* Create Drawer */}
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
      />

      {/* Update Drawer */}
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
      />

      {/* Delete Confirmation Dialog */}
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
    </div>
  )
}
