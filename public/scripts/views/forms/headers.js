(function(window) {
    //"use strict";
  
    window.ls.container.get("view").add({
        selector: "data-forms-headers",
        controller: function(element) {
          let key = document.createElement("input");
          let value = document.createElement("input");
          let wrap = document.createElement("div");
          let cell1 = document.createElement("div");
          let cell2 = document.createElement("div");
    
          key.type = "text";
          key.className = "margin-bottom-no";
          key.placeholder = "Key";
          value.type = "text";
          value.className = "margin-bottom-no";
          value.placeholder = "Value";
    
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
            element.value =
              key.value.toLowerCase() + ":" + value.value.toLowerCase();
          };
    
          let syncB = function() {
            let split = element.value.toLowerCase().split(":");
            key.value = split[0] || "";
            value.value = split[1] || "";
    
            key.value = key.value.trim();
            value.value = value.value.trim();
          };
    
          syncB();
        }
      });
  })(window);