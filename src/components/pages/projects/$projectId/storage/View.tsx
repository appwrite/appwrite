import { useEffect, useState } from 'react'
import { useNavigate, useParams, useLocation, useSearch } from '@tanstack/react-router'
import {
  isStoragePlaceholderBucketId,
  storageSidebarBucketsQueryOptions,
} from '@/lib/storage-routes'
import { HardDrive } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import {
  bucketsQueryOptions,
  Dependencies,
  useProject,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CreateBucket } from './_components/CreateBucket'
import { useT } from '@/lib/i18n/translate'

/**
 * Storage index: buckets live in the workspace sidebar; this pane prompts selection
 * or creation when no bucket route is active.
 */
export function View() {
  const t = useT()
  const { projectId, bucketId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { create?: string }
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)

  const { data: total = 0 } = useQuery({
    ...bucketsQueryOptions(projectId, 0, 1, ''),
    select: (d) => d.total ?? 0,
  })

  const { data: sidebarBuckets } = useQuery({
    ...storageSidebarBucketsQueryOptions(projectId!),
    enabled: !!projectId && isStoragePlaceholderBucketId(bucketId),
  })

  useEffect(() => {
    if (!projectId || !isStoragePlaceholderBucketId(bucketId)) return
    const firstId = sidebarBuckets?.buckets?.[0]?.$id
    if (!firstId) return
    navigate({
      to: '/projects/$projectId/storage/$bucketId',
      params: { projectId, bucketId: firstId },
      replace: true,
    })
  }, [bucketId, navigate, projectId, sidebarBuckets?.buckets])

  const { project } = useProject(projectId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const bucketsLimit = organizationPlan?.buckets ?? 0

  useEffect(() => {
    if (search?.create === 'bucket' && !createOpen) {
      setCreateOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createOpen, navigate, location.pathname])

  const createMutation = useMutation({
    mutationFn: async (data: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const bucketId = data.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId,
        name: data.name,
      })
    },
    onSuccess: (bucket) => {
      toast.success(`${bucket.name} ${t('has been created')}`)
      void queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId: projectId!, bucketId: bucket.$id },
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  const showPlanLimitLine =
    total === 0 && bucketsLimit > 0 && project?.teamId

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col justify-center px-4 py-8 sm:px-6 sm:py-12">
        <EmptyState variant="card" isEmpty className="w-full">
          <div className="flex flex-col items-center text-center">
            <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <HardDrive className="h-6 w-6 text-muted-foreground" />
            </div>
            <h3 className="mb-2 text-[15px] font-medium text-foreground">
              {total === 0 ? t('Create your first bucket') : t('Select a bucket')}
            </h3>
            <p
              className={
                showPlanLimitLine
                  ? 'mb-2 max-w-sm text-[13px] text-muted-foreground'
                  : 'mb-6 max-w-sm text-[13px] text-muted-foreground'
              }
            >
              {total === 0
                ? t(
                    'Buckets isolate files, permissions, and delivery rules. Create one from the sidebar to start uploading.',
                  )
                : t(
                    'Choose a bucket in the left sidebar to browse files, security, and settings. This layout mirrors the database console workspace.',
                  )}
            </p>
            {showPlanLimitLine ? (
              <p className="mb-6 max-w-sm text-[12px] text-muted-foreground">
                {t('Plan limit:')} {total} {t('of')} {bucketsLimit}{' '}
                {t('buckets')} ·{' '}
                {resolveOrganizationPlanDisplayLabel({
                  planName: organizationPlan?.name ?? null,
                  planId: organizationPlan?.$id,
                })}
              </p>
            ) : null}
          </div>
        </EmptyState>
      </div>

      <CreateBucket
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreate={(data) => createMutation.mutate(data)}
        isLoading={createMutation.isPending}
      />
    </div>
  )
}
