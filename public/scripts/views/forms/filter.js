(function (window) {
    //"use strict";

    window.ls.container.get('view').add({
        selector: 'data-forms-filter',
        controller: function(document, container, expression, element, form, di) {
            let name    = element.dataset['formsFilter'] || '';
            let events  = element.dataset['event'] || '';

            let serialize = function(obj, prefix) {
                let str = [],
                    p;
                for (p in obj) {
                    if (obj.hasOwnProperty(p)) {
                        let k = prefix ? prefix + "[" + p + "]" : p,
                            v = obj[p];

                        if(v === '') {
                            continue;
                        }

                        str.push((v !== null && typeof v === "object") ?
                            serialize(v, k) :
                            encodeURIComponent(k) + "=" + encodeURIComponent(v));
                    }
                }
                return str.join("&");
            };

            let parse = function (filter) {
                if(filter === '') {
                    return null;
                }

                let operatorsMap = ["!=", ">=", "<=", "=", ">", "<"];

                let operator = null;

                for (let key = 0; key < operatorsMap.length; key++) {
                    if(filter.indexOf(operatorsMap[key]) > -1) {
                        operator = operatorsMap[key];
                    }
                }

                if (operator === null) {
                    throw new Error("Invalid operator");
                }

                filter = filter.split(operator);

                if (filter.length !== 2) {
                    throw new Error("Invalid filter expression");
                }

                return {
                    "key": filter[0],
                    "value": filter[1],
                    "operator": operator
                };
            };

            let flatten = function (params) {
                let list = {};

                for (let key in params) {
                    if (params.hasOwnProperty(key)) {
                        if(key !== 'filters') {
                            list[key] = params[key];
                        }
                        else {
                            for (let i = 0; i < params[key].length; i++) {
                                let filter = parse(params[key][i]);

                                if (null === filter) {
                                    continue;
                                }

                                list['filters-' + filter.key] = params[key][i];
                            }
                        }
                    }
                }

                return list;
            };

            let apply = function (params) {

                let cached = container.get(name);
                cached = (cached) ? cached.params : [];

                params = Object.assign(cached, params);

                container.set(name, {
                    name: name,
                    params: params,
                    query: serialize(params),
                    forward: parseInt(params.offset) + parseInt(params.limit),
                    backward: parseInt(params.offset) - parseInt(params.limit),
                    keys: flatten(params)
                }, true, name);

                document.dispatchEvent(new CustomEvent(name + '-changed', {
                    bubbles: false,
                    cancelable: true
                }));

                di.report(name + '-changed');
            };

            switch (element.tagName) {
                case 'INPUT':
                    break;
                case 'TEXTAREA':
                    break;
                case 'BUTTON':
                    element.addEventListener('click', function () {
                        apply(JSON.parse(expression.parse(element.dataset['params'] || '{}')));
                    });
                    break;
                case 'FORM':
                    element.addEventListener('input', function () {
                        apply(form.toJson(element));
                    });

                    element.addEventListener('change', function () {
                        apply(form.toJson(element));
                    });

                    element.addEventListener('reset', function () {
                        setTimeout(function () {
                            apply(form.toJson(element));

                        }, 0);

                    });

                    events = events.trim().split(',');

                    for (let y = 0; y < events.length; y++) {

                        if(events[y] === 'init') {
                            element.addEventListener('rendered', function () {
                                apply(form.toJson(element));
                            }, {once: true});
                        }
                        else {
                            di.listen(events[y], function (e) {
                                if(e) {
                                    e.target.removeEventListener(e.type, arguments.callee);
                                }

                                apply(form.toJson(element));
                            });

                            //document.addEventListener(events[y], function (e) {
                            //    e.target.removeEventListener(e.type, arguments.callee);
                            //    apply(form.toJson(element));
                            //});
                        }

                        element.setAttribute('data-event', 'none'); // Avoid re-attaching event
                    }
                    break;
                default:
                    break;
            }
        }
    });

})(window);