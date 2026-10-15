/**
 * Finish View Component
 *
 * Success screen with site preview and next steps.
 */

import { useState, useMemo, useEffect, useRef } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate, Link } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { Button } from '@/components/ui/button'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { sdk, getSiteScreenshotFilePreviewUrl } from '@/lib/appwrite/sdk'
import {
  SITE_SCREENSHOTS_BUCKET_ID,
  SITE_SCREENSHOT_LARGE_WIDTH,
  SITE_SCREENSHOT_LARGE_HEIGHT,
} from '@/lib/sites/screenshot-preview-sizes'
import {
  deploymentHasScreenshot,
  refetchSitePreviewCaches,
} from '@/lib/sites/deployment-screenshots'
import { useAvifSupport } from '@/lib/avif-support'
import { ImageFormat } from '@appwrite.io/console'
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
import { useT } from '@/lib/i18n/translate'
import { domainUrl } from '@/lib/domains/url'

interface FinishViewProps {
  siteId?: string
  deploymentId?: string
}

export function FinishView({ siteId, deploymentId }: FinishViewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { theme, resolvedTheme } = useTheme()
  const { formData, resetFormData } = useWizard()

  // Use provided IDs or fall back to form data
  const actualSiteId = siteId || formData.createdSiteId
  const actualDeploymentId = deploymentId || formData.createdDeploymentId

  // Local state
  const [qrDialogOpen, setQrDialogOpen] = useState(false)
  const [isNavigatingToDashboard, setIsNavigatingToDashboard] = useState(false)
  const didRefetchPreviewCachesRef = useRef(false)

  // Fetch site details
  const { data: site } = useProjectSite(projectId, actualSiteId)

  // Fetch deployment details
  const { data: deployment } = useSiteDeployment(
    projectId,
    actualSiteId,
    actualDeploymentId,
  )

  useEffect(() => {
    if (
      !projectId ||
      !actualSiteId ||
      !deploymentHasScreenshot(deployment) ||
      didRefetchPreviewCachesRef.current
    ) {
      return
    }
    didRefetchPreviewCachesRef.current = true
    void refetchSitePreviewCaches(
      queryClient,
      projectId,
      actualSiteId,
      actualDeploymentId,
    )
  }, [projectId, actualSiteId, actualDeploymentId, deployment, queryClient])

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

  const avifSupported = useAvifSupport()

  // Get screenshot URL (preview size, theme matches app)
  const screenshotUrl = useMemo(() => {
    if (!deployment) return null
    const screenshotId = isDark
      ? (deployment as { screenshotDark?: string }).screenshotDark
      : (deployment as { screenshotLight?: string }).screenshotLight
    if (!screenshotId || !projectId) return null
    return getSiteScreenshotFilePreviewUrl(projectId, {
      bucketId: SITE_SCREENSHOTS_BUCKET_ID,
      fileId: screenshotId,
      width: SITE_SCREENSHOT_LARGE_WIDTH,
      height: SITE_SCREENSHOT_LARGE_HEIGHT,
      output: avifSupported ? ImageFormat.Avif : undefined,
    })
  }, [deployment, isDark, projectId, avifSupported])

  // Get primary domain
  const primaryDomain = useMemo(() => {
    if (domains.length > 0) {
      return domains[0].domain
    }
    return null
  }, [domains])

  const siteUrl = primaryDomain ? domainUrl(primaryDomain) : null

  // QR code image URL from console avatars API (for "View on mobile" dialog)
  const qrImageUrl = useMemo(() => {
    if (!siteUrl) return null
    return sdk.forConsole.avatars.getQR({ text: siteUrl, size: 256 })
  }, [siteUrl])

  const handleGoToDashboard = async () => {
    if (isNavigatingToDashboard) return
    setIsNavigatingToDashboard(true)

    const siteIdToOpen = actualSiteId
    const deploymentIdToRefresh = actualDeploymentId

    try {
      if (projectId && siteIdToOpen) {
        await refetchSitePreviewCaches(
          queryClient,
          projectId,
          siteIdToOpen,
          deploymentIdToRefresh,
        )
      }
    } catch {
      // Navigation should still proceed; hard reload remains a fallback.
    }

    resetFormData()

    if (siteIdToOpen) {
      navigate({
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: siteIdToOpen },
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
              {t('Deployment successful!')}
            </p>
            <p className="text-[12px] text-muted-foreground mt-0.5">
              {t('Your site is now live')}
            </p>
          </div>
        </div>
      </div>
    </div>
  )

  return (
    <>
    <WizardLayout
      title={t('Create site')}
      fallbackPath={`/projects/${projectId}/sites`}
      fullscreen
      maxWidth="max-w-[1400px]"
      footerAlign="right"
      sidebar={sidebarContent}
      footer={
        <Button
          onClick={handleGoToDashboard}
          disabled={isNavigatingToDashboard}
        >
          {t('Go to dashboard')}
        </Button>
      }
    >
      {/* Site preview card */}
      {site && (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          {/* Screenshot */}
          {screenshotUrl ? (
            <div className="aspect-video w-full overflow-hidden bg-muted">
              <img
                src={screenshotUrl}
                alt={`${site.name} ${t('preview')}`}
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
                      className="mt-2 flex items-center gap-1.5 link-neutral text-[12px]"
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
                    <ExternalLink className="me-1.5 h-4 w-4" />
                    {t('Visit site')}
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
            {t('Next steps')}
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
                  {t('Add repository')}
                </p>
                <p className="text-[11px] text-muted-foreground">
                  {t('Connect a Git repository for automatic deployments')}
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
                {t('Add custom domain')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Configure your own domain name')}
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
            className="flex w-full items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors text-start"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Share2 className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <p className="text-[13px] font-medium text-foreground">
                {t('Copy site URL')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Copy the site URL to clipboard')}
              </p>
            </div>
            <ArrowRight className="h-4 w-4 text-muted-foreground" />
          </button>

          {/* Open on mobile */}
          <button
            type="button"
            onClick={() => {
              if (!siteUrl) {
                toast.error(t('Site URL is not available yet'))
                return
              }
              setQrDialogOpen(true)
            }}
            className="flex w-full items-center gap-4 px-6 py-4 hover:bg-accent/50 transition-colors text-start"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              <Smartphone className="h-5 w-5" />
            </div>
            <div className="flex-1">
              <p className="text-[13px] font-medium text-foreground">
                {t('Open on mobile')}
              </p>
              <p className="text-[11px] text-muted-foreground">
                {t('Scan QR code to view on your phone')}
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
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('View on mobile')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Scan this QR code to open your site on a mobile device')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-6 flex items-center justify-center">
          {qrImageUrl ? (
            <div className="p-4 bg-white rounded-lg">
              <img
                src={qrImageUrl}
                alt={t('QR code to open site on mobile')}
                className="h-48 w-48 rounded"
              />
            </div>
          ) : (
            <p className="text-[13px] text-muted-foreground text-center px-4">
              {t('QR code could not be generated. Try again in a moment.')}
            </p>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex justify-end">
          <Button variant="outline" onClick={() => setQrDialogOpen(false)}>
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
    </>
  )
}
