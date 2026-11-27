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

/** Greptile's review and testing, November 2026. */
const GREPTILE_RUN: ContrastRow[] = [
  {
    title: 'TREX in a sandbox',
    detail:
      'Runs the pull request’s app, clicks through the flows it touches, and attaches logs and video.',
  },
  {
    title: 'Three credits a run',
    detail: 'On top of the review, at $1 a credit.',
  },
  {
    title: 'Ends at the merge',
    detail:
      'Hosting, deploys, and production monitoring stay with other tools.',
  },
]

const APPWRITE_RUN: ContrastRow[] = [
  {
    title: 'A preview for each pull request',
    detail:
      'Sites builds the branch and posts a preview link next to the review.',
  },
  {
    title: 'Commands in a sandbox',
    detail:
      'Normal and Expert reviews can run targeted commands to confirm a suspected bug.',
  },
  {
    title: 'Production after the merge',
    detail:
      'Monitors watch usage and endpoints, and alerts escalate to your phone with Beep.',
  },
]

/** Greptile billing, November 2026. */
const GREPTILE_BILLING: ContrastRow[] = [
  {
    title: 'Prepaid $1 credits',
    detail: 'Shared by the organization, with no seat fees.',
  },
  {
    title: '1, 3, or 10 credits a review',
    detail: 'Base, Plus, or Apex, plus 3 for a TREX run.',
  },
  {
    title: '50 free credits a month',
    detail: 'On Starter, for one active developer.',
  },
  { title: 'Reviews pause at zero', detail: 'Until someone adds credits.' },
]

export function GreptileComparison() {
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
            <VersusPill name="Greptile" />
            <ComparisonHeroTitle
              className="mx-auto mt-7 max-w-lg xl:mx-0"
              title="Review the pull request,"
              accent="then run it for real"
            />
            <p className="mx-auto mt-6 max-w-xl text-[15px] leading-7 text-muted-foreground sm:text-[16px] sm:leading-8 xl:mx-0">
              {t(
                'Greptile reviews pull requests and can test the app in a sandbox. Appwrite Reviews comes with the platform that deploys the app for real, previews every pull request, and watches production with Monitors and Beep after the merge.',
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
              competitorName="Greptile"
              competitorLane={[
                {
                  stage: 'Review',
                  name: 'Greptile',
                  status: 'Reviews the PR',
                  coverage: 'included',
                },
                {
                  stage: 'Deploy',
                  name: 'TREX',
                  status: 'Sandbox test only',
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
              competitorFooter="Hosting, monitoring, and paging come from other vendors"
            />
          </div>
        </div>
      </section>

      <ContrastSection
        eyebrow="Before and after the merge"
        title="From a sandbox run to production"
        description="Greptile can exercise a pull request in a sandbox before you merge. Appwrite previews it on your real hosting, then keeps watching it in production."
        competitorName="Greptile"
        competitorRows={GREPTILE_RUN}
        appwriteLabel="Appwrite"
        appwriteRows={APPWRITE_RUN}
      />

      <ReviewAndDeploySection description="The Appwrite app that builds the preview also reviews the pull request. Both comments land on the pull request together, in the same layout, with links back to the Console." />

      <EveryReviewSection
        title="A grade, not just a score"
        description="Every review ends in a merge confidence grade from S to F and a verdict decided by code, both built from findings a second model has verified."
      />

      <ContrastSection
        eyebrow="Credits"
        title="Credits that come with your plan"
        description="Both bill reviews in credits. Greptile sells them prepaid at $1 each. Appwrite includes them in the plan your project already has, every month."
        competitorName="Greptile"
        competitorRows={GREPTILE_BILLING}
        appwriteLabel="Appwrite Reviews"
        appwriteRows={APPWRITE_REVIEW_BILLING}
        footnote="Published prices as of November 2026. Greptile reviews non-commercial open source for free and offers startup credits."
      />

      <ReviewsHubSection
        title="The review is one step of the release"
        description="Greptile focuses on the pull request. Appwrite Reviews shares a project with the products that deploy, measure, protect, and watch the code you merge."
      />

      <PlatformBreadthSection
        id="greptile"
        title="Greptile reviews code. Appwrite also runs it."
        description="Appwrite Reviews comes with auth, databases, storage, functions, messaging, realtime, and hosting in the same project."
      />

      <OpenSourceSection
        id="greptile"
        title="A reviewer you can read and run"
        description="Greptile is closed source. Appwrite and its review service are open source, so you can audit how your code is reviewed and run it on your own servers."
      />

      <ComparisonTableSection
        id="greptile"
        title="Review features, side by side"
        description="Greptile is a dedicated code reviewer. Appwrite Reviews is code review inside the platform that ships and runs your app."
      />

      <ComparisonClosing
        id="greptile"
        cta={{
          title: 'Ship the pull request, then watch it',
          description:
            'Connect a repository, get a graded review on the next pull request, and keep watching it in production with Monitors.',
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
