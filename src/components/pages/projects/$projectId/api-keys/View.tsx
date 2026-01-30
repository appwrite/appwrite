import { useState, useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation } from '@tanstack/react-query'
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
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import type { Models } from '@appwrite.io/console'

export function View() {
  const { projectId } = useParams({
    strict: false,
  })
  const [searchValue, setSearchValue] = useState('')
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [updateDrawerOpen, setUpdateDrawerOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedKeyId, setSelectedKeyId] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  // Fetch API keys
  const { apiKeys, isLoading } = useApiKeys(projectId)

  // Filter API keys based on search
  const filteredApiKeys = apiKeys.filter((key) =>
    key.name.toLowerCase().includes(searchValue.toLowerCase()),
  )

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
      onSuccess: () => {
        toast.success('API key created successfully')
        setCreateDrawerOpen(false)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to create API key')
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
          toast.success('API key updated successfully')
          setUpdateDrawerOpen(false)
          setSelectedKeyId(null)
        },
        onError: (error: Error) => {
          toast.error(getErrorMessage(error) || 'Failed to update API key')
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

    deleteMutation.mutate(selectedKeyId, {
      onSuccess: () => {
        toast.success('API key deleted successfully')
        setDeleteDialogOpen(false)
        setSelectedKeyId(null)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete API key')
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
        title="API Keys"
        searchPlaceholder="Search API keys..."
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        createLabel="Create API key"
        onCreate={() => setCreateDrawerOpen(true)}
        showFilters={false}
        fullWidthBorder
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        <ApiKeysList
          apiKeys={filteredApiKeys}
          isLoading={isLoading}
          onUpdate={handleUpdate}
          onDelete={handleDelete}
          onCopy={handleCopy}
          copiedField={copiedField}
          showActions={true}
        />
      </div>

      {/* Create Drawer */}
      <ApiKeyDrawer
        open={createDrawerOpen}
        onOpenChange={setCreateDrawerOpen}
        onSubmit={handleCreate}
        isLoading={createMutation.isPending}
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
      />

      {/* Delete Confirmation Dialog */}
      <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete API key</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete "{selectedKey?.name}"? This action
              cannot be undone.
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
