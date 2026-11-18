/**
 * Secrecy of a function or site template variable.
 *
 * A template catalog marks credential inputs with `type: 'password'` and older
 * servers never set `secret` on them, so the type is the only signal a setup
 * wizard can rely on. Treating a password input as secret keeps the value out
 * of the variable get and list routes, which need no more than `functions.read`
 * or `sites.read`.
 */
export function isTemplateVariableSecret(variable: {
  secret?: boolean
  type?: string
}): boolean {
  return variable.secret === true || variable.type === 'password'
}
