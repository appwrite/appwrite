"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.mergeEnvironment = void 0;
function mergeEnvironment(provided) {
    return Object.assign({}, {
        cwd: process.cwd(),
        stdout: process.stdout,
        stderr: process.stderr,
        env: process.env,
        debug: false,
    }, provided);
}
exports.mergeEnvironment = mergeEnvironment;
//# sourceMappingURL=environment.js.map