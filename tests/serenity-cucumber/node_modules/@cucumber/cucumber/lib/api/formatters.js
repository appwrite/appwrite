"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.initializeFormatters = void 0;
const util_1 = require("util");
const builder_1 = __importDefault(require("../formatter/builder"));
const fs_1 = __importDefault(require("mz/fs"));
const path_1 = __importDefault(require("path"));
const mkdirp_1 = require("mkdirp");
async function initializeFormatters({ env, cwd, stdout, logger, onStreamError, eventBroadcaster, eventDataCollector, configuration, supportCodeLibrary, }) {
    async function initializeFormatter(stream, target, type) {
        stream.on('error', (error) => {
            logger.error(error.message);
            onStreamError();
        });
        const typeOptions = {
            env,
            cwd,
            eventBroadcaster,
            eventDataCollector,
            log: stream.write.bind(stream),
            parsedArgvOptions: configuration.options,
            stream,
            cleanup: stream === stdout
                ? async () => await Promise.resolve()
                : (0, util_1.promisify)(stream.end.bind(stream)),
            supportCodeLibrary,
        };
        if (type === 'progress-bar' && !stream.isTTY) {
            logger.warn(`Cannot use 'progress-bar' formatter for output to '${target}' as not a TTY. Switching to 'progress' formatter.`);
            type = 'progress';
        }
        return await builder_1.default.build(type, typeOptions);
    }
    const formatters = [];
    formatters.push(await initializeFormatter(stdout, 'stdout', configuration.stdout));
    const streamPromises = [];
    Object.entries(configuration.files).forEach(([target, type]) => {
        streamPromises.push((async (target, type) => {
            const absoluteTarget = path_1.default.resolve(cwd, target);
            try {
                await (0, mkdirp_1.mkdirp)(path_1.default.dirname(absoluteTarget));
            }
            catch (error) {
                logger.warn('Failed to ensure directory for formatter target exists');
            }
            const stream = fs_1.default.createWriteStream(null, {
                fd: await fs_1.default.open(absoluteTarget, 'w'),
            });
            formatters.push(await initializeFormatter(stream, target, type));
        })(target, type));
    });
    await Promise.all(streamPromises);
    return async function () {
        await Promise.all(formatters.map(async (f) => await f.finished()));
    };
}
exports.initializeFormatters = initializeFormatters;
//# sourceMappingURL=formatters.js.map