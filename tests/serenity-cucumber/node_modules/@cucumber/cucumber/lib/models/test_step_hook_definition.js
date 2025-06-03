"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const pickle_filter_1 = require("../pickle_filter");
const definition_1 = __importDefault(require("./definition"));
class TestStepHookDefinition extends definition_1.default {
    constructor(data) {
        super(data);
        this.tagExpression = data.options.tags;
        this.pickleTagFilter = new pickle_filter_1.PickleTagFilter(data.options.tags);
    }
    appliesToTestCase(pickle) {
        return this.pickleTagFilter.matchesAllTagExpressions(pickle);
    }
    async getInvocationParameters({ hookParameter, }) {
        return await Promise.resolve({
            getInvalidCodeLengthMessage: () => this.buildInvalidCodeLengthMessage('0 or 1', '2'),
            parameters: [hookParameter],
            validCodeLengths: [0, 1, 2],
        });
    }
}
exports.default = TestStepHookDefinition;
//# sourceMappingURL=test_step_hook_definition.js.map