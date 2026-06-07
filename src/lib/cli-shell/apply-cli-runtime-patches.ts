import type { CliShellContainer } from './types'

const RUNTIME_PATCH_PATH = '/.__cli_runtime_patch.js'

/**
 * Patches readline.createInterface inside the live almostnode container.
 * Idempotent and never throws (complements Vite bundle patches).
 */
const RUNTIME_PATCH_SCRIPT = `'use strict';

(function applyCliRuntimePatches() {
  var readline = require('readline');
  if (readline.__cliRuntimePatched) return;

  var fallbackOutput = {
    muted: false,
    mute: function mute() {
      this.muted = true;
    },
    unmute: function unmute() {
      this.muted = false;
    },
    end: function end() {},
    write: function write() {},
    isTTY: false,
    rows: 0,
    columns: 0,
  };

  var fallbackInput = {
    isTTY: false,
    pause: function pause() {},
    resume: function resume() {},
  };

  function enhanceReadlineInterface(rl, options) {
    if (!rl.output) {
      rl.output = (options && options.output) || fallbackOutput;
    }
    if (!rl.input) {
      rl.input = (options && options.input) || fallbackInput;
    }
    if (!rl._getCursorPos) {
      rl._getCursorPos = function _getCursorPos() {
        if (typeof rl.getCursorPos === 'function') {
          return rl.getCursorPos();
        }
        return { cols: rl.cursor || 0, rows: 0 };
      };
    }
    return rl;
  }

  function wrapCreateInterface(target) {
    if (!target || !target.createInterface || target.createInterface.__cliRuntimePatched) {
      return;
    }
    var originalCreateInterface = target.createInterface;
    function createInterfacePatched(options) {
      var rl = originalCreateInterface.call(target, options);
      return enhanceReadlineInterface(rl, options);
    }
    createInterfacePatched.__cliRuntimePatched = true;
    try {
      target.createInterface = createInterfacePatched;
    } catch (_assignError) {
      Object.defineProperty(target, 'createInterface', {
        value: createInterfacePatched,
        writable: true,
        configurable: true,
      });
    }
  }

  wrapCreateInterface(readline);
  if (readline.default) {
    wrapCreateInterface(readline.default);
  }

  try {
    readline.__cliRuntimePatched = true;
  } catch (_flagError) {
    /* namespace may be frozen */
  }
})();
`

/** Ensure readline is patched; safe to call before every command. */
export function applyCliRuntimePatches(container: CliShellContainer): void {
  try {
    container.vfs.writeFileSync(RUNTIME_PATCH_PATH, RUNTIME_PATCH_SCRIPT)
    container.runFile(RUNTIME_PATCH_PATH)
  } catch {
    // Vite bundle patches are the primary fix; this is a fallback.
  }
}
