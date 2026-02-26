(function () {
  // Server-side merged PDF viewer (memo first, then attachments)
  // This disables the old in-browser PDF-lib merge (blob URLs + wrong order).
  const btn = document.getElementById("btnViewDocument");
  if (!btn) return;

  const APP = window.__CTX__ || {};
  const PUBLIC = APP.public || "/document-tracker/public";

  function openMerged(docId) {
    if (!docId) return;
    const url =
      `${PUBLIC}/view_document.php?document_id=${encodeURIComponent(docId)}&v=${Date.now()}`;
    window.open(url, "_blank", "noopener");
  }

  // Make it available to app.js (it calls window.DTMergeView.open(docId))
  window.DTMergeView = window.DTMergeView || {};
  window.DTMergeView.open = openMerged;

  // Also intercept click in CAPTURE phase so no other handler runs (prevents double-open)
  btn.addEventListener(
    "click",
    (e) => {
      e.preventDefault();
      e.stopImmediatePropagation();
      const docId = btn.dataset.docId || "";
      openMerged(docId);
    },
    true
  );
})();