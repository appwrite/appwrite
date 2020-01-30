// Init

window.ls.error = function() {
  return function(error) {
    console.error("ERROR-APP", error);
  };
};

window.addEventListener("error", function(event) {
  console.error("ERROR-EVENT:", event.error.message, event.error.stack);
});

document.addEventListener("deleteCurrentSession", function() {
  window.location = "/auth/signin";
});

document.addEventListener(
  "http-get-401",
  function() {
    /* on error */
    document.dispatchEvent(new CustomEvent("deleteCurrentSession"));
  },
  true
);
