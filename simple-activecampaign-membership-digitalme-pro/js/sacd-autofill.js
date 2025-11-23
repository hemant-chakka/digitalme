jQuery(function($){
    var contactCache = null;

    // Keywords for field detection
    var FIELDS = {
        firstName: [
            "first", "fname", "first_name", "givenname", "given_name", "forename"
        ],
        lastName: [
            "last", "lname", "last_name", "surname", "familyname", "family_name"
        ],
        email: [
            "email", "mail", "e-mail"
        ],
        phone: [
            "phone", "tel", "telephone", "mobile", "cell"
        ]
    };

    /**
     * Try to autofill a field if its attributes/label match keywords.
     */
    function tryAutofill(field, value, keywords) {
        if (!value) return;

        // If field already filled, skip
        if ($(field).val()) return;

        // Gather signals for matching
        var signals = [
            field.name || "",
            field.id || "",
            field.placeholder || "",
            $(field).attr("aria-label") || ""
        ];

        // Look for label associated with field (via for attribute or nearby)
        var label = $(field).closest("form").find('label[for="'+field.id+'"]');
        if (label.length) signals.push(label.text());
        // Also check previousSibling labels
        var nearLabel = $(field).prev('label');
        if (nearLabel.length) signals.push(nearLabel.text());

        // Combine and normalize
        var signalStr = signals.join(" ").replace(/\s+/g, " ").toLowerCase();

        // Match any keyword
        var matches = keywords.some(function(word){
            return signalStr.includes(word);
        });

        if (matches) $(field).val(value);
    }

    function autofill_ac(contact) {
        // For each input in any form present
        $('input').each(function(){
            tryAutofill(this, contact.firstName, FIELDS.firstName);
            tryAutofill(this, contact.lastName,  FIELDS.lastName);
            tryAutofill(this, contact.email,     FIELDS.email);
            tryAutofill(this, contact.phone,     FIELDS.phone);
        });
    }

    function fetchAndAutofill() {
        if (contactCache) {
            autofill_ac(contactCache);
            return;
        }
        $.post(
            SACD_AUTOFILL.ajaxUrl,
            { action: 'sacd_get_ac_contact', nonce: SACD_AUTOFILL.nonce },
            function(resp) {
                if (resp.success && resp.data) {
                    contactCache = resp.data;
                    autofill_ac(contactCache);
                }
            }
        );
    }

    // Autofill on load and periodically for 10 seconds
    fetchAndAutofill();

    $(document.body).on('updated_checkout customer_address_changed', function(){
        fetchAndAutofill();
    });

    var tries = 0, maxTries = 10;
    var autofillInterval = setInterval(function() {
        tries++;
        fetchAndAutofill();
        if (tries >= maxTries) clearInterval(autofillInterval);
    }, 1000);
});