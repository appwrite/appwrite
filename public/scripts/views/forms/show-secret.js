(function(window) {
    "use strict";

    window.ls.container.get("view").add({
      selector: "data-forms-show-secret",
      controller: function(element, document) {
        let button = document.createElement("a");    
        button.type = "button";
        button.className = "icon-eye";
        button.innerHTML = "show/hide";
        button.style.cursor = "pointer";
        button.style.fontSize = "10px";
        
        element.insertAdjacentElement("beforebegin", button);
  
        const toggle = function(event) {
          switch (element.type) { //TODO@kodumbeats: add closed-eye icon to public/styles/icons.less and toggle onClick
            case "password":
              element.type = "text";
              break;
            case "text":
              element.type = "password";
              break;
            default:
              console.warn("data-forms-show-secret: element.type NOT text NOR password")
          };
        };
  
        button.addEventListener("click", toggle);
      }
    });
  })(window);
