"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const worker_1 = __importDefault(require("./worker"));
const verror_1 = __importDefault(require("verror"));
const value_checker_1 = require("../../value_checker");
function run() {
    const exit = (exitCode, error, message) => {
        if ((0, value_checker_1.doesHaveValue)(error)) {
            console.error(verror_1.default.fullStack(new verror_1.default(error, message))); // eslint-disable-line no-console
        }
        process.exit(exitCode);
    };
    const worker = new worker_1.default({
        id: process.env.CUCUMBER_WORKER_ID,
        sendMessage: (message) => process.send(message),
        cwd: process.cwd(),
        exit,
    });
    process.on('message', (m) => {
        worker
            .receiveMessage(m)
            .catch((error) => exit(1, error, 'Unexpected error on worker.receiveMessage'));
    });
}
run();
//# sourceMappingURL=run_worker.js.map