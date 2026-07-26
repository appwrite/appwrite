/**
 * Patches for the prebuilt almostnode bundle used by the browser CLI shell.
 */

/** Worker URL rewrite (see vite-almostnode-plugin). */
const VITE_WORKER_URL =
  /new URL\(\s*\/\*\s*@vite-ignore\s*\*\/\s*"([^"]+)",\s*import\.meta\.url\s*\)/gs

const PROCESS_EXIT_PREFIX = 'Process exited with code'
const EXIT_CATCH_MARKER = '__appwrite_cli_exit_catch__'

/**
 * Commander assumes `process.exit` never returns. Appwrite CLI boots inside
 * `void (async () => { ... })()`, so after sync startup almostnode must still
 * throw on exit to stop Commander. That throw rejects the voided promise.
 *
 * Vite logs unhandled rejections even when preventDefault runs, so we also
 * rewrite those IIFEs at execute-time to attach a .catch that swallows exit
 * errors (works even when the on-disk VFS copy was not patched).
 */
function buildAsyncProcessExitPatch(runtimeVar: string, tickVar: string): {
  find: string
  replace: string
} {
  const find = `    const proc = ${runtimeVar}.getProcess();
    proc.exit = (code2 = 0) => {
      if (!exitCalled) {
        exitCalled = true;
        exitCode = code2;
        proc.emit("exit", code2);
        exitResolve(code2);
      }
      if (syncExecution) {
        throw new Error(\`Process exited with code \${code2}\`);
      }
    };
    proc.argv = ["node", resolvedPath2, ...args.slice(1)];
    if (_abortSignal) {
      proc.stdout.isTTY = true;
      proc.stderr.isTTY = true;
      proc.stdin.isTTY = true;
      proc.stdin.setRawMode = () => proc.stdin;
      _activeProcessStdin = proc.stdin;
    }
    try {
      ${runtimeVar}.runFile(resolvedPath2);
    } catch (error) {
      if (error instanceof Error && error.message.startsWith("Process exited with code")) {
        return { stdout, stderr, exitCode };
      }
      const errorMsg = error instanceof Error ? \`\${error.message}
\${error.stack || ""}\` : String(error);
      return { stdout, stderr: stderr + \`Error: \${errorMsg}
\`, exitCode: 1 };
    } finally {
      syncExecution = false;
    }
    if (exitCalled) {
      return { stdout, stderr, exitCode };
    }
    if (stdout.length > 0 || stderr.length > 0) {
      await new Promise((${tickVar}) => setTimeout(${tickVar}, 0));
      return { stdout, stderr, exitCode: exitCalled ? exitCode : 0 };
    }
    const rejectionHandler = (event) => {
      const reason = event.reason;
      if (reason instanceof Error && reason.message.startsWith("Process exited with code")) {
        event.preventDefault();
        return;
      }
      const msg = reason instanceof Error ? \`Unhandled rejection: \${reason.message}
\${reason.stack || ""}
\` : \`Unhandled rejection: \${String(reason)}
\`;
      appendStderr(msg);
    };
    globalThis.addEventListener("unhandledrejection", rejectionHandler);`

  const replace = `    const rejectionHandler = (event) => {
      const reason = event.reason;
      const message = reason && typeof reason === "object" && typeof reason.message === "string" ? reason.message : String(reason ?? "");
      if (message.startsWith("${PROCESS_EXIT_PREFIX}")) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
      }
      const msg = reason instanceof Error ? \`Unhandled rejection: \${reason.message}
\${reason.stack || ""}
\` : \`Unhandled rejection: \${String(reason)}
\`;
      appendStderr(msg);
    };
    globalThis.addEventListener("unhandledrejection", rejectionHandler, true);
    const proc = ${runtimeVar}.getProcess();
    proc.exit = (code2 = 0) => {
      if (!exitCalled) {
        exitCalled = true;
        exitCode = code2;
        proc.emit("exit", code2);
        exitResolve(code2);
      }
      throw new Error(\`Process exited with code \${code2}\`);
    };
    proc.argv = ["node", resolvedPath2, ...args.slice(1)];
    if (_abortSignal) {
      proc.stdout.isTTY = true;
      proc.stderr.isTTY = true;
      proc.stdin.isTTY = true;
      proc.stdin.setRawMode = () => proc.stdin;
      _activeProcessStdin = proc.stdin;
    }
    try {
      ${runtimeVar}.runFile(resolvedPath2);
    } catch (error) {
      globalThis.removeEventListener("unhandledrejection", rejectionHandler, true);
      if (error instanceof Error && error.message.startsWith("${PROCESS_EXIT_PREFIX}")) {
        return { stdout, stderr, exitCode };
      }
      const errorMsg = error instanceof Error ? \`\${error.message}
\${error.stack || ""}\` : String(error);
      return { stdout, stderr: stderr + \`Error: \${errorMsg}
\`, exitCode: 1 };
    } finally {
      syncExecution = false;
    }
    if (exitCalled) {
      globalThis.removeEventListener("unhandledrejection", rejectionHandler, true);
      return { stdout, stderr, exitCode };
    }`

  return { find, replace }
}

