/**
 * Reviews response models, copied from `Review`, `ReviewFinding`,
 * `ReviewRepository`, `ReviewLearning`, `ReviewAuthor`, `ReviewCategory`,
 * `ReviewSummary`, `ReviewPeriod`, `ReviewDay` and `ReviewRepositorySummary` in
 * `src/Appwrite/Utopia/Response/Model`. Delete with
 * `service.ts` once the generated SDK carries the `reviews` service.
 */

import type { Models } from '@appwrite.io/console'

export type ReviewStatus =
  | 'queued'
  | 'processing'
  | 'completed'
  | 'skipped'
  | 'failed'

export type ReviewVerdict = 'approve' | 'request_changes' | 'comment'

export type ReviewTrigger =
  | 'opened'
  | 'synchronize'
  | 'ready_for_review'
  | 'retry'
  | 'mention'

/** How hard a repository's pull requests are reviewed, and what each review costs. */
export type ReviewStrength = 'basic' | 'normal' | 'expert'

export type ReviewFindingSeverity = 'info' | 'minor' | 'major' | 'critical'

/**
 * Review Finding
 */
export type ReviewFinding = {
  /**
   * Finding ID.
   */
  $id: string
  /**
   * Finding creation date in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Path of the file the finding is about.
   */
  path: string
  /**
   * First line of the finding.
   */
  startLine: number
  /**
   * Last line of the finding.
   */
  endLine: number
  /**
   * Finding severity.
   */
  severity: ReviewFindingSeverity
  /**
   * Finding category, such as bug, security or performance.
   */
  category: string
  /**
   * Finding title.
   */
  title: string
  /**
   * Finding explanation in Markdown.
   */
  body: string
  /**
   * VCS comment the finding was posted as.
   */
  providerCommentId: string
}

/**
 * A changed file in a review's walkthrough.
 */
export type ReviewWalkthroughEntry = {
  /**
   * File path from the repository root.
   */
  path: string
  /**
   * What the pull request changes in the file.
   */
  change: string
}

/**
 * Review
 */
export type Review = {
  /**
   * Review ID.
   */
  $id: string
  /**
   * Date the review was queued, in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Review update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * ID of the repository the review belongs to.
   */
  repositoryId: string
  /**
   * VCS repository ID.
   */
  providerRepositoryId: string
  /**
   * VCS repository owner.
   */
  providerRepositoryOwner: string
  /**
   * VCS repository name.
   */
  providerRepositoryName: string
  /**
   * Pull request number.
   */
  providerPullRequestId: string
  /**
   * VCS login of who opened the pull request. Empty for reviews queued before
   * it was recorded.
   */
  providerPullRequestAuthor: string
  /**
   * Head commit of the pull request.
   */
  providerCommitHash: string
  /**
   * Base commit the head is compared with, once the review has started.
   */
  providerBaseCommitHash: string
  /**
   * What queued the review.
   */
  trigger: ReviewTrigger
  /**
   * Strength the review runs and is billed at: the repository's when it was queued.
   */
  strength: ReviewStrength
  /**
   * Review status.
   */
  status: ReviewStatus
  /**
   * Review verdict once completed.
   */
  verdict: ReviewVerdict | ''
  /**
   * Merge confidence once completed, from S (best) to F.
   */
  tier: string
  /**
   * Review summary in Markdown.
   */
  summary: string
  /**
   * What each changed file does, once completed. Empty until then.
   */
  walkthrough: ReviewWalkthroughEntry[]
  /**
   * Why the review was skipped or failed.
   */
  reason: string
  /**
   * VCS review ID, once posted.
   */
  providerReviewId: string
  /**
   * Date the review started, in ISO 8601 format.
   */
  startedAt: string
  /**
   * Date the review ended, in ISO 8601 format.
   */
  completedAt: string
  /**
   * Findings the review posted. Findings dropped before posting are not counted.
   */
  findingsTotal: number
  /**
   * Severity of the worst finding the review posted. Empty when it posted none.
   */
  severity: ReviewFindingSeverity | ''
  /**
   * Findings posted with the review. Empty in lists; get a review to read them.
   */
  findings: ReviewFinding[]
}

