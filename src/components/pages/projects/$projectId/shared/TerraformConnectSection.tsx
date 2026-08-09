import { useEffect, useMemo, useState } from 'react'
import { ExternalLink, Key, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import { cn } from '@/lib/utils'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canCreateKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useCreateApiKey,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

const TERRAFORM_PROVIDER_REPO =
  'https://github.com/appwrite/terraform-provider-appwrite'

/** Provider docs on the public Terraform Registry (pinned line matches published 0.0.x) */
const TERRAFORM_REGISTRY_PROVIDER_DOCS =
  'https://registry.terraform.io/providers/appwrite/appwrite/latest/docs'

const TERRAFORM_API_KEY_DEFAULT_NAME = 'Terraform'

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

type TerraformExampleFile = {
  label: string
  code: string
  language: CodeBlockLanguage
  /** Shown below the code for non-main.tf tabs */
  footerHint?: string
}

function buildTerraformExampleFiles(
  endpoint: string,
  projectId: string,
): TerraformExampleFile[] {
  const ep = endpoint || 'https://cloud.appwrite.io/v1'
  const pid = projectId || 'YOUR_PROJECT_ID'

  const mainTf = `terraform {
  required_providers {
    appwrite = {
      source  = "appwrite/appwrite"
      version = "~> 0.0.4"
    }
  }
}

variable "appwrite_api_key" {
  type        = string
  sensitive   = true
}

provider "appwrite" {
  endpoint   = "${ep}"
  project_id = "${pid}"
  api_key    = var.appwrite_api_key
}`

  const providersTf = `variable "appwrite_api_key" {
  type      = string
  sensitive = true
}

provider "appwrite" {
  endpoint    = "https://appwrite-instance.com/v1"
  project_id  = "${pid}"
  api_key     = var.appwrite_api_key
  self_signed = true
}`

  const exportsSh = `export APPWRITE_ENDPOINT="${ep}"
export APPWRITE_PROJECT_ID="${pid}"
export APPWRITE_API_KEY="your-api-key"`

  const databaseTf = `resource "appwrite_tablesdb" "main" {
  id   = "main"
  name = "main"
}

resource "appwrite_tablesdb_table" "users" {
  database_id = appwrite_tablesdb.main.id
  id          = "users"
  name        = "users"
}

resource "appwrite_tablesdb_column" "email" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "email"
  type        = "email"
  required    = true
}

resource "appwrite_tablesdb_index" "email_unique" {
  database_id = appwrite_tablesdb.main.id
  table_id    = appwrite_tablesdb_table.users.id
  key         = "email_unique"
  type        = "unique"
  columns     = [appwrite_tablesdb_column.email.key]
}`

  const functionsTf = `resource "appwrite_function" "hello_world" {
  name       = "hello-world"
  runtime    = "node-22"
  entrypoint = "index.js"
  commands   = "npm install"
}

resource "appwrite_function" "on_user_create" {
  name       = "on-user-create"
  runtime    = "node-22"
  events     = ["users.*.create"]
  entrypoint = "index.js"
  execute    = ["any"]
}

resource "appwrite_function_variable" "api_url" {
  function_id = appwrite_function.hello_world.id
  key         = "API_URL"
  value       = "https://api.example.com"
}`

  const tfvarsExample = `# terraform.tfvars (gitignore this file)
# Keys match variable names in main.tf (no TF_VAR_ prefix here).
appwrite_api_key = "your-api-key"`

  return [
    {
      label: 'main.tf',
      language: 'hcl',
      code: mainTf,
    },
    {
      label: 'providers.tf',
      language: 'hcl',
      code: providersTf,
      footerHint:
        'Common filename for provider {} blocks. Example: custom endpoint and self_signed when Appwrite is not at cloud.appwrite.io. Secrets stay in tfvars, env, or CI - not in .tf files. One required_providers block per root module (see main.tf).',
    },
    {
      label: 'terraform.tfvars',
      language: 'hcl',
      code: tfvarsExample,
      footerHint:
        'Place next to your .tf files. Terraform loads terraform.tfvars automatically. Add *.tfvars to .gitignore so the API key is never committed.',
    },
    {
      label: 'exports.sh',
      language: 'bash',
      code: exportsSh,
      footerHint:
        'Shell exports matching APPWRITE_* provider options. When set, you can skip duplicate fields in provider {}. Use secrets in CI, not committed files.',
    },
    {
      label: 'tablesdb.tf',
      language: 'hcl',
      code: databaseTf,
      footerHint:
        'TablesDB example: database, table, column, and index. Add alongside your provider configuration.',
    },
    {
      label: 'functions.tf',
      language: 'hcl',
      code: functionsTf,
      footerHint:
        'Functions example: a basic function, an event-driven function, and an environment variable.',
    },
  ]
}

export type TerraformConnectSectionProps = {
  endpoint: string
  projectId: string
  onViewApiKeys: () => void
}

/**
 * Terraform tab for Connect project modal - same grid pattern as the SDK tab:
 * description + links on the left, scrollable code panel on the right.
 */
export function TerraformConnectSection({
  endpoint,
  projectId,
  onViewApiKeys,
}: TerraformConnectSectionProps) {
  const t = useT()
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateKey(access, features)
  const createMutation = useCreateApiKey(projectId)
  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [apiKeyMethod, setApiKeyMethod] = useState<'env' | 'tfvars'>('env')
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)

  const codeFiles = useMemo(
    () => buildTerraformExampleFiles(endpoint, projectId),
    [endpoint, projectId],
  )

  const codeFileTabs = useMemo(
    () =>
      codeFiles.map((file, index) => ({
        id: String(index),
        label: file.label,
      })),
    [codeFiles],
  )

  useEffect(() => {
    setSelectedFileIndex(0)
  }, [endpoint, projectId])

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

  const selectTfvarsExample = () => {
    const index = codeFiles.findIndex(
      (file) => file.label === 'terraform.tfvars',
    )
    if (index >= 0) {
      setSelectedFileIndex(index)
    }
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
    <div className="grid grid-cols-[0.9fr_1.4fr] gap-6 pt-4 min-h-0 flex-1">
      <div className="space-y-5 min-w-0 min-h-0 overflow-y-auto">
        <div className="space-y-2">
          <h4 className="text-[13px] font-semibold text-foreground">
            {t('Infrastructure as code')}
          </h4>
          <p className="text-[13px] text-muted-foreground leading-relaxed">
            {t(
              'Manage Appwrite resources as code. Copy an example on the right, then run terraform init and apply.',
            )}
          </p>
        </div>

        <div className="space-y-3">
          <div className="space-y-1.5">
            <h4 className="text-[13px] font-semibold text-foreground">
              {t('API key')}
            </h4>
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {t(
                'Required for apply. Never commit secrets to Git.',
              )}
            </p>
          </div>
          <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-muted/30 p-1 w-fit">
            <button
              type="button"
              onClick={() => setApiKeyMethod('env')}
              className={cn(
                'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                apiKeyMethod === 'env'
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {t('Environment variable')}
            </button>
            <button
              type="button"
              onClick={() => {
                setApiKeyMethod('tfvars')
                selectTfvarsExample()
              }}
              className={cn(
                'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                apiKeyMethod === 'tfvars'
                  ? 'bg-background text-foreground shadow-sm'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              terraform.tfvars
            </button>
          </div>
          {apiKeyMethod === 'env' ? (
            <div className="space-y-2">
              <CodeBlock
                code='export TF_VAR_appwrite_api_key="your-api-key"'
                language="bash"
                label={t('Terminal')}
                showCopy
              />
              <p className="text-[12px] text-muted-foreground leading-relaxed">
                {t(
                  'Name after TF_VAR_ must match the variable (usually lowercase). .env files are not loaded.',
                )}
              </p>
            </div>
          ) : (
            <p className="text-[12px] text-muted-foreground leading-relaxed">
              {t(
                'Terraform loads terraform.tfvars next to your .tf files automatically. Gitignore *.tfvars.',
              )}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
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
              {...analyticsAttrs('create-api-key')}
            >
              <Plus className="h-3.5 w-3.5" />
              {t('Create API key')}
            </Button>
            <Button
              type="button"
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

        <div className="flex flex-col gap-2">
          <a
            href={TERRAFORM_REGISTRY_PROVIDER_DOCS}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
          >
            <TerraformIcon />
            {t('Provider docs on Terraform Registry')}
            <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden />
          </a>
          <a
            href={TERRAFORM_PROVIDER_REPO}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
          >
            <GitHubIcon className="h-4 w-4" />
            appwrite/terraform-provider-appwrite
            <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden />
          </a>
        </div>
      </div>

      <div className="min-w-0 min-h-0 flex flex-col gap-2 flex-1">
        {selectedFile && (
          <ConnectCodeExample
            code={selectedFile.code}
            language={selectedFile.language}
            tabs={codeFileTabs}
            activeTabId={String(selectedFileIndex)}
            onTabChange={(id) => setSelectedFileIndex(Number(id))}
            selectorAriaLabel={t('Select file')}
            fixedHeight="100%"
            className="flex-1 min-h-0"
          />
        )}
        {selectedFile && (
          <p className="shrink-0 text-[12px] text-muted-foreground pt-2">
            {selectedFile.label === 'main.tf' ? (
              <>
                {t("Provider uses this project's endpoint and project ID. Run")}{' '}
                <code className="rounded bg-muted px-1 py-0.5 text-[11px]">
                  terraform init
                </code>{' '}
                {t('then')}{' '}
                <code className="rounded bg-muted px-1 py-0.5 text-[11px]">
                  terraform apply
                </code>
                .
              </>
            ) : selectedFile.footerHint ? (
              t(selectedFile.footerHint)
            ) : null}
          </p>
        )}
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
      initialName={t(TERRAFORM_API_KEY_DEFAULT_NAME)}
    />
    </div>
  )
}
