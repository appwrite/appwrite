import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Compression } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useBucket,
  Dependencies,
  useProject,
  useOrganizationPlan,
  getCachedBucketListsFromQueryClient,
  pickNextBucketIdAfterDelete,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Loader2, FolderOpen, X, Plus } from 'lucide-react'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  pickFormDecimalByteDisplayUnit,
  toByteCount,
} from '@/lib/utils/byte-display-unit'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { useNavigate } from '@tanstack/react-router'
import { useT } from '@/lib/i18n/translate'

export function BucketSettings() {
  const t = useT()
  const { projectId, bucketId } = useParams({
    strict: false,
  })
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Fetch bucket data
  const { data: bucket, isLoading: bucketLoading } = useBucket(
    projectId,
    bucketId,
  )

  // Get project to access teamId (organization ID)
  const { project } = useProject(projectId)
  const orgId = project?.teamId

  // Get organization plan to check max file size limit
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

  // Get max file size from plan object (fileSize is in MB, -1 means unlimited)
  const maxFileSizeByPlan = useMemo(() => {
    if (!organizationPlan) return null

    const fileSize = organizationPlan?.fileSize
    const fileSizeNumber =
      fileSize == null
        ? null
        : typeof fileSize === 'bigint'
          ? Number(fileSize)
          : Number(fileSize)

    // -1 means unlimited
    if (
      fileSizeNumber == null ||
      !Number.isFinite(fileSizeNumber) ||
      fileSizeNumber === -1
    ) {
      return null
    }

    // Convert MB to bytes (using 1000 base)
    return fileSizeNumber * 1000 * 1000
  }, [organizationPlan])

  // State for form fields
  const [bucketName, setBucketName] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [encryption, setEncryption] = useState(false)
  const [compression, setCompression] = useState<'none' | 'gzip' | 'zstd'>(
    'none',
  )
  const [transformations, setTransformations] = useState(false)
  const [maximumFileSize, setMaximumFileSize] = useState(0)
  const [fileSizeUnit, setFileSizeUnit] = useState<
    'bytes' | 'KB' | 'MB' | 'GB'
  >('MB')
  const [allowedFileExtensions, setAllowedFileExtensions] = useState<string[]>(
    [],
  )
  const [extensionInput, setExtensionInput] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')

  // Initialize form fields when bucket loads
  useEffect(() => {
    if (bucket) {
      setBucketName(bucket.name)
      setEnabled(bucket.enabled)
      setEncryption(bucket.encryption)
      const bucketCompression =
        (bucket.compression as 'none' | 'gzip' | 'zstd') || 'none'
      setCompression(bucketCompression)
      setTransformations(bucket.transformations)
      // Pick the unit whose numeric value is smallest while still a whole integer
      if (toByteCount(bucket.maximumFileSize) > 0) {
        const { value, unit } = pickFormDecimalByteDisplayUnit(
          toByteCount(bucket.maximumFileSize),
        )
        setMaximumFileSize(value)
        setFileSizeUnit(unit)
      } else {
        setMaximumFileSize(0)
        setFileSizeUnit('MB')
      }
      // Initialize extensions
      setAllowedFileExtensions(bucket.allowedFileExtensions || [])
    }
  }, [bucket])

  // Update name mutation
  const updateNameMutation = useMutation({
    mutationFn: async (name: string) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: name.trim(),
        enabled: bucket.enabled ?? undefined,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        transformations: bucket.transformations ?? undefined,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Bucket name has been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        enabled,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        transformations: bucket.transformations ?? undefined,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(
        enabled ? t('Bucket has been enabled') : t('Bucket has been disabled'),
      )
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
      queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (bucket) {
        setEnabled(bucket.enabled)
      }
    },
  })

  // Update compression mutation
  const updateCompressionMutation = useMutation({
    mutationFn: async (compression: 'none' | 'gzip' | 'zstd') => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        compression:
          compression === 'none' ? undefined : (compression as Compression),
        enabled: bucket.enabled ?? undefined,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        transformations: bucket.transformations ?? undefined,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Compression setting has been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Update file size mutation
  const updateMaximumFileSizeMutation = useMutation({
    mutationFn: async (maximumFileSize: number) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        maximumFileSize,
        enabled: bucket.enabled ?? undefined,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        transformations: bucket.transformations ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Maximum file size has been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Update allowed extensions mutation
  const updateAllowedExtensionsMutation = useMutation({
    mutationFn: async (allowedFileExtensions: string[]) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        allowedFileExtensions,
        enabled: bucket.enabled ?? undefined,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        transformations: bucket.transformations ?? undefined,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Allowed file extensions have been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Delete bucket mutation
  const deleteBucketMutation = useMutation({
    mutationFn: async () => {
      if (!projectId || !bucketId)
        throw new Error('Project ID and Bucket ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.deleteBucket({ bucketId })
    },
    onSuccess: async () => {
      const deletedId = bucketId!
      const lists = getCachedBucketListsFromQueryClient(queryClient, projectId)
      const nextBucketId = pickNextBucketIdAfterDelete(lists, deletedId)
      await queryClient.refetchQueries({ queryKey: Dependencies.BUCKETS })
      toast.success(t('Bucket has been deleted'))
      if (nextBucketId) {
        navigate({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId: projectId!, bucketId: nextBucketId },
        })
      } else {
        navigate({
          to: '/projects/$projectId/storage/$bucketId',
          params: { projectId: projectId!, bucketId: '-' },
        })
      }
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Individual security feature mutations
  const updateEncryptionMutation = useMutation({
    mutationFn: async (encryption: boolean) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        encryption,
        enabled: bucket.enabled ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        transformations: bucket.transformations ?? undefined,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Encryption setting has been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (bucket) {
        setEncryption(bucket.encryption)
      }
    },
  })

  const updateTransformationsMutation = useMutation({
    mutationFn: async (transformations: boolean) => {
      if (!projectId || !bucketId || !bucket)
        throw new Error('Project ID, Bucket ID, and Bucket are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.storage.updateBucket({
        bucketId,
        name: bucket.name, // Required parameter
        transformations,
        enabled: bucket.enabled ?? undefined,
        encryption: bucket.encryption ?? undefined,
        antivirus: bucket.antivirus ?? undefined,
        compression: bucket.compression as Compression,
        maximumFileSize: bucket.maximumFileSize ?? undefined,
        allowedFileExtensions: bucket.allowedFileExtensions ?? undefined,
      })
    },
    onSuccess: () => {
      toast.success(t('Image transformations setting has been updated'))
      queryClient.invalidateQueries({
        queryKey: ['bucket', 'project', projectId, bucketId],
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (bucket) {
        setTransformations(bucket.transformations)
      }
    },
  })

  const handleNameUpdate = () => {
    if (bucketName.trim() && bucketName !== bucket?.name) {
      updateNameMutation.mutate(bucketName)
    }
  }

  const handleEnabledToggle = (checked: boolean) => {
    setEnabled(checked)
  }

  const handleEncryptionToggle = (checked: boolean) => {
    setEncryption(checked)
  }

  const handleTransformationsToggle = (checked: boolean) => {
    setTransformations(checked)
  }

  const handleEncryptionUpdate = () => {
    if (encryption !== bucket?.encryption) {
      updateEncryptionMutation.mutate(encryption)
    }
  }

  const handleTransformationsUpdate = () => {
    if (transformations !== bucket?.transformations) {
      updateTransformationsMutation.mutate(transformations)
    }
  }

  const handleCompressionUpdate = (value: 'none' | 'gzip' | 'zstd') => {
    setCompression(value)
  }

  const getCompressionDisplayName = (
    value: 'none' | 'gzip' | 'zstd' | null | undefined,
  ): string => {
    if (!value) return ''
    const names: Record<'none' | 'gzip' | 'zstd', string> = {
      none: t('None'),
      gzip: 'Gzip',
      zstd: 'Zstd',
    }
    return names[value]
  }

  // Convert file size to bytes based on unit
  const fileSizeInBytes = useMemo(() => {
    if (maximumFileSize === null || maximumFileSize === 0) return 0
    const multipliers: Record<'bytes' | 'KB' | 'MB' | 'GB', number> = {
      bytes: 1,
      KB: 1000,
      MB: 1000 * 1000,
      GB: 1000 * 1000 * 1000,
    }
    return Math.round(maximumFileSize * multipliers[fileSizeUnit])
  }, [maximumFileSize, fileSizeUnit])

  // Calculate max file size in current unit for input validation
  const maxFileSizeInCurrentUnit = useMemo(() => {
    if (maxFileSizeByPlan === null) return undefined
    const multipliers: Record<'bytes' | 'KB' | 'MB' | 'GB', number> = {
      bytes: 1,
      KB: 1000,
      MB: 1000 * 1000,
      GB: 1000 * 1000 * 1000,
    }
    return maxFileSizeByPlan / multipliers[fileSizeUnit]
  }, [maxFileSizeByPlan, fileSizeUnit])

  // Validation error for file size input
  const fileSizeError = useMemo(() => {
    if (maxFileSizeByPlan === null) return null // Enterprise has no limit
    if (fileSizeInBytes === 0) return null // 0 means unlimited, which is allowed
    if (fileSizeInBytes > maxFileSizeByPlan) {
      return {
        message: `${t('Maximum file size cannot exceed')} ${formatBytes(maxFileSizeByPlan)} ${t('for your plan')}`,
        showUpgrade: true,
      }
    }
    return null
  }, [fileSizeInBytes, maxFileSizeByPlan, t])

  const handleMaximumFileSizeUpdate = () => {
    if (fileSizeInBytes !== toByteCount(bucket?.maximumFileSize)) {
      // Validate against plan limit
      if (fileSizeError) {
        toast.error(fileSizeError.message || t('File size exceeds plan limit'))
        return
      }
      updateMaximumFileSizeMutation.mutate(fileSizeInBytes)
    }
  }

  const handleAllowedExtensionsUpdate = () => {
    if (
      !arraysEqual(allowedFileExtensions, bucket?.allowedFileExtensions || [])
    ) {
      updateAllowedExtensionsMutation.mutate(allowedFileExtensions)
    }
  }

  const handleAddExtension = (ext: string) => {
    const trimmed = ext.trim().toLowerCase().replace(/^\./, '')
    if (
      trimmed &&
      !allowedFileExtensions.includes(trimmed) &&
      allowedFileExtensions.length < 100
    ) {
      setAllowedFileExtensions([...allowedFileExtensions, trimmed])
      setExtensionInput('')
    }
  }

  const handleRemoveExtension = (ext: string) => {
    setAllowedFileExtensions(allowedFileExtensions.filter((e) => e !== ext))
  }

  const handleExtensionInputKeyDown = (
    e: React.KeyboardEvent<HTMLInputElement>,
  ) => {
    if (e.key === 'Enter' && extensionInput.trim()) {
      e.preventDefault()
      handleAddExtension(extensionInput)
    } else if (e.key === ',' && extensionInput.trim()) {
      e.preventDefault()
      handleAddExtension(extensionInput)
    } else if (e.key === ' ' && extensionInput.trim()) {
      e.preventDefault()
      handleAddExtension(extensionInput)
    } else if (
      (e.key === 'Backspace' || e.key === 'Delete') &&
      !extensionInput.trim() &&
      allowedFileExtensions !== null &&
      allowedFileExtensions.length > 0
    ) {
      e.preventDefault()
      handleRemoveExtension(
        allowedFileExtensions[allowedFileExtensions.length - 1],
      )
    }
  }

  const popularExtensions = [
    'jpg',
    'png',
    'gif',
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'zip',
    'mp4',
  ]

  // Helper to compare arrays
  const arraysEqual = (a: string[], b: string[]): boolean => {
    if (a.length !== b.length) return false
    const sortedA = [...a].sort()
    const sortedB = [...b].sort()
    return sortedA.every((val, idx) => val === sortedB[idx])
  }

  if (bucketLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!bucket) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          {t('Bucket not found')}
        </p>
      </div>
    )
  }

  return (
    <div className="w-full px-4 py-4 sm:px-6">
      <div className="space-y-6">
        {/* Update Bucket Name */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Name')}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <p className="text-[13px] text-muted-foreground">
              {t(
                "Update your bucket's display name. This will be visible to all organization members.",
              )}
            </p>
            <Input
              value={bucketName}
              onChange={(e) => setBucketName(e.target.value)}
              placeholder={t('Bucket name')}
              className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                bucketName.trim() === bucket.name ||
                !bucketName.trim() ||
                updateNameMutation.isPending
              }
              onClick={handleNameUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Bucket Information */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {bucket.name}
            </h3>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Switch
                  id="toggle"
                  checked={enabled ?? false}
                  onCheckedChange={handleEnabledToggle}
                  disabled={updateEnabledMutation.isPending}
                />
                <Label htmlFor="toggle" className="text-[13px] text-foreground">
                  {enabled ? t('Enabled') : t('Disabled')}
                </Label>
              </div>
            </div>
            <div className="mt-4 space-y-1">
              <p className="text-[13px] text-muted-foreground">
                {t('Bucket ID:')}{' '}
                <span className="ms-1.5">
                  <CopyableId id={bucket.$id} size="sm" />
                </span>
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('Created:')}{' '}
                <DateTooltip
                  date={new Date(bucket.$createdAt)}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
              <p className="text-[13px] text-muted-foreground">
                {t('Last updated:')}{' '}
                <DateTooltip
                  date={new Date(bucket.$updatedAt)}
                  showFormattedDate
                  className="text-foreground"
                />
              </p>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                enabled === bucket.enabled || updateEnabledMutation.isPending
              }
              onClick={() => {
                if (enabled !== bucket.enabled) {
                  updateEnabledMutation.mutate(enabled)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Encryption */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Encryption')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                "Encrypt files stored in this bucket. For file size above 20MB encryption is skipped even if it's enabled. This change will only apply to new files uploaded after the update.",
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div>
                <Label
                  htmlFor="encryption"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Enabled')}
                </Label>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('Encrypt files stored in this bucket')}
                </p>
              </div>
              <Switch
                id="encryption"
                checked={encryption}
                onCheckedChange={handleEncryptionToggle}
                disabled={updateEncryptionMutation.isPending}
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                encryption === bucket.encryption ||
                updateEncryptionMutation.isPending
              }
              onClick={handleEncryptionUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Antivirus */}
        {/* <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Antivirus
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Scan uploaded files for viruses. For file size above 20MB antivirus scanning is skipped even if it's enabled.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <Label htmlFor="antivirus" className="text-[13px] font-medium text-foreground">
                Enabled
              </Label>
              <p className="text-[12px] text-muted-foreground mt-0.5">
                Scan uploaded files for viruses
              </p>
            </div>
            <div className="flex items-center gap-2">
              {updateAntivirusMutation.isPending && (
                <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
              )}
              <Switch
                id="antivirus"
                checked={antivirus}
                onCheckedChange={handleAntivirusToggle}
                disabled={updateAntivirusMutation.isPending}
              />
            </div>
          </div>
        </div>
      </div> */}

        {/* Image Transformations */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Image transformations')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Enable image transformation features for files in this bucket.')}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-center justify-between">
              <div>
                <Label
                  htmlFor="transformations"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Enabled')}
                </Label>
                <p className="text-[12px] text-muted-foreground mt-0.5">
                  {t('Enable image transformation features')}
                </p>
              </div>
              <Switch
                id="transformations"
                checked={transformations}
                onCheckedChange={handleTransformationsToggle}
                disabled={updateTransformationsMutation.isPending}
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                transformations === bucket.transformations ||
                updateTransformationsMutation.isPending
              }
              onClick={handleTransformationsUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Compression */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Compression')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Choose a compression algorithm for files in this bucket. Compression reduces file sizes, lowering storage costs and bandwidth usage while improving transfer speeds. This change will only apply to new files uploaded after the update.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-4">
              <div>
                <Label
                  htmlFor="compression"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Algorithm')}
                </Label>
                <Select
                  value={compression || undefined}
                  onValueChange={(value) =>
                    handleCompressionUpdate(value as 'none' | 'gzip' | 'zstd')
                  }
                  disabled={updateCompressionMutation.isPending}
                >
                  <SelectTrigger id="compression" className="mt-1.5 max-w-sm">
                    <SelectValue placeholder={t('Select algorithm')}>
                      {getCompressionDisplayName(compression)}
                    </SelectValue>
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none" className="items-start py-2">
                      <div className="flex flex-col gap-0.5">
                        <span className="font-medium">{t('None')}</span>
                        <span className="text-[11px] text-muted-foreground">
                          {t('No compression applied. Files are stored as-is.')}
                        </span>
                      </div>
                    </SelectItem>
                    <SelectItem value="gzip" className="items-start py-2">
                      <div className="flex flex-col gap-0.5">
                        <span className="font-medium">Gzip</span>
                        <span className="text-[11px] text-muted-foreground">
                          {t('Good balance between compression ratio and speed.')}
                        </span>
                      </div>
                    </SelectItem>
                    <SelectItem value="zstd" className="items-start py-2">
                      <div className="flex flex-col gap-0.5">
                        <span className="font-medium">Zstd</span>
                        <span className="text-[11px] text-muted-foreground">
                          {t('Excellent compression ratios with fast decompression.')}
                        </span>
                      </div>
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                compression ===
                  ((bucket.compression as 'none' | 'gzip' | 'zstd') ||
                    'none') || updateCompressionMutation.isPending
              }
              onClick={() => {
                if (
                  compression !==
                  ((bucket.compression as 'none' | 'gzip' | 'zstd') || 'none')
                ) {
                  updateCompressionMutation.mutate(compression)
                }
              }}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Maximum File Size */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Maximum file size')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t('Set the maximum file size allowed.')}{' '}
              {maxFileSizeByPlan !== null ? (
                <>
                  {t('Maximum allowed value is')} {formatBytes(maxFileSizeByPlan)}{' '}
                  {t('for your plan.')}{' '}
                  <Button
                    variant="link"
                    size="sm"
                    className="h-auto p-0 text-[13px] font-medium underline"
                    onClick={() => {
                      navigate({
                        to: '/upgrade',
                        ...(orgId ? { search: { orgId } } : {}),
                      })
                    }}
                  >
                    {t('Upgrade')}
                  </Button>{' '}
                  {t("to increase the limit. Set to 0 to use your plan's maximum limit")}{' '}
                  ({formatBytes(maxFileSizeByPlan)}).
                </>
              ) : (
                t('No limit for your plan. Set to 0 for unlimited.')
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="flex items-end gap-3">
              <div className="space-y-2 flex-1 max-w-[200px]">
                <Label
                  htmlFor="max-file-size"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Size')}
                </Label>
                <Input
                  id="max-file-size"
                  type="number"
                  min={0}
                  step="any"
                  value={maximumFileSize}
                  onChange={(e) => {
                    const value = parseFloat(e.target.value)
                    if (!isNaN(value) && value >= 0) {
                      setMaximumFileSize(value)
                    } else if (e.target.value === '') {
                      setMaximumFileSize(0)
                    }
                  }}
                  max={maxFileSizeInCurrentUnit}
                  placeholder={t('0 for unlimited')}
                  disabled={updateMaximumFileSizeMutation.isPending}
                />
              </div>
              <div className="space-y-2 flex-1 max-w-[200px]">
                <Label
                  htmlFor="file-size-unit"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Unit')}
                </Label>
                <Select
                  value={fileSizeUnit}
                  onValueChange={(value) => {
                    const newUnit = value as 'bytes' | 'KB' | 'MB' | 'GB'
                    // Convert current value to new unit, preserving decimals
                    const currentBytes = fileSizeInBytes
                    const multipliers: Record<
                      'bytes' | 'KB' | 'MB' | 'GB',
                      number
                    > = {
                      bytes: 1,
                      KB: 1000,
                      MB: 1000 * 1000,
                      GB: 1000 * 1000 * 1000,
                    }
                    const newValue = currentBytes / multipliers[newUnit]
                    setMaximumFileSize(newValue)
                    setFileSizeUnit(newUnit)
                  }}
                  disabled={updateMaximumFileSizeMutation.isPending}
                >
                  <SelectTrigger id="file-size-unit">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="bytes">{t('Bytes')}</SelectItem>
                    <SelectItem value="KB">KB</SelectItem>
                    <SelectItem value="MB">MB</SelectItem>
                    <SelectItem value="GB">GB</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <div className="mt-2">
              {fileSizeError ? (
                <div className="flex items-center gap-2 flex-wrap">
                  <p className="text-[12px] text-destructive">
                    {fileSizeError.message}
                  </p>
                  {fileSizeError.showUpgrade && orgId && (
                    <Button
                      variant="link"
                      size="sm"
                      className="h-auto p-0 text-[12px] font-medium underline"
                      onClick={() => {
                        navigate({
                          to: '/upgrade',
                          search: { orgId },
                        })
                      }}
                    >
                      {t('Upgrade')}
                    </Button>
                  )}
                </div>
              ) : (
                <p className="text-[12px] text-muted-foreground">
                  {bucket.maximumFileSize > 0
                    ? `${t('Current:')} ${formatBytes(bucket.maximumFileSize)}`
                    : t('Unlimited')}
                </p>
              )}
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !bucket ||
                Math.abs(fileSizeInBytes - toByteCount(bucket.maximumFileSize)) < 1 ||
                updateMaximumFileSizeMutation.isPending ||
                fileSizeError !== null
              }
              onClick={handleMaximumFileSizeUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Allowed File Extensions */}
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Allowed file extensions')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Restrict file uploads to specific file extensions. Maximum of 100 extensions are allowed, each 64 characters long. Leave empty to allow all.',
              )}
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-4">
              <div>
                <Label
                  htmlFor="allowed-extensions"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('File extensions')}
                </Label>
                <p className="text-[12px] text-muted-foreground mt-0.5 mb-2">
                  {t('Type and press Enter or comma to add extensions')}
                </p>
                <div className="relative max-w-md">
                  <div
                    className={cn(
                      'flex flex-wrap items-center gap-1.5 min-h-[36px] rounded-md border bg-transparent px-3 py-1.5 text-sm transition-[color,box-shadow] outline-none',
                      'focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]',
                      updateAllowedExtensionsMutation.isPending ||
                        allowedFileExtensions.length >= 100
                        ? 'opacity-50 cursor-not-allowed'
                        : '',
                    )}
                  >
                    {allowedFileExtensions.map((ext) => (
                      <Badge
                        key={ext}
                        variant="secondary"
                        className="gap-1 h-6 text-[11px] px-1.5 py-0 bg-muted border-border"
                      >
                        {ext}
                        <button
                          type="button"
                          onClick={() => handleRemoveExtension(ext)}
                          className="ms-0.5 rounded-full hover:bg-muted/80 p-0.5"
                          disabled={updateAllowedExtensionsMutation.isPending}
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </Badge>
                    ))}
                    <input
                      id="allowed-extensions"
                      type="text"
                      value={extensionInput}
                      onChange={(e) => setExtensionInput(e.target.value)}
                      onKeyDown={handleExtensionInputKeyDown}
                      placeholder={
                        allowedFileExtensions.length === 0
                          ? t('Enter extension (e.g., jpg)')
                          : ''
                      }
                      className="flex-1 min-w-[120px] bg-transparent border-0 outline-none text-sm placeholder:text-muted-foreground"
                      disabled={
                        updateAllowedExtensionsMutation.isPending ||
                        allowedFileExtensions.length >= 100
                      }
                    />
                  </div>
                </div>
                <div className="flex flex-wrap gap-2 mt-3">
                  {popularExtensions.map((ext) => (
                    <Button
                      key={ext}
                      type="button"
                      variant="outline"
                      size="sm"
                      className="h-8 text-[12px]"
                      onClick={() => handleAddExtension(ext)}
                      disabled={
                        updateAllowedExtensionsMutation.isPending ||
                        allowedFileExtensions.includes(ext) ||
                        allowedFileExtensions.length >= 100
                      }
                    >
                      <Plus className="h-3 w-3 me-1" />
                      {ext}
                    </Button>
                  ))}
                </div>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30">
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !bucket ||
                arraysEqual(
                  allowedFileExtensions,
                  bucket.allowedFileExtensions || [],
                ) ||
                updateAllowedExtensionsMutation.isPending
              }
              onClick={handleAllowedExtensionsUpdate}
            >
              {t('Update')}
            </Button>
          </div>
        </div>

        {/* Delete Bucket */}
        <div className="rounded-xl border border-red-500/30 bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Delete bucket')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Permanently delete this bucket and all its files. This action cannot be undone.',
              )}
            </p>
          </div>
          <div className="border-t border-red-500/20" />
          <div className="px-6 py-4">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <FolderOpen className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-[14px] font-medium text-foreground truncate">
                  {bucket.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {t('Last updated:')}{' '}
                  <DateTooltip
                    date={new Date(bucket.$updatedAt)}
                    showFormattedDate
                    className="text-foreground"
                  />
                </p>
              </div>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
              <DialogTrigger asChild>
                <Button
                  variant="destructive"
                  size="sm"
                  className="h-9 text-[13px]"
                >
                  {t('Delete')}
                </Button>
              </DialogTrigger>
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-start">
                  <DialogTitle>{t('Delete bucket')}</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    {t('Are you sure you want to delete')}{' '}
                    <strong>{bucket.name}</strong>?{' '}
                    {t(
                      'This will permanently delete the bucket and all its files. This action cannot be undone.',
                    )}
                  </DialogDescription>
                </DialogHeader>
                <div className="border-t border-border" />
                <div className="px-6 pb-4 pt-0">
                  <div className="space-y-4">
                    <div>
                      <Label
                        htmlFor="delete-confirmation"
                        className="text-[13px] font-medium text-foreground"
                      >
                        {t('Type the bucket name to confirm')}
                      </Label>
                      <Input
                        id="delete-confirmation"
                        value={deleteConfirmation}
                        onChange={(e) => setDeleteConfirmation(e.target.value)}
                        placeholder={bucket.name}
                        className="mt-1.5"
                      />
                    </div>
                  </div>
                </div>
                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 text-[13px]"
                    onClick={() => {
                      setDeleteDialogOpen(false)
                      setDeleteConfirmation('')
                    }}
                    disabled={deleteBucketMutation.isPending}
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={
                      deleteConfirmation !== bucket.name ||
                      deleteBucketMutation.isPending
                    }
                    onClick={() => {
                      if (deleteConfirmation === bucket.name) {
                        deleteBucketMutation.mutate()
                      }
                    }}
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </div>
        </div>
      </div>
    </div>
  )
}
