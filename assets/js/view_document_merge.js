(function () {
  // Client-side "View Document" merge for InfinityFree-friendly hosting.
  // Requires pdf-lib loaded globally as window.PDFLib (via CDN script include).
  const btn = document.getElementById("btnViewDocument");
  if (!btn) return;

  const APP = window.__CTX__ || {};
  const API = APP.api || "/document-tracker/api";
  const PUBLIC = APP.public || "/document-tracker/public";

  function byId(id) { return document.getElementById(id); }

  function toast(msg) {
    // Use your existing message area if present; otherwise fallback to alert.
    const attachMsg = byId("attachMsg");
    if (attachMsg) {
      attachMsg.textContent = msg;
      attachMsg.style.display = msg ? "" : "none";
      return;
    }
    alert(msg);
  }

  function isPdf(mime, name) {
    mime = (mime || "").toLowerCase();
    name = (name || "").toLowerCase();
    return mime === "application/pdf" || name.endsWith(".pdf");
  }

  function isJpgPng(mime, name) {
    mime = (mime || "").toLowerCase();
    name = (name || "").toLowerCase();
    if (mime === "image/jpeg" || mime === "image/jpg" || mime === "image/png") return true;
    return name.endsWith(".jpg") || name.endsWith(".jpeg") || name.endsWith(".png");
  }

  async function fetchJson(url) {
    const res = await fetch(url, { headers: { "Accept": "application/json" } });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || data.ok !== true) {
      throw new Error(data?.error || `Request failed (${res.status})`);
    }
    return data;
  }

  async function fetchArrayBuffer(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`Failed to download file (${res.status})`);
    return await res.arrayBuffer();
  }

  function sortAttachments(items) {
    // main first, then append; then by uploaded_at, then id
    return (items || []).slice().sort((a, b) => {
      const aa = Number(a.is_append || 0), bb = Number(b.is_append || 0);
      if (aa !== bb) return aa - bb;

      const ta = Date.parse(String(a.uploaded_at || "").replace(" ", "T")) || 0;
      const tb = Date.parse(String(b.uploaded_at || "").replace(" ", "T")) || 0;
      if (ta !== tb) return ta - tb;

      return Number(a.id || 0) - Number(b.id || 0);
    });
  }

  async function mergeToPdfBytes(attachments) {
    if (!window.PDFLib || !window.PDFLib.PDFDocument) {
      throw new Error("pdf-lib not loaded. Check footer.php script include.");
    }

    const { PDFDocument } = window.PDFLib;
    const outPdf = await PDFDocument.create();

    for (const a of attachments) {
      const id = Number(a.id || 0);
      if (!id) continue;

      const name = (a.original_name || "file").toString();
      const mime = (a.mime || "").toString();

      const dlUrl = `${PUBLIC}/download_attachment.php?id=${encodeURIComponent(id)}`;
      const bytes = await fetchArrayBuffer(dlUrl);

      if (isPdf(mime, name)) {
        const src = await PDFDocument.load(bytes, { ignoreEncryption: true });
        const pages = await outPdf.copyPages(src, src.getPageIndices());
        pages.forEach(p => outPdf.addPage(p));
        continue;
      }

      if (isJpgPng(mime, name)) {
        const page = outPdf.addPage();
        let img;
        if (name.toLowerCase().endsWith(".png") || mime.toLowerCase() === "image/png") {
          img = await outPdf.embedPng(bytes);
        } else {
          img = await outPdf.embedJpg(bytes);
        }

        // Fit image to page with margins (simple "contain")
        const { width: pw, height: ph } = page.getSize();
        const margin = 24;
        const maxW = pw - margin * 2;
        const maxH = ph - margin * 2;

        const iw = img.width;
        const ih = img.height;

        const scale = Math.min(maxW / iw, maxH / ih);
        const w = iw * scale;
        const h = ih * scale;

        const x = (pw - w) / 2;
        const y = (ph - h) / 2;

        page.drawImage(img, { x, y, width: w, height: h });
        continue;
      }

      // Unknown type: skip but keep user informed
      throw new Error(`Unsupported attachment type for merge: ${name} (${mime || "unknown"})`);
    }

    return await outPdf.save();
  }

  async function handleClick(e) {
    e.preventDefault();

    const docId = (byId("d_id")?.value || "").toString().trim();
    if (!docId) {
      toast("Select a document first.");
      return;
    }

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = "Building PDF…";

    try {
      toast(""); // clear
      const listUrl = `${API}/attachments_list.php?document_id=${encodeURIComponent(docId)}`;
      const data = await fetchJson(listUrl);

      const all = sortAttachments(data.attachments || []);
      if (all.length === 0) throw new Error("No attachments to merge.");

      // Safety: limit very large sets (basic guard)
      const totalBytes = all.reduce((sum, a) => sum + Number(a.size_bytes || 0), 0);
      const MAX_TOTAL = 60 * 1024 * 1024; // 60MB (tweakable)
      if (totalBytes > MAX_TOTAL) {
        throw new Error("Attachments too large to merge in-browser. Use individual view/download.");
      }

      const mergedBytes = await mergeToPdfBytes(all);
      const blob = new Blob([mergedBytes], { type: "application/pdf" });
      const url = URL.createObjectURL(blob);

      // Open in new tab
      window.open(url, "_blank", "noopener");

      // Revoke later
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (err) {
      toast(err?.message || "Failed to build merged PDF.");
    } finally {
      btn.disabled = false;
      btn.textContent = oldText;
    }
  }

  btn.addEventListener("click", handleClick);
})();