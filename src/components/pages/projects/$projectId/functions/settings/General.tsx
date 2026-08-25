import { useState, useEffect } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  useProjectFunction,
  buildFunctionUpdateParams,
  useDeleteFunction,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Trash2 } from 'lucide-react'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  SettingsCardsList,
  type SettingsCardItem,
} from '@/components/global/shared/settings-search/SettingsCardsList'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  const [name, setName] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)

  const deleteFunctionMutation = useDeleteFunction(projectId)

  useEffect(() => {
    if (func) {
      setName(func.name || '')
      setEnabled(func.enabled !== false)
    }
  }, [func])

  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Function updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        error instanceof Error ? error.message : t('Failed to update function'),
      )
    },
  })

  const updateEnabledMutation = useMutation({
    mutationFn: async (nextEnabled: boolean) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, { enabled: nextEnabled }),
      )
    },
    onSuccess: (updated, nextEnabled) => {
      toast.success(
        nextEnabled
          ? t('Function has been enabled')
          : t('Function has been disabled'),
      )
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
      if (func) {
        setEnabled(func.enabled !== false)
      }
    },
  })

  const handleSaveName = () => {
    if (!name.trim()) {
      toast.error(t('Function name is required'))
      return
    }
    updateFunctionMutation.mutate({ name })
  }

  const handleDeleteFunction = () => {
    if (!functionId) return
    deleteFunctionMutation.mutate(functionId, {
      onSuccess: () => {
        toast.success(t('Function deleted successfully'))
        navigate({
          to: '/projects/$projectId/functions',
          params: { projectId: projectId! },
        })
      },
      onError: (error: unknown) => {
        toast.error(
          error instanceof Error
            ? error.message
            : t('Failed to delete function'),
        )
      },
    })
    setDeleteDialogOpen(false)
  }

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Loading settings...')}
        </p>
      </div>
    )
  }

  if (!func) return null

  const cards: SettingsCardItem[] = [
    {
      id: 'details',
      search: {
        title: 'Details',
        description: 'Identifiers and timestamps for this function.',
        keywords: ['id', 'created', 'updated', 'identifiers'],
      },
      node: (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Details')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Identifiers and timestamps for this function.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-1">
          <p className="text-[13px] text-muted-foreground">
            {t('Function ID:')}{' '}
            <span className="ms-1.5">
              <CopyableId id={func.$id} size="sm" />
            </span>
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Created:')}{' '}
            <DateTooltip
              date={new Date(func.$createdAt)}
              showFormattedDate
              className="text-foreground"
            />
          </p>
          <p className="text-[13px] text-muted-foreground">
            {t('Last updated:')}{' '}
            <DateTooltip
              date={new Date(func.$updatedAt || func.$createdAt)}
              showFormattedDate
              className="text-foreground"
            />
          </p>
        </div>
      </div>
      ),
    },
    {
      id: 'name',
      search: {
        title: 'Name',
        description: 'Function name used for identification',
        keywords: ['rename', 'display'],
      },
      node: (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Name')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Function name used for identification')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('Enter function name')}
            className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              name === func.name ||
              !name.trim() ||
              updateFunctionMutation.isPending
            }
            onClick={handleSaveName}
          >
            {t('Update')}
          </Button>
        </div>
      </div>
      ),
    },
    {
      id: 'status',
      search: {
        title: 'Status',
        description: 'Enable or disable this function without deleting it.',
        keywords: ['enabled', 'disabled', 'toggle'],
      },
      node: (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Status')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Enable or disable this function without deleting it.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <Switch
                id="toggle-enabled"
                checked={enabled}
                onCheckedChange={setEnabled}
                disabled={updateEnabledMutation.isPending}
              />
              <Label
                htmlFor="toggle-enabled"
                className="text-[13px] text-foreground"
              >
                {enabled ? t('Enabled') : t('Disabled')}
              </Label>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={
              enabled === (func.enabled !== false) ||
              updateEnabledMutation.isPending
            }
            onClick={() => {
              if (enabled !== (func.enabled !== false)) {
                updateEnabledMutation.mutate(enabled)
              }
            }}
          >
            {t('Update')}
          </Button>
        </div>
      </div>
      ),
    },
    {
      id: 'delete',
      search: {
        title: 'Delete function',
        keywords: ['delete', 'remove', 'destroy', 'danger'],
      },
      node: (
      <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Delete function')}
          </h3>
        </div>
        <div className="border-t border-destructive/20" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground">
            {t(
              'Permanently delete this function and all its data. This action cannot be undone.',
            )}
          </p>
          <div className="flex items-center gap-3 mt-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
              {func.runtime ? (
                <RuntimeIcon runtime={func.runtime} className="h-5 w-5" />
              ) : (
                <Trash2 className="h-5 w-5 text-muted-foreground" />
              )}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-[14px] font-medium text-foreground truncate">
                {func.name || t('Unnamed Function')}
              </p>
              <p className="text-[12px] text-muted-foreground">
                {func.runtime || t('No runtime')}
              </p>
            </div>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
          <Button
            variant="destructive"
            size="sm"
            className="h-9 text-[13px]"
            disabled={deleteFunctionMutation.isPending}
            onClick={() => setDeleteDialogOpen(true)}
          >
            <Trash2 className="me-1.5 h-4 w-4" />
            {t('Delete function')}
          </Button>
          <ConfirmNameDialog
            open={deleteDialogOpen}
            onOpenChange={setDeleteDialogOpen}
            title="Delete function"
            description={
              <>
                {t('Are you sure you want to delete')}{' '}
                <span className="font-medium text-foreground">
                  {func.name || t('this function')}
                </span>{' '}
                {t('and all its data? This action cannot be undone.')}
              </>
            }
            confirmValue={func.name?.trim() || func.$id}
            confirmPlaceholder="Enter function name"
            onConfirm={handleDeleteFunction}
            isConfirming={deleteFunctionMutation.isPending}
          />
        </div>
      </div>
      ),
    },
  ]

  return <SettingsCardsList cards={cards} />
}
