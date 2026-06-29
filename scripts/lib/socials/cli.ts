export type SocialCommand = {
  description: string
  run: (args: string[]) => Promise<void>
}

export type SocialPlatform = {
  description: string
  commands: Record<string, SocialCommand>
}

export function printRootHelp(platforms: Record<string, SocialPlatform>): void {
  console.log(`Usage: bun run socials <platform> <command> [options]

Platforms:`)

  for (const [name, platform] of Object.entries(platforms)) {
    console.log(`  ${name.padEnd(12)} ${platform.description}`)
  }

  console.log(`
Run \`bun run socials <platform> help\` for platform commands.
`)
}

export function printPlatformHelp(platform: string, config: SocialPlatform): void {
  console.log(`${platform} — ${config.description}

Usage: bun run socials ${platform} <command> [options]

Commands:`)

  for (const [name, command] of Object.entries(config.commands)) {
    console.log(`  ${name.padEnd(12)} ${command.description}`)
  }

  console.log('')
}

export async function runPlatformCommand(
  platform: string,
  config: SocialPlatform,
  action: string | undefined,
  args: string[],
): Promise<void> {
  if (!action || action === 'help' || action === '--help' || action === '-h') {
    printPlatformHelp(platform, config)
    return
  }

  const command = config.commands[action]
  if (!command) {
    console.error(`Unknown ${platform} command: ${action}\n`)
    printPlatformHelp(platform, config)
    process.exit(1)
  }

  await command.run(args)
}
