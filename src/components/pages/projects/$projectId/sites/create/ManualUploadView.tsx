/**
 * Manual Upload View Component
 *
 * Upload a tar.gz file containing site source code.
 */

import { useState, useRef, useEffect } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
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
  Upload,
  File,
  X,
  GitBranch,
  LayoutTemplate,
  ArrowRight,
} from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useCreateSite,
  useCreateSiteDomain,
  Dependencies,
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'
import { useWizard } from './WizardContext'
import { DomainInput } from './DomainInput'
import { BuildSettings } from './BuildSettings'
import { EnvironmentVariables } from './EnvironmentVariables'

export function ManualUploadView() {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const {
    formData,
    updateFormData,
    frameworks,
    getFrameworkDefaults,
    generateDomain,
    setCurrentPath,
  } = useWizard()

  // Set current path
  useEffect(() => {
    setCurrentPath('manual')
  }, [setCurrentPath])

  // Local form state
  const [siteName, setSiteName] = useState(formData.siteName || 'My website')
  const [siteId, setSiteId] = useState<string | undefined>(formData.siteId)
  const [framework, setFramework] = useState(formData.framework || '')
  const [installCommand, setInstallCommand] = useState(formData.installCommand || '')
  const [buildCommand, setBuildCommand] = useState(formData.buildCommand || '')
  const [outputDirectory, setOutputDirectory] = useState(formData.outputDirectory || '')
  const [variables, setVariables] = useState(formData.variables || [])
  const [domain, setDomain] = useState(formData.domain || '')
  const [domainValid, setDomainValid] = useState(formData.domainValid || false)
  const [uploadFile, setUploadFile] = useState<File | null>(formData.uploadFile || null)
  const [isDragging, setIsDragging] = useState(false)
  const [isDeploying, setIsDeploying] = useState(false)

  // Update build commands when framework changes
  useEffect(() => {
    if (framework) {
      const defaults = getFrameworkDefaults(framework)
      if (!installCommand) setInstallCommand(defaults.installCommand)
      if (!buildCommand) setBuildCommand(defaults.buildCommand)
      if (!outputDirectory) setOutputDirectory(defaults.outputDirectory)
    }
  }, [framework, getFrameworkDefaults])

  // Generate domain when site name changes
  useEffect(() => {
    if (siteName && !domain) {
      setDomain(generateDomain(siteName))
    }
  }, [siteName, generateDomain])

  const handleFileSelect = (files: FileList | null) => {
    if (!files || files.length === 0) return

    const file = files[0]
    if (!file.name.endsWith('.tar.gz') && !file.name.endsWith('.tgz')) {
      toast.error('Only .tar.gz files are supported')
      return
    }

    // Check file size (max 100MB)
    if (file.size > 100 * 1024 * 1024) {
      toast.error('File size must be less than 100MB')
      return
    }

    setUploadFile(file)
  }

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(true)
  }

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(false)
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    setIsDragging(false)
    handleFileSelect(e.dataTransfer.files)
  }

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  // Mutations
  const createSiteMutation = useCreateSite(projectId)
  const createDomainMutation = useCreateSiteDomain(projectId)

  const handleDeploy = async () => {
    if (!projectId || !siteName || !framework || !uploadFile) {
      toast.error('Please fill in all required fields and upload a file')
      return
    }

    if (!domainValid) {
      toast.error('Please enter a valid domain')
      return
    }

    setIsDeploying(true)

    try {
      // 1. Create the site
      const site = await createSiteMutation.mutateAsync({
        siteId: siteId || undefined,
        name: siteName,
        framework,
        installCommand: installCommand || undefined,
        buildCommand: buildCommand || undefined,
        outputDirectory: outputDirectory || undefined,
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
          variables.map((v) =>
            projectSdk.sites.createVariable({
              siteId: site.$id,
              key: v.key,
              value: v.value,
              secret: v.secret,
            }),
          ),
        )
      }

      // 4. Upload deployment using uploader
      // Note: This is a placeholder - the actual uploader would need to be implemented
      const projectSdk = sdk.forProject(projectId)
      const deployment = await (projectSdk.sites as any).createDeployment({
        siteId: site.$id,
        code: uploadFile,
        activate: true,
        installCommand,
        buildCommand,
        outputDirectory,
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
        to: '/projects/$projectId/sites/create/deploying',
        params: { projectId },
        search: { siteId: site.$id, deploymentId: deployment.$id },
      })
    } catch (error: any) {
      toast.error(error.message || 'Failed to create site')
      setIsDeploying(false)
    }
  }

  const sidebarContent = (
    <div className="space-y-4">
      {/* Framework info */}
      {framework && (
        <div className="rounded-xl border border-border bg-card/50 p-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <FrameworkIcon framework={framework} size="md" />
            </div>
            <div>
              <p className="text-[12px] text-muted-foreground">Framework</p>
              <p className="text-[13px] font-medium text-foreground">
                {frameworks.find((f) => f.key === framework)?.name || framework}
              </p>
            </div>
          </div>
        </div>
      )}

      {/* Other options */}
      <div className="rounded-xl border border-border bg-card/50 p-4">
        <h3 className="text-[13px] font-semibold text-foreground mb-3">
          Other options
        </h3>
        <div className="space-y-2">
          <Link
            to="/projects/$projectId/sites/create/repositories"
            params={{ projectId: projectId! }}
            className="flex items-center gap-2 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <GitBranch className="h-3.5 w-3.5" />
            Import from Git
          </Link>
          <Link
            to="/projects/$projectId/sites/create/templates"
            params={{ projectId: projectId! }}
            className="flex items-center gap-2 text-[12px] text-muted-foreground hover:text-foreground transition-colors"
          >
            <LayoutTemplate className="h-3.5 w-3.5" />
            Browse templates
          </Link>
        </div>
      </div>
    </div>
  )

  return (
    <WizardLayout
      title="Create site"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
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
              !uploadFile ||
              !domainValid ||
              createSiteMutation.isPending
            }
          >
            Deploy
          </Button>
        </>
      }
    >
      {/* File upload section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Upload file
          </h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Upload a .tar.gz file containing your site source code
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <input
            ref={fileInputRef}
            type="file"
            accept=".tar.gz,.tgz"
            onChange={(e) => handleFileSelect(e.target.files)}
            className="hidden"
          />
          
          {!uploadFile ? (
            <div
              onDragOver={handleDragOver}
              onDragLeave={handleDragLeave}
              onDrop={handleDrop}
              onClick={() => fileInputRef.current?.click()}
              className={cn(
                'flex flex-col items-center justify-center rounded-lg border-2 border-dashed py-12 px-6 cursor-pointer transition-colors',
                isDragging
                  ? 'border-primary bg-primary/5'
                  : 'border-border hover:border-muted-foreground hover:bg-accent/50',
              )}
            >
              <Upload className="h-8 w-8 text-muted-foreground mb-3" />
              <p className="text-[13px] font-medium text-foreground">
                Drop your file here or click to browse
              </p>
              <p className="text-[12px] text-muted-foreground mt-1">
                Only .tar.gz files up to 100MB
              </p>
            </div>
          ) : (
            <div className="flex items-center gap-3 rounded-lg border border-border bg-background px-4 py-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <File className="h-5 w-5 text-muted-foreground" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="truncate text-[13px] font-medium text-foreground">
                  {uploadFile.name}
                </p>
                <p className="text-[12px] text-muted-foreground">
                  {formatFileSize(uploadFile.size)}
                </p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setUploadFile(null)}
                className="h-8 w-8 p-0"
              >
                <X className="h-4 w-4" />
              </Button>
            </div>
          )}
        </div>
      </div>

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

          {/* Framework selector */}
          <div className="space-y-2">
            <Label htmlFor="framework" className="text-[13px]">
              Framework
            </Label>
            <Select
              value={framework}
              onValueChange={(value) => {
                setFramework(value)
                const defaults = getFrameworkDefaults(value)
                setInstallCommand(defaults.installCommand)
                setBuildCommand(defaults.buildCommand)
                setOutputDirectory(defaults.outputDirectory)
              }}
            >
              <SelectTrigger className="h-9 text-[13px]">
                <SelectValue placeholder="Select framework" />
              </SelectTrigger>
              <SelectContent>
                {frameworks.map((f) => (
                  <SelectItem key={f.key} value={f.key}>
                    <div className="flex items-center gap-2">
                      <FrameworkIcon framework={f.key} size="sm" />
                      {f.name}
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      {/* Build settings */}
      <BuildSettings
        installCommand={installCommand}
        buildCommand={buildCommand}
        outputDirectory={outputDirectory}
        onInstallCommandChange={setInstallCommand}
        onBuildCommandChange={setBuildCommand}
        onOutputDirectoryChange={setOutputDirectory}
        frameworkKey={framework}
      />

      {/* Environment variables */}
      <EnvironmentVariables
        variables={variables}
        onChange={setVariables}
      />

      {/* Domain section */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">Domain</h3>
          <p className="text-[12px] text-muted-foreground mt-1">
            Your site will be accessible at this URL
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <DomainInput
            value={domain}
            onChange={setDomain}
            onValidChange={setDomainValid}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/20">
          <p className="text-[11px] text-muted-foreground leading-relaxed">
            Want to use your own domain? After deployment, you can connect a custom domain via CNAME record or let Appwrite manage your DNS.{' '}
            <a
              href="https://appwrite.io/docs/products/sites/domains"
              target="_blank"
              rel="noopener noreferrer"
              className="text-foreground hover:underline font-medium"
            >
              Learn more →
            </a>
          </p>
        </div>
      </div>
    </WizardLayout>
  )
}
