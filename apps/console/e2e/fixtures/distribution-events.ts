type Segment = string | number

function template(pattern: Segment[], wildcards: Set<number>): string {
  return pattern
    .map((segment) =>
      typeof segment === 'string'
        ? segment
        : wildcards.has(segment)
          ? '*'
          : `[${segment}]`,
    )
    .join('.')
}

function fill(
  pattern: Segment[],
  values: string[],
  wildcards: Set<number>,
): string {
  return pattern
    .map((segment) =>
      typeof segment === 'string'
        ? segment
        : wildcards.has(segment)
          ? '*'
          : values[segment],
    )
    .join('.')
}

function unique<T>(items: T[], key: (item: T) => string): T[] {
  const seen = new Map<string, T>()
  for (const item of items) {
    if (!seen.has(key(item))) seen.set(key(item), item)
  }
  return [...seen.values()]
}

/**
 * Port of `Appwrite\Event\Event::generateEvents()`: every event the server
 * publishes for one resource change, in the same order. `params` names each
 * level as `[type, id]`, for example
 * `[['distributionApps', appId], ['builds', buildId], ['logs', logId]]`.
 */
export function distributionEvents(
  params: readonly (readonly [string, string])[],
  action: string,
): string[] {
  if (params.length < 1 || params.length > 3) {
    throw new Error('Events have one to three resource levels.')
  }
  const values = params.map(([, id]) => id)
  const keys = values.map((_, index) => index)
  const [resource, sub, subSub] = params.map(([type], index): Segment[] => [
    type,
    index,
  ])
  const head = resource
  const subPattern = sub ? [...head, ...sub] : head
  const subSubPattern = sub && subSub ? [...subPattern, ...subSub] : subPattern
  const nested = subSub !== undefined

  const patterns: Segment[][] = []
  if (action) {
    if (nested) {
      patterns.push([...subSubPattern, action], subSubPattern)
    } else if (sub) {
      patterns.push([...subPattern, action], subPattern)
    } else {
      patterns.push([...head, action])
    }
  }
  if (nested) patterns.push(subSubPattern)
  if (sub) patterns.push(subPattern)
  patterns.push(head)

  const none = new Set<number>()
  const events: string[] = []
  for (const pattern of unique(patterns, (item) => template(item, none))) {
    events.push(fill(pattern, values, none))
    events.push(fill(pattern, values, new Set(keys)))
    const wildcards: Set<number>[] = []
    const add = (replaced: number[]) => {
      wildcards.push(
        new Set(replaced.filter((index) => pattern.includes(index))),
      )
    }
    for (const key of keys) {
      for (const current of keys) {
        if (nested) {
          for (const subCurrent of keys) {
            if (subCurrent === current || subCurrent === key) continue
            add([subCurrent])
            add([subCurrent, current])
            add([current])
          }
        } else if (current !== key) {
          add([current])
        }
      }
    }
    for (const replaced of unique(wildcards, (item) =>
      template(pattern, item),
    )) {
      events.push(fill(pattern, values, replaced))
    }
  }
  return unique(events, (event) => event)
}
