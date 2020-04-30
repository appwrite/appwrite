(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-text-direction",
    controller: function(element, rtl) {
      
      var setDirection = function() {
        var value = element.value[0] ? element.value : "";
        var direction = "ltr";
        var align = "left";

        if (rtl.isRTL(value)) {
          direction = "rtl";
          align = "right";
        }

        element.style.direction = direction;
        element.style.textAlign = align;
      };

      element.addEventListener("keyup", setDirection);
      element.addEventListener("change", setDirection);
      element.addEventListener("cut", setDirection);
      element.addEventListener("paste", setDirection);
      element.addEventListener("drop", setDirection);

      setDirection();
    }
  });
})(window);
