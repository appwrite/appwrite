(function(window) {
    //"use strict";
  
    window.ls.container.get("view").add({
        selector: "data-forms-key-value",
        controller: function(element) {
          let key = document.createElement("input");
          let value = document.createElement("input");
          let wrap = document.createElement("div");
          let cell1 = document.createElement("div");
          let cell2 = document.createElement("div");
    
          key.type = "text";
          key.className = "margin-bottom-no";
          key.placeholder = element.getAttribute("data-forms-translation-key") || "Key";
          key.required = true;
          value.type = "text";
          value.className = "margin-bottom-no";
          value.placeholder = element.getAttribute("data-forms-translation-value") || "Value";
          value.required = true;
    
          wrap.className = "row thin margin-bottom-small";
          cell1.className = "col span-6";
          cell2.className = "col span-6";
    
          element.parentNode.insertBefore(wrap, element);
          cell1.appendChild(key);
          cell2.appendChild(value);
          wrap.appendChild(cell1);
          wrap.appendChild(cell2);
    
          key.addEventListener("input", function() {
            syncA();
          });
    
          value.addEventListener("input", function() {
            syncA();
          });
    
          element.addEventListener("change", function() {
            syncB();
          });
    
          let syncA = function() {
            element.name = key.value;
            element.value = value.value;
          };
    
          let syncB = function() {
            key.value = element.name || "";
            value.value = element.value || "";
          };
    
          syncB();
        }
      });
  })(window);