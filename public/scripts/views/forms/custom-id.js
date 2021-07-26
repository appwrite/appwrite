(function(window) {
    "use strict";
  //note field below field to validation errors
  //2021-07-25-17-47-59.png
  //validation rule
    window.ls.container.get("view").add({
      selector: "data-custom-id",
      controller: function(element) {
        var prevData = "";
        let idType = element.getAttribute('id-type');

        var div = window.document.createElement("div");

        div.className = "input-copy";

        var button = window.document.createElement("i");
        
        button.type = "button";
        button.className = idType == "custom" ? "icon-cog copy" : "icon-edit copy";
        button.style.cursor = "pointer";
        
        
        var writer = document.createElement("input");
        writer.type = "text";
        writer.className = "";
        

        div.appendChild(writer);
        div.appendChild(button);
        element.parentNode.insertBefore(div, element);
        writer.autofocus;

        var switchType = function(event) {
          if(idType == "custom") {
            idType = "auto";
            element.setAttribute('id-type', idType);
            prevData = writer.value;
            writer.disabled = true;
            writer.value = 'auto-generated';
            element.value = 'unique()';
          } else {
            idType = "custom";
            element.setAttribute('id-type', idType);
            writer.value = prevData;
            writer.disabled = false;
            element.value = prevData;
            writer.focus();
          }
          button.className = idType == "custom" ? "icon-cog copy" : "icon-edit copy";
        }

        var sync = function(event) {
          if(element.value !== 'unique()') {
            writer.value = element.value;
          }
        }

        var syncE = function(event) {
          element.value = writer.value;
        }

        sync();
        writer.addEventListener("change", syncE);
        button.addEventListener("click", switchType);

      }
    });
  })(window);
  