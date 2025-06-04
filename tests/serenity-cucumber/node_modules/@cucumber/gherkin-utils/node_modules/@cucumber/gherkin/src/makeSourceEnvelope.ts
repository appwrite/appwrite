import * as messages from '@cucumber/messages'

export default function makeSourceEnvelope(data: string, uri: string): messages.Envelope {
  let mediaType: messages.SourceMediaType
  if (uri.endsWith('.feature')) {
    mediaType = messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN
  } else if (uri.endsWith('.md')) {
    mediaType = messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_MARKDOWN
  }
  if (!mediaType) throw new Error(`The uri (${uri}) must end with .feature or .md`)
  return {
    source: {
      data,
      uri,
      mediaType,
    },
  }
}
