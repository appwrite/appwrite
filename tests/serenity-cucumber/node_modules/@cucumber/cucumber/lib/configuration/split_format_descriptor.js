"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.splitFormatDescriptor = void 0;
function splitFormatDescriptor(logger, option) {
    const doWarning = (result) => {
        let expected = `"${result[0]}"`;
        if (result[1]) {
            expected += `:"${result[1]}"`;
        }
        logger.warn(`Each part of a user-specified format should be quoted; see https://github.com/cucumber/cucumber-js/blob/main/docs/deprecations.md#ambiguous-colons-in-formats
Change to ${expected}`);
    };
    let result;
    let match1, match2;
    // "foo":"bar" or "foo":bar
    if ((match1 = option.match(/^"([^"]*)":(.*)$/)) !== null) {
        // "foo":"bar"
        if ((match2 = match1[2].match(/^"([^"]*)"$/)) !== null) {
            result = [match1[1], match2[1]];
        }
        // "foo":bar
        else {
            result = [match1[1], match1[2]];
            if (result[1].includes(':')) {
                doWarning(result);
            }
        }
    }
    // foo:"bar"
    else if ((match1 = option.match(/^(.*):"([^"]*)"$/)) !== null) {
        result = [match1[1], match1[2]];
        if (result[0].includes(':')) {
            doWarning(result);
        }
    }
    // "foo"
    else if ((match1 = option.match(/^"([^"]*)"$/)) !== null) {
        result = [match1[1], ''];
    }
    // file://foo or file:///foo or file://C:/foo or file://C:\foo or file:///C:/foo or file:///C:\foo
    else if ((match1 = option.match(/^(file:\/{2,3}(?:[a-zA-Z]:[/\\])?)(.*)$/)) !== null) {
        // file://foo:bar
        if ((match2 = match1[2].match(/^([^:]*):(.*)$/)) !== null) {
            result = [match1[1] + match2[1], match2[2]];
        }
        else {
            result = [option, ''];
        }
        doWarning(result);
    }
    // C:\foo or C:/foo
    else if ((match1 = option.match(/^([a-zA-Z]:[/\\])(.*)$/)) !== null) {
        // C:\foo:bar or C:/foo:bar
        if ((match2 = match1[2].match(/^([^:]*):(.*)$/)) !== null) {
            result = [match1[1] + match2[1], match2[2]];
        }
        else {
            result = [option, ''];
        }
        doWarning(result);
    }
    // foo:bar
    else if ((match1 = option.match(/^([^:]*):(.*)$/)) !== null) {
        result = [match1[1], match1[2]];
        if (option.split(':').length > 2) {
            doWarning(result);
        }
    }
    // foo
    else {
        result = [option, ''];
    }
    return result;
}
exports.splitFormatDescriptor = splitFormatDescriptor;
//# sourceMappingURL=split_format_descriptor.js.map