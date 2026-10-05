/**
 * Flag links to blog posts marked `removed: true` anywhere under src/ (other
 * posts, docs, changelog, integrations, and code). Removed posts redirect to
 * the home page, so links to them must be dropped or pointed elsewhere.
 *
 * Usage:
 *   bun run lint:removed-blog-links
 *
 * Exit 1 when links remain (for CI).
 */
import { readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import {
  findRemovedBlogPostLinks,
  listLiveSourceFiles,
  readRemovedBlogPostSlugs,
} from '../src/lib/blog/removed-posts.ts'

const ROOT = join(import.meta.dirname, '..')

function main() {
  const removedSlugs = readRemovedBlogPostSlugs()
  let count = 0

  for (const file of listLiveSourceFiles(removedSlugs)) {
    const links = findRemovedBlogPostLinks(readFileSync(file, 'utf8'), removedSlugs)
    for (const link of links) {
      console.error(
        `${relative(ROOT, file).replace(/\\/g, '/')}:${link.line}  links to removed post "${link.slug}"`,
      )
      count += 1
    }
  }

  if (count > 0) {
    console.error(
      `\n${count} link(s) to removed blog posts. Remove them or point them at a live page.`,
    )
    process.exit(1)
  }

  console.log(`No links to the ${removedSlugs.size} removed blog posts.`)
}

main()
