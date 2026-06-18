import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { peelLeadingPostgresSqlComments } from '@/lib/postgres-sql'

export function preparePostgresQueryForExplanation(sql: string): {
  query: string
  analyze: boolean
} {
  const { leadingComments, sqlWithoutLeadingComments } =
    peelLeadingPostgresSqlComments(sql.trim())
  let text = sqlWithoutLeadingComments.trim()
  let analyze = false

  const explainMatch = text.match(/^explain\s+/i)
  if (!explainMatch) {
    return {
      query: leadingComments
        ? `${leadingComments}\n${text}`.trim()
        : text,
      analyze: false,
    }
  }

  text = text.slice(explainMatch[0].length).trimStart()

  const parenMatch = text.match(/^\(([^)]*)\)\s*/i)
  if (parenMatch) {
    const options = parenMatch[1].toLowerCase()
    analyze = /\banalyze\b/.test(options)
    text = text.slice(parenMatch[0].length).trimStart()
  } else {
    if (/^analyze\b/i.test(text)) {
      analyze = true
      text = text.replace(/^analyze\b/i, '').trimStart()
    }

    text = text.replace(/^verbose\b/i, '').trimStart()
    text = text.replace(/^format\s+\w+\b/i, '').trimStart()
  }

  const query = text.trim()
  return {
    query: leadingComments ? `${leadingComments}\n${query}`.trim() : query,
    analyze,
  }
}

export async function explainPostgresDatabaseQuery(
  projectId: string,
  databaseId: string,
  query: string,
  analyze?: boolean,
): Promise<Models.DedicatedDatabaseQueryExplanation> {
  const prepared = preparePostgresQueryForExplanation(query)

  return sdk.forProject(projectId).compute.createDatabaseQueryExplanation({
    databaseId,
    query: prepared.query,
    analyze: analyze ?? prepared.analyze,
  })
}
