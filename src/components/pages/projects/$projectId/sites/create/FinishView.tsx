/**
 * Finish View Component
 *
 * Success screen with site preview and next steps.
 */

import { useState, useMemo } from 'react'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ArrowRight,
  CheckCircle2,
  ExternalLink,
  GitBranch,
  Globe,
  Share2,
  Smartphone,
} from 'lucide-react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  useProjectSite,
  useSiteDeployment,
  useSiteDomains,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useWizard } from './WizardContext'

const SCREENSHOTS_BUCKET_ID = 'screenshots'

interface FinishViewProps {
  siteId?: string
  deploymentId?: string
}

export function FinishView({ siteId, deploymentId }: FinishViewProps) {
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { theme, resolvedTheme } = useTheme()
  const { formData, resetFormData } = useWizard()

  // Use provided IDs or fall back to form data
  const actualSiteId = siteId || formData.createdSiteId
  const actualDeploymentId = deploymentId || formData.createdDeploymentId

  // Local state
  const [qrDialogOpen, setQrDialogOpen] = useState(false)

  // Fetch site details
  const { data: site } = useProjectSite(projectId, actualSiteId)

  // Fetch deployment details
  const { data: deployment } = useSiteDeployment(
    projectId,
    actualSiteId,
    actualDeploymentId,
  )

  // Fetch site domains
  const { rules: domains } = useSiteDomains(projectId, actualSiteId, 0, 10)

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

  // Get screenshot URL (preview size, theme matches app)
  const screenshotUrl = useMemo(() => {
    if (!deployment) return null
    const screenshotId = isDark
      ? (deployment as { screenshotDark?: string }).screenshotDark
      : (deployment as { screenshotLight?: string }).screenshotLight
    if (!screenshotId) return null
    return sdk.forConsole.storage.getFilePreview({
      bucketId: SCREENSHOTS_BUCKET_ID,
      fileId: screenshotId,
      width: 1280,
      height: 720,
    })
  }, [deployment, isDark])

  // Get primary domain
  const primaryDomain = useMemo(() => {
    if (domains.length > 0) {
      return domains[0].domain
    }
    return null
  }, [domains])

  const siteUrl = primaryDomain ? `https://${primaryDomain}` : null

  // QR code image URL from console avatars API (for "View on mobile" dialog)
  const qrImageUrl = useMemo(() => {
    if (!siteUrl) return null
    return sdk.forConsole.avatars.getQR({ text: siteUrl, size: 256 })
  }, [siteUrl])

  const handleGoToDashboard = () => {
    // Reset wizard state
    resetFormData()

    if (actualSiteId) {
      navigate({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: actualSiteId },
      })
    } else {
      navigate({
        to: '/projects/$projectId/sites',
        params: { projectId: projectId! },
      })
    }
  }

  const sidebarContent = (
    <div className="space-y-4">
      {/* Success message */}
      <div className="rounded-xl border border-green-500/30 bg-green-500/10 p-4">
        <div className="flex items-center gap-3">
          <CheckCircle2 className="h-6 w-6 text-green-500 shrink-0" />
          <div>
            <p className="text-[14px] font-semibold text-green-600 dark:text-green-400">
              Deployment successful!
            </p>
            <p className="text-[12px] text-muted-foreground mt-0.5">
              Your site is now live
            </p>
          </div>
        </div>
      </div>
    </div>
  )

  return (
    <>
    <WizardLayout
      title="Create site"
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={<Button onClick={handleGoToDashboard}>Go to dashboard</Button>}
    >
      {/* Site preview card */}
      {site && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          {/* Screenshot */}
          {screenshotUrl ? (
            <div className="aspect-video w-full overflow-hidden bg-muted">
              <img
                src={screenshotUrl}
                alt={`${site.name} preview`}
                className="h-full w-full object-cover object-top"
              />
            </div>
          ) : (
            <div className="aspect-video w-full flex items-center justify-center bg-gradient-to-br from-muted/50 via-muted/30 to-muted/20">
              <FrameworkIcon framework={site.framework} size="lg" />
            </div>
          )}

          {/* Content */}
          <div className="p-6">
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-start gap-4">
                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <FrameworkIcon framework={site.framework} size="md" />
                </div>
                <div>
                  <h3 className="text-[16px] font-semibold text-foreground">
                    {site.name}
                  </h3>
                  <CopyableId id={site.$id} size="xs" />
                  {siteUrl && (
                    <a
                      href={siteUrl}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-2 flex items-center gap-1.5 text-[12px] text-primary hover:underline"
                    >
                      <Globe className="h-3.5 w-3.5 shrink-0" />
                      <span className="min-w-0 truncate">{primaryDomain}</span>
                      <ExternalLink
                        className="h-3.5 w-3.5 shrink-0 opacity-80"
                        aria-hidden
                      />
                    </a>
                  )}
                </div>
              </div>
              {siteUrl && (
                <Button asChild>
                  <a href={siteUrl} target="_blank" rel="noopener noreferrer">
                    <ExternalLink className="mr-1.5 h-4 w-4" />
                    Visit site
                  </a>
                </Button>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Next steps */}
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Next steps
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="divide-y divide-border">
          {/* Add repository (if not connected) */}
          {site && !site.installationId && (
            <Link
              to="/projects/$projectId/sites/$siteId/settings"
              params={{ projectId: projectId!, siteId: actualSiteId! }}
              className="flex items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors"
            >
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <GitBranch className="h-5 w-5" />
              </div>
              <div className="flex-1">
                <p className="text-[13px] font-medium text-foreground">
                  Add repository
                </p>
                <p className="text-[11px] text-muted-foreground">
                  Connect a Git repository for automatic deployments
                </p>
              </div>
              <ArrowRight className="h-4 w-4 text-muted-foreground" />
            </Link>
          )}

          {/* Add custom domain */}
          <Link
            to="/projects/$projectId/sites/$siteId/domains"
            params={{ projectId: projectId!, siteId: actualSiteId! }}
            className="flex items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Globe className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <p className="text-[13px] font-medium text-foreground">
                Add custom domain
              </p>
              <p className="text-[11px] text-muted-foreground">
                Configure your own domain name
              </p>
            </div>
            <ArrowRight className="h-4 w-4 text-muted-foreground" />
          </Link>

          {/* Copy site URL */}
          <button
            onClick={() => {
              if (siteUrl) {
                navigator.clipboard.writeText(siteUrl)
              }
            }}
            className="flex w-full items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors text-left"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Share2 className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <p className="text-[13px] font-medium text-foreground">
                Copy site URL
              </p>
              <p className="text-[11px] text-muted-foreground">
                Copy the site URL to clipboard
              </p>
            </div>
            <ArrowRight className="h-4 w-4 text-muted-foreground" />
          </button>

          {/* Open on mobile */}
          <button
            type="button"
            onClick={() => {
              if (!siteUrl) {
                toast.error('Site URL is not available yet')
                return
              }
              setQrDialogOpen(true)
            }}
            className="flex w-full items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors text-left"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Smartphone className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <p className="text-[13px] font-medium text-foreground">
                Open on mobile
              </p>
              <p className="text-[11px] text-muted-foreground">
                Scan QR code to view on your phone
              </p>
            </div>
            <ArrowRight className="h-4 w-4 text-muted-foreground" />
          </button>
        </div>
      </div>
    </WizardLayout>

    <Dialog open={qrDialogOpen} onOpenChange={setQrDialogOpen}>
      <DialogContent
        className="z-[10000] sm:max-w-md p-0"
        overlayClassName="z-[9999]"
      >
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>View on mobile</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Scan this QR code to open your site on a mobile device
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-6 flex items-center justify-center">
          {qrImageUrl ? (
            <div className="p-4 bg-white rounded-lg">
              <img
                src={qrImageUrl}
                alt="QR code to open site on mobile"
                className="h-48 w-48 rounded"
              />
            </div>
          ) : (
            <p className="text-[13px] text-muted-foreground text-center px-4">
              QR code could not be generated. Try again in a moment.
            </p>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
          <Button variant="outline" onClick={() => setQrDialogOpen(false)}>
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
    </>
  )
}
