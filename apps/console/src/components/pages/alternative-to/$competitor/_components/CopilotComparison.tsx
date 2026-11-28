import { BookMarked, Check, Minus } from 'lucide-react'
import {
  ArtPanel,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductFeaturePublicIcon } from '@/components/pages/products/features/_components/ProductFeaturePublicIcon'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  AppwriteMark,
  ComparisonClosing,
  ComparisonHeading,
  ComparisonHeroBackdrop,
  ComparisonHeroTitle,
  ComparisonSection,
  ComparisonTableSection,
  CompetitorMonogram,
  SecondaryLinkButton,
  StartBuildingButton,
  VersusPill,
  comparisonHeroCopyClassName,
  comparisonHeroGridClassName,
} from './ComparisonParts'
import { OpenSourceSection } from './OpenSource'
import { PlatformBreadthSection } from './PlatformBreadth'
import {
  APPWRITE_REVIEW_BILLING,
  ContrastSection,
  EveryReviewSection,
  ReviewAndDeploySection,
  ReviewsHubSection,
  ReviewsLanesVisual,
  type ContrastRow,
} from './ReviewsComparisonParts'

/* -------------------------------------------------------------------------------------------------
 * A reviewer you can talk to, on the Git provider you use
 * -----------------------------------------------------------------------------------------------*/

function Avatar({
  initials,
  bot = false,
}: {
  initials?: string
  bot?: boolean
}) {
  return (
    <span
      className={cn(
        'flex size-6 shrink-0 items-center justify-center rounded-full border border-border text-[9.5px] font-semibold',
        bot ? 'bg-background dark:bg-card' : 'bg-muted text-muted-foreground',
      )}
      aria-hidden
    >
      {bot ? <AppwriteMark className="size-3" /> : initials}
    </span>
  )
}

/** A finding, a reply with facts, and the reviewer checking them, dismissing, and remembering. */
function ThreadVisual() {
  const t = useT()
  return (
    <div className="space-y-3 text-start">
      <ArtPanel delayMs={60} innerClassName="p-3.5">
        <p className="flex items-center gap-2 text-[11.5px]">
          <Avatar bot />
          <span className="font-semibold text-foreground">appwrite</span>
          <span className="rounded-full border border-border px-1.5 text-[9.5px] leading-4 text-muted-foreground">
            {t('bot')}
          </span>
        </p>
        <p className="mt-2 flex flex-wrap items-center gap-1.5">
          <span className="rounded-[5px] bg-amber-400/20 px-1.5 text-[10px] font-semibold leading-[15px] text-amber-700 dark:text-amber-300">
            {t('Minor')}
          </span>
          <span className="rounded-[5px] border border-border px-1.5 text-[10px] leading-[15px] text-muted-foreground">
            {t('error-handling')}
          </span>
        </p>
        <p className="mt-2 text-[12.5px] font-medium leading-5 text-foreground">
          {t('Retrying after a timeout can create a second order.')}
        </p>
      </ArtPanel>

      <div
        className="product-hero-rise ms-8 flex gap-2.5"
        style={riseStyle(260)}
      >
        <Avatar initials="AS" />
        <p className="rounded-2xl rounded-ss-md bg-muted/60 px-3.5 py-2.5 text-[12px] leading-5 text-foreground">
          {t(
            'The endpoint is idempotent. We send an Idempotency-Key header on every retry.',
          )}
        </p>
      </div>

      <ArtPanel
        className="ms-8"
        delayMs={480}
        innerClassName="product-tone-shadow p-3.5"
      >
        <p className="flex items-center gap-2 text-[11.5px]">
          <Avatar bot />
          <span className="font-semibold text-foreground">appwrite</span>
          <span className="text-[10.5px] text-muted-foreground">
            {t('replied')}
          </span>
        </p>
        <p className="mt-2 text-[12px] leading-5 text-foreground">
          {t(
            'Confirmed: createOrder sends the key on every retry. Dismissing this finding.',
          )}
        </p>
        <p className="mt-2.5 inline-flex items-center gap-1.5 rounded-md bg-[rgb(var(--tone-rgb)/0.12)] px-2 py-1 text-[11px] font-medium text-[var(--tone-ink)]">
          <BookMarked className="size-3" aria-hidden />
          {t(
            'Learning saved: retries are safe when requests carry an idempotency key',
          )}
        </p>
      </ArtPanel>
    </div>
  )
}

