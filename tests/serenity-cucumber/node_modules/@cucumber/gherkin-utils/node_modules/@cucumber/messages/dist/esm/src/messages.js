var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
import { Type } from 'class-transformer';
import 'reflect-metadata';
export class Attachment {
    constructor() {
        this.body = '';
        this.contentEncoding = AttachmentContentEncoding.IDENTITY;
        this.mediaType = '';
    }
}
__decorate([
    Type(() => Source)
], Attachment.prototype, "source", void 0);
export class Duration {
    constructor() {
        this.seconds = 0;
        this.nanos = 0;
    }
}
export class Envelope {
}
__decorate([
    Type(() => Attachment)
], Envelope.prototype, "attachment", void 0);
__decorate([
    Type(() => GherkinDocument)
], Envelope.prototype, "gherkinDocument", void 0);
__decorate([
    Type(() => Hook)
], Envelope.prototype, "hook", void 0);
__decorate([
    Type(() => Meta)
], Envelope.prototype, "meta", void 0);
__decorate([
    Type(() => ParameterType)
], Envelope.prototype, "parameterType", void 0);
__decorate([
    Type(() => ParseError)
], Envelope.prototype, "parseError", void 0);
__decorate([
    Type(() => Pickle)
], Envelope.prototype, "pickle", void 0);
__decorate([
    Type(() => Source)
], Envelope.prototype, "source", void 0);
__decorate([
    Type(() => StepDefinition)
], Envelope.prototype, "stepDefinition", void 0);
__decorate([
    Type(() => TestCase)
], Envelope.prototype, "testCase", void 0);
__decorate([
    Type(() => TestCaseFinished)
], Envelope.prototype, "testCaseFinished", void 0);
__decorate([
    Type(() => TestCaseStarted)
], Envelope.prototype, "testCaseStarted", void 0);
__decorate([
    Type(() => TestRunFinished)
], Envelope.prototype, "testRunFinished", void 0);
__decorate([
    Type(() => TestRunStarted)
], Envelope.prototype, "testRunStarted", void 0);
__decorate([
    Type(() => TestStepFinished)
], Envelope.prototype, "testStepFinished", void 0);
__decorate([
    Type(() => TestStepStarted)
], Envelope.prototype, "testStepStarted", void 0);
__decorate([
    Type(() => UndefinedParameterType)
], Envelope.prototype, "undefinedParameterType", void 0);
export class GherkinDocument {
    constructor() {
        this.comments = [];
    }
}
__decorate([
    Type(() => Feature)
], GherkinDocument.prototype, "feature", void 0);
__decorate([
    Type(() => Comment)
], GherkinDocument.prototype, "comments", void 0);
export class Background {
    constructor() {
        this.location = new Location();
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.steps = [];
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Background.prototype, "location", void 0);
__decorate([
    Type(() => Step)
], Background.prototype, "steps", void 0);
export class Comment {
    constructor() {
        this.location = new Location();
        this.text = '';
    }
}
__decorate([
    Type(() => Location)
], Comment.prototype, "location", void 0);
export class DataTable {
    constructor() {
        this.location = new Location();
        this.rows = [];
    }
}
__decorate([
    Type(() => Location)
], DataTable.prototype, "location", void 0);
__decorate([
    Type(() => TableRow)
], DataTable.prototype, "rows", void 0);
export class DocString {
    constructor() {
        this.location = new Location();
        this.content = '';
        this.delimiter = '';
    }
}
__decorate([
    Type(() => Location)
], DocString.prototype, "location", void 0);
export class Examples {
    constructor() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.tableBody = [];
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Examples.prototype, "location", void 0);
__decorate([
    Type(() => Tag)
], Examples.prototype, "tags", void 0);
__decorate([
    Type(() => TableRow)
], Examples.prototype, "tableHeader", void 0);
__decorate([
    Type(() => TableRow)
], Examples.prototype, "tableBody", void 0);
export class Feature {
    constructor() {
        this.location = new Location();
        this.tags = [];
        this.language = '';
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.children = [];
    }
}
__decorate([
    Type(() => Location)
], Feature.prototype, "location", void 0);
__decorate([
    Type(() => Tag)
], Feature.prototype, "tags", void 0);
__decorate([
    Type(() => FeatureChild)
], Feature.prototype, "children", void 0);
export class FeatureChild {
}
__decorate([
    Type(() => Rule)
], FeatureChild.prototype, "rule", void 0);
__decorate([
    Type(() => Background)
], FeatureChild.prototype, "background", void 0);
__decorate([
    Type(() => Scenario)
], FeatureChild.prototype, "scenario", void 0);
export class Rule {
    constructor() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.children = [];
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Rule.prototype, "location", void 0);
__decorate([
    Type(() => Tag)
], Rule.prototype, "tags", void 0);
__decorate([
    Type(() => RuleChild)
], Rule.prototype, "children", void 0);
export class RuleChild {
}
__decorate([
    Type(() => Background)
], RuleChild.prototype, "background", void 0);
__decorate([
    Type(() => Scenario)
], RuleChild.prototype, "scenario", void 0);
export class Scenario {
    constructor() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.steps = [];
        this.examples = [];
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Scenario.prototype, "location", void 0);
__decorate([
    Type(() => Tag)
], Scenario.prototype, "tags", void 0);
__decorate([
    Type(() => Step)
], Scenario.prototype, "steps", void 0);
__decorate([
    Type(() => Examples)
], Scenario.prototype, "examples", void 0);
export class Step {
    constructor() {
        this.location = new Location();
        this.keyword = '';
        this.text = '';
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Step.prototype, "location", void 0);
__decorate([
    Type(() => DocString)
], Step.prototype, "docString", void 0);
__decorate([
    Type(() => DataTable)
], Step.prototype, "dataTable", void 0);
export class TableCell {
    constructor() {
        this.location = new Location();
        this.value = '';
    }
}
__decorate([
    Type(() => Location)
], TableCell.prototype, "location", void 0);
export class TableRow {
    constructor() {
        this.location = new Location();
        this.cells = [];
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], TableRow.prototype, "location", void 0);
__decorate([
    Type(() => TableCell)
], TableRow.prototype, "cells", void 0);
export class Tag {
    constructor() {
        this.location = new Location();
        this.name = '';
        this.id = '';
    }
}
__decorate([
    Type(() => Location)
], Tag.prototype, "location", void 0);
export class Hook {
    constructor() {
        this.id = '';
        this.sourceReference = new SourceReference();
    }
}
__decorate([
    Type(() => SourceReference)
], Hook.prototype, "sourceReference", void 0);
export class Location {
    constructor() {
        this.line = 0;
    }
}
export class Meta {
    constructor() {
        this.protocolVersion = '';
        this.implementation = new Product();
        this.runtime = new Product();
        this.os = new Product();
        this.cpu = new Product();
    }
}
__decorate([
    Type(() => Product)
], Meta.prototype, "implementation", void 0);
__decorate([
    Type(() => Product)
], Meta.prototype, "runtime", void 0);
__decorate([
    Type(() => Product)
], Meta.prototype, "os", void 0);
__decorate([
    Type(() => Product)
], Meta.prototype, "cpu", void 0);
__decorate([
    Type(() => Ci)
], Meta.prototype, "ci", void 0);
export class Ci {
    constructor() {
        this.name = '';
    }
}
__decorate([
    Type(() => Git)
], Ci.prototype, "git", void 0);
export class Git {
    constructor() {
        this.remote = '';
        this.revision = '';
    }
}
export class Product {
    constructor() {
        this.name = '';
    }
}
export class ParameterType {
    constructor() {
        this.name = '';
        this.regularExpressions = [];
        this.preferForRegularExpressionMatch = false;
        this.useForSnippets = false;
        this.id = '';
    }
}
export class ParseError {
    constructor() {
        this.source = new SourceReference();
        this.message = '';
    }
}
__decorate([
    Type(() => SourceReference)
], ParseError.prototype, "source", void 0);
export class Pickle {
    constructor() {
        this.id = '';
        this.uri = '';
        this.name = '';
        this.language = '';
        this.steps = [];
        this.tags = [];
        this.astNodeIds = [];
    }
}
__decorate([
    Type(() => PickleStep)
], Pickle.prototype, "steps", void 0);
__decorate([
    Type(() => PickleTag)
], Pickle.prototype, "tags", void 0);
export class PickleDocString {
    constructor() {
        this.content = '';
    }
}
export class PickleStep {
    constructor() {
        this.astNodeIds = [];
        this.id = '';
        this.text = '';
    }
}
__decorate([
    Type(() => PickleStepArgument)
], PickleStep.prototype, "argument", void 0);
export class PickleStepArgument {
}
__decorate([
    Type(() => PickleDocString)
], PickleStepArgument.prototype, "docString", void 0);
__decorate([
    Type(() => PickleTable)
], PickleStepArgument.prototype, "dataTable", void 0);
export class PickleTable {
    constructor() {
        this.rows = [];
    }
}
__decorate([
    Type(() => PickleTableRow)
], PickleTable.prototype, "rows", void 0);
export class PickleTableCell {
    constructor() {
        this.value = '';
    }
}
export class PickleTableRow {
    constructor() {
        this.cells = [];
    }
}
__decorate([
    Type(() => PickleTableCell)
], PickleTableRow.prototype, "cells", void 0);
export class PickleTag {
    constructor() {
        this.name = '';
        this.astNodeId = '';
    }
}
export class Source {
    constructor() {
        this.uri = '';
        this.data = '';
        this.mediaType = SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN;
    }
}
export class SourceReference {
}
__decorate([
    Type(() => JavaMethod)
], SourceReference.prototype, "javaMethod", void 0);
__decorate([
    Type(() => JavaStackTraceElement)
], SourceReference.prototype, "javaStackTraceElement", void 0);
__decorate([
    Type(() => Location)
], SourceReference.prototype, "location", void 0);
export class JavaMethod {
    constructor() {
        this.className = '';
        this.methodName = '';
        this.methodParameterTypes = [];
    }
}
export class JavaStackTraceElement {
    constructor() {
        this.className = '';
        this.fileName = '';
        this.methodName = '';
    }
}
export class StepDefinition {
    constructor() {
        this.id = '';
        this.pattern = new StepDefinitionPattern();
        this.sourceReference = new SourceReference();
    }
}
__decorate([
    Type(() => StepDefinitionPattern)
], StepDefinition.prototype, "pattern", void 0);
__decorate([
    Type(() => SourceReference)
], StepDefinition.prototype, "sourceReference", void 0);
export class StepDefinitionPattern {
    constructor() {
        this.source = '';
        this.type = StepDefinitionPatternType.CUCUMBER_EXPRESSION;
    }
}
export class TestCase {
    constructor() {
        this.id = '';
        this.pickleId = '';
        this.testSteps = [];
    }
}
__decorate([
    Type(() => TestStep)
], TestCase.prototype, "testSteps", void 0);
export class Group {
    constructor() {
        this.children = [];
    }
}
__decorate([
    Type(() => Group)
], Group.prototype, "children", void 0);
export class StepMatchArgument {
    constructor() {
        this.group = new Group();
    }
}
__decorate([
    Type(() => Group)
], StepMatchArgument.prototype, "group", void 0);
export class StepMatchArgumentsList {
    constructor() {
        this.stepMatchArguments = [];
    }
}
__decorate([
    Type(() => StepMatchArgument)
], StepMatchArgumentsList.prototype, "stepMatchArguments", void 0);
export class TestStep {
    constructor() {
        this.id = '';
    }
}
__decorate([
    Type(() => StepMatchArgumentsList)
], TestStep.prototype, "stepMatchArgumentsLists", void 0);
export class TestCaseFinished {
    constructor() {
        this.testCaseStartedId = '';
        this.timestamp = new Timestamp();
        this.willBeRetried = false;
    }
}
__decorate([
    Type(() => Timestamp)
], TestCaseFinished.prototype, "timestamp", void 0);
export class TestCaseStarted {
    constructor() {
        this.attempt = 0;
        this.id = '';
        this.testCaseId = '';
        this.timestamp = new Timestamp();
    }
}
__decorate([
    Type(() => Timestamp)
], TestCaseStarted.prototype, "timestamp", void 0);
export class TestRunFinished {
    constructor() {
        this.success = false;
        this.timestamp = new Timestamp();
    }
}
__decorate([
    Type(() => Timestamp)
], TestRunFinished.prototype, "timestamp", void 0);
export class TestRunStarted {
    constructor() {
        this.timestamp = new Timestamp();
    }
}
__decorate([
    Type(() => Timestamp)
], TestRunStarted.prototype, "timestamp", void 0);
export class TestStepFinished {
    constructor() {
        this.testCaseStartedId = '';
        this.testStepId = '';
        this.testStepResult = new TestStepResult();
        this.timestamp = new Timestamp();
    }
}
__decorate([
    Type(() => TestStepResult)
], TestStepFinished.prototype, "testStepResult", void 0);
__decorate([
    Type(() => Timestamp)
], TestStepFinished.prototype, "timestamp", void 0);
export class TestStepResult {
    constructor() {
        this.duration = new Duration();
        this.status = TestStepResultStatus.UNKNOWN;
    }
}
__decorate([
    Type(() => Duration)
], TestStepResult.prototype, "duration", void 0);
export class TestStepStarted {
    constructor() {
        this.testCaseStartedId = '';
        this.testStepId = '';
        this.timestamp = new Timestamp();
    }
}
__decorate([
    Type(() => Timestamp)
], TestStepStarted.prototype, "timestamp", void 0);
export class Timestamp {
    constructor() {
        this.seconds = 0;
        this.nanos = 0;
    }
}
export class UndefinedParameterType {
    constructor() {
        this.expression = '';
        this.name = '';
    }
}
export var AttachmentContentEncoding;
(function (AttachmentContentEncoding) {
    AttachmentContentEncoding["IDENTITY"] = "IDENTITY";
    AttachmentContentEncoding["BASE64"] = "BASE64";
})(AttachmentContentEncoding || (AttachmentContentEncoding = {}));
export var PickleStepType;
(function (PickleStepType) {
    PickleStepType["UNKNOWN"] = "Unknown";
    PickleStepType["CONTEXT"] = "Context";
    PickleStepType["ACTION"] = "Action";
    PickleStepType["OUTCOME"] = "Outcome";
})(PickleStepType || (PickleStepType = {}));
export var SourceMediaType;
(function (SourceMediaType) {
    SourceMediaType["TEXT_X_CUCUMBER_GHERKIN_PLAIN"] = "text/x.cucumber.gherkin+plain";
    SourceMediaType["TEXT_X_CUCUMBER_GHERKIN_MARKDOWN"] = "text/x.cucumber.gherkin+markdown";
})(SourceMediaType || (SourceMediaType = {}));
export var StepDefinitionPatternType;
(function (StepDefinitionPatternType) {
    StepDefinitionPatternType["CUCUMBER_EXPRESSION"] = "CUCUMBER_EXPRESSION";
    StepDefinitionPatternType["REGULAR_EXPRESSION"] = "REGULAR_EXPRESSION";
})(StepDefinitionPatternType || (StepDefinitionPatternType = {}));
export var StepKeywordType;
(function (StepKeywordType) {
    StepKeywordType["UNKNOWN"] = "Unknown";
    StepKeywordType["CONTEXT"] = "Context";
    StepKeywordType["ACTION"] = "Action";
    StepKeywordType["OUTCOME"] = "Outcome";
    StepKeywordType["CONJUNCTION"] = "Conjunction";
})(StepKeywordType || (StepKeywordType = {}));
export var TestStepResultStatus;
(function (TestStepResultStatus) {
    TestStepResultStatus["UNKNOWN"] = "UNKNOWN";
    TestStepResultStatus["PASSED"] = "PASSED";
    TestStepResultStatus["SKIPPED"] = "SKIPPED";
    TestStepResultStatus["PENDING"] = "PENDING";
    TestStepResultStatus["UNDEFINED"] = "UNDEFINED";
    TestStepResultStatus["AMBIGUOUS"] = "AMBIGUOUS";
    TestStepResultStatus["FAILED"] = "FAILED";
})(TestStepResultStatus || (TestStepResultStatus = {}));
//# sourceMappingURL=messages.js.map