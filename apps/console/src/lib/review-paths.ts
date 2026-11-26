/**
 * Which reviews belong to a function or site: a repository may hold several
 * of them, each in its own root directory, and a review's walkthrough lists
 * the files its pull request changes.
 */

import type { Review } from '@/lib/appwrite/reviews'

/**
 * A resource's root directory as a path from the repository root, without
 * leading `./` or `/` and trailing `/`. Empty for the repository root.
 */
export function normalizeRootDirectory(
  rootDirectory: string | null | undefined,
): string {
  let directory = (rootDirectory ?? '').trim()
  for (;;) {
    if (directory.startsWith('./')) {
      directory = directory.slice(2)
    } else if (directory.startsWith('/')) {
      directory = directory.slice(1)
    } else {
      break
    }
  }
  while (directory.endsWith('/')) {
    directory = directory.slice(0, -1)
  }
  return directory === '.' ? '' : directory
}

/** Whether `path`, from the repository root, is inside `directory`. */
export function isPathInDirectory(path: string, directory: string): boolean {
  if (directory === '') return true
  const file = normalizeRootDirectory(path)
  return file === directory || file.startsWith(`${directory}/`)
}

/**
 * Whether a review belongs to the resource in `directory`: its pull request
 * changes a file there. A resource at the repository root owns every review
 * of the repository. Otherwise a review without a walkthrough (not finished,
 * skipped or failed) is left out, since nothing shows it changes the resource.
 */
export function reviewTouchesDirectory(
  review: Pick<Review, 'walkthrough'>,
  directory: string,
): boolean {
  if (directory === '') return true
  return (review.walkthrough ?? []).some((entry) =>
    isPathInDirectory(entry.path, directory),
  )
}