const DEFER_REJECTION_HANDLER_REMOVAL = {
  find: `    } finally {
      _activeProcessStdin = null;
      _onForkedChildExit = prevChildExitHandler;
      globalThis.removeEventListener("unhandledrejection", rejectionHandler);
    }`,
  replace: `    } finally {
      _activeProcessStdin = null;
      _onForkedChildExit = prevChildExitHandler;
      setTimeout(() => {
        globalThis.removeEventListener("unhandledrejection", rejectionHandler, true);
      }, 100);
    }`,
}

/** Already-patched bundles used removeEventListener without the capture flag. */
const DEFER_REJECTION_HANDLER_REMOVAL_CAPTURE = {
  find: `    } finally {
      _activeProcessStdin = null;
      _onForkedChildExit = prevChildExitHandler;
      setTimeout(() => {
        globalThis.removeEventListener("unhandledrejection", rejectionHandler);
      }, 0);
    }`,
  replace: `    } finally {
      _activeProcessStdin = null;
      _onForkedChildExit = prevChildExitHandler;
      setTimeout(() => {
        globalThis.removeEventListener("unhandledrejection", rejectionHandler, true);
      }, 100);
    }`,
}

/**
 * Before eval'ing a script, attach .catch to Appwrite CLI async entrypoints so
 * process.exit throws are handled promises (not uncaught rejections).
 */
const ATTACH_EXIT_CATCH_ON_EXECUTE = {
  find: `    if (!filename.endsWith(".cjs")) {
      code = transformEsmToCjs(code, filename);
    }
    try {
      const importMetaUrl = "file://" + filename;`,
  replace: `    if (!filename.endsWith(".cjs")) {
      code = transformEsmToCjs(code, filename);
    }
    if (!code.includes("${EXIT_CATCH_MARKER}") && code.includes("void (async () =>")) {
      const __exitCatch = ".catch((err) => { /* ${EXIT_CATCH_MARKER} */ if (err && typeof err.message === \\"string\\" && err.message.startsWith(\\"${PROCESS_EXIT_PREFIX}\\")) return; console.error(err); })";
      code = code.replace(/process\\.exit\\(0\\);\\s*\\}\\)\\(\\);/g, "process.exit(0);\\n  })()" + __exitCatch + ";");
      code = code.replace(/process\\.stdout\\.columns = oldWidth;\\s*\\}\\)\\(\\);/g, "process.stdout.columns = oldWidth;\\n  })()" + __exitCatch + ";");
    }
    try {
      const importMetaUrl = "file://" + filename;`,
}

const ASYNC_PROCESS_EXIT_PATCHES = [
  buildAsyncProcessExitPatch('runtime', 'r'),
  buildAsyncProcessExitPatch('runtime2', 'r2'),
  DEFER_REJECTION_HANDLER_REMOVAL,
  DEFER_REJECTION_HANDLER_REMOVAL_CAPTURE,
  ATTACH_EXIT_CATCH_ON_EXECUTE,
]

/** Strip leftover almostnode process debug logging (cwd/chdir). */
const STRIP_PROCESS_DEBUG_LOGS = {
  find: `    cwd() {
      if (!proc._cwdCallCount) proc._cwdCallCount = 0;
      proc._cwdCallCount++;
      if (proc._cwdCallCount <= 5 || proc._cwdCallCount % 100 === 0) {
        console.log(\`[process] cwd() called (\${proc._cwdCallCount}x), returning:\`, currentDir2);
      }
      return currentDir2;
    },
    chdir(directory) {
      console.log("[process] chdir called:", directory, "from:", currentDir2);
      if (!directory.startsWith("/")) {
        directory = currentDir2 + "/" + directory;
      }
      currentDir2 = directory;
      console.log("[process] chdir result:", currentDir2);
    },`,
  replace: `    cwd() {
      return currentDir2;
    },
    chdir(directory) {
      if (!directory.startsWith("/")) {
        directory = currentDir2 + "/" + directory;
      }
      currentDir2 = directory;
    },`,
}

export function patchAlmostnodeBundle(code: string): string {
  let patched = code.replace(VITE_WORKER_URL, '"$1"')

  for (const { find, replace } of [
    ...ASYNC_PROCESS_EXIT_PATCHES,
    STRIP_PROCESS_DEBUG_LOGS,
  ]) {
    if (!patched.includes(find)) continue
    patched = patched.split(find).join(replace)
  }

  return patched
}

/** Skip stderr when it duplicates stdout (commander + almostnode async exit quirk). */
export function shouldWriteCliStderr(
  stdout: string,
  stderr: string,
): boolean {
  if (!stderr) return false
  if (!stdout) return true
  return stderr !== stdout
}
