(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-text-count",
    controller: function(element) {
      var counter = document.createElement("div");

      counter.className = "counter";

      element.parentNode.insertBefore(counter, element.nextSibling);

      var count = function() {
        if (0 <= element.maxLength) {
          counter.innerText =
            (element.maxLength - element.value.length).toString() +
            " / " +
            element.maxLength;
        } else {
          var words =
            element.value !== "" ? element.value.trim().split(" ").length : 0;
          counter.innerText =
            words + " words and " + element.value.length.toString() + " chars";
        }
      };

      element.addEventListener("keyup", count);
      element.addEventListener("change", count);
      element.addEventListener("cut", count);
      element.addEventListener("paste", count);
      element.addEventListener("drop", count);

      count();
    }
  });
})(window);
