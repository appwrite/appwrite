"use strict";
var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.TestRunStarted = exports.TestRunFinished = exports.TestCaseStarted = exports.TestCaseFinished = exports.TestStep = exports.StepMatchArgumentsList = exports.StepMatchArgument = exports.Group = exports.TestCase = exports.StepDefinitionPattern = exports.StepDefinition = exports.JavaStackTraceElement = exports.JavaMethod = exports.SourceReference = exports.Source = exports.PickleTag = exports.PickleTableRow = exports.PickleTableCell = exports.PickleTable = exports.PickleStepArgument = exports.PickleStep = exports.PickleDocString = exports.Pickle = exports.ParseError = exports.ParameterType = exports.Product = exports.Git = exports.Ci = exports.Meta = exports.Location = exports.Hook = exports.Tag = exports.TableRow = exports.TableCell = exports.Step = exports.Scenario = exports.RuleChild = exports.Rule = exports.FeatureChild = exports.Feature = exports.Examples = exports.DocString = exports.DataTable = exports.Comment = exports.Background = exports.GherkinDocument = exports.Exception = exports.Envelope = exports.Duration = exports.Attachment = void 0;
exports.TestStepResultStatus = exports.StepKeywordType = exports.StepDefinitionPatternType = exports.SourceMediaType = exports.PickleStepType = exports.AttachmentContentEncoding = exports.UndefinedParameterType = exports.Timestamp = exports.TestStepStarted = exports.TestStepResult = exports.TestStepFinished = void 0;
var class_transformer_1 = require("class-transformer");
require("reflect-metadata");
var Attachment = exports.Attachment = /** @class */ (function () {
    function Attachment() {
        this.body = '';
        this.contentEncoding = AttachmentContentEncoding.IDENTITY;
        this.mediaType = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Source; })
    ], Attachment.prototype, "source", void 0);
    return Attachment;
}());
var Duration = /** @class */ (function () {
    function Duration() {
        this.seconds = 0;
        this.nanos = 0;
    }
    return Duration;
}());
exports.Duration = Duration;
var Envelope = exports.Envelope = /** @class */ (function () {
    function Envelope() {
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Attachment; })
    ], Envelope.prototype, "attachment", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return GherkinDocument; })
    ], Envelope.prototype, "gherkinDocument", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Hook; })
    ], Envelope.prototype, "hook", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Meta; })
    ], Envelope.prototype, "meta", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return ParameterType; })
    ], Envelope.prototype, "parameterType", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return ParseError; })
    ], Envelope.prototype, "parseError", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Pickle; })
    ], Envelope.prototype, "pickle", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Source; })
    ], Envelope.prototype, "source", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return StepDefinition; })
    ], Envelope.prototype, "stepDefinition", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestCase; })
    ], Envelope.prototype, "testCase", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestCaseFinished; })
    ], Envelope.prototype, "testCaseFinished", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestCaseStarted; })
    ], Envelope.prototype, "testCaseStarted", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestRunFinished; })
    ], Envelope.prototype, "testRunFinished", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestRunStarted; })
    ], Envelope.prototype, "testRunStarted", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestStepFinished; })
    ], Envelope.prototype, "testStepFinished", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestStepStarted; })
    ], Envelope.prototype, "testStepStarted", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return UndefinedParameterType; })
    ], Envelope.prototype, "undefinedParameterType", void 0);
    return Envelope;
}());
var Exception = /** @class */ (function () {
    function Exception() {
        this.type = '';
    }
    return Exception;
}());
exports.Exception = Exception;
var GherkinDocument = exports.GherkinDocument = /** @class */ (function () {
    function GherkinDocument() {
        this.comments = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Feature; })
    ], GherkinDocument.prototype, "feature", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Comment; })
    ], GherkinDocument.prototype, "comments", void 0);
    return GherkinDocument;
}());
var Background = exports.Background = /** @class */ (function () {
    function Background() {
        this.location = new Location();
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.steps = [];
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Background.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Step; })
    ], Background.prototype, "steps", void 0);
    return Background;
}());
var Comment = exports.Comment = /** @class */ (function () {
    function Comment() {
        this.location = new Location();
        this.text = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Comment.prototype, "location", void 0);
    return Comment;
}());
var DataTable = exports.DataTable = /** @class */ (function () {
    function DataTable() {
        this.location = new Location();
        this.rows = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], DataTable.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TableRow; })
    ], DataTable.prototype, "rows", void 0);
    return DataTable;
}());
var DocString = exports.DocString = /** @class */ (function () {
    function DocString() {
        this.location = new Location();
        this.content = '';
        this.delimiter = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], DocString.prototype, "location", void 0);
    return DocString;
}());
var Examples = exports.Examples = /** @class */ (function () {
    function Examples() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.tableBody = [];
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Examples.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Tag; })
    ], Examples.prototype, "tags", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TableRow; })
    ], Examples.prototype, "tableHeader", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TableRow; })
    ], Examples.prototype, "tableBody", void 0);
    return Examples;
}());
var Feature = exports.Feature = /** @class */ (function () {
    function Feature() {
        this.location = new Location();
        this.tags = [];
        this.language = '';
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.children = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Feature.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Tag; })
    ], Feature.prototype, "tags", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return FeatureChild; })
    ], Feature.prototype, "children", void 0);
    return Feature;
}());
var FeatureChild = exports.FeatureChild = /** @class */ (function () {
    function FeatureChild() {
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Rule; })
    ], FeatureChild.prototype, "rule", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Background; })
    ], FeatureChild.prototype, "background", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Scenario; })
    ], FeatureChild.prototype, "scenario", void 0);
    return FeatureChild;
}());
var Rule = exports.Rule = /** @class */ (function () {
    function Rule() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.children = [];
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Rule.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Tag; })
    ], Rule.prototype, "tags", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return RuleChild; })
    ], Rule.prototype, "children", void 0);
    return Rule;
}());
var RuleChild = exports.RuleChild = /** @class */ (function () {
    function RuleChild() {
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Background; })
    ], RuleChild.prototype, "background", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Scenario; })
    ], RuleChild.prototype, "scenario", void 0);
    return RuleChild;
}());
var Scenario = exports.Scenario = /** @class */ (function () {
    function Scenario() {
        this.location = new Location();
        this.tags = [];
        this.keyword = '';
        this.name = '';
        this.description = '';
        this.steps = [];
        this.examples = [];
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Scenario.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Tag; })
    ], Scenario.prototype, "tags", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Step; })
    ], Scenario.prototype, "steps", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Examples; })
    ], Scenario.prototype, "examples", void 0);
    return Scenario;
}());
var Step = exports.Step = /** @class */ (function () {
    function Step() {
        this.location = new Location();
        this.keyword = '';
        this.text = '';
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Step.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return DocString; })
    ], Step.prototype, "docString", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return DataTable; })
    ], Step.prototype, "dataTable", void 0);
    return Step;
}());
var TableCell = exports.TableCell = /** @class */ (function () {
    function TableCell() {
        this.location = new Location();
        this.value = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], TableCell.prototype, "location", void 0);
    return TableCell;
}());
var TableRow = exports.TableRow = /** @class */ (function () {
    function TableRow() {
        this.location = new Location();
        this.cells = [];
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], TableRow.prototype, "location", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return TableCell; })
    ], TableRow.prototype, "cells", void 0);
    return TableRow;
}());
var Tag = exports.Tag = /** @class */ (function () {
    function Tag() {
        this.location = new Location();
        this.name = '';
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], Tag.prototype, "location", void 0);
    return Tag;
}());
var Hook = exports.Hook = /** @class */ (function () {
    function Hook() {
        this.id = '';
        this.sourceReference = new SourceReference();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return SourceReference; })
    ], Hook.prototype, "sourceReference", void 0);
    return Hook;
}());
var Location = /** @class */ (function () {
    function Location() {
        this.line = 0;
    }
    return Location;
}());
exports.Location = Location;
var Meta = exports.Meta = /** @class */ (function () {
    function Meta() {
        this.protocolVersion = '';
        this.implementation = new Product();
        this.runtime = new Product();
        this.os = new Product();
        this.cpu = new Product();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Product; })
    ], Meta.prototype, "implementation", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Product; })
    ], Meta.prototype, "runtime", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Product; })
    ], Meta.prototype, "os", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Product; })
    ], Meta.prototype, "cpu", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Ci; })
    ], Meta.prototype, "ci", void 0);
    return Meta;
}());
var Ci = exports.Ci = /** @class */ (function () {
    function Ci() {
        this.name = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Git; })
    ], Ci.prototype, "git", void 0);
    return Ci;
}());
var Git = /** @class */ (function () {
    function Git() {
        this.remote = '';
        this.revision = '';
    }
    return Git;
}());
exports.Git = Git;
var Product = /** @class */ (function () {
    function Product() {
        this.name = '';
    }
    return Product;
}());
exports.Product = Product;
var ParameterType = exports.ParameterType = /** @class */ (function () {
    function ParameterType() {
        this.name = '';
        this.regularExpressions = [];
        this.preferForRegularExpressionMatch = false;
        this.useForSnippets = false;
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return SourceReference; })
    ], ParameterType.prototype, "sourceReference", void 0);
    return ParameterType;
}());
var ParseError = exports.ParseError = /** @class */ (function () {
    function ParseError() {
        this.source = new SourceReference();
        this.message = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return SourceReference; })
    ], ParseError.prototype, "source", void 0);
    return ParseError;
}());
var Pickle = exports.Pickle = /** @class */ (function () {
    function Pickle() {
        this.id = '';
        this.uri = '';
        this.name = '';
        this.language = '';
        this.steps = [];
        this.tags = [];
        this.astNodeIds = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleStep; })
    ], Pickle.prototype, "steps", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleTag; })
    ], Pickle.prototype, "tags", void 0);
    return Pickle;
}());
var PickleDocString = /** @class */ (function () {
    function PickleDocString() {
        this.content = '';
    }
    return PickleDocString;
}());
exports.PickleDocString = PickleDocString;
var PickleStep = exports.PickleStep = /** @class */ (function () {
    function PickleStep() {
        this.astNodeIds = [];
        this.id = '';
        this.text = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleStepArgument; })
    ], PickleStep.prototype, "argument", void 0);
    return PickleStep;
}());
var PickleStepArgument = exports.PickleStepArgument = /** @class */ (function () {
    function PickleStepArgument() {
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleDocString; })
    ], PickleStepArgument.prototype, "docString", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleTable; })
    ], PickleStepArgument.prototype, "dataTable", void 0);
    return PickleStepArgument;
}());
var PickleTable = exports.PickleTable = /** @class */ (function () {
    function PickleTable() {
        this.rows = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleTableRow; })
    ], PickleTable.prototype, "rows", void 0);
    return PickleTable;
}());
var PickleTableCell = /** @class */ (function () {
    function PickleTableCell() {
        this.value = '';
    }
    return PickleTableCell;
}());
exports.PickleTableCell = PickleTableCell;
var PickleTableRow = exports.PickleTableRow = /** @class */ (function () {
    function PickleTableRow() {
        this.cells = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return PickleTableCell; })
    ], PickleTableRow.prototype, "cells", void 0);
    return PickleTableRow;
}());
var PickleTag = /** @class */ (function () {
    function PickleTag() {
        this.name = '';
        this.astNodeId = '';
    }
    return PickleTag;
}());
exports.PickleTag = PickleTag;
var Source = /** @class */ (function () {
    function Source() {
        this.uri = '';
        this.data = '';
        this.mediaType = SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN;
    }
    return Source;
}());
exports.Source = Source;
var SourceReference = exports.SourceReference = /** @class */ (function () {
    function SourceReference() {
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return JavaMethod; })
    ], SourceReference.prototype, "javaMethod", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return JavaStackTraceElement; })
    ], SourceReference.prototype, "javaStackTraceElement", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Location; })
    ], SourceReference.prototype, "location", void 0);
    return SourceReference;
}());
var JavaMethod = /** @class */ (function () {
    function JavaMethod() {
        this.className = '';
        this.methodName = '';
        this.methodParameterTypes = [];
    }
    return JavaMethod;
}());
exports.JavaMethod = JavaMethod;
var JavaStackTraceElement = /** @class */ (function () {
    function JavaStackTraceElement() {
        this.className = '';
        this.fileName = '';
        this.methodName = '';
    }
    return JavaStackTraceElement;
}());
exports.JavaStackTraceElement = JavaStackTraceElement;
var StepDefinition = exports.StepDefinition = /** @class */ (function () {
    function StepDefinition() {
        this.id = '';
        this.pattern = new StepDefinitionPattern();
        this.sourceReference = new SourceReference();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return StepDefinitionPattern; })
    ], StepDefinition.prototype, "pattern", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return SourceReference; })
    ], StepDefinition.prototype, "sourceReference", void 0);
    return StepDefinition;
}());
var StepDefinitionPattern = /** @class */ (function () {
    function StepDefinitionPattern() {
        this.source = '';
        this.type = StepDefinitionPatternType.CUCUMBER_EXPRESSION;
    }
    return StepDefinitionPattern;
}());
exports.StepDefinitionPattern = StepDefinitionPattern;
var TestCase = exports.TestCase = /** @class */ (function () {
    function TestCase() {
        this.id = '';
        this.pickleId = '';
        this.testSteps = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestStep; })
    ], TestCase.prototype, "testSteps", void 0);
    return TestCase;
}());
var Group = exports.Group = /** @class */ (function () {
    function Group() {
        this.children = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Group; })
    ], Group.prototype, "children", void 0);
    return Group;
}());
var StepMatchArgument = exports.StepMatchArgument = /** @class */ (function () {
    function StepMatchArgument() {
        this.group = new Group();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Group; })
    ], StepMatchArgument.prototype, "group", void 0);
    return StepMatchArgument;
}());
var StepMatchArgumentsList = exports.StepMatchArgumentsList = /** @class */ (function () {
    function StepMatchArgumentsList() {
        this.stepMatchArguments = [];
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return StepMatchArgument; })
    ], StepMatchArgumentsList.prototype, "stepMatchArguments", void 0);
    return StepMatchArgumentsList;
}());
var TestStep = exports.TestStep = /** @class */ (function () {
    function TestStep() {
        this.id = '';
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return StepMatchArgumentsList; })
    ], TestStep.prototype, "stepMatchArgumentsLists", void 0);
    return TestStep;
}());
var TestCaseFinished = exports.TestCaseFinished = /** @class */ (function () {
    function TestCaseFinished() {
        this.testCaseStartedId = '';
        this.timestamp = new Timestamp();
        this.willBeRetried = false;
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestCaseFinished.prototype, "timestamp", void 0);
    return TestCaseFinished;
}());
var TestCaseStarted = exports.TestCaseStarted = /** @class */ (function () {
    function TestCaseStarted() {
        this.attempt = 0;
        this.id = '';
        this.testCaseId = '';
        this.timestamp = new Timestamp();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestCaseStarted.prototype, "timestamp", void 0);
    return TestCaseStarted;
}());
var TestRunFinished = exports.TestRunFinished = /** @class */ (function () {
    function TestRunFinished() {
        this.success = false;
        this.timestamp = new Timestamp();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestRunFinished.prototype, "timestamp", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Exception; })
    ], TestRunFinished.prototype, "exception", void 0);
    return TestRunFinished;
}());
var TestRunStarted = exports.TestRunStarted = /** @class */ (function () {
    function TestRunStarted() {
        this.timestamp = new Timestamp();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestRunStarted.prototype, "timestamp", void 0);
    return TestRunStarted;
}());
var TestStepFinished = exports.TestStepFinished = /** @class */ (function () {
    function TestStepFinished() {
        this.testCaseStartedId = '';
        this.testStepId = '';
        this.testStepResult = new TestStepResult();
        this.timestamp = new Timestamp();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return TestStepResult; })
    ], TestStepFinished.prototype, "testStepResult", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestStepFinished.prototype, "timestamp", void 0);
    return TestStepFinished;
}());
var TestStepResult = exports.TestStepResult = /** @class */ (function () {
    function TestStepResult() {
        this.duration = new Duration();
        this.status = TestStepResultStatus.UNKNOWN;
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Duration; })
    ], TestStepResult.prototype, "duration", void 0);
    __decorate([
        (0, class_transformer_1.Type)(function () { return Exception; })
    ], TestStepResult.prototype, "exception", void 0);
    return TestStepResult;
}());
var TestStepStarted = exports.TestStepStarted = /** @class */ (function () {
    function TestStepStarted() {
        this.testCaseStartedId = '';
        this.testStepId = '';
        this.timestamp = new Timestamp();
    }
    __decorate([
        (0, class_transformer_1.Type)(function () { return Timestamp; })
    ], TestStepStarted.prototype, "timestamp", void 0);
    return TestStepStarted;
}());
var Timestamp = /** @class */ (function () {
    function Timestamp() {
        this.seconds = 0;
        this.nanos = 0;
    }
    return Timestamp;
}());
exports.Timestamp = Timestamp;
var UndefinedParameterType = /** @class */ (function () {
    function UndefinedParameterType() {
        this.expression = '';
        this.name = '';
    }
    return UndefinedParameterType;
}());
exports.UndefinedParameterType = UndefinedParameterType;
var AttachmentContentEncoding;
(function (AttachmentContentEncoding) {
    AttachmentContentEncoding["IDENTITY"] = "IDENTITY";
    AttachmentContentEncoding["BASE64"] = "BASE64";
})(AttachmentContentEncoding = exports.AttachmentContentEncoding || (exports.AttachmentContentEncoding = {}));
var PickleStepType;
(function (PickleStepType) {
    PickleStepType["UNKNOWN"] = "Unknown";
    PickleStepType["CONTEXT"] = "Context";
    PickleStepType["ACTION"] = "Action";
    PickleStepType["OUTCOME"] = "Outcome";
})(PickleStepType = exports.PickleStepType || (exports.PickleStepType = {}));
var SourceMediaType;
(function (SourceMediaType) {
    SourceMediaType["TEXT_X_CUCUMBER_GHERKIN_PLAIN"] = "text/x.cucumber.gherkin+plain";
    SourceMediaType["TEXT_X_CUCUMBER_GHERKIN_MARKDOWN"] = "text/x.cucumber.gherkin+markdown";
})(SourceMediaType = exports.SourceMediaType || (exports.SourceMediaType = {}));
var StepDefinitionPatternType;
(function (StepDefinitionPatternType) {
    StepDefinitionPatternType["CUCUMBER_EXPRESSION"] = "CUCUMBER_EXPRESSION";
    StepDefinitionPatternType["REGULAR_EXPRESSION"] = "REGULAR_EXPRESSION";
})(StepDefinitionPatternType = exports.StepDefinitionPatternType || (exports.StepDefinitionPatternType = {}));
var StepKeywordType;
(function (StepKeywordType) {
    StepKeywordType["UNKNOWN"] = "Unknown";
    StepKeywordType["CONTEXT"] = "Context";
    StepKeywordType["ACTION"] = "Action";
    StepKeywordType["OUTCOME"] = "Outcome";
    StepKeywordType["CONJUNCTION"] = "Conjunction";
})(StepKeywordType = exports.StepKeywordType || (exports.StepKeywordType = {}));
var TestStepResultStatus;
(function (TestStepResultStatus) {
    TestStepResultStatus["UNKNOWN"] = "UNKNOWN";
    TestStepResultStatus["PASSED"] = "PASSED";
    TestStepResultStatus["SKIPPED"] = "SKIPPED";
    TestStepResultStatus["PENDING"] = "PENDING";
    TestStepResultStatus["UNDEFINED"] = "UNDEFINED";
    TestStepResultStatus["AMBIGUOUS"] = "AMBIGUOUS";
    TestStepResultStatus["FAILED"] = "FAILED";
})(TestStepResultStatus = exports.TestStepResultStatus || (exports.TestStepResultStatus = {}));
//# sourceMappingURL=messages.js.map