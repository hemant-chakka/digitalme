(function () {
  console.log("Prefill Default JS loaded");
  function log() {
    if (window.console && console.log) console.log.apply(console, arguments);
  }

  function dispatchCartStoreAddress(type, obj) {
    try {
      if (window.wp && wp.data && wp.data.dispatch) {
        const dispatch = wp.data.dispatch("wc/store/cart");

        if (dispatch && typeof dispatch[`set${type}Address`] === "function") {
          dispatch[`set${type}Address`](obj);
          return true;
        }
      }
    } catch (e) {
      // ignore
    }
    return false;
  }

  function setNativeValueRobust(el, value) {
    if (!el) return false;

    const valueSetter = Object.getOwnPropertyDescriptor(el.__proto__, "value")?.set;
    const prototype = Object.getPrototypeOf(el);
    const prototypeValueSetter = Object.getOwnPropertyDescriptor(prototype, "value")?.set;

    if (valueSetter && valueSetter !== prototypeValueSetter) {
      prototypeValueSetter.call(el, value);
    } else if (valueSetter) {
      valueSetter.call(el, value);
    } else {
      // fallback
      el.value = value;
    }
  }

  function setElValue(el, val) {
    if (!el) return false;
    const tag = el.tagName.toLowerCase();

    if (tag === "select") {
      const match = Array.from(el.options).find((opt) => opt.value == val || opt.text == val);

      if (match) {
        el.value = match.value;
      } else {
        el.value = val;
      }
    } else if (tag === "input" || tag === "textarea") {
      const type = (el.type || "").toLowerCase();

      try {
        setNativeValueRobust(el, val);
      } catch (e) {
        // ignore - failed
      }
    } else {
      el.setAttribute("value", val);
    }

    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
    return true;
  }
  function findFieldElement(prefix, fieldKey) {
    const attempts = [
      `[name="${prefix}${fieldKey}"]`,
      `[name="${prefix}[${fieldKey}]"]`,
      `[name="${prefix}.${fieldKey}"]`,
      `#${prefix}${fieldKey}`,
      `[id*="${prefix}${fieldKey}"]`,
      `[name*="${prefix}${fieldKey}"]`,
      `[data-key="${prefix}${fieldKey}"]`,
      `[data-key*="${fieldKey}"]`,
      `input[id*="${fieldKey}"]`,
      `select[id*="${fieldKey}"]`,
      `textarea[id*="${fieldKey}"]`,
    ];

    for (const sel of attempts) {
      try {
        const el = document.querySelector(sel);
        if (el) return el;
      } catch (e) {}
    }
    return null;
  }

  function normalizeFieldNames(key) {
    switch (key) {
      case "street_address_1":
        return ["address_1"];
      case "street_address_2":
        return ["address_2"];
      case "postcode":
        return ["postcode"];
      case "first_name":
      case "last_name":
      case "email":
      case "phone":
      case "city":
      case "state":
      case "country":
      case "company":
        return [key];
      default:
        return [key];
    }
  }

  function applyPrefillsToDom(prefills) {
    const prefixes = ["billing_", "shipping_"];
    let updated = 0;

    prefixes.forEach((prefix) => {
      for (const key in prefills) {
        if (!prefills[key] && prefills[key] !== 0) continue;
        const normalized = normalizeFieldNames(key);
        normalized.forEach((fieldKey) => {
          const el = findFieldElement(prefix, fieldKey);
          if (el) {
            if (setElValue(el, prefills[key])) updated++;
          }
        });
      }
    });

    for (const key in prefills) {
      const norm = normalizeFieldNames(key)[0];

      ["billing-", "shipping-"].forEach((prefix) => {
        try {
          const el = document.querySelector(`[name="${prefix}${norm}"]`) ?? document.querySelector(`#${prefix}${norm}`);
          if (el) {
            setElValue(el, prefills[key]);
            updated++;
          }
        } catch (e) {}
      });
    }

    return updated;
  }

  function buildAddressObject(prefills) {
    return {
      first_name: prefills.first_name || "",
      last_name: prefills.last_name || "",
      phone: prefills.phone || "",
      email: prefills.email || "",
      company: prefills.company || "",
      address_1: prefills.street_address_1 || prefills.address_1 || "",
      address_2: prefills.street_address_2 || prefills.address_2 || "",
      city: prefills.city || "",
      state: prefills.state || "",
      postcode: prefills.postcode || "",
      country: prefills.country || "",
    };
  }

  document.addEventListener("DOMContentLoaded", function () {
    if (typeof checkoutFieldPrefills === "undefined" || !checkoutFieldPrefills) {
      return;
    }

    const prefills = checkoutFieldPrefills;
    const billingObj = buildAddressObject(prefills);
    const shippingObj = buildAddressObject(prefills);

    let usedStore = false;
    try {
      usedStore = dispatchCartStoreAddress("Billing", billingObj) || dispatchCartStoreAddress("Shipping", shippingObj);
    } catch (e) {
      /* ignore */
    }

    function applyAll() {
      const updatedCount = applyPrefillsToDom(prefills);
      if (prefills.email) {
        const elEmail =
          findFieldElement("billing_", "email") ||
          document.querySelector('[name="billing_email"]') ||
          document.querySelector('[name="email"]');
        if (elEmail) {
          setElValue(elEmail, prefills.email);
        }
      }

      if (prefills.phone) {
        const elPhone = findFieldElement("billing_", "phone") || document.querySelector('[name="billing_phone"]');
        if (elPhone) {
          setElValue(elPhone, prefills.phone);
        }
      }

      return updatedCount;
    }

    let attempts = 0;
    const maxAttempts = 8;
    const interval = setInterval(function () {
      attempts++;
      const updated = applyAll();

      if (attempts >= maxAttempts) {
        clearInterval(interval);
        log("prefill attempts finished");
      }
    }, 400);
  });
})();
