// WPRankLab admin scripts

(function (wp) {
    if (typeof wp === 'undefined' || !wp.data || !wp.data.select) {
        return;
    }

    var wasSaving = false;

    wp.data.subscribe(function () {
        var editor = wp.data.select('core/editor');
        if (!editor) {
            return;
        }

        var isSaving = editor.isSavingPost();
        var isAutosaving = editor.isAutosavingPost ? editor.isAutosavingPost() : false;

        // Detect transition: was saving, now not saving, and not an autosave.
        if (wasSaving && !isSaving && !isAutosaving) {
            // Post save just finished -> reload page so PHP metabox reflects latest meta.
            window.location.reload();
        }

        wasSaving = isSaving;
    });
})(window.wp);


(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.wpranklab-copy-btn');
        if (!btn) return;

        var targetId = btn.getAttribute('data-wpranklab-copy-target');
        if (!targetId) return;

        var el = document.getElementById(targetId);
        if (!el) return;

        var text = el.innerText || el.textContent || '';
        navigator.clipboard.writeText(text).then(function () {
            btn.innerText = 'Copied!';
            setTimeout(function () {
                btn.innerText = 'Copy';
            }, 1500);
        });
    });
})();

(function (wp) {
  // Only for Block Editor environments
  if (typeof wp === "undefined" || !wp.data || !wp.blocks) return;

  function getInsertIndex() {
    const be = wp.data.select("core/block-editor");
    if (!be) return null;

    const selectedId = be.getSelectedBlockClientId && be.getSelectedBlockClientId();
    if (!selectedId) return null;

    const index = be.getBlockIndex(selectedId);
    return (typeof index === "number") ? (index + 1) : null;
  }

  document.addEventListener("click", function (e) {
    const el = e.target.closest(".wpranklab-insert-missing-topic");
    if (!el) return;

    // If we can't do block insertion, let it follow the href (server-side fallback).
    const canInsert = wp.data.select("core/block-editor") && wp.data.dispatch("core/block-editor") && wp.blocks.parse;
    if (!canInsert || typeof wpranklabAdmin === "undefined") return;

    e.preventDefault();

    const postId = el.getAttribute("data-postid");
    const topic  = el.getAttribute("data-topic");

    if (!postId || !topic) return;

    el.setAttribute("disabled", "disabled");
    el.classList.add("disabled");
    const oldText = el.innerText;
    el.innerText = "Generating…";

    const fd = new FormData();
    fd.append("action", "wpranklab_missing_topic_section");
    fd.append("nonce", wpranklabAdmin.nonce);
    fd.append("post_id", postId);
    fd.append("topic", topic);

    fetch(wpranklabAdmin.ajaxUrl, { method: "POST", body: fd, credentials: "same-origin" })
      .then(r => r.json())
      .then(res => {
        if (!res || !res.success) {
          throw new Error(res && res.data && res.data.message ? res.data.message : "Failed");
        }

        const html = (res.data && res.data.html) ? res.data.html : "";
        if (!html) throw new Error("Empty section");

        // Convert HTML into blocks and insert after the selected block.
        const blocks = wp.blocks.parse(html);
        if (!blocks || !blocks.length) throw new Error("Could not parse blocks");

        const idx = getInsertIndex();
        const dispatch = wp.data.dispatch("core/block-editor");
        if (idx === null) {
          // No selection -> append at end
          dispatch.insertBlocks(blocks);
        } else {
          dispatch.insertBlocks(blocks, idx);
        }

        el.innerText = "Inserted (unsaved)";
      })
      .catch(err => {
        // Restore & fall back to href if needed
        el.removeAttribute("disabled");
        el.classList.remove("disabled");
        el.innerText = oldText;

        // Optional: show a quick alert
        alert("Could not insert at cursor: " + (err && err.message ? err.message : "Unknown error") + "\n\nFalling back to normal insert.");
        window.location.href = el.getAttribute("href");
      });
  });
})(window.wp);


document.addEventListener("click", function (e) {
  const btn = e.target.closest(".wpranklab-copy-schema");
  if (!btn) return;

  const targetId = btn.getAttribute("data-target");
  const ta = document.getElementById(targetId);
  if (!ta) return;

  ta.select();
  ta.setSelectionRange(0, ta.value.length);

  try {
    document.execCommand("copy");
    const old = btn.innerText;
    btn.innerText = "Copied!";
    setTimeout(() => (btn.innerText = old), 900);
  } catch (err) {
    alert("Copy failed. Please copy manually.");
  }
});
