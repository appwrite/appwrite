/**
 * Whether an image in an assistant message may load: only images on the
 * console's own origin. Message text can carry content the agent read from
 * project data, and a remote image is requested as soon as the message
 * renders, with whatever the text put into its URL.
 */
export function isLoadableMessageImage(src: string, origin: string): boolean {
  if (!origin) return false
  try {
    return new URL(src, origin).origin === origin
  } catch {
    return false
  }
}
