import { useEffect, useMemo, useState } from 'react'
import { Key, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { PostgresCopyableField } from '@/components/pages/projects/$projectId/databases/postgres/_components/PostgresCopyableField'
import {
  getProjectS3StorageEndpoint,
  getProjectS3StorageRegion,
  S3_STORAGE_API_KEY_DEFAULT_NAME,
  S3_STORAGE_API_KEY_SCOPES,
} from '@/lib/storage-s3'
import { useCreateApiKey, useProject, useOrganizationScopes } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import { canCreateKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'


type S3ExampleFile = {
  label: string
  code: string
  language: CodeBlockLanguage
  footerHint?: string
}

function buildS3ExampleFiles(
  s3Endpoint: string,
  projectId: string,
  region: string,
): S3ExampleFile[] {
  const endpoint = s3Endpoint || 'https://cloud.appwrite.io/v1/s3'
  const pid = projectId || 'YOUR_PROJECT_ID'
  const regionValue = region || 'your-region'

  const boto3 = `import boto3

s3 = boto3.client(
    "s3",
    endpoint_url="${endpoint}",
    aws_access_key_id="${pid}",
    aws_secret_access_key="your-api-key",
    region_name="${regionValue}",
)
s3.list_buckets()`

  const awsCli = `export AWS_ACCESS_KEY_ID="${pid}"
export AWS_SECRET_ACCESS_KEY="your-api-key"
export AWS_DEFAULT_REGION="${regionValue}"
export AWS_ENDPOINT_URL="${endpoint}"

aws s3 ls # List buckets
aws s3 mb "s3://my-new-bucket" # Create new bucket
aws s3 cp ./file.txt s3://my-new-bucket/file.txt # Upload file to a bucket
aws s3 ls s3://my-new-bucket --human-readable # List files inside a bucket
aws s3 cp s3://my-new-bucket/file.txt ./export.txt # Download file from bucket
aws s3 rm s3://my-new-bucket/file.txt # Delete a file from bucket
aws s3 rb "s3://my-new-bucket" --force # Remove bucket and its contents`

  const rclone = `# Save config to ~/.config/rclone/rclone.conf
mkdir -p ~/.config/rclone
cat > ~/.config/rclone/rclone.conf <<'EOF'
[appwrite]
type = s3
provider = Other
access_key_id = ${pid}
secret_access_key = your-api-key
endpoint = ${endpoint}
region = ${regionValue}
EOF

# List buckets
rclone lsd appwrite:

# Create a bucket
rclone mkdir appwrite:my-new-bucket

# List files in a bucket
rclone ls appwrite:my-new-bucket

# Sync local -> remote (preview with --dry-run first)
rclone sync ./local-folder appwrite:my-new-bucket --dry-run
rclone sync ./local-folder appwrite:my-new-bucket

# Sync remote -> local (preview with --dry-run first)
rclone sync appwrite:my-new-bucket ./local-folder --dry-run
rclone sync appwrite:my-new-bucket ./local-folder`

  return [
    { label: 'AWS CLI', code: awsCli, language: 'bash' },
    { label: 'boto3', code: boto3, language: 'python' },
    { label: 'rclone', code: rclone, language: 'bash' },
  ]
}

function S3StorageScopeList() {
  return (
    <span className="inline-flex flex-wrap items-center gap-x-1 gap-y-1">
      {S3_STORAGE_API_KEY_SCOPES.map((scope, index) => (
        <span key={scope} className="inline-flex items-center">
          {index > 0 ? (
            <span className="text-muted-foreground">, </span>
          ) : null}
          <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
            {scope}
          </code>
        </span>
      ))}
    </span>
  )
}

export type S3ConnectSectionProps = {
  projectId: string
  onViewApiKeys: () => void
}

export function S3ConnectSection({
  projectId,
  onViewApiKeys,
}: S3ConnectSectionProps) {
  const t = useT()
  const { project, isLoading: projectLoading } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateKey(access, features)
  const createMutation = useCreateApiKey(projectId)
  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const s3Endpoint = useMemo(
    () => (projectId ? getProjectS3StorageEndpoint(projectId) : ''),
    [projectId],
  )

  const s3Region = useMemo(
    () =>
      projectId
        ? getProjectS3StorageRegion(projectId, project?.region)
        : undefined,
    [projectId, project?.region],
  )

  const codeFiles = useMemo(
    () => buildS3ExampleFiles(s3Endpoint, projectId, s3Region ?? ''),
    [s3Endpoint, projectId, s3Region],
  )

  const codeFileTabs = useMemo(
    () =>
      codeFiles.map((file, index) => ({
        id: String(index),
        label: file.label,
      })),
    [codeFiles],
  )

  const initialApiKeyName = t(S3_STORAGE_API_KEY_DEFAULT_NAME)

  useEffect(() => {
    setSelectedFileIndex(0)
  }, [s3Endpoint, projectId, s3Region])

  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]

  const handleCopyKey = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  const handleCreateApiKey = (data: {
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

  return (
    <>
      <div className="grid grid-cols-[0.9fr_1.4fr] gap-5 pt-3 min-h-0 flex-1">
        <div className="space-y-3 min-w-0 min-h-0">
          <div className="space-y-2">
            <h4 className="text-[13px] font-semibold text-foreground">
              {t('S3-compatible access')}
            </h4>
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t(
                'Connect Appwrite Storage to rclone, AWS CLI, and custom pipelines with a project-scoped HTTPS endpoint and SigV4 signing.',
              )}
            </p>
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t(
                'Use your project ID as the access key and an API key with these Storage scopes as the secret:',
              )}{' '}
              <S3StorageScopeList />
              . {t('API keys are only shown once at creation.')}
            </p>
          </div>

          <div className="space-y-3 rounded-xl border border-border bg-muted/30 px-4 py-3">
            <PostgresCopyableField label={t('Endpoint')} value={s3Endpoint} />
            <PostgresCopyableField
              label={t('Access key')}
              value={projectId}
            />
            <PostgresCopyableField
              label={t('Region')}
              value={s3Region ?? ''}
              isLoading={projectLoading && !s3Region}
            />
            <div className="flex flex-wrap gap-2 border-t border-border pt-3">
              <Button
                variant="secondary"
                size="sm"
                className="h-8 gap-1.5 text-[12px]"
                onClick={() => setCreateDrawerOpen(true)}
                disabled={noCreatePermission}
                title={
                  noCreatePermission
                    ? t("You don't have permission to create API keys.")
                    : undefined
                }
              >
                <Plus className="h-3.5 w-3.5" />
                {t('Create S3 API key')}
              </Button>
              <Button
                variant="secondary"
                size="sm"
                className="h-8 gap-1.5 text-[12px]"
                onClick={onViewApiKeys}
              >
                <Key className="h-3.5 w-3.5" />
                {t('View API keys')}
              </Button>
            </div>
          </div>
        </div>

        <div className="min-w-0 min-h-0 flex flex-col flex-1">
          {selectedFile ? (
            <ConnectCodeExample
              code={selectedFile.code}
              language={selectedFile.language ?? 'plaintext'}
              tabs={codeFileTabs}
              activeTabId={String(selectedFileIndex)}
              onTabChange={(id) => setSelectedFileIndex(Number(id))}
              selectorAriaLabel={t('Select file')}
              fixedHeight="100%"
              className="flex-1 min-h-0"
            />
          ) : null}
        </div>
      </div>

      <ApiKeyDrawer
        open={createDrawerOpen}
        onOpenChange={(open) => {
          setCreateDrawerOpen(open)
          if (!open) setCreatedKeySecret(null)
        }}
        onSubmit={handleCreateApiKey}
        isLoading={createMutation.isPending}
        createdKeySecret={createdKeySecret}
        onCopy={handleCopyKey}
        copiedField={copiedField}
        initialName={initialApiKeyName}
        initialScopes={[...S3_STORAGE_API_KEY_SCOPES]}
      />
    </>
  )
}
