"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const data_table_1 = __importDefault(require("./data_table"));
const definition_1 = __importDefault(require("./definition"));
const step_arguments_1 = require("../step_arguments");
const value_checker_1 = require("../value_checker");
class StepDefinition extends definition_1.default {
    constructor(data) {
        super(data);
        this.keyword = data.keyword;
        this.pattern = data.pattern;
        this.expression = data.expression;
    }
    async getInvocationParameters({ step, world, }) {
        const parameters = await Promise.all(this.expression.match(step.text).map((arg) => arg.getValue(world)));
        if ((0, value_checker_1.doesHaveValue)(step.argument)) {
            const argumentParamater = (0, step_arguments_1.parseStepArgument)(step.argument, {
                dataTable: (arg) => new data_table_1.default(arg),
                docString: (arg) => arg.content,
            });
            parameters.push(argumentParamater);
        }
        return {
            getInvalidCodeLengthMessage: () => this.baseGetInvalidCodeLengthMessage(parameters),
            parameters,
            validCodeLengths: [parameters.length, parameters.length + 1],
        };
    }
    matchesStepName(stepName) {
        return (0, value_checker_1.doesHaveValue)(this.expression.match(stepName));
    }
}
exports.default = StepDefinition;
//# sourceMappingURL=step_definition.js.map