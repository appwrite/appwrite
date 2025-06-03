"use strict";
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
Object.defineProperty(exports, "__esModule", { value: true });
const commander_1 = require("commander");
const package_json_1 = require("../package.json");
const formatCommand_1 = require("./commands/formatCommand");
const program = new commander_1.Command();
program.version(package_json_1.version);
program
    .command('format')
    .arguments('[files...]')
    .option('-l, --language <ISO 639-1>', 'specify the language (dialect) of the source')
    .addOption(new commander_1.Option('-f, --from-syntax <syntax>', 'from syntax').choices(['gherkin', 'markdown']))
    .addOption(new commander_1.Option('-t, --to-syntax <syntax>', 'to syntax').choices(['gherkin', 'markdown']))
    .description(`Formats one or more files. STDIN is formatted and written to STDOUT (assuming gherkin syntax by default)`, {
    files: 'One or more .feature or .feature.md files',
})
    .action((files, options) => __awaiter(void 0, void 0, void 0, function* () {
    yield (0, formatCommand_1.formatCommand)(files, process.stdin.isTTY ? null : process.stdin, process.stdout, options);
}));
program.parse(process.argv);
//# sourceMappingURL=cli.js.map