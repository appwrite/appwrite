/**
 * Social media CLI for managing and inspecting platform accounts.
 *
 * Run: bun run socials <platform> <command> [options]
 *
 * Examples:
 *   bun run socials x posts
 *   bun run socials x posts --username=appwrite --limit=10
 *   bun run socials x help
 */
import 'dotenv/config'

import { type SocialPlatform, printRootHelp, runPlatformCommand } from './lib/socials/cli'
import { xPlatform } from './lib/socials/platforms/x'

type PlatformEntry = SocialPlatform & {
  run: (action: string | undefined, args: string[]) => Promise<void>
}

function registerPlatform(name: string, platform: SocialPlatform): PlatformEntry {
  return {
    ...platform,
    run: (action, args) => runPlatformCommand(name, platform, action, args),
  }
}

const platforms = {
  x: registerPlatform('x', xPlatform),
} as const

async function main(): Promise<void> {
  const [platform, action, ...rest] = process.argv.slice(2)

  if (!platform || platform === '--help' || platform === '-h' || platform === 'help') {
    printRootHelp(platforms)
    return
  }

  const handler = platforms[platform as keyof typeof platforms]
  if (!handler) {
    console.error(`Unknown platform: ${platform}\n`)
    printRootHelp(platforms)
    process.exit(1)
  }

  try {
    await handler.run(action, rest)
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error)
    console.error(`Error: ${message}`)
    process.exit(1)
  }
}

await main()
