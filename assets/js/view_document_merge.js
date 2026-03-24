(function () {
  const btn = document.getElementById("btnViewDocument");
  if (!btn) return;

  const APP = window.__CTX__ || {};
  const PUBLIC = APP.public || "/document-tracker/public";

  function openMerged(docId, branchId = 0) {
    if (!docId) return;
    const resolvedBranchId = Number(branchId || (typeof window.DTGetSelectedBranchId === "function" ? window.DTGetSelectedBranchId() : 0) || 0);
    const qs = new URLSearchParams({ document_id: String(docId), v: String(Date.now()) });
    const bid = resolvedBranchId;
    if (bid > 0) qs.set("branch_id", String(bid));
    const url = `${PUBLIC}/view_document.php?${qs.toString()}`;
    window.open(url, "_blank", "noopener");
  }

  window.DTMergeView = window.DTMergeView || {};
  window.DTMergeView.open = openMerged;

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
