import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  ComparisonClosing,
  ComparisonHeroBackdrop,
  ComparisonHeroTitle,
  ComparisonTableSection,
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

/** CodeRabbit's published plans, November 2026. */
const CODERABBIT_BILLING: ContrastRow[] = [
  {
    title: 'A seat per developer',
    detail:
      'Everyone who opens pull requests needs one, from $24 a month billed annually.',
  },
  {
    title: 'Higher tiers for more',
    detail:
      'Team at $48 and Advanced at $72 per developer add tests, CI fixes, and security review.',
  },
  {
    title: 'Hourly review limits',
    detail: 'From 5 pull request reviews per developer an hour on Essentials.',
  },
  {
    title: 'Usage past the limits',
    detail:
      '$0.25 per reviewed file, with no spending cap unless an admin sets one.',
  },
]

export function CodeRabbitComparison() {
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
            <VersusPill name="CodeRabbit" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-lg xl:mx-0"
              title="Reviews from the platform that"
              accent="ships your code"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(
                'CodeRabbit is a dedicated reviewer you add to your Git provider and pay for per developer. Appwrite Reviews comes with the platform that deploys your Sites and Functions, watches production, and pages your phone, so one Git connection and one bill cover the whole pull request.',
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
              competitorName="CodeRabbit"
              competitorLane={[
                {
                  stage: 'Review',
                  name: 'CodeRabbit',
                  status: 'Reviews the PR',
                  coverage: 'included',
                },
                {
                  stage: 'Deploy',
                  name: 'Another platform',
                  status: 'Separate vendor',
                  coverage: 'other',
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
              competitorFooter="Hosting, monitoring, and paging come from other vendors"
            />
          </div>
        </div>
      </section>

      <ReviewAndDeploySection description="Appwrite’s Git app deploys every commit to Sites and Functions and reviews the same pull request. The review lands next to the deployment comment, in the same layout, from the app you already installed." />

      <ContrastSection
        eyebrow="Pricing"
        title="Pay per review, not per developer"
        description="CodeRabbit charges a seat for every developer who opens pull requests. Appwrite Reviews draws credits from the plan your project already has, whoever opens the pull request."
        competitorName="CodeRabbit"
        competitorRows={CODERABBIT_BILLING}
        appwriteLabel="Appwrite Reviews"
        appwriteRows={APPWRITE_REVIEW_BILLING}
        footnote="Published prices as of November 2026. CodeRabbit also bills monthly at higher rates, and reviews public open source repositories for free."
      />

      <EveryReviewSection
        title="What every pull request gets"
        description="A summary, findings on the lines they concern, and a grade you can merge on, checked before anything is posted."
      />

      <ReviewsHubSection
        title="Review where the rest of your app lives"
        description="CodeRabbit reviews the code and stops at the pull request. Appwrite Reviews shares a project with the products that deploy, run, and watch what you merge."
      />

      <PlatformBreadthSection
        id="coderabbit"
        title="CodeRabbit reviews code. Appwrite also runs it."
        description="Swap a review vendor for a platform. Appwrite Reviews comes with auth, databases, storage, functions, messaging, realtime, and hosting in the same project."
      />

      <OpenSourceSection
        id="coderabbit"
        title="A reviewer you can read and run"
        description="CodeRabbit is a closed service. Appwrite and its review service are open source, so you can audit how your code is reviewed and run it on your own servers."
      />

      <ComparisonTableSection
        id="coderabbit"
        title="Review features, inside your platform"
        description="CodeRabbit is a dedicated code reviewer. Appwrite Reviews is code review built into the platform that ships your app."
      />

      <ComparisonClosing
        id="coderabbit"
        cta={{
          title: 'Review your next pull request',
          description:
            'Connect a repository, turn on review, and get a summary, findings, and a grade on the next pull request.',
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