/**
 * Reviews List
 */
export type ReviewList = {
  /**
   * Total number of reviews that matched your query.
   */
  total: number
  /**
   * List of reviews.
   */
  reviews: Review[]
}

/** One step of the review agent, such as a tool call or the sandbox starting. */
export type ReviewEvent = {
  $id: string
  $createdAt: string
  /** Step name, such as `tool.read_file`, `sandbox.ready` or `review.done`. */
  type: string
  /** When the step happened, in ISO 8601 format. */
  time: string
  data: Record<string, unknown>
}

export type ReviewEventList = {
  total: number
  events: ReviewEvent[]
}

/**
 * Review Repository
 */
export type ReviewRepository = {
  /**
   * Repository ID.
   */
  $id: string
  /**
   * Date code review was turned on for the repository, in ISO 8601 format.
   */
  $createdAt: string
  /**
   * Repository update date in ISO 8601 format.
   */
  $updatedAt: string
  /**
   * VCS installation ID the repository is connected through.
   */
  installationId: string
  /**
   * VCS repository ID.
   */
  providerRepositoryId: string
  /**
   * How hard pull requests are reviewed.
   */
  strength: ReviewStrength
}

/**
 * A VCS installation with its code review settings, which the Console SDK's
 * `Installation` model does not carry yet.
 */
export type ReviewInstallation = Models.Installation & {
  /**
   * Whether code review is turned on for repositories added to the installation later.
   */
  reviewNewRepositories?: boolean
}

/**
 * Review Repositories List
 */
export type ReviewRepositoryList = {
  /**
   * Total number of repositories that matched your query.
   */
  total: number
  /**
   * List of repositories.
   */
  repositories: ReviewRepository[]
}

/**
 * Review Learning: something the reviewer was asked to remember in a
 * conversation on GitHub. Later reviews of the repository follow it.
 */
export type ReviewLearning = {
  /**
   * Learning ID.
   */
  $id: string
  /**
   * Learning creation date in ISO 8601 format.
   */
  $createdAt: string
  /**
   * ID of the repository whose reviews follow the learning.
   */
  repositoryId: string
  /**
   * What the reviewer was asked to remember.
   */
  body: string
  /**
   * GitHub login of who asked for it.
   */
  author: string
  /**
   * The comment it was asked in, if known.
   */
  sourceUrl: string
}

/**
 * Review Learnings List
 */
export type ReviewLearningList = {
  /**
   * Total number of learnings that matched your query.
   */
  total: number
  /**
   * List of learnings.
   */
  learnings: ReviewLearning[]
}

/** Date range the author performance is computed over. */
export type ReviewAuthorRange = '7d' | '30d' | '90d'

/**
 * Review Author: how one author's pull requests fared in review over a range.
 */
export type ReviewAuthor = {
  /**
   * VCS login of the pull requests' author. Empty for pull requests reviewed
   * before authors were recorded.
   */
  author: string
  /** Pull requests reviewed. */
  pulls: number
  /** Completed reviews of the pull requests. */
  reviews: number
  /** Pull requests whose latest review approved. */
  approved: number
  /** Pull requests approved by their first review. */
  approvedFirst: number
  /** Findings posted on the pull requests. */
  findings: number
  /** Critical findings posted. */
  critical: number
  /** Major findings posted. */
  major: number
  /** Findings fixed by a later commit. */
  resolved: number
  /** Findings settled in their thread or dismissed by a maintainer. */
  dismissed: number
  /** Average merge confidence tier of the latest reviews, S to F; empty when none has one. */
  tier: string
  /** Date of the latest review in ISO 8601 format. */
  lastReviewAt: string
}

/**
 * Review Authors List
 */
