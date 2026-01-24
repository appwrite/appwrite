/**
 * Template Configuration View Component
 *
 * Configuration screen for deploying a site from a template.
 * Handles site details, Git connection options, and template variables.
 */

import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { IdInput } from '@/components/ui/id-input'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ExternalLink,
  Loader2,
  GitBranch,
  Plus,
} from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import {
  useSiteTemplate,
  useCreateSite,
  useCreateSiteDomain,
  useCreateTemplateDeployment,
  Dependencies,
} from '@/lib/react-query/hooks'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import { EnvironmentVariables } from './EnvironmentVariables'

/**
 * Helper to safely extract a framework string from template.frameworks
 * The API might return strings or objects with a 'key' or 'name' property
 */
function getFrameworkString(framework: unknown): string {
  if (!framework) return ''
  if (typeof framework === 'string') return framework
  if (typeof framework === 'object' && framework !== null) {
    const obj = framework as Record<string, unknown>
    if (typeof obj.key === 'string') return obj.key
    if (typeof obj.name === 'string') return obj.name
    if (typeof obj.id === 'string') return obj.id
  }
  return ''
}

interface TemplateConfigViewProps {
  templateParam: string
}

export function TemplateConfigView({ templateParam }: TemplateConfigViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { theme, resolvedTheme } = useTheme()
  const {
    formData,
    updateFormData,
    frameworks,
    getFrameworkDefaults,
    generateDomain,
  } = useWizard()

  const templateId = decodeURIComponent(templateParam)

  // Fetch template details
  const { data: template, isLoading: templateLoading } = useSiteTemplate(
    projectId,
    templateId,
  )

  // Local form state
  const [siteName, setSiteName] = useState(formData.siteName || '')
  const [siteId, setSiteId] = useState<string | undefined>(formData.siteId)
  const [framework, setFramework] = useState(formData.framework || '')
  const [gitConnection, setGitConnection] = useState<'now' | 'later'>('later')
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Determine theme for screenshots
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return (
      resolvedTheme === 'dark' ||
      (resolvedTheme === 'system' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches) ||
      theme === 'dark'
    )
  }, [theme, resolvedTheme])

  // Initialize form from template
  useEffect(() => {
    if (template) {
      if (!siteName) setSiteName(template.name)
      if (!framework && template.frameworks?.length) {
        setFramework(getFrameworkString(template.frameworks[0]))
      }
      // Pre-fill template variables
      if (template.variables?.length && variables.length === 0) {
        const templateVars = template.variables.map((v) => ({
          key: v.name,
          value: v.value || '',
          secret: v.secret || false,
        }))
        setVariables(templateVars)
      }
    }
  }, [template])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
  }, [siteName, generateDomain])

  // Get screenshot URL - templates include full URLs
  const screenshotUrl = useMemo(() => {
    if (!template) return null
    return isDark ? template.screenshotDark : template.screenshotLight
  }, [template, isDark])

  // Mutations
  const createSiteMutation = useCreateSite(projectId)
  const createDomainMutation = useCreateSiteDomain(projectId)
  const createDeploymentMutation = useCreateTemplateDeployment(projectId)

  const handleDeploy = async () => {
    if (!projectId || !template || !siteName || !framework) {
      toast.error('Please fill in all required fields')
      return
    }

    if (!domainValid) {
      toast.error('Please enter a valid domain')
      return
    }

    setIsDeploying(true)

    try {
      // Get framework defaults
      const defaults = getFrameworkDefaults(framework)

      // 1. Create the site
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        installCommand: defaults.installCommand,
        buildCommand: defaults.buildCommand,
        outputDirectory: defaults.outputDirectory,
        adapter: template.adapter,
        fallbackFile: template.fallbackFile,
      })

      // 2. Create domain rule
      if (domain) {
        await createDomainMutation.mutateAsync({
          domain,
          siteId: site.$id,
        })
      }

      // 3. Create environment variables
      if (variables.length > 0) {
        const projectSdk = sdk.forProject(projectId)
        await Promise.all(
          variables
            .filter((v) => v.value) // Only create variables with values
            .map((v) =>
              projectSdk.sites.createVariable({
                siteId: site.$id,
                key: v.key,
                value: v.value,
                secret: v.secret,
              }),
            ),
        )
      }

      // 4. Create template deployment
      const deployment = await createDeploymentMutation.mutateAsync({
        siteId: site.$id,
        repository: template.providerRepositoryId || template.key,
        owner: template.providerOwner || 'appwrite',
        rootDirectory: template.providerRootDirectory,
        type: 'tag',
        reference: template.providerVersion || 'main',
        activate: true,
      })

      // Update form data
      updateFormData({
        createdSiteId: site.$id,
        createdDeploymentId: deployment.$id,
      })

      // Invalidate queries
      queryClient.invalidateQueries({ queryKey: Dependencies.SITES })

      // Navigate to deploying screen
      navigate({
        to: '/projects/$projectId/sites/create-site/deploying',
        params: { projectId },
        search: { siteId: site.$id, deploymentId: deployment.$id },
      })
    } catch (error: any) {
      toast.error(error.message || 'Failed to create site')
      setIsDeploying(false)
    }
  }

  const frameworkInfo = useMemo(() => {
    return frameworks.find((f) => f.key === framework)
  }, [frameworks, framework])

  const sidebarContent = (
    <div className="space-y-4">
      {/* Template preview */}
      {template && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          {screenshotUrl ? (
            <div className="aspect-video w-full overflow-hidden bg-muted">
              <img
                src={screenshotUrl}
                alt={`${template.name} preview`}
                className="h-full w-full object-cover"
              />
            </div>
          ) : (
            <div className="aspect-video w-full flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20">
              <FrameworkIcon
                framework={getFrameworkString(template.frameworks?.[0])}
                size="lg"
              />
            </div>
          )}
          <div className="p-4">
            <h3 className="text-[13px] font-semibold text-foreground">
              {template.name}
            </h3>
            {template.tagline && (
              <p className="mt-1 text-[11px] text-muted-foreground line-clamp-2">
                {template.tagline}
              </p>
            )}
            <div className="mt-3 flex gap-2">
              {template.demoUrl && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 text-[11px]"
                  asChild
                >
                  <a
                    href={template.demoUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <ExternalLink className="mr-1 h-3 w-3" />
                    Demo
                  </a>
                </Button>
              )}
              {template.providerRepositoryId && (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-7 text-[11px]"
                  asChild
                >
                  <a
                    href={`https://github.com/${template.providerOwner}/${template.providerRepositoryId}`}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <GitBranch className="mr-1 h-3 w-3" />
                    Source
                  </a>
                </Button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Framework info */}
      {frameworkInfo && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={framework} size="sm" />
            </div>
            <div>
              <p className="text-[12px] text-muted-foreground">Framework</p>
              <p className="text-[13px] font-medium text-foreground">
                {frameworkInfo.name}
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  )

  if (templateLoading) {
    return (
      <WizardLayout
        title="Configure site"
        fallbackPath={`/projects/${projectId}/sites`}
        fullscreen
      >
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      </WizardLayout>
    )
  }

  if (!template) {
    return (
      <WizardLayout
        title="Template not found"
        fallbackPath={`/projects/${projectId}/sites`}
        fullscreen
      >
        <div className="text-center py-16">
          <p className="text-muted-foreground">Template not found</p>
          <Button
            variant="outline"
            className="mt-4"
            asChild
          >
            <Link
              to="/projects/$projectId/sites/create-site/templates"
              params={{ projectId: projectId! }}
            >
              Browse templates
            </Link>
          </Button>
        </div>
      </WizardLayout>
    )
  }

  return (
    <WizardLayout
      title="Configure site"
      showBackButton
      backButtonLabel="Back"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <>
          <Button
            variant="outline"
            onClick={() => window.history.back()}
            disabled={isDeploying}
          >
            Cancel
          </Button>
          <Button
            onClick={handleDeploy}
            disabled={
              isDeploying ||
              !siteName ||
              !framework ||
              !domainValid ||
              createSiteMutation.isPending
            }
          >
            {isDeploying ? (
              <>
                <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                Deploying...
              </>
            ) : (
              'Deploy'
            )}
          </Button>
        </>
      }
    >
      {/* Details section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Details</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {/* Site name */}
          <div className="space-y-2">
            <Label htmlFor="site-name" className="text-[13px]">
              Site name
            </Label>
            <Input
              id="site-name"
              value={siteName}
              onChange={(e) => setSiteName(e.target.value)}
              placeholder="My awesome site"
              className="h-9 text-[13px]"
            />
          </div>

          {/* Site ID */}
          <div className="space-y-2">
            <Label className="text-[13px]">Site ID</Label>
            <IdInput
              value={siteId}
              onChange={setSiteId}
              placeholder="Auto-generated"
            />
          </div>

          {/* Framework (if template supports multiple) */}
          {template.frameworks && template.frameworks.length > 1 && (
            <div className="space-y-2">
              <Label htmlFor="framework" className="text-[13px]">
                Framework
              </Label>
              <Select value={framework} onValueChange={setFramework}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue placeholder="Select framework" />
                </SelectTrigger>
                <SelectContent>
                  {template.frameworks.map((f, index) => {
                    const fKey = getFrameworkString(f)
                    const fInfo = frameworks.find((fr) => fr.key === fKey)
                    return (
                      <SelectItem key={fKey || index} value={fKey}>
                        <div className="flex items-center gap-2">
                          <FrameworkIcon framework={fKey} size="sm" />
                          {fInfo?.name || fKey}
                        </div>
                      </SelectItem>
                    )
                  })}
                </SelectContent>
              </Select>
            </div>
          )}
        </div>
      </div>

      {/* Git connection section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Git connection
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Optionally connect a repository for automatic deployments
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <RadioGroup
            value={gitConnection}
            onValueChange={(value) => setGitConnection(value as 'now' | 'later')}
            className="space-y-3"
          >
            <div className="flex items-center space-x-3">
              <RadioGroupItem value="later" id="git-later" />
              <Label htmlFor="git-later" className="text-[13px] font-normal">
                Connect later
              </Label>
            </div>
            <div className="flex items-center space-x-3">
              <RadioGroupItem value="now" id="git-now" disabled />
              <Label
                htmlFor="git-now"
                className="text-[13px] font-normal text-muted-foreground"
              >
                Connect now (coming soon)
              </Label>
            </div>
          </RadioGroup>
        </div>
      </div>

      {/* Template variables section */}
      {template.variables && template.variables.length > 0 && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Template variables
            </h3>
            <p className="text-[12px] text-muted-foreground mt-1">
              Configure the required environment variables for this template
            </p>
          </div>
          <div className="border-t border-border" />
          <div className="px-6 py-4">
            <div className="space-y-3">
              {variables.map((variable, index) => {
                const templateVar = template.variables?.find(
                  (v) => v.name === variable.key,
                )
                return (
                  <div key={variable.key} className="space-y-2">
                    <div className="flex items-center justify-between">
                      <Label className="text-[13px] font-mono">
                        {variable.key}
                        {templateVar?.required && (
                          <span className="text-destructive ml-1">*</span>
                        )}
                      </Label>
                      {templateVar?.secret && (
                        <span className="text-[10px] text-muted-foreground">
                          Secret
                        </span>
                      )}
                    </div>
                    {templateVar?.description && (
                      <p
                        className="text-[11px] text-muted-foreground"
                        dangerouslySetInnerHTML={{
                          __html: templateVar.description,
                        }}
                      />
                    )}
                    <Input
                      value={variable.value}
                      onChange={(e) => {
                        const newVars = [...variables]
                        newVars[index] = { ...variable, value: e.target.value }
                        setVariables(newVars)
                      }}
                      placeholder={templateVar?.placeholder || `Enter ${variable.key}`}
                      type={templateVar?.secret ? 'password' : 'text'}
                      className="h-9 text-[13px] font-mono"
                    />
                  </div>
                )
              })}
            </div>
          </div>
        </div>
      )}

      {/* Additional variables */}
      <EnvironmentVariables
        variables={variables.filter(
          (v) => !template.variables?.some((tv) => tv.name === v.key),
        )}
        onChange={(newVars) => {
          // Merge with template variables
          const templateVarKeys = template.variables?.map((v) => v.name) || []
          const templateVars = variables.filter((v) =>
            templateVarKeys.includes(v.key),
          )
          setVariables([...templateVars, ...newVars])
        }}
        defaultOpen={false}
      />

      {/* Domain section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
          />
        </div>
      </div>
    </WizardLayout>
  )
}
