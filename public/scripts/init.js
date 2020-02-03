// Init

window.ls.error = function() {
  return function(error) {
    console.error("ERROR-APP", error);
  };
};

window.addEventListener("error", function(event) {
  console.error("ERROR-EVENT:", event.error.message, event.error.stack);
});

document.addEventListener("account.deleteSession", function() {
  window.location = "/auth/signin";
});