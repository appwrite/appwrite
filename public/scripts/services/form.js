(function (window) {
    "use strict";

    window.ls.container.set('form', function () {

        function cast(value, from, to,) {
            if (value && Array.isArray(value) && to !== 'array') {
                value = value.map(element => cast(element, from, to));
                return value;
            }
            switch (to) {
                case 'int':
                case 'integer':
                    value = parseInt(value);
                    break;
                case 'numeric':
                    value = Number(value);
                    break;
                case 'float':
                    value = parseFloat(value);
                    break;
                case 'string':
                    value = value.toString();
                    if (value.length === 0) {
                        value = null;
                    }
                    break;
                case 'json':
                    value = (value) ? JSON.parse(value) : [];
                    break;
                case 'array':
                    if (value && value.constructor && value.constructor === Array) {
                        break;
                    }
                    if (from === 'csv') {
                        if (value.length === 0) {
                            value = [];
                        } else {
                            value = value.split(',');
                        }
                    } else {
                        value = [value];
                    }
                    break;
                case 'array-empty':
                    value = [];
                    break;
                case 'bool':
                case 'boolean':
                    value = (value === 'false') ? false : value;
                    value = !!value;
                    break;
            }

            return value;
        }

        function toJson(element, json) {
            json = json || {};
            let name = element.getAttribute('name');
            let type = element.getAttribute('type');
            let castTo = element.getAttribute('data-cast-to');
            let castFrom = element.getAttribute('data-cast-from');
            let ref = json;

            if (name && 'FORM' !== element.tagName) {
                if (name.startsWith('[')) { // Check for array names
                    let splitName = name.split('.');
                    if (splitName.length > 1 && splitName[0].endsWith(']')) {
                        name = splitName[splitName.length-1];
                    }
                }
                if ('FIELDSET' === element.tagName) { // Fieldset Array / Object
                    if (castTo === 'object') {

                        if (json[name] === undefined) {
                            json[name] = {};
                        }

                        ref = json[name];
                    }
                    else {
                        if (!Array.isArray(json[name])) {
                            json[name] = [];
                        }

                        json[name].push({});

                        ref = json[name][json[name].length - 1];
                    }
                }
                else if (undefined !== element.value) {
                    if ('SELECT' === element.tagName && element.children > 0) { // Select
                        json[name] = element.children[element.selectedIndex].value;
                    }
                    else if ('radio' === type) { // Radio
                        if (element.checked) {
                            json[name] = element.value;
                        }
                    }
                    else if ('checkbox' === type) { // Checkbox
                        const byKey = !!element.getAttribute('data-by-key');
                        if (byKey) {
                            if (!Array.isArray(json[name])) {
                                json[name] = [];
                            }

                            if (element.checked) {
                                json[name].push(element.value);
                            }
                        } else {
                            if (Array.isArray(json[name])) {
                                json[name].push(element.checked);
                            } else {
                                json[name] = element.checked;
                            }
                        }
                    }
                    else if ('file' === type) { // File upload
                        json[name] = element.files[0];
                    }
                    else if (undefined !== element.value) { // Normal

                        if ((json[name] !== undefined) && (!Array.isArray(json[name]))) { // Support for list array when name is repeating more than once
                            json[name] = [json[name]];
                        }

                        if (Array.isArray(json[name])) {
                            json[name].push(element.value);
                        }
                        else {
                            json[name] = element.value;
                        }
                    }

                    json[name] = cast(json[name], castFrom, castTo); // Apply casting
                }
            }

            for (let i = 0; i < element.children.length; i++) {
                if (Array.isArray(ref)) {
                    ref.push({});
                    toJson(element.children[i], ref[ref.length]);
                }
                else {
                    toJson(element.children[i], ref);
                }
            }

            return json;
        }

        return {
            'toJson': toJson
        }
    }, true, false);

})(window);
