"use strict";
var __assign = (this && this.__assign) || function () {
    __assign = Object.assign || function(t) {
        for (var s, i = 1, n = arguments.length; i < n; i++) {
            s = arguments[i];
            for (var p in s) if (Object.prototype.hasOwnProperty.call(s, p))
                t[p] = s[p];
        }
        return t;
    };
    return __assign.apply(this, arguments);
};
var __values = (this && this.__values) || function(o) {
    var s = typeof Symbol === "function" && Symbol.iterator, m = s && o[s], i = 0;
    if (m) return m.call(o);
    if (o && typeof o.length === "number") return {
        next: function () {
            if (o && i >= o.length) o = void 0;
            return { value: o && o[i++], done: !o };
        }
    };
    throw new TypeError(s ? "Object is not iterable." : "Symbol.iterator is not defined.");
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.removeUserInfoFromUrl = void 0;
var fs_1 = require("fs");
var CiEnvironments_js_1 = require("./CiEnvironments.js");
var evaluateVariableExpression_js_1 = __importDefault(require("./evaluateVariableExpression.js"));
function detectCiEnvironment(env) {
    var e_1, _a;
    try {
        for (var CiEnvironments_1 = __values(CiEnvironments_js_1.CiEnvironments), CiEnvironments_1_1 = CiEnvironments_1.next(); !CiEnvironments_1_1.done; CiEnvironments_1_1 = CiEnvironments_1.next()) {
            var ciEnvironment = CiEnvironments_1_1.value;
            var detected = detect(ciEnvironment, env);
            if (detected) {
                return detected;
            }
        }
    }
    catch (e_1_1) { e_1 = { error: e_1_1 }; }
    finally {
        try {
            if (CiEnvironments_1_1 && !CiEnvironments_1_1.done && (_a = CiEnvironments_1.return)) _a.call(CiEnvironments_1);
        }
        finally { if (e_1) throw e_1.error; }
    }
}
exports.default = detectCiEnvironment;
function removeUserInfoFromUrl(value) {
    if (!value)
        return value;
    try {
        var url = new URL(value);
        url.password = '';
        url.username = '';
        return url.toString();
    }
    catch (ignore) {
        return value;
    }
}
exports.removeUserInfoFromUrl = removeUserInfoFromUrl;
function detectGit(ciEnvironment, env) {
    var _a, _b, _c;
    var revision = detectRevision(ciEnvironment, env);
    if (!revision) {
        return undefined;
    }
    var remote = (0, evaluateVariableExpression_js_1.default)((_a = ciEnvironment.git) === null || _a === void 0 ? void 0 : _a.remote, env);
    if (!remote) {
        return undefined;
    }
    var tag = (0, evaluateVariableExpression_js_1.default)((_b = ciEnvironment.git) === null || _b === void 0 ? void 0 : _b.tag, env);
    var branch = (0, evaluateVariableExpression_js_1.default)((_c = ciEnvironment.git) === null || _c === void 0 ? void 0 : _c.branch, env);
    return __assign(__assign({ revision: revision, remote: removeUserInfoFromUrl(remote) }, (tag && { tag: tag })), (branch && { branch: branch }));
}
function detectRevision(ciEnvironment, env) {
    var _a, _b, _c;
    if (env.GITHUB_EVENT_NAME === 'pull_request') {
        if (!env.GITHUB_EVENT_PATH)
            throw new Error('GITHUB_EVENT_PATH not set');
        var json = (0, fs_1.readFileSync)(env.GITHUB_EVENT_PATH, 'utf-8');
        var event_1 = JSON.parse(json);
        var revision = (_b = (_a = event_1.pull_request) === null || _a === void 0 ? void 0 : _a.head) === null || _b === void 0 ? void 0 : _b.sha;
        if (!revision) {
            throw new Error("Could not find .pull_request.head.sha in ".concat(env.GITHUB_EVENT_PATH, ":\n").concat(JSON.stringify(event_1, null, 2)));
        }
        return revision;
    }
    return (0, evaluateVariableExpression_js_1.default)((_c = ciEnvironment.git) === null || _c === void 0 ? void 0 : _c.revision, env);
}
function detect(ciEnvironment, env) {
    var url = (0, evaluateVariableExpression_js_1.default)(ciEnvironment.url, env);
    if (url === undefined) {
        // The url is what consumers will use as the primary key for a build
        // If this cannot be determined, we return nothing.
        return undefined;
    }
    var buildNumber = (0, evaluateVariableExpression_js_1.default)(ciEnvironment.buildNumber, env);
    var git = detectGit(ciEnvironment, env);
    return __assign({ name: ciEnvironment.name, url: url, buildNumber: buildNumber }, (git && { git: git }));
}
//# sourceMappingURL=detectCiEnvironment.js.map