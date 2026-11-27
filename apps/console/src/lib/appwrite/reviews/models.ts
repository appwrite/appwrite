/**
 * Reviews models: the Console SDK's, with the fields the console matches on
 * read as string unions. The SDK types a review's status, verdict, trigger and
 * severity as plain strings and its walkthrough as untyped objects.
 */

import type { Models, ReviewStrength as Strength } from '@appwrite.io/console'

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
export type ReviewStrength = `${Strength}`

export type ReviewFindingSeverity = 'info' | 'minor' | 'major' | 'critical'

export type ReviewFinding = Omit<Models.ReviewFinding, 'severity'> & {
  severity: ReviewFindingSeverity
}

/** A changed file in a review's walkthrough. */
export type ReviewWalkthroughEntry = {
  /** File path from the repository root. */
  path: string
  /** What the pull request changes in the file. */
  change: string
}

export type Review = Omit<
  Models.Review,
  | 'trigger'
  | 'status'
  | 'verdict'
  | 'walkthrough'
  | 'severity'
  | 'findings'
  | 'strength'
> & {
  trigger: ReviewTrigger
  status: ReviewStatus
  /** Empty until the review completes. */
  verdict: ReviewVerdict | ''
  walkthrough: ReviewWalkthroughEntry[]
  /** The most severe finding's severity; empty without findings. */
  severity: ReviewFindingSeverity | ''
  findings: ReviewFinding[]
  strength: ReviewStrength
}

export type ReviewList = { total: number; reviews: Review[] }

export type ReviewEvent = Omit<Models.ReviewEvent, 'data'> & {
  data: Record<string, unknown>
}

export type ReviewEventList = { total: number; events: ReviewEvent[] }

export type ReviewRepository = Omit<Models.ReviewRepository, 'strength'> & {
  strength: ReviewStrength
}

export type ReviewInstallation = Models.Installation

export type ReviewRepositoryList = {
  total: number
  repositories: ReviewRepository[]
}

export type ReviewLearning = Models.ReviewLearning

export type ReviewLearningList = Models.ReviewLearningList

export type ReviewAuthorRange = '7d' | '30d' | '90d'

export type ReviewAuthor = Models.ReviewAuthor

export type ReviewAuthorList = Models.ReviewAuthorList

export type ReviewCategory = Models.ReviewCategory

export type ReviewCategoryList = Models.ReviewCategoryList

export type ReviewPeriod = Models.ReviewPeriod

export type ReviewDay = Models.ReviewDay

export type ReviewRepositorySummary = Models.ReviewRepositorySummary

export type ReviewSummary = Models.ReviewSummary
