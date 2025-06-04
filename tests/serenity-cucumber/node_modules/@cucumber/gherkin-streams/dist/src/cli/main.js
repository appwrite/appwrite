"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const message_streams_1 = require("@cucumber/message-streams");
const messages_1 = require("@cucumber/messages");
const commander_1 = require("commander");
const package_json_1 = __importDefault(require("../../package.json"));
const GherkinStreams_1 = __importDefault(require("../GherkinStreams"));
const program = new commander_1.Command();
program.version(package_json_1.default.version);
program.option('--no-source', 'Do not output Source messages');
program.option('--no-ast', 'Do not output GherkinDocument messages');
program.option('--no-pickles', 'Do not output Pickle messages');
program.option('--predictable-ids', 'Use predictable ids', false);
program.parse(process.argv);
const paths = program.args;
const options = {
    defaultDialect: 'en',
    includeSource: program.opts().source,
    includeGherkinDocument: program.opts().ast,
    includePickles: program.opts().pickles,
    newId: program.opts().predictableIds
        ? messages_1.IdGenerator.incrementing()
        : messages_1.IdGenerator.uuid(),
};
GherkinStreams_1.default.fromPaths(paths, options)
    .pipe(new message_streams_1.MessageToNdjsonStream())
    .pipe(process.stdout);
//# sourceMappingURL=main.js.map