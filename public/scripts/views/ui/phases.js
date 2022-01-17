(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ui-phases",
    controller: function(element, window, document, expression, router, view) {
      var tabs = document.createElement("ul");
      var container = document.createElement("div");
      var titles = Array.prototype.slice.call(
        element.querySelectorAll("li > h1")
      );
      var next = Array.prototype.slice.call(
        element.querySelectorAll("[data-next]")
      );
      var previous = Array.prototype.slice.call(
        element.querySelectorAll("[data-previous]")
      );
      var position = 0;
      var init = false;

      if(titles.length === 0) {
        titles = Array.prototype.slice.call(
          element.querySelectorAll("li > h2")
        );
      }

      if(titles.length === 0) {
        titles = Array.prototype.slice.call(
          element.querySelectorAll("li > h3")
        );
      }

      if(titles.length === 0) {
        titles = Array.prototype.slice.call(
          element.querySelectorAll("li > h4")
        );
      }

      if(titles.length === 0) {
        titles = Array.prototype.slice.call(
          element.querySelectorAll("li > h5")
        );
      }

      if(titles.length === 0) {
        titles = Array.prototype.slice.call(
          element.querySelectorAll("li > h6")
        );
      }

      for (var i = 0; i < element.children.length; i++) {
        var tabState = expression.parse(
          element.children[i].dataset["state"] || ""
        );

        if (tabState && tabState === (window.location.pathname + window.location.search).substring(0, tabState.length)) {
          position = i;
        }
      }

      var setTab = function(index) {
        var tabState = expression.parse(
          element.children[index].dataset["state"] || ""
        );
        var url = "";

        if (
          tabState !== "" &&
          tabState !== window.location.pathname + window.location.search
        ) {
          var parser = document.createElement("a");
          parser.href = tabState;
          url = !init ? parser.pathname + window.location.search : tabState;

          if (position != index) {
            // When tab has changed add state to history
            window.history.pushState({}, "", url);
            router.reset();
          }
        }

        element.children[position].classList.remove("selected");
        element.children[index].classList.add("selected");
        tabs.children[position].classList.remove("selected");
        tabs.children[index].classList.add("selected");
        position = index;

        document.dispatchEvent(new CustomEvent("tab-changed"));

        init = true;
      };

      tabs.classList.add("tabs");
      container.classList.add("container");
      container.classList.add("close");

      container.dataset["lsUiOpen"] = "";
      container.dataset["buttonClass"] = "icon icon-down-dir";

      titles.map(function(obj, i) {
        var title = document.createElement("li");
        title.innerHTML = obj.innerHTML;
        title.className = obj.className;
        title.tabIndex = 0;
        tabs.appendChild(title);

        title.addEventListener("click", function() {
          setTab(i);
        });

        title.addEventListener("keyup", function() {
          if (event.which === 13) {
            setTab(i);
          }
        });

        view.render(title);
      });

      next.map(function(obj) {
        obj.addEventListener("click", function() {
          setTab(position + 1);
        });
      });

      previous.map(function(obj) {
        obj.addEventListener("click", function() {
          setTab(position - 1);
        });
      });
      
      setTab(position);

      container.appendChild(tabs);

      element.parentNode.insertBefore(container, element);
    }
  });
})(window);
