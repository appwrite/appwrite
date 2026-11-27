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
  ContrastSection,
  EveryReviewSection,
  ReviewAndDeploySection,
  ReviewsHubSection,
  ReviewsLanesVisual,
  type ContrastRow,
} from './ReviewsComparisonParts'

/** What Cursor offers beyond review, November 2026. */
const CURSOR_PLATFORM: ContrastRow[] = [
  {
    title: 'No hosting or databases',
    detail: 'Your sites and Postgres still run with another vendor.',
  },
  {
    title: 'Origin, in early beta',
    detail:
      'Git hosting with Vercel preview deploys and Buildkite or Depot CI.',
  },
  {
    title: 'Rollouts on your observability',
    detail:
      'Checks deploys against a tool you connect, such as Datadog, and opens an issue. It never rolls back on its own.',
  },
  {
    title: 'Review per use',
    detail: 'About $1.00 to $1.50 a review, on a Cursor Premium or Teams plan.',
  },
]

const APPWRITE_PLATFORM: ContrastRow[] = [
  {
    title: 'Auth for your own users',
    detail:
      'Email, OAuth, SMS, MFA, teams, and sessions for the people who use your app.',
  },
  {
    title: 'A complete backend',
    detail:
      'Databases, storage, functions in 13+ runtimes, messaging, and realtime.',
  },
  {
    title: 'Hosting with custom domains',
    detail:
      'Sites and Functions deploy from Git, with a preview for each pull request.',
  },
  {
    title: 'Monitors and Beep',
    detail:
      'Usage monitors and endpoint checks, with alerts and escalations on your phone.',
  },
]

export function BugbotComparison() {
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
            <VersusPill name="Cursor Bugbot" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-lg xl:mx-0"
              title="Review the code, then run"
              accent="the app your customers use"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(
                'Bugbot is Cursor’s pull request reviewer, and your sites and databases still run somewhere else. Appwrite Reviews comes with a production backend: auth for your own users, databases, functions, and hosting, with Monitors and Beep watching it after every merge.',
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
              competitorName="Cursor Bugbot"
              competitorLane={[
                {
                  stage: 'Review',
                  name: 'Cursor Bugbot',
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
                  name: 'Cursor Rollouts',
                  status: 'Your own tools',
                  coverage: 'partial',
                },
                {
                  stage: 'Respond',
                  name: 'Another app',
                  status: 'Separate vendor',
                  coverage: 'other',
                },
              ]}
              competitorFooter="Hosting, databases, and paging come from other vendors"
            />
          </div>
        </div>
      </section>

      <ReviewAndDeploySection description="One Appwrite Git connection deploys your Sites and Functions and reviews their pull requests. The review and the deployment comment arrive together, in the same layout, from the same app." />

      <ContrastSection
        eyebrow="Beyond review"
        title="Built for the app your customers use"
        description="Cursor now reaches past the editor, into Git hosting and deploy checks, but not into hosting your app or its database. Appwrite is the production backend the reviewed code runs on, open source and self-hostable."
        competitorName="Cursor"
        competitorRows={CURSOR_PLATFORM}
        appwriteLabel="Appwrite"
        appwriteRows={APPWRITE_PLATFORM}
        footnote="Cursor features as documented in November 2026. Origin is in early beta, and Rollouts is on Teams and Enterprise plans."
      />

      <EveryReviewSection
        title="Findings you can act on"
        description="Every finding is verified before it is posted, graded into a merge decision, and comes with a prompt your coding agent can pick up."
      />

      <ReviewsHubSection
        title="One project, from pull request to pager"
        description="Bugbot ends at the review and Rollouts reads tools you bring. Appwrite Reviews sits in the project that deploys the code, measures its traffic, and pages you when it misbehaves."
      />

      <PlatformBreadthSection
        id="bugbot"
        title="Bugbot reviews code. Appwrite runs your product."
        description="Appwrite Reviews comes with auth, databases, storage, functions, messaging, realtime, and hosting for customer-facing apps, all in one open-source project."
      />

      <OpenSourceSection
        id="bugbot"
        title="Open source from review to runtime"
        description="Bugbot, Graphite, and Origin are closed services. Appwrite and its review service are open source, so the platform behind your app is code you can read and run."
      />

      <ComparisonTableSection
        id="bugbot"
        title="Code review, and what runs after it"
        description="Bugbot is part of Cursor. Appwrite Reviews is part of the backend platform your app runs on."
      />

      <ComparisonClosing
        id="bugbot"
        cta={{
          title: 'Review it, ship it, watch it',
          description:
            'Connect a repository, deploy it to Appwrite, and get a review on the next pull request with Monitors watching after the merge.',
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
