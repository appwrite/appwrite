import { useState, useEffect } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { useBucket, Dependencies } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { PermissionsEditor } from '../auth/PermissionsEditor'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

export function BucketSecurity() {
  const t = useT()
  const { projectId, bucketId } = useParams({
    strict: false,
  })
  const queryClient = useQueryClient()

  // Fetch bucket data
  const { data: bucket, isLoading: bucketLoading } = useBucket(
    projectId,
    bucketId,
  )

  // State for permissions
  const [bucketPermissions, setBucketPermissions] = useState<string[]>([])
  const [fileSecurity, setFileSecurity] = useState(false)

  // Initialize state when bucket loads
  useEffect(() => {
    if (bucket) {
      setBucketPermissions(bucket.$permissions || [])
      setFileSecurity(bucket.fileSecurity)
    }
  }, [bucket])

  // Helper to compare arrays
  const arraysEqual = (a: string[], b: string[]): boolean => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  // Update bucket permissions mutation
  const updateBucketPermissionsMutation = useMutation({
    mutationFn: async (permissions: string[]) => {
      if (!projectId || !bucketId || !bucket) {
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        permissions,
        enabled: bucket.enabled ?? undefined, // Preserve current enabled state
      })
    },
    onSuccess: () => {
      toast.success(t('Bucket permissions have been updated'))
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKET })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Update file security mutation
  const updateFileSecurityMutation = useMutation({
    mutationFn: async (newFileSecurity: boolean) => {
      if (!projectId || !bucketId || !bucket) {
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      }
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        fileSecurity: newFileSecurity,
        enabled: bucket.enabled ?? undefined, // Preserve current enabled state
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKET })
      toast.success(t('Security has been updated'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleBucketPermissionsUpdate = () => {
    if (!arraysEqual(bucketPermissions, bucket?.$permissions || [])) {
      updateBucketPermissionsMutation.mutate(bucketPermissions)
    }
  }

  if (bucketLoading) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Loading...')}</div>
      </div>
    )
  }

  if (!bucket) {
    return (
      <div className="flex h-full items-center justify-center">
        <div className="text-muted-foreground">{t('Bucket not found')}</div>
      </div>
    )
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Permissions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Permissions')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Choose who can access your bucket and files.')}{' '}
              <DocsRouteLink className="link-neutral" href="/docs/permissions">
                {t('Learn more')}
              </DocsRouteLink>
              .
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <PermissionsEditor
              permissions={bucketPermissions}
              onPermissionsChange={setBucketPermissions}
              withCreate={true}
              projectId={projectId}
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                arraysEqual(bucketPermissions, bucket.$permissions || []) ||
                updateBucketPermissionsMutation.isPending
              }
              onClick={handleBucketPermissionsUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* File Level Security */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('File level security')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="file-security"
                  checked={fileSecurity}
                  onCheckedChange={(checked) => setFileSecurity(checked)}
                  disabled={updateFileSecurityMutation.isPending}
                />
                <Label
                  htmlFor="file-security"
                  className="text-[13px] text-foreground"
                >
                  {t('File level security')}
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-2">
              <p className="text-[13px] text-muted-foreground">
                {t('When file security is enabled, users need')}{' '}
                <strong>
                  {t('both bucket permissions and file permissions')}
                </strong>{' '}
                {t(
                  'to access files. File permissions are an additional layer, not an alternative to bucket permissions.',
                )}
              </p>
              <p className="text-[13px] text-muted-foreground">
                <strong>{t('Upload operations')}</strong>{' '}
                {t(
                  'always require bucket-level permissions, regardless of file security settings.',
                )}
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('If file security is disabled, users can access files')}{' '}
                <strong>{t('only if they have bucket permissions')}</strong>.{' '}
                {t('File permissions will be ignored.')}
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                fileSecurity === bucket.fileSecurity ||
                updateFileSecurityMutation.isPending
              }
              onClick={() => {
                if (fileSecurity !== bucket.fileSecurity) {
                  updateFileSecurityMutation.mutate(fileSecurity)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
