import { describe, expect, test } from 'bun:test'
import { getChangelogEntryDiscordEmbed } from '@/lib/changelog/seo'
import {
  DISCORD_EMBED_MAX_BYTES,
  DISCORD_EMBED_SCRIPT_ID,
  escapeDiscordMarkdown,
  getDiscordEmbedScript,
  getDiscordLinkButton,
  getDiscordTimestamp,
  truncateDiscordText,
  type DiscordContainer,
} from '@/lib/seo/discord-embed'
import { getThreadDiscordEmbed } from '@/lib/threads/seo'
import type { DiscordThread } from '@/lib/threads/types'

const BUTTON_KEYS = ['id', 'type', 'url', 'style', 'label', 'emoji', 'disabled']

type Component = {
  type: number
  components?: Component[]
  accessory?: Component
  [key: string]: unknown
}

function flatten(component: Component): Component[] {
  return [
    component,
    ...(component.accessory ? flatten(component.accessory) : []),
    ...(component.components ?? []).flatMap(flatten),
  ]
}

function getContent(container: DiscordContainer): string {
  return flatten(container as Component)
    .filter((component) => component.type === 10)
    .map((component) => component.content)
    .join('\n')
}

/** The structural rules Discord validates before it renders a component embed. */
function expectValidEmbed(container: DiscordContainer) {
  const components = flatten(container as Component)
  expect(components.filter((component) => component.type === 17)).toHaveLength(
    1,
  )
  expect(components.length).toBeLessThanOrEqual(40)

  for (const button of components.filter((component) => component.type === 2)) {
    expect(button.style).toBe(5)
    expect(Object.keys(button).every((key) => BUTTON_KEYS.includes(key))).toBe(
      true,
    )
    expect(String(button.url)).toStartWith('https://')
  }

  const script = getDiscordEmbedScript(container)
  expect(script).toBeDefined()
  expect(new TextEncoder().encode(script!.children).length).toBeLessThanOrEqual(
    DISCORD_EMBED_MAX_BYTES,
  )
}

function createThread(overrides: Partial<DiscordThread> = {}): DiscordThread {
  return {
    $id: 'thread-row',
    $collectionId: 'threads',
    $databaseId: 'threads',
    $createdAt: '2026-10-01T10:00:00.000Z',
    $updatedAt: '2026-10-02T10:00:00.000Z',
    $permissions: [],
    $sequence: 1,
    discord_id: '1290000000000000000',
    author: 'dev_jane',
    author_avatar: 'https://cdn.discordapp.com/avatars/123456789/abc.png',
    title: 'Realtime subscription drops after deploy',
    content: 'My realtime channel stops receiving events after I redeploy.',
    tldr: 'Reconnect the client after the function redeploys.',
    vote_count: 3,
    message_count: 4,
    participant_count: 2,
    last_activity: '2026-10-02T10:00:00.000Z',
    is_resolved: true,
    ...overrides,
  } as DiscordThread
}

describe('escapeDiscordMarkdown', () => {
  test('escapes inline markdown and masked links', () => {
    expect(
      escapeDiscordMarkdown('use *bold* and [link](https://x.io) in `code`'),
    ).toBe('use \\*bold\\* and \\[link\\](https://x.io) in \\`code\\`')
  })

  test('escapes mention and timestamp markup', () => {
    expect(escapeDiscordMarkdown('<@123> at <t:1:R>')).toBe(
      '\\<@123\\> at \\<t:1:R\\>',
    )
  })

  test('collapses newlines so text cannot open a heading, list, or quote', () => {
    expect(escapeDiscordMarkdown('# Title\n- item\n> quote')).toBe(
      '\\# Title - item \\> quote',
    )
  })
})

describe('truncateDiscordText', () => {
  test('keeps short text', () => {
    expect(truncateDiscordText('  short   text ', 20)).toBe('short text')
  })

  test('cuts on a word boundary within the limit', () => {
    const text = truncateDiscordText('one two three four five six', 15)
    expect(text).toBe('one two three…')
    expect(text.length).toBeLessThanOrEqual(15)
  })
})

describe('getDiscordTimestamp', () => {
  test('formats seconds since the epoch', () => {
    expect(getDiscordTimestamp('2026-10-02T10:00:00.000Z', 'R')).toBe(
      '<t:1790935200:R>',
    )
  })

  test('skips invalid dates', () => {
    expect(getDiscordTimestamp('not a date', 'R')).toBeUndefined()
  })
})

