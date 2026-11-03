/**
 * Reviews response models, copied from `Review`, `ReviewFinding` and
 * `ReviewRepository` in `src/Appwrite/Utopia/Response/Model`. Delete with
 * `service.ts` once the generated SDK carries the `reviews` service.
 */

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
