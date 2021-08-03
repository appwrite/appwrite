(function (window) {
  "use strict";
  //note field below field to validation errors
  //2021-07-25-17-47-59.png
  //validation rule
  window.ls.container.get("view").add({
    selector: "data-custom-id",
    controller: function (element) {
      var prevData = "";
      let idType = element.getAttribute('id-type');

      var div = window.document.createElement("div");

      div.className = "input-copy";

      var button = window.document.createElement("i");

      button.type = "button";
      button.style.cursor = "pointer";

      var writer = document.createElement("input");
      writer.type = "text";
      writer.className = "";
      writer.setAttribute("maxlength", element.getAttribute("maxlength"));
      var placeholder = element.getAttribute("placeholder");
      if (placeholder) {
        writer.setAttribute("placeholder", placeholder);
      }

      var info = window.document.createElement("div");
      info.className = "text-fade text-size-xs margin-top-negative-small margin-bottom";

      div.appendChild(writer);
      div.appendChild(button);
      element.parentNode.insertBefore(div, element);
      element.parentNode.insertBefore(info, div.nextSibling);

      var switchType = function (event) {
        if (idType == "custom") {
          idType = "auto";
          setIdType(idType);
        } else {
          idType = "custom";
          setIdType(idType);
        }
      }

      var setIdType = function (idType) {
        if (idType == "custom") {
          info.innerHTML = "(Allowed Characters A-Z, a-z, 0-9, and non-leading _)";
          element.setAttribute('id-type', idType);
          writer.value = prevData;
          writer.disabled = false;
          element.value = prevData;
          writer.focus();
        } else {
          info.innerHTML = "(Automatically generated)";
          element.setAttribute('id-type', idType);
          prevData = writer.value;
          writer.disabled = true;
          writer.value = 'auto-generated';
          element.value = 'unique()';
        }
        button.className = idType == "custom" ? "icon-cog copy" : "icon-edit copy";
      }

      var sync = function (event) {
        if (element.value !== 'unique()') {
          writer.value = element.value;
        }
      }

      var syncE = function (event) {
        element.value = writer.value;
      }

      var keypress = function (e) {
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

      sync();
      setIdType(idType);
      writer.addEventListener("change", syncE);
      writer.addEventListener('keypress', keypress);
      button.addEventListener("click", switchType);

    }
  });
})(window);
