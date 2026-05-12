import { useEffect, useState } from 'react'
import { useNavigate, useParams, useLocation, useSearch } from '@tanstack/react-router'
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

/**
 * Storage index: buckets live in the workspace sidebar; this pane prompts selection
 * or creation when no bucket route is active.
 */
export function View() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const location = useLocation()
  const search = useSearch({ strict: false }) as { create?: string }
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)

  const { data: total = 0 } = useQuery({
    ...bucketsQueryOptions(projectId, 0, 1, ''),
    select: (d) => d.total ?? 0,
  })

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
      toast.success(`${bucket.name} has been created`)
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

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex flex-1 flex-col items-center justify-center px-6 py-12">
        <EmptyState
          icon={HardDrive}
          title={total === 0 ? 'Create your first bucket' : 'Select a bucket'}
          description={
            total === 0
              ? 'Buckets isolate files, permissions, and delivery rules. Create one from the sidebar to start uploading.'
              : 'Choose a bucket in the left sidebar to browse files, security, and settings. This layout mirrors the database console workspace.'
          }
          isEmpty
          variant="card"
          className="max-w-md"
        />
        {total === 0 && bucketsLimit > 0 && project?.teamId ? (
          <p className="mt-4 max-w-md text-center text-[12px] text-muted-foreground">
            Plan limit: {total} of {bucketsLimit} buckets ·{' '}
            {resolveOrganizationPlanDisplayLabel({
              planName: organizationPlan?.name ?? null,
              planId: organizationPlan?.$id,
            })}
          </p>
        ) : null}
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
