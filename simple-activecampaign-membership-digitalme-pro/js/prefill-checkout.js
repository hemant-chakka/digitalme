(function () {
  const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

  if (isSafari) {
    import("./prefill-safari.js")
      .then((module) => module.initPrefill && module.initPrefill())
      .catch(console.error);
  } else {
    import("./prefill-default.js")
      .then((module) => module.initPrefill && module.initPrefill())
      .catch(console.error);
  }
})();