export type ReviewAuthorList = {
  /**
   * Total number of authors.
   */
  total: number
  /**
   * Authors with the most reviewed pull requests first.
   */
  authors: ReviewAuthor[]
}

/**
 * Review Category: how the findings of one category landed over a range.
 */
export type ReviewCategory = {
  /** Finding category, such as bug, security or performance. */
  category: string
  /** Findings of the category posted on pull requests. */
  findings: number
  /** Findings fixed by a later commit. */
  resolved: number
  /** Findings settled without a change: declined in their thread, or whose thread a maintainer resolved. */
  dismissed: number
}

/**
 * Review Categories List
 */
export type ReviewCategoryList = {
  /**
   * Total number of categories.
   */
  total: number
  /**
   * Categories with the most dismissed findings first.
   */
  categories: ReviewCategory[]
}

/**
 * Review Period: how the project's pull requests fared in review over a period.
 */
export type ReviewPeriod = {
  /** Start of the period in ISO 8601 format. */
  start: string
  /** End of the period in ISO 8601 format. */
  end: string
  /** Pull requests reviewed. */
  pulls: number
  /** Completed reviews of the pull requests. */
  reviews: number
  /** Pull requests whose latest review of the period approved. */
  approved: number
  /** Pull requests approved by their first review of the period. */
  approvedFirst: number
  /** Findings posted on the pull requests. */
  findings: number
  /** Critical findings posted. */
  critical: number
  /** Major findings posted. */
  major: number
  /** Minor findings posted. */
  minor: number
  /** Informational findings posted. */
  info: number
  /** Findings fixed by a later commit. */
  resolved: number
  /** Findings settled in their thread or dismissed by a maintainer. */
  dismissed: number
  /** Median time from a review being queued to it completing, in milliseconds. 0 when no review completed. */
  duration: number
}

/**
 * Review Day: what the reviews completed on one UTC day concluded.
 */
export type ReviewDay = {
  /** Start of the day, in UTC, in ISO 8601 format. */
  date: string
  /** Reviews completed that day. */
  reviews: number
  /** Reviews that approved. */
  approved: number
  /** Reviews that requested changes. */
  changesRequested: number
  /** Reviews that only commented. */
  commented: number
  /** Findings the reviews posted. */
  findings: number
  /** Critical findings posted. */
  critical: number
  /** Major findings posted. */
  major: number
  /** Minor findings posted. */
  minor: number
  /** Informational findings posted. */
  info: number
  /** Findings since fixed by a later commit. */
  resolved: number
  /** Findings since settled in their thread or dismissed by a maintainer. */
  dismissed: number
}

/**
 * Review Repository Summary: how one repository's pull requests fared in
 * review over a range.
 */
export type ReviewRepositorySummary = {
  /** ID of the repository the reviews belong to. */
  repositoryId: string
  /** VCS repository owner, as of the latest review. */
  providerRepositoryOwner: string
  /** VCS repository name, as of the latest review. */
  providerRepositoryName: string
  /** Pull requests reviewed. */
  pulls: number
  /** Completed reviews of the pull requests. */
  reviews: number
  /** Pull requests whose latest review approved. */
  approved: number
  /** Pull requests approved by their first review. */
  approvedFirst: number
  /** Findings posted on the pull requests. */
  findings: number
  /** Critical findings posted. */
  critical: number
  /** Major findings posted. */
  major: number
  /** Findings fixed by a later commit. */
  resolved: number
  /** Findings settled in their thread or dismissed by a maintainer. */
  dismissed: number
  /** Date of the latest review in ISO 8601 format. */
  lastReviewAt: string
}

/**
 * Review Summary: a range next to the same length of time before it, each
 * day of the range, and each repository's share of it.
 */
export type ReviewSummary = {
  /** The range, then the same length of time before it. */
  periods: ReviewPeriod[]
  /** Each day of the range, oldest first. */
  days: ReviewDay[]
  /** Each repository with a review in the range, the most reviewed pull requests first. */
  repositories: ReviewRepositorySummary[]
}
