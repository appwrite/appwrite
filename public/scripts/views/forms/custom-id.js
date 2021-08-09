(function (window) {
  "use strict";
  window.ls.container.get("view").add({
    selector: "data-custom-id",
    controller: function (element, sdk, console, window) {
      let prevData = "";
      let idType = element.getAttribute('data-id-type');
      let disableSwitch = element.getAttribute('data-disable-switch');

      const div = window.document.createElement("div");
      if(disableSwitch !== "true") {
        div.className = "input-copy";
      }

      const button = window.document.createElement("i");
      button.type = "button";
      button.style.cursor = "pointer";

      const writer = window.document.createElement("input");
      writer.type = "text";
      writer.setAttribute("maxlength", element.getAttribute("maxlength"));
      const placeholder = element.getAttribute("placeholder");
      if (placeholder) {
        writer.setAttribute("placeholder", placeholder);
      }

      const info = window.document.createElement("div");
      info.className = "text-fade text-size-xs margin-top-negative-small margin-bottom";

      div.appendChild(writer);
      if(disableSwitch !== "true") {
        div.appendChild(button);
      }
      element.parentNode.insertBefore(div, element);
      element.parentNode.insertBefore(info, div.nextSibling);

      const switchType = function (event) {
        if (idType == "custom") {
          idType = "auto";
          setIdType(idType);
        } else {
          idType = "custom";
          setIdType(idType);
        }
      }

      const validate = function (event) {
        const [service, method] = element.dataset["validator"].split('.');
        const value = event.target.value;
        if (value.length < 1) {
          event.target.setCustomValidity("ID is required");
        } else {
          switch (service) {
            case 'projects':
              setValidity(console[service][method](value), event.target);
              break;
            default:
              setValidity(sdk[service][method](value), event.target);
          }
        }
      }

      const setValidity = async function (promise, target) {
        try {
          await promise;
          target.setCustomValidity("ID already exists");
        } catch (e) {
          target.setCustomValidity("");
        }
      }

      const setIdType = function (idType) {
        if (idType == "custom") {
          element.setAttribute("data-id-type", idType);
          info.innerHTML = "Allowed Characters A-Z, a-z, 0-9, and non-leading underscore";
          if (prevData === 'auto-generated') {
            prevData = ""
          }
          writer.setAttribute("value", prevData);
          writer.value = prevData;
          element.value = prevData;
          writer.removeAttribute("disabled");
          writer.focus();
          writer.addEventListener('blur', validate);
        } else {
          idType = 'auto'
          element.setAttribute("data-id-type", idType);
          info.innerHTML = "Appwrite will generate a unique ID";
          prevData = writer.value;
          writer.setAttribute("disabled", true);
          writer.setAttribute("value", "auto-generated");
          writer.value = "auto-generated";
          element.value = 'unique()';
        }
        button.className = idType == "custom" ? "icon-shuffle copy" : "icon-edit copy";
      }

      const syncEditorWithID = function (event) {
        if (element.value !== 'unique()' || idType != 'auto') {
          writer.value = element.value;
        }
        if (idType == 'auto') {
          element.value = 'unique()';
        }
      }

      const keypress = function (e) {
        // which key is pressed, keyPressed = e.which || e.keyCode; 
        const key = e.which || e.keyCode;
        const ZERO = 48;
        const NINE = 57;
        const SMALL_A = 97;
        const SMALL_Z = 122;
        const CAPITAL_A = 65;
        const CAPITAL_Z = 90;
        const UNDERSCORE = 95;

        const isNotValidDigit = key < ZERO || key > NINE;
        const isNotValidSmallAlphabet = key < SMALL_A || key > SMALL_Z;
        const isNotValidCapitalAlphabet = key < CAPITAL_A || key > CAPITAL_Z;

        //Leading underscore is prevented
        if (key == UNDERSCORE && e.target.value.length == 0) {
          e.preventDefault();
        }
        if (key != UNDERSCORE && isNotValidDigit && isNotValidSmallAlphabet && isNotValidCapitalAlphabet) {
          e.preventDefault();
        }
      }

      syncEditorWithID();
      setIdType(idType);
      writer.addEventListener("change", function (event) {
        element.value = writer.value;
      });
      writer.form.addEventListener('reset', function (event) {
        const resetEvent = new Event('reset');
        element.dispatchEvent(resetEvent);
      });
      element.addEventListener('reset', function (event) {
        idType = element.getAttribute('data-id-type');
        setIdType(idType);
      });
      writer.addEventListener('keypress', keypress);
      button.addEventListener("click", switchType);

    }
  });
})(window);
