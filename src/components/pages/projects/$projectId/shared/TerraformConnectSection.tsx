import { useEffect, useMemo, useState } from 'react'
import { Check, Copy, ExternalLink, Key } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { cn } from '@/lib/utils'
import { TerraformIcon } from '@/components/global/shared/TerraformIcon'

const TERRAFORM_PROVIDER_REPO =
  'https://github.com/appwrite/terraform-provider-appwrite'

/** Provider docs on the public Terraform Registry (pinned line matches published 0.0.x) */
const TERRAFORM_REGISTRY_PROVIDER_DOCS =
  'https://registry.terraform.io/providers/appwrite/appwrite/latest/docs'

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

  const databaseTf = `resource "appwrite_database" "main" {
  id   = "main"
  name = "Main"
}

resource "appwrite_table" "users" {
  database_id = appwrite_database.main.id
  id          = "users"
  name        = "Users"
}

resource "appwrite_column" "name" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "name"
  type        = "varchar"
  size        = 255
  required    = true
}

resource "appwrite_column" "email" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "email"
  type        = "email"
  required    = true
}

resource "appwrite_column" "age" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "age"
  type        = "integer"
  min         = 0
  max         = 150
}

resource "appwrite_column" "role" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "role"
  type        = "enum"
  elements    = ["admin", "editor", "viewer"]
  default     = "viewer"
}

resource "appwrite_column" "tags" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "tags"
  type        = "varchar"
  size        = 64
  array       = true
}

resource "appwrite_column" "location" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "location"
  type        = "point"
}

resource "appwrite_index" "email_unique" {
  database_id = appwrite_database.main.id
  table_id    = appwrite_table.users.id
  key         = "email_unique"
  type        = "unique"
  columns     = [appwrite_column.email.key]
}`

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
      label: 'exports.sh',
      language: 'bash',
      code: exportsSh,
      footerHint:
        'Shell exports matching APPWRITE_* provider options. When set, you can skip duplicate fields in provider {}. Use secrets in CI, not committed files.',
    },
    {
      label: 'database.tf',
      language: 'hcl',
      code: databaseTf,
      footerHint:
        'Example database, table, columns, and index. Add alongside your provider configuration.',
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
  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [copied, setCopied] = useState(false)

  const codeFiles = useMemo(
    () => buildTerraformExampleFiles(endpoint, projectId),
    [endpoint, projectId],
  )

  useEffect(() => {
    setSelectedFileIndex(0)
    setCopied(false)
  }, [endpoint, projectId])

  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]

  const handleCopyCode = () => {
    if (!selectedFile) return
    navigator.clipboard.writeText(selectedFile.code)
    setCopied(true)
    toast.success('Copied to clipboard')
    setTimeout(() => setCopied(false), 2000)
  }

  return (
    <div className="grid grid-cols-[0.9fr_1.4fr] gap-6 pt-4 min-h-0 flex-1">
      <div className="space-y-4 min-w-0 min-h-0 overflow-y-auto">
        <div className="space-y-3">
          <h4 className="text-[13px] font-semibold text-foreground">
            Infrastructure as code
          </h4>
          <p className="text-[13px] text-muted-foreground leading-relaxed">
            The official Appwrite Terraform provider lets you create and update
            project resources from{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">.tf</code>{' '}
            files instead of clicking through the console - ideal for staging and
            production parity, code review, and automated pipelines.
          </p>
          <p className="text-[13px] text-muted-foreground leading-relaxed">
            Use it when you want repeatable environments, documented changes in
            Git, or to wire Appwrite into a broader Terraform stack (VPC, DNS,
            functions, and more) in one workflow. The registry documents
            resources such as{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              appwrite_database
            </code>
            ,{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              appwrite_bucket
            </code>
            ,{' '}
            <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
              appwrite_messaging_topic
            </code>
            , and others, with full schemas and imports.
          </p>
        </div>
        <div className="rounded-xl border border-border bg-muted/30 overflow-hidden">
          <div className="px-4 py-3 border-b border-border">
            <h4 className="text-[13px] font-semibold text-foreground">
              API keys
            </h4>
          </div>
          <div className="px-4 py-3 space-y-3">
            <p className="text-[13px] text-muted-foreground">
              Terraform needs an API key with scopes for the resources you
              manage. Pass it with{' '}
              <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                TF_VAR_appwrite_api_key
              </code>{' '}
              or{' '}
              <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                terraform.tfvars
              </code>
              - never commit secrets to Git.
            </p>
            <Button
              variant="secondary"
              size="sm"
              className="h-9 text-[13px] gap-1.5"
              onClick={onViewApiKeys}
            >
              <Key className="h-4 w-4" />
              View API keys
            </Button>
          </div>
        </div>
        <div className="flex flex-col gap-2">
          <a
            href={TERRAFORM_REGISTRY_PROVIDER_DOCS}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-[13px] text-primary hover:underline"
          >
            <TerraformIcon />
            Provider docs on Terraform Registry
            <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden />
          </a>
          <a
            href={TERRAFORM_PROVIDER_REPO}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-[13px] text-primary hover:underline"
          >
            <GitHubIcon className="h-4 w-4" />
            appwrite/terraform-provider-appwrite
            <ExternalLink className="h-3.5 w-3.5 shrink-0" aria-hidden />
          </a>
        </div>
      </div>

      <div className="min-w-0 min-h-0 flex flex-col gap-2 flex-1">
        <div className="shrink-0 flex flex-wrap items-center justify-between gap-2">
          {codeFiles.length > 1 ? (
            <div className="flex flex-wrap gap-1.5">
              {codeFiles.map((file, i) => (
                <button
                  key={file.label}
                  type="button"
                  onClick={() => {
                    setSelectedFileIndex(i)
                    setCopied(false)
                  }}
                  className={cn(
                    'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                    i === selectedFileIndex
                      ? 'bg-muted text-foreground'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted/70',
                  )}
                >
                  {file.label}
                </button>
              ))}
            </div>
          ) : (
            <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {selectedFile?.label}
            </span>
          )}
          {selectedFile && (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
              onClick={handleCopyCode}
            >
              {copied ? (
                <Check className="h-3.5 w-3.5" />
              ) : (
                <Copy className="h-3.5 w-3.5" />
              )}
              Copy
            </Button>
          )}
        </div>
        {selectedFile && (
          <div className="min-h-0 flex-1 flex flex-col">
            <CodeBlock
              code={selectedFile.code}
              language={selectedFile.language}
              showCopy={false}
              fixedHeight="100%"
              className="flex-1 min-h-0 flex flex-col [&>div:last-child]:flex-1 [&>div:last-child]:min-h-0"
            />
          </div>
        )}
        {selectedFile && (
          <p className="shrink-0 text-[12px] text-muted-foreground pt-2">
            {selectedFile.label === 'main.tf' ? (
              <>
                Provider uses this project&apos;s endpoint and project ID. Run{' '}
                <code className="rounded bg-muted px-1 py-0.5 text-[11px]">
                  terraform init
                </code>{' '}
                then{' '}
                <code className="rounded bg-muted px-1 py-0.5 text-[11px]">
                  terraform apply
                </code>
                .
              </>
            ) : (
              selectedFile.footerHint ?? null
            )}
          </p>
        )}
      </div>
    </div>
  )
}
