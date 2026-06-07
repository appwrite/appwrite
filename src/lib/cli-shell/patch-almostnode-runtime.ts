/**
 * Patches for almostnode's prebuilt browser runtime.
 */

const STREAM_CLASS_PATTERN =
  /class Stream extends EventEmitter \{\s*pipe\(destination\) \{\s*return destination;\s*\}\s*\}/

const LEGACY_STREAM_SHIM = `function Stream() {
  if (!(this instanceof Stream)) return new Stream();
}
Stream.prototype = Object.create(EventEmitter.prototype);
Stream.prototype.constructor = Stream;
Stream.prototype.pipe = function pipe(destination) {
  return destination;
};`

const READLINE_OUTPUT_FALLBACK =
  '{ mute() {}, unmute() {}, end() {}, write() {}, isTTY: false, rows: 0, columns: 0 }'

const READLINE_INPUT_FALLBACK =
  '{ isTTY: false, pause() {}, resume() {} }'

/** Patch createInterface so rl.output always exists (inquirer/mute-stream). */
const READLINE_CREATE_INTERFACE_PATTERN =
  /function createInterface\(options(\d+)\) \{\s*return new Interface\(options\1\);\s*\}/

/** Ensure Interface instances always expose output/input (inquirer close() calls rl.output.unmute()). */
const READLINE_INTERFACE_PROMPT_PATTERN =
  /this\.promptText = \(_options(\d+) == null \? void 0 : _options\1\.prompt\) \?\? "";\s*\}/

export function patchAlmostnodeStreamShim(code: string): string {
  if (!code.includes('class Stream extends EventEmitter')) {
    return code
  }
  return code.replace(STREAM_CLASS_PATTERN, LEGACY_STREAM_SHIM)
}

export function patchAlmostnodeReadlineShim(code: string): string {
  if (!code.includes('function createInterface(options')) {
    return code
  }
  return code.replace(
    READLINE_CREATE_INTERFACE_PATTERN,
    (_match, optionsVar: string) => `function createInterface(options${optionsVar}) {
  const rl = new Interface(options${optionsVar});
  rl.output = (options${optionsVar} == null ? void 0 : options${optionsVar}.output) ?? ${READLINE_OUTPUT_FALLBACK};
  rl.input = (options${optionsVar} == null ? void 0 : options${optionsVar}.input) ?? ${READLINE_INPUT_FALLBACK};
  if (!rl._getCursorPos) {
    rl._getCursorPos = function _getCursorPos() {
      return typeof rl.getCursorPos === "function" ? rl.getCursorPos() : { cols: rl.cursor || 0, rows: 0 };
    };
  }
  return rl;
}`,
  )
}

export function patchAlmostnodeInterfaceShim(code: string): string {
  if (!code.includes('class Interface extends EventEmitter')) {
    return code
  }
  return code.replace(
    READLINE_INTERFACE_PROMPT_PATTERN,
    (_match, optionsVar: string) => `this.promptText = (_options${optionsVar} == null ? void 0 : _options${optionsVar}.prompt) ?? "";
    this.output = (_options${optionsVar} == null ? void 0 : _options${optionsVar}.output) ?? ${READLINE_OUTPUT_FALLBACK};
    this.input = (_options${optionsVar} == null ? void 0 : _options${optionsVar}.input) ?? ${READLINE_INPUT_FALLBACK};
  }`,
  )
}

export function patchAlmostnodeRuntime(code: string): string {
  let next = code
  next = patchAlmostnodeStreamShim(next)
  next = patchAlmostnodeInterfaceShim(next)
  next = patchAlmostnodeReadlineShim(next)
  return next
}
