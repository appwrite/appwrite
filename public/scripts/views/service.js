(function(window) {
  "use strict";

  window.ls.view.add({
    selector: "data-service",
    controller: function(element, view, container, form, alerts, expression, window) {
      let action = element.dataset["service"];
      let service = element.dataset["name"] || null;
      let event = expression.parse(element.dataset["event"]); // load, click, change, submit
      let confirm = element.dataset["confirm"] || ""; // Free text
      let loading = element.dataset["loading"] || ""; // Free text
      let loaderId = null;
      let scope = element.dataset["scope"] || "sdk"; // Free text
      let success = element.dataset["success"] || "";
      let failure = element.dataset["failure"] || "";
      let running = false;

      let callbacks = {
        hide: function() {
          return function() {
            return element.style.opacity = '0';
          };
        },

        reset: function() {
          return function() {
            if ("FORM" === element.tagName) {
              return element.reset();
            }

            throw new Error("This callback is only valid for forms");
          };
        },

        alert: function(text, classname) {
          return function(alerts) {
            alerts.add({ text: text, class: classname || "success" }, 6000);
          };
        },

        redirect: function(url) {
          return function(router) {
            /**
             * Force page reload to /console to render the layout.
             */
            if (url === "/console") {
              window.location = url;
              return;
            }
            router.change(url || "/");
          };
        },

        reload: function() {
          return function(router) {
            router.reload();
          };
        },

        state: function(keys) {
          let updateQueryString = function(key, value, url) {
            var re = new RegExp("([?&])" + key + "=.*?(&|#|$)(.*)", "gi"),
              hash;

            if (re.test(url)) {
              if (typeof value !== "undefined" && value !== null) {
                return url.replace(re, "$1" + key + "=" + value + "$2$3");
              } else {
                hash = url.split("#");
                url = hash[0].replace(re, "$1$3").replace(/(&|\?)$/, "");
                if (typeof hash[1] !== "undefined" && hash[1] !== null) {
                  url += "#" + hash[1];
                }
                return url;
              }
            } else {
              if (typeof value !== "undefined" && value !== null) {
                var separator = url.indexOf("?") !== -1 ? "&" : "?";
                hash = url.split("#");
                url = hash[0] + separator + key + "=" + value;
                if (typeof hash[1] !== "undefined" && hash[1] !== null) {
                  url += "#" + hash[1];
                }
                return url;
              } else {
                return url;
              }
            }
          };

          keys = keys.split(",").map(element => element.trim());

          return function(serviceForm, router, window) {
            let url = window.location.href;

            keys.map(node => {
              node = node.split("=");

              let key = node[0] || "";
              let name = node[1] || key;

              let value = getValue(key, "param", serviceForm);
              url = updateQueryString(name, value ? value : null, url);
            });

            if (url !== window.location.href) {
              window.history.pushState({}, "", url);
              router.reset();
            }
          };
        },

        trigger: function(events) {
          return function(document) {
            events = events.trim().split(",");

            for (let i = 0; i < events.length; i++) {
              if ("" === events[i]) {
                continue;
              }

              document.dispatchEvent(new CustomEvent(events[i]));
            }
          };
        },

        setId: function name(params) {
          
        },

        default: function() {
          let collection = container.get('project-collection');
          let document = container.get('project-document');
          
          if(collection && document && collection.$id === document.$id) {
            for (const [key, value] of Object.entries(document)) {
              delete document[key];
            }

            if(collection.rules) {
              for (let index = 0; index < collection.rules.length; index++) {
                const element = collection.rules[index];

                switch (element.type) {
                  case 'text':
                  case 'email':
                  case 'url':
                  case 'ip':
                    document[element.key] = element.default || '';
                    break;

                  case 'numeric':
                    document[element.key] = element.default || '0';
                    break;

                  case 'boolean':
                    document[element.key] = element.default || false;
                    break;

                  case 'document':
                    document[element.key] = element.default || {'$id': '', '$collection': '', '$permissions': {}};
                    break;

                  default:
                    document[element.key] = null;
                    break;
                  }

                  if(element.array) {
                    document[element.key] = [];
                }
              }
            }
          }
        }
      };

      /**
       * Original Solution From:
       * @see https://stackoverflow.com/a/41322698/2299554
       *  Notice: this version add support for $ sign in arg name.
       *
       * Retrieve a function's parameter names and default values
       * Notes:
       *  - parameters with default values will not show up in transpiler code (Babel) because the parameter is removed from the function.
       *  - does NOT support inline arrow functions as default values
       *      to clarify: ( name = "string", add = defaultAddFunction )   - is ok
       *                  ( name = "string", add = ( a )=> a + 1 )        - is NOT ok
       *  - does NOT support default string value that are appended with a non-standard ( word characters or $ ) variable name
       *      to clarify: ( name = "string" + b )         - is ok
       *                  ( name = "string" + $b )        - is ok
       *                  ( name = "string" + b + "!" )   - is ok
       *                  ( name = "string" + λ )         - is NOT ok
       * @param {function} func
       * @returns {Array} - An array of the given function's parameter [key, default value] pairs.
       */
      let getParams = function getParams(func) {
        const REGEX_COMMENTS = /((\/\/.*$)|(\/\*[\s\S]*?\*\/))/gm;
        const REGEX_FUNCTION_PARAMS = /(?:\s*(?:function\s*[^(]*)?\s*)((?:[^'"]|(?:(?:(['"])(?:(?:.*?[^\\]\2)|\2))))*?)\s*(?=(?:=>)|{)/m;
        const REGEX_PARAMETERS_VALUES = /\s*([\w\\$]+)\s*(?:=\s*((?:(?:(['"])(?:\3|(?:.*?[^\\]\3)))((\s*\+\s*)(?:(?:(['"])(?:\6|(?:.*?[^\\]\6)))|(?:[\w$]*)))*)|.*?))?\s*(?:,|$)/gm;

        let functionAsString = func.toString();
        let params = [];
        let match;

        let indexOfArguments = functionAsString.indexOf('(');

        if (indexOfArguments !== -1) {
          functionAsString = functionAsString.slice(indexOfArguments, -1);
        }

        functionAsString = functionAsString.replaceAll('={}', "");
        functionAsString = functionAsString.replaceAll('=[]', "");
        functionAsString = functionAsString.replace(REGEX_COMMENTS, "");
        functionAsString = functionAsString.match(REGEX_FUNCTION_PARAMS)[1];

        if (functionAsString.charAt(0) === "(") {
          functionAsString = functionAsString.slice(1, -1);
        }

        while ((match = REGEX_PARAMETERS_VALUES.exec(functionAsString))) {
          //params.push([match[1], match[2]]); // with default values
          params.push(match[1]); // only with arg name
        }

        return params;
      };

      let getValue = function(key, prefix, data) {
        let result = null;

        if (!key) {
          return null;
        }

        let attrKey = prefix + key.charAt(0).toUpperCase() + key.slice(1);
        /**
         * 1. Get from element data-param-* (expression supported)
         * 2. Get from element form object-*
         */
        if (element.dataset[attrKey]) {
          result = expression.parse(element.dataset[attrKey]);

          if (element.dataset[attrKey + "CastTo"] === "array") {
            result = result.split(",");
          }
        }

        if (typeof data[key] !== 'undefined') {
          result = data[key];
        }

        if (typeof result === 'undefined') {
          result = "";
        }

        return result;
      };

      let resolve = function(target, prefix = "param", data = {}) {
        if (!target) {
          return function() {};
        }

        let args = getParams(target);

        return target.apply(
          container.get(scope),
          args.map(function(value) {
            let result = getValue(value, prefix, data);

            return result ?? undefined;
          })
        );
      };

      let exec = function(event) {

        let parsedSuccess = expression.parse(success);
        let parsedFailure = expression.parse(failure);
        let parsedAction = expression.parse(action);
        
        parsedSuccess =
          parsedSuccess && parsedSuccess != ""
            ? parsedSuccess.split(",").map(element => element.trim())
            : [];
        parsedFailure =
          parsedFailure && parsedFailure != ""
            ? parsedFailure.split(",").map(element => element.trim())
            : [];

        element.$lsSkip = true;

        element.classList.add("load-service-start");

        if (!document.body.contains(element)) {
          element = undefined;
          return false;
        }

        if (event) {
          event.preventDefault();
        }

        if(running) {
          return false;
        }

        running = true;
        element.style.backgroud = 'red';

        if (confirm) {
          if (window.confirm(confirm) !== true) {
            element.classList.add("load-service-end");
            element.$lsSkip = false;
            running = false;
            return false;
          }
        }

        if (loading) {
          loaderId = alerts.add({ text: loading, class: "" }, 0);
        }

        let method = container.path(scope + "." + parsedAction);
        
        if (!method) {
          throw new Error('Method "' + scope + "." + parsedAction + '" not found');
        }
        
        let formData = "FORM" === element.tagName ? form.toJson(element) : {};
        
        let result = resolve(method, "param", formData);

        if (!result) {
          return;
        }

        if(Promise.resolve(result) != result) {
          result = new Promise((resolve, reject) => {
            resolve(result);
          });
        }

        result.then(
          function(data) {
            if (loaderId !== null) {
              // Remove loader if needed
              alerts.remove(loaderId);
            }

            if (!element) {
              return;
            }

            running = false;
            element.style.backgroud = 'transparent';
            element.classList.add("load-service-end");

            if(service) {
              container.set(service.replace(".", "-"), data, true, true);
            }

            container.set("serviceData", data, true, true);
            container.set("serviceForm", formData, true, true);

            for (let i = 0; i < parsedSuccess.length; i++) {
              // Trigger success callbacks
              container.resolve(
                resolve(
                  callbacks[parsedSuccess[i]],
                  "successParam" +
                    parsedSuccess[i].charAt(0).toUpperCase() +
                    parsedSuccess[i].slice(1),
                  {}
                )
              );
            }

            container.set("serviceData", null, true, true);
            container.set("serviceForm", null, true, true);

            element.$lsSkip = false;

            view.render(element);
          },
          function(exception) {
            console.error(exception);
            if (loaderId !== null) {
              // Remove loader if needed
              alerts.remove(loaderId);
            }

            if (!element) {
              return;
            }

            running = false;
            element.style.backgroud = 'transparent';
            element.classList.add("load-service-end");

            for (let i = 0; i < parsedFailure.length; i++) {
              // Trigger failure callbacks
              container.resolve(
                resolve(
                  callbacks[parsedFailure[i]],
                  "failureParam" +
                    parsedFailure[i].charAt(0).toUpperCase() +
                    parsedFailure[i].slice(1),
                    {
                      text: exception.message ?? undefined
                    }
                )
              );
            }

            element.$lsSkip = false;

            view.render(element);
          }
        );
      };

      let events = event.trim().split(",");

      for (let y = 0; y < events.length; y++) {
        if ("" === events[y]) {
          continue;
        }

        switch (events[y].trim()) {
          case "load":
            exec();
            break;
          case "none":
            break;
          case "click":
          case "change":
          case "keypress":
          case "keydown":
          case "keyup":
          case "input":
          case "submit":
            element.addEventListener(events[y], exec);
            break;
          default:
            document.addEventListener(events[y], exec);
        }
      }
    }
  });
})(window);