const PROVIDERS: {
  name: string
  icon: string
  appwrite: boolean
  copilot: boolean
}[] = [
  { name: 'GitHub', icon: '/icons/github.svg', appwrite: true, copilot: true },
  { name: 'GitLab', icon: '/icons/gitlab.svg', appwrite: true, copilot: false },
  {
    name: 'Bitbucket',
    icon: '/icons/bitbucket.svg',
    appwrite: true,
    copilot: false,
  },
  {
    name: 'Azure DevOps',
    icon: '/icons/azure.svg',
    appwrite: false,
    copilot: true,
  },
]

function ProviderMatrix() {
  const t = useT()
  return (
    <div className="text-start">
      <div className="grid grid-cols-[minmax(0,1fr)_6rem_6rem] pb-3 font-mono text-[10px] uppercase tracking-[0.16em] text-muted-foreground">
        <span>{t('Git provider')}</span>
        <span className="flex items-center justify-center gap-1.5 normal-case tracking-normal">
          <AppwriteMark className="size-3.5" />
          Appwrite
        </span>
        <span className="flex items-center justify-center gap-1.5 normal-case tracking-normal">
          <CompetitorMonogram
            name="GitHub Copilot"
            className="size-4 text-[9px]"
          />
          Copilot
        </span>
      </div>
      <ul className="divide-y divide-border border-y border-border">
        {PROVIDERS.map((provider, index) => (
          <li
            key={provider.name}
            className="product-hero-rise grid grid-cols-[minmax(0,1fr)_6rem_6rem] items-center py-3"
            style={riseStyle(120 + index * 80)}
          >
            <span className="flex items-center gap-2.5 text-[13px] text-foreground">
              <ProductFeaturePublicIcon
                src={provider.icon}
                className="size-4"
              />
              {provider.name}
            </span>
            {[provider.appwrite, provider.copilot].map((supported, column) => (
              <span key={column} className="flex justify-center">
                {supported ? (
                  <Check
                    className={cn(
                      'size-4',
                      column === 0
                        ? 'text-[var(--tone-ink)]'
                        : 'text-muted-foreground',
                    )}
                    aria-label={t('Yes')}
                  />
                ) : (
                  <Minus
                    className="size-4 text-muted-foreground/50"
                    aria-label={t('No')}
                  />
                )}
              </span>
            ))}
          </li>
        ))}
      </ul>
    </div>
  )
}

function TalkSection() {
  return (
    <ComparisonSection
      backdrop={
        <div
          className="product-dot-grid product-dot-grid-fade absolute inset-0 opacity-70"
          aria-hidden
        />
      }
    >
      <div className="grid min-w-0 gap-14 lg:grid-cols-2 lg:gap-16">
        <div className="min-w-0">
          <ComparisonHeading
            eyebrow="Conversation"
            title="A reviewer that answers back"
            description="Reply to a finding and Appwrite Reviews reads the code to check what you said, resolves or dismisses the finding, and remembers lasting rules. GitHub documents that Copilot code review does not see replies to its comments."
            size="md"
          />
          <div className="mt-10">
            <ThreadVisual />
          </div>
        </div>
        <div className="min-w-0">
          <ComparisonHeading
            eyebrow="Git providers"
            title="Not only GitHub"
            description="Appwrite Reviews works on GitHub, GitLab, and Bitbucket. Copilot code review runs on GitHub.com and Azure DevOps."
            size="md"
          />
          <div className="mt-10">
            <ProviderMatrix />
          </div>
        </div>
      </div>
    </ComparisonSection>
  )
}

/** Copilot code review billing, November 2026. */
const COPILOT_BILLING: ContrastRow[] = [
  {
    title: 'AI Credits per review',
    detail:
      'About $1.50 at the default effort, from about $0.25 on Lite to about $10 on Max.',
  },
  {
    title: 'Plus Actions minutes',
    detail: 'Each review also runs on GitHub Actions time.',
  },
  {
    title: 'A Copilot plan',
    detail:
      'Pro includes 1,500 credits for $10 a month. Business is $19 per seat.',
  },
  {
    title: 'No reviews on Copilot Free',
    detail: 'Free can only review a selection in VS Code.',
  },
]

export function CopilotComparison() {
  const t = useT()
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <ComparisonHeroBackdrop />
        <div
          className={cn(
            comparisonHeroGridClassName,
            'xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]',
          )}
        >
          <div className={comparisonHeroCopyClassName}>
            <VersusPill name="GitHub Copilot" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-lg xl:mx-0"
              title="Reviews that answer back,"
              accent="next to your deploys"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(
                'Copilot code review lives in GitHub and bills AI Credits and Actions minutes for each review. Appwrite Reviews works on GitHub, GitLab, and Bitbucket, answers your replies, and comes with the platform that deploys, hosts, and monitors your app.',
              )}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-2 xl:justify-start">
              <StartBuildingButton />
              <SecondaryLinkButton
                href="/products/reviews"
                label="Explore Appwrite Reviews"
              />
            </div>
          </div>
          <div className="min-w-0 overflow-x-clip">
            <ReviewsLanesVisual
              competitorName="GitHub Copilot"
              competitorLane={[
                {
                  stage: 'Review',
                  name: 'GitHub Copilot',
                  status: 'Reviews the PR',
                  coverage: 'included',
                },
                {
                  stage: 'Deploy',
                  name: 'GitHub Pages',
                  status: 'Static sites only',
                  coverage: 'partial',
                },
                {
                  stage: 'Observe',
                  name: 'Another tool',
                  status: 'Separate vendor',
                  coverage: 'other',
                },
                {
                  stage: 'Respond',
                  name: 'Another app',
                  status: 'Separate vendor',
                  coverage: 'other',
                },
              ]}
              competitorFooter="GitHub hosts your code and CI, not your backend"
            />
          </div>
        </div>
      </section>

      <TalkSection />

      <ReviewAndDeploySection description="On GitHub, the Appwrite app that deploys your Sites and Functions is the one that reviews the pull request. Its review sits next to the deployment comment, with the same layout and a link back to the Console." />

      <ContrastSection
        eyebrow="One bill"
        title="Review credits that come with your plan"
        description="Copilot code review draws GitHub AI Credits and Actions minutes for each review. Appwrite Reviews uses credits from the plan your project already has."
        competitorName="GitHub Copilot"
        competitorRows={COPILOT_BILLING}
        appwriteLabel="Appwrite Reviews"
        appwriteRows={APPWRITE_REVIEW_BILLING}
        footnote="Averages published by GitHub in November 2026. Actual Copilot costs vary with pull request size and effort."
      />

      <EveryReviewSection
        title="A review you can merge on"
        description="Every review grades the pull request and decides its verdict from verified findings, so the status on the pull request means something."
      />

      <ReviewsHubSection
        title="Built into the project that runs your app"
        description="GitHub keeps your code and runs your CI. Appwrite runs what you ship: the same project reviews the pull request, deploys it, and watches it in production."
      />

      <PlatformBreadthSection
        id="copilot"
        title="Copilot reviews code. Appwrite also runs it."
        description="Appwrite Reviews comes with auth, databases, storage, functions, messaging, realtime, and hosting in one project, next to the repository you already keep on GitHub."
      />

      <OpenSourceSection
        id="copilot"
        title="Open source, down to the reviewer"
        description="Copilot is a closed service that runs only on GitHub.com. Appwrite and its review service are open source, so you can read how reviews work and run them yourself."
      />

      <ComparisonTableSection
        id="copilot"
        title="Code review, provider by provider"
        description="Copilot code review is part of GitHub. Appwrite Reviews is part of the platform that ships your app, on the Git provider you choose."
      />

      <ComparisonClosing
        id="copilot"
        cta={{
          title: 'Get a reviewer that answers back',
          description:
            'Connect a repository on any supported Git provider and get a summary, findings, and a grade on the next pull request.',
          secondary: (
            <SecondaryLinkButton
              href="/products/reviews"
              label="Explore Appwrite Reviews"
            />
          ),
        }}
      />
    </>
  )
}