describe('getDiscordEmbedScript', () => {
  const container: DiscordContainer = {
    type: 17,
    components: [{ type: 10, content: 'Ends with </script><!-- here' }],
  }

  test('targets the script Discord reads', () => {
    const script = getDiscordEmbedScript(container)

    expect(script?.id).toBe(DISCORD_EMBED_SCRIPT_ID)
    expect(script?.type).toBe('application/json')
    expect(JSON.parse(script!.children)).toEqual({ component: container })
  })

  test('cannot close the script element early', () => {
    expect(getDiscordEmbedScript(container)?.children).not.toContain('<')
  })

  test('omits a payload over the byte limit', () => {
    const script = getDiscordEmbedScript({
      type: 17,
      components: [{ type: 10, content: 'a'.repeat(DISCORD_EMBED_MAX_BYTES) }],
    })

    expect(script).toBeUndefined()
  })

  test('counts escape sequences at full length', () => {
    // 500 characters, but 3,000 bytes once each `<` becomes <.
    const script = getDiscordEmbedScript({
      type: 17,
      components: [{ type: 10, content: '<'.repeat(500) }],
    })

    expect(script).toBeUndefined()
  })

  test('omits a payload over the component limit', () => {
    const script = getDiscordEmbedScript({
      type: 17,
      components: Array.from({ length: 20 }, () => ({
        type: 1 as const,
        components: [getDiscordLinkButton('Open', 'https://appwrite.io')],
      })),
    })

    expect(script).toBeUndefined()
  })
})

describe('getThreadDiscordEmbed', () => {
  const canonicalUrl = 'https://appwrite.io/threads/1290000000000000000'

  test('summarizes the thread with the author avatar', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread(),
    })
    const content = getContent(embed)

    expectValidEmbed(embed)
    expect(content).toContain('### Realtime subscription drops after deploy')
    expect(content).toContain(
      'Reconnect the client after the function redeploys.',
    )
    expect(content).toContain(
      '-# Asked by dev\\_jane · ✅ Resolved · 4 messages · 2 participants · Last active <t:1790935200:R>',
    )
    expect(embed.components[0]).toMatchObject({
      type: 9,
      accessory: { type: 11, media: { url: createThread().author_avatar } },
    })
  })

  test('links back to the page and to Discord', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread(),
    })

    expect(embed.components.at(-1)).toEqual({
      type: 1,
      components: [
        getDiscordLinkButton('Read thread', canonicalUrl),
        getDiscordLinkButton(
          'Open in Discord',
          'https://discord.com/channels/564160730845151244/1290000000000000000',
        ),
      ],
    })
  })

  test('skips the thumbnail without an https avatar', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread({ author_avatar: '' }),
    })

    expectValidEmbed(embed)
    expect(embed.components[0]?.type).toBe(10)
  })

  test('counts messages from the stored total, not the loaded page', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread({ message_count: 120 }),
    })

    expect(getContent(embed)).toContain(' · 120 messages · ')
  })

  test('renders user markdown literally', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread({
        title: '# **Help** with <@everyone>',
        is_resolved: false,
      }),
    })
    const content = getContent(embed)

    expect(content).toContain('### \\# \\*\\*Help\\*\\* with \\<@everyone\\>')
    expect(content).not.toContain('Resolved')
  })

  test('fits a long thread', () => {
    const embed = getThreadDiscordEmbed({
      canonicalUrl,
      thread: createThread({
        title: 'How do I '.repeat(30),
        seo_description: '',
        tldr: 'Use the `Query.equal()` helper with an index on the column. '.repeat(
          20,
        ),
      }),
    })

    expectValidEmbed(embed)
  })
})

describe('getChangelogEntryDiscordEmbed', () => {
  const entry = {
    title: 'Introducing MCP Events',
    date: '2026-10-19',
    description: 'Subscribe agents to project events.',
    cover: '/images/blog/announcing-mcp-events/cover.avif',
    slug: '2026-10-19',
    href: '/changelog/entry/2026-10-19',
    parsedTags: [],
    content: '',
  }

  test('shows the cover as a JPEG the crawler can fetch', () => {
    const embed = getChangelogEntryDiscordEmbed(entry, {
      siteOrigin: 'https://appwrite.io',
    })

    expectValidEmbed(embed)
    expect(embed.components[0]).toEqual({
      type: 12,
      items: [
        {
          media: {
            url: 'https://appwrite.io/og/cover.jpg?path=%2Fimages%2Fblog%2Fannouncing-mcp-events%2Fcover.avif',
          },
        },
      ],
    })
  })

  test('summarizes the entry and links back', () => {
    const embed = getChangelogEntryDiscordEmbed(entry, {
      siteOrigin: 'https://appwrite.io',
    })
    const content = getContent(embed)

    expect(content).toContain('### Introducing MCP Events')
    expect(content).toContain('Subscribe agents to project events.')
    expect(content).toContain('-# Changelog · ')
    expect(embed.components.at(-1)).toEqual({
      type: 1,
      components: [
        getDiscordLinkButton(
          'Read update',
          'https://appwrite.io/changelog/entry/2026-10-19',
        ),
        getDiscordLinkButton('All updates', 'https://appwrite.io/changelog'),
      ],
    })
  })

  test('skips the generic description', () => {
    const embed = getChangelogEntryDiscordEmbed(
      { ...entry, description: undefined },
      { siteOrigin: 'https://appwrite.io' },
    )

    expect(getContent(embed)).toStartWith(
      '### Introducing MCP Events\n-# Changelog · ',
    )
  })

  test('skips the gallery without a cover', () => {
    const embed = getChangelogEntryDiscordEmbed(
      { ...entry, cover: undefined },
      { siteOrigin: 'https://appwrite.io' },
    )

    expectValidEmbed(embed)
    expect(embed.components[0]?.type).toBe(10)
  })
})
