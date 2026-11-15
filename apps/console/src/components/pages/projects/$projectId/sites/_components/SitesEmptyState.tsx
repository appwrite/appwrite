import { useMemo } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { Check, Globe, Lock } from 'lucide-react'
import { useTheme } from 'next-themes'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ProductEmptyStateCreateButton,
  ProductEmptyStateHero,
  ProductEmptyStateVisual,
} from '@/components/global/shared/ProductEmptyState'
import {
  SiteTemplateCard,
  SiteTemplateCardSkeleton,
} from '@/components/pages/projects/$projectId/sites/_components/SiteTemplateCard'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { useT } from '@/lib/i18n/translate'
import {
  useConsoleVariables,
  useProject,
  useSiteTemplates,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import { SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE } from '@/lib/sites/site-template-wizard'
import { cn } from '@/lib/utils'
import {
  GitHubIcon,
  buildVcsAuthUrl,
  getVisibleVcsOAuthProviders,
  vcsProviderButtonIconClassName,
} from '@/lib/vcs/providers'

const FRAMEWORKS = [
  'tanstack-start',
  'nextjs',
  'nuxt',
  'sveltekit',
  'astro',
  'remix',
]

/** Templates shown in the quick start row; the API ranks them best first. */
const TEMPLATE_COUNT = 4

const BUILD_STEPS = [
  { label: 'build', time: '38s' },
  { label: 'deploy', time: '4s' },
]

/** Decorative live site in a browser, its latest deployment, and supported frameworks. */
function BrowserVisual() {
  return (
    <ProductEmptyStateVisual className="py-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card shadow-xl">
        <div className="flex items-center gap-1.5 border-b border-border bg-muted/40 px-3 py-2">
          {[0, 1, 2].map((dot) => (
            <span
              key={dot}
              className="h-2 w-2 rounded-full bg-muted-foreground/25"
            />
          ))}
          <span
            dir="ltr"
            className="mx-auto flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-0.5"
          >
            <Lock className="h-2.5 w-2.5 text-muted-foreground" />
            <span className="font-mono text-[10px] text-foreground">
              my-app.appwrite.network
            </span>
          </span>
          <span className="w-8" />
        </div>
        <div className="bg-background px-5 pb-5 pt-3">
          <div className="flex items-center gap-3">
            <span className="h-4 w-4 rounded bg-foreground/80" />
            {['w-8', 'w-10', 'w-7'].map((width) => (
              <span
                key={width}
                className={cn(
                  'h-1.5 rounded-full bg-muted-foreground/25',
                  width,
                )}
              />
            ))}
            <span className="ms-auto h-4 w-12 rounded-full bg-[var(--brand-cta)]" />
          </div>
          <div className="mt-6 flex flex-col items-center">
            <span className="h-3 w-52 rounded-full bg-foreground/70" />
            <span className="mt-2 h-3 w-36 rounded-full bg-foreground/70" />
            <span className="mt-3 h-1.5 w-44 rounded-full bg-muted-foreground/25" />
            <span className="mt-4 flex gap-2">
              <span className="h-5 w-16 rounded-md bg-[var(--brand-cta)]" />
              <span className="h-5 w-16 rounded-md border border-border" />
            </span>
          </div>
          <div className="mt-6 grid grid-cols-3 gap-2">
            {[0, 1, 2].map((card) => (
              <span
                key={card}
                className="rounded-md border border-border bg-muted/30 p-2"
              >
                <span className="block h-6 rounded bg-muted-foreground/15" />
                <span className="mt-2 block h-1.5 w-3/4 rounded-full bg-muted-foreground/25" />
              </span>
            ))}
          </div>
        </div>
      </div>

      <div className="absolute -end-4 top-20 w-48 rounded-lg border border-border bg-popover p-2.5 text-start shadow-lg">
        <div className="flex items-center gap-2">
          <GitHubIcon className="h-3.5 w-3.5 text-foreground" />
          <span dir="ltr" className="font-mono text-[10px] text-foreground">
            main · 8f3a2c1
          </span>
          <span className="ms-auto h-1.5 w-1.5 rounded-full bg-emerald-500" />
        </div>
        <div className="mt-2.5 space-y-1.5">
          {BUILD_STEPS.map((step) => (
            <div
              key={step.label}
              className="flex items-center gap-1.5 font-mono text-[10px]"
            >
              <Check className="h-3 w-3 text-emerald-600 dark:text-emerald-400" />
              <span className="text-muted-foreground">{step.label}</span>
              <span className="ms-auto tabular-nums text-muted-foreground">
                {step.time}
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="absolute -start-4 bottom-12 grid grid-cols-2 gap-1.5 rounded-lg border border-border bg-popover p-1.5 shadow-lg">
        {FRAMEWORKS.map((framework) => (
          <span
            key={framework}
            className="flex h-7 w-7 items-center justify-center rounded-md bg-muted/60"
          >
            <FrameworkIcon
              framework={framework}
              size="sm"
              className="h-4 w-4"
            />
          </span>
        ))}
      </div>
    </ProductEmptyStateVisual>
  )
}

/**
 * Git providers to deploy from. One the project has not connected starts its
 * authorization; a connected one opens the repository picker.
 */
function GitProviders({ projectId }: { projectId: string }) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { vcsProviders } = useConsoleVariables()
  const { project } = useProject(projectId)
  const { data: installations } = useVcsInstallations(projectId)
  const providers = getVisibleVcsOAuthProviders(features, vcsProviders)

  if (providers.length === 0) return null

  const connected = new Set(
    installations?.installations.map((installation) => installation.provider),
  )
  const createUrl =
    typeof window === 'undefined'
      ? ''
      : `${window.location.origin}/projects/${projectId}/sites/create`

  return (
    <div className="mt-8">
      <p className="text-[13px] text-muted-foreground">
        {t('Connect a Git provider to deploy on every push.')}
      </p>
      <div className="mt-3 flex flex-wrap items-center justify-center gap-2 @4xl:justify-start">
        {providers.map((provider) => {
          const label = (
            <>
              <provider.Icon className={vcsProviderButtonIconClassName} />
              {provider.label}
            </>
          )
          return (
            <Button
              key={provider.id}
              size="sm"
              variant="outline"
              className="h-8 text-[13px]"
              asChild
            >
              {connected.has(provider.id) ? (
                <Link
                  to="/projects/$projectId/sites/create"
                  params={{ projectId }}
                >
                  {label}
                </Link>
              ) : (
                <a
                  href={buildVcsAuthUrl({
                    endpoint: getApiEndpoint(project?.region),
                    provider: provider.id,
                    projectId,
                    successUrl: createUrl,
                    failureUrl: createUrl,
                  })}
                >
                  {label}
                </a>
              )}
            </Button>
          )
        })}
      </div>
    </div>
  )
}

/** The top-ranked templates, each opening its deploy screen. */
function Templates({ projectId }: { projectId: string }) {
  const t = useT()
  const navigate = useNavigate()
  const { theme, resolvedTheme } = useTheme()
  const isDark = useMemo(() => {
    if (typeof window === 'undefined') return true
    return resolvedTheme === 'dark' || theme === 'dark'
  }, [theme, resolvedTheme])

  // The create wizard's first gallery page, so browsing all templates opens warm.
  const { templates, isLoading } = useSiteTemplates(
    projectId,
    undefined,
    undefined,
    SITE_TEMPLATE_GALLERY_DEFAULT_PAGE_SIZE,
  )

  if (!isLoading && templates.length === 0) return null

  return (
    <section className="mt-12 sm:mt-14">
      <div className="flex items-end justify-between gap-4">
        <div className="text-start">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Start from a template')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t('Deploy a working site in a few clicks, then make it yours.')}
          </p>
        </div>
        <Link
          to="/projects/$projectId/sites/create"
          params={{ projectId }}
          className="link-neutral shrink-0 text-[13px]"
        >
          {t('Browse templates')}
        </Link>
      </div>
      <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {isLoading
          ? Array.from({ length: TEMPLATE_COUNT }).map((_, index) => (
              <SiteTemplateCardSkeleton key={index} compact />
            ))
          : templates.slice(0, TEMPLATE_COUNT).map((template) => (
              <SiteTemplateCard
                key={template.key}
                template={template}
                isDark={isDark}
                size="compact"
                interactive
                onSelect={() =>
                  navigate({
                    to: '/projects/$projectId/sites/create/templates/$template',
                    params: {
                      projectId,
                      template: encodeURIComponent(template.key),
                    },
                  })
                }
              />
            ))}
      </div>
    </section>
  )
}

export function SitesEmptyState({
  projectId,
  onCreate,
  createDisabled = false,
  createDisabledTooltip,
}: {
  projectId: string
  onCreate?: () => void
  createDisabled?: boolean
  createDisabledTooltip?: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const docsUrl = getDocsPageUrl('/docs/products/sites', features.marketing)

  return (
    <div className="py-6 sm:py-10">
      <ProductEmptyStateHero
        layout="columns"
        visual={<BrowserVisual />}
        icon={Globe}
        title={t('Create your first site')}
        description={t(
          'Deploy web apps from Git or a template. Every push gets a build, a preview URL, and fast global delivery.',
        )}
        actions={
          <>
            <ProductEmptyStateCreateButton
              onClick={onCreate}
              disabled={createDisabled}
              disabledTooltip={createDisabledTooltip}
            >
              {t('Create site')}
            </ProductEmptyStateCreateButton>
            <Button variant="outline" className="h-9 text-[13px]" asChild>
              <a href={docsUrl} target="_blank" rel="noopener noreferrer">
                {t('Read the docs')}
              </a>
            </Button>
          </>
        }
      >
        {createDisabled ? null : <GitProviders projectId={projectId} />}
      </ProductEmptyStateHero>
      {createDisabled ? null : <Templates projectId={projectId} />}
    </div>
  )
}
