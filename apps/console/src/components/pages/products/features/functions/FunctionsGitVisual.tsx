import { FolderGit2, GitBranch, GitCommitHorizontal, GitPullRequest } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { ArtIconBadge, ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const COMMITS = [
  {
    sha: 'a41f9c2',
    message: 'feat: add webhook handler',
    ref: 'main',
    created: '2m ago',
    status: 'active',
  },
  {
    sha: '7d03e18',
    message: 'fix: retry failed charges',
    ref: 'PR #128',
    created: '2m ago',
    status: 'preview',
  },
  {
    sha: 'c92b6a4',
    message: 'chore: bump stripe sdk',
    ref: 'main',
    created: 'Yesterday',
    status: 'ready',
  },
] as const

function CommitStatus({ status }: { status: (typeof COMMITS)[number]['status'] }) {
  const t = useT()
  if (status === 'active') {
    return (
      <Badge variant="active" className="shrink-0 text-[10px]">
        {t('Active')}
      </Badge>
    )
  }
  if (status === 'preview') {
    return (
      <Badge variant="info" className="shrink-0 text-[10px]">
        {t('Preview')}
      </Badge>
    )
  }
  return (
    <Badge variant="deploymentReady" className="shrink-0 text-[10px]">
      {t('Ready')}
    </Badge>
  )
}

export function FunctionsGitVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      <ArtPanel
        className="mx-auto w-fit max-w-full"
        innerClassName="product-tone-shadow flex items-center gap-3 px-3.5 py-2.5"
        delayMs={60}
      >
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40">
          <ProductFeaturePublicIcon src="/icons/github.svg" className="size-[18px]" />
        </span>
        <div className="min-w-0">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Git repository')}
          </p>
          <p dir="ltr" className="truncate font-mono text-[13px] font-semibold text-foreground">
            appwrite/backend
          </p>
        </div>
        <Badge variant="success" className="ms-2 shrink-0 text-[10px]">
          {t('Connected')}
        </Badge>
      </ArtPanel>

      <div className="mx-auto h-7 w-0 border-s border-dashed border-foreground/25" aria-hidden />

      <div className="relative mx-auto max-w-[460px]">
        <span
          className="absolute bottom-6 start-[15px] top-6 border-s border-dashed border-foreground/25"
          aria-hidden
        />
        <div className="space-y-2.5">
          {COMMITS.map((commit, index) => {
            const active = commit.status === 'active'
            const isPullRequest = commit.status === 'preview'
            const RefIcon = isPullRequest ? GitPullRequest : GitBranch
            return (
              <div key={commit.sha} className="relative flex items-center gap-3">
                <span
                  className="product-hero-rise relative z-[1] flex size-[31px] shrink-0 items-center justify-center"
                  style={riseStyle(250 + index * 160)}
                  aria-hidden
                >
                  {active ? (
                    <span className="absolute size-3 animate-ping rounded-full bg-[rgb(var(--tone-rgb)/0.45)] motion-reduce:animate-none" />
                  ) : null}
                  <span
                    className={cn(
                      'relative size-3 rounded-full border-2',
                      active
                        ? 'border-[var(--tone-ink)] bg-[var(--tone-ink)]'
                        : 'border-foreground/30 bg-background',
                    )}
                  />
                </span>
                <ArtPanel
                  className="min-w-0 flex-1"
                  innerClassName={cn(
                    'flex items-center gap-2.5 px-3 py-2.5',
                    active &&
                      'border-[rgb(var(--tone-rgb)/0.45)] dark:border-[rgb(var(--tone-rgb)/0.45)]',
                  )}
                  delayMs={300 + index * 160}
                  float={active}
                >
                  <GitCommitHorizontal className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p dir="ltr" className="truncate text-start text-[12px] font-medium text-foreground">
                      {commit.message}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1.5 text-[10px] text-muted-foreground">
                      <RefIcon className="size-3 shrink-0" aria-hidden />
                      <span dir="ltr" className="font-mono">
                        {commit.ref}
                      </span>
                      <span aria-hidden>·</span>
                      <span dir="ltr" className="hidden font-mono sm:inline">
                        {commit.sha}
                      </span>
                      <span className="hidden sm:inline" aria-hidden>
                        ·
                      </span>
                      <span>{t(commit.created)}</span>
                    </p>
                  </div>
                  <CommitStatus status={commit.status} />
                </ArtPanel>
              </div>
            )
          })}
        </div>
      </div>

      <div className="mt-6 grid gap-2.5 sm:grid-cols-2 sm:gap-3">
        <ArtPanel
          className="sm:mt-3"
          innerClassName="flex items-center gap-2.5 px-3 py-2.5"
          delayMs={850}
          float
          floatDelayMs={400}
        >
          <ArtIconBadge icon={GitBranch} />
          <div className="min-w-0">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Production branch')}
            </p>
            <p dir="ltr" className="mt-0.5 truncate text-start font-mono text-[11px] text-foreground">
              main
            </p>
          </div>
        </ArtPanel>

        <ArtPanel
          innerClassName="px-3 py-2.5"
          delayMs={1000}
          float
          floatDelayMs={1100}
        >
          <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
            {t('Build triggers')}
          </p>
          <div dir="ltr" className="mt-1.5 flex flex-wrap gap-1">
            {['main', 'release/*'].map((branch) => (
              <span
                key={branch}
                className="inline-flex items-center gap-1 rounded-md bg-[rgb(var(--tone-rgb)/0.12)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--tone-ink)]"
              >
                <GitBranch className="size-2.5" aria-hidden />
                {branch}
              </span>
            ))}
            <span className="inline-flex items-center gap-1 rounded-md bg-muted/60 px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">
              <FolderGit2 className="size-2.5" aria-hidden />
              functions/**
            </span>
          </div>
        </ArtPanel>
      </div>
    </div>
  )
}
