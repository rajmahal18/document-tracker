(function () {
  const backdrop = document.getElementById("drawerBackdrop");
  const drawer = document.getElementById("drawer");
  const closeBtn = document.getElementById("drawerClose");

  const elId = document.getElementById("d_id");
  const elTracking = document.getElementById("d_tracking");
  const elRequester = document.getElementById("d_requester");
  const elDate = document.getElementById("d_date");
  const elSubject = document.getElementById("d_subject");
  const elType = document.getElementById("d_type");
  const elDays = document.getElementById("d_days");

  const elStatus = document.getElementById("d_status");
  const elHolder = document.getElementById("d_holder");
  const elDestination = document.getElementById("d_destination");
  const elLastHolder = document.getElementById("d_last_holder");

  const elRemarks = document.getElementById("d_remarks");
  const elTimeline = document.getElementById("d_timeline");

  // Attachments (list + view)
  const elAttachments = document.getElementById("d_attachments");
  const btnViewDocument = document.getElementById("btnViewDocument");

  // Toggle buttons (must exist in documents.php)
  const btnToggleAttachments = document.getElementById("btnToggleAttachments");
  const btnToggleUpload = document.getElementById("btnToggleUpload");
  const btnToggleForward = document.getElementById("btnToggleForward");

  // Upload form
  const attachForm = document.getElementById("attachForm");
  const attachFile = document.getElementById("attachFile");
  const attachType = document.getElementById("attachType");
  const attachNote = document.getElementById("attachNote");
  const btnAttachUpload = document.getElementById("btnAttachUpload");
  const attachMsg = document.getElementById("attachMsg");

  // Drawer actions
  const btnUnderAction = document.getElementById("btnUnderAction");
  const btnAckReceived = document.getElementById("btnAckReceived");
  const btnRelease = document.getElementById("btnRelease");
  const btnArchive = document.getElementById("btnArchive");

  // Forward box
  const forwardBox = document.getElementById("forwardBox");
  const selForwardTo = document.getElementById("f_to_section");
  const btnForward = document.getElementById("btnForward");

  // Attachment preview modal elements
  const attModal = document.getElementById("attModal");
  const attModalBackdrop = document.getElementById("attModalBackdrop");
  const attClose = document.getElementById("attClose");
  const attBody = document.getElementById("attBody");
  const attTitle = document.getElementById("attTitle");
  const attSub = document.getElementById("attSub");
  const attDownload = document.getElementById("attDownload");
  const attDialog = document.getElementById("attDialog");

  const APP = window.__APP__ || {};
  const API = APP.api || "/document-tracker/api";
  const PUBLIC = APP.public || "/document-tracker/public";

  // Forward permission cached per drawer-open
  let currentCanForward = false;

  function esc(s) {
    return (s ?? "").toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function fmtBytes(n) {
    const b = Number(n || 0);
    if (!isFinite(b) || b <= 0) return "—";
    const units = ["B", "KB", "MB", "GB"];
    let i = 0;
    let v = b;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }

  function prettyAction(a) {
    const key = (a ?? "").toString().trim().toLowerCase();
    const map = {
      created: "Created",
      sent: "Sent",
      received: "Received",
      forwarded: "Forwarded",
      released: "Released",
      release_undone: "Undo Release",
      archived: "Archived",
      archive_undone: "Undo Archive",
      attachment_added: "Attachment",
      cancelled: "Cancelled",
      under_action: "Under Action",
      updated: "Updated",
      status_changed: "Status Changed",
    };
    return map[key] || (key ? key : "Updated");
  }

  function fileExt(name) {
    const s = (name || "").toLowerCase();
    const i = s.lastIndexOf(".");
    return i >= 0 ? s.slice(i + 1) : "";
  }

  function isAllowedAttachmentFile(file) {
    const ext = fileExt(file?.name || "");
    return ["pdf", "jpg", "jpeg", "png"].includes(ext);
  }

  function isCollapsed(el) {
    return !!el?.classList?.contains("collapsed");
  }

  function setCollapsed(el, collapsed) {
    if (!el) return;
    el.classList.toggle("collapsed", !!collapsed);
  }

  function syncToggleLabels() {
    if (btnToggleAttachments && elAttachments) {
      btnToggleAttachments.textContent = isCollapsed(elAttachments) ? "View all" : "Hide";
    }
    if (btnToggleUpload && attachForm) {
      btnToggleUpload.textContent = isCollapsed(attachForm) ? "Add attachment" : "Hide upload";
    }
    if (btnToggleForward && forwardBox) {
      btnToggleForward.textContent = isCollapsed(forwardBox) ? "Forward" : "Hide forward";
    }
  }

  function updateForwardUI() {
    if (!forwardBox) return;

    const isOpen = !forwardBox.classList.contains("collapsed");

    // Toggle visible only when allowed (set by openDrawer)
    if (btnToggleForward) btnToggleForward.style.display = currentCanForward ? "" : "none";

    // Confirm button visible only when open + allowed
    if (btnForward) btnForward.style.display = (currentCanForward && isOpen) ? "" : "none";
  }

  function loadSectionsOptions() {
    if (!selForwardTo) return;

    const list = window.__SECTIONS__ || [];
    const grouped = {};

    list.forEach(s => {
      const div = (s.division_name || "Other").toString();
      if (!grouped[div]) grouped[div] = [];
      grouped[div].push(s);
    });

    let html = `<option value="">-- Select section --</option>`;
    Object.keys(grouped).forEach(div => {
      html += `<optgroup label="${esc(div)}">`;
      grouped[div].forEach(s => {
        html += `<option value="${Number(s.id)}">${esc(s.name)}</option>`;
      });
      html += `</optgroup>`;
    });

    selForwardTo.innerHTML = html;
  }
  loadSectionsOptions();

  // =========================
  // Attachment Modal
  // =========================
  function openAttachmentModal({ viewUrl, dlUrl, mime, name }) {
    if (!attModal || !attBody) {
      if (dlUrl) window.open(dlUrl, "_blank");
      return;
    }

    const m = (mime || "").toLowerCase();
    const isImage = m.startsWith("image/");
    const isPdf = m.includes("pdf");

    if (attTitle) attTitle.textContent = name || "Attachment Preview";
    if (attSub) attSub.textContent = mime ? `Type: ${mime}` : "";

    if (attDownload) {
      attDownload.href = dlUrl || "#";
      attDownload.style.display = dlUrl ? "" : "none";
    }

    if (isImage) {
      attBody.innerHTML = `<img src="${esc(viewUrl)}" alt="${esc(name || "attachment")}" />`;
    } else if (isPdf) {
      const pdfSrc = `${viewUrl}#toolbar=0&navpanes=0&scrollbar=0`;
      attBody.innerHTML = `<iframe src="${esc(pdfSrc)}" title="${esc(name || "PDF")}"></iframe>`;
    } else {
      attBody.innerHTML = `
        <div style="padding:16px;max-width:720px;">
          <div style="font-weight:900;margin-bottom:6px;">Preview not supported</div>
          <div class="mini" style="opacity:.8;">
            This file type can’t be reliably previewed in the browser (${esc(mime || "unknown")}).
            Please use Download.
          </div>
        </div>
      `;
    }

    attModal.classList.add("open");
    attModal.setAttribute("aria-hidden", "false");
  }

  function closeAttachmentModal() {
    if (!attModal) return;
    attModal.classList.remove("open");
    attModal.setAttribute("aria-hidden", "true");
    if (attBody) attBody.innerHTML = "";
    if (attDialog) attDialog.classList.remove("isPdf");
  }

  attClose?.addEventListener("click", closeAttachmentModal);
  attModalBackdrop?.addEventListener("click", closeAttachmentModal);

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      if (attModal?.classList.contains("open")) closeAttachmentModal();
      if (drawer?.classList.contains("open")) closeDrawer();
    }
  });

  // =========================
  // Attachments list (click = preview)
  // =========================
  async function loadAttachments(docId) {
    if (!elAttachments) return;

    try {
      const url = `${API}/attachments_list.php?document_id=${encodeURIComponent(docId)}`;
      const res = await fetch(url, { headers: { "Accept": "application/json" } });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        elAttachments.textContent = data?.error || `Failed to load attachments. (${res.status})`;
        return;
      }

      const items = data.attachments || [];
      if (items.length === 0) {
        elAttachments.innerHTML = `<div class="mini" style="opacity:.7;">No files yet.</div>`;
        return;
      }

      function fmt(dt) {
        const d = new Date((dt || "").toString().replace(" ", "T"));
        if (isNaN(d.getTime())) return dt || "";
        return d.toLocaleString("en-GB", {
          day: "2-digit",
          month: "short",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        }).replace(",", "");
      }

      elAttachments.innerHTML = items.map((a) => {
        const id = Number(a.id || 0);
        const name = (a.original_name || "file").toString();
        const mime = (a.mime || "").toString();

        const isAppend = Number(a.is_append || 0) === 1;
        const by = (a.uploaded_by || "—").toString();
        const bySec = (a.uploaded_by_section || "").toString();
        const who = bySec ? `${by} • ${bySec}` : by;
        const note = (a.note || "").toString();
        const when = fmt(a.uploaded_at);
        const size = fmtBytes(a.size_bytes);

        const viewUrl = `${PUBLIC}/view_attachment.php?id=${id}`;
        const dlUrl = `${PUBLIC}/download_attachment.php?id=${id}`;

        return `
          <div class="attachItem">
            <div class="attachTop">
              <a class="attachLink"
                 href="#"
                 data-view-url="${esc(viewUrl)}"
                 data-dl-url="${esc(dlUrl)}"
                 data-mime="${esc(mime)}"
                 data-name="${esc(name)}"
              >${esc(name)}</a>
              ${isAppend ? `<span class="chip action" style="margin-left:8px;">APPEND</span>` : ``}
            </div>
            <div class="attachMeta mini">${esc(who)} • ${esc(when)} • ${esc(size)}</div>
            ${note ? `<div class="attachNote mini">${esc(note)}</div>` : ``}
          </div>
        `;
      }).join("");

    } catch {
      elAttachments.textContent = "Failed to load attachments.";
    }
  }

  // Delegated click for attachment preview
  document.addEventListener("click", (e) => {
    const link = e.target?.closest?.("a.attachLink[data-view-url]");
    if (!link) return;

    e.preventDefault();

    openAttachmentModal({
      viewUrl: link.getAttribute("data-view-url") || "",
      dlUrl: link.getAttribute("data-dl-url") || "",
      mime: link.getAttribute("data-mime") || "",
      name: link.getAttribute("data-name") || "Attachment",
    });
  });

  // =========================
  // Upload attachment
  // =========================
  async function uploadAttachment() {
    const docId = elId?.value;
    if (!docId) return;

    if (!attachFile || !attachFile.files || attachFile.files.length === 0) {
      alert("Please choose a file.");
      return;
    }

    const f0 = attachFile.files[0];
    if (!isAllowedAttachmentFile(f0)) {
      const msg = "Unsupported file type. Allowed: PDF, JPG, PNG. Please export Office files to PDF first.";
      if (attachMsg) attachMsg.textContent = msg;
      else alert(msg);
      attachFile.value = "";
      return;
    }

    if (attachMsg) attachMsg.textContent = "Uploading…";
    if (btnAttachUpload) btnAttachUpload.disabled = true;

    const form = new FormData();
    form.append("document_id", docId);
    form.append("file", f0);
    form.append("is_append", (attachType?.value || "1") === "1" ? "1" : "0");
    form.append("note", attachNote ? attachNote.value : "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/attachments_upload.php`, {
        method: "POST",
        body: form,
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        if (attachMsg) attachMsg.textContent = data?.error || `Upload failed. (${res.status})`;
        else alert(data?.error || `Upload failed. (${res.status})`);
        return;
      }

      if (attachMsg) attachMsg.textContent = "Uploaded ✅";
      if (attachFile) attachFile.value = "";
      if (attachNote) attachNote.value = "";

      await loadAttachments(docId);
      await loadTimeline(docId);

    } catch {
      if (attachMsg) attachMsg.textContent = "Upload failed (network error).";
      else alert("Upload failed (network error).");
    } finally {
      if (btnAttachUpload) btnAttachUpload.disabled = false;
    }
  }

  // =========================
  // Drawer open/close
  // =========================
  function openDrawer(payload) {
    // Always reset collapsibles on open (clean default)
    setCollapsed(elAttachments, true);
    setCollapsed(attachForm, true);
    setCollapsed(forwardBox, true);

    // reset button labels
    syncToggleLabels();

    // View document button
    if (btnViewDocument) {
      const docId = payload.id || "";
      if (docId) {
        btnViewDocument.dataset.docId = String(docId);
        btnViewDocument.style.display = "";
      } else {
        btnViewDocument.dataset.docId = "";
        btnViewDocument.style.display = "none";
      }
    }

    if (elId) elId.value = payload.id || "";
    if (elTracking) elTracking.textContent = payload.tracking_no || "";

    if (elRequester) elRequester.textContent = payload.requester || "—";
    if (elDate) elDate.textContent = payload.document_date || "—";
    if (elSubject) elSubject.textContent = payload.subject || "—";
    if (elType) elType.textContent = payload.content_type || "—";
    if (elDays) elDays.textContent = payload.days_stuck ?? "0";

    const inTransit = (payload.in_transit === 1 || payload.in_transit === "1" || payload.in_transit === true);
    const docStatus = (payload.current_status || "ACTIVE").toString().toUpperCase();

    // Status (must match table)
    if (elStatus) {
      elStatus.textContent = payload.status_label || (inTransit ? "IN TRANSIT" : (docStatus || "—"));
      elStatus.className = payload.status_chip_class || (inTransit ? "chip action" : "chip incoming");
    }

    // Holder
    if (elHolder) {
      elHolder.textContent = payload.current_holder_text || "—";
      elHolder.className = "chip incoming";
    }

    if (elDestination) elDestination.textContent = payload.movement_text || "—";
    if (elLastHolder) elLastHolder.textContent = payload.last_holder_text || "—";
    if (elRemarks) elRemarks.value = "";

    // Open drawer
    backdrop?.classList.add("open");
    drawer?.classList.add("open");

    // Load data
    if (attachMsg) attachMsg.textContent = "";
    if (elAttachments) elAttachments.textContent = "Loading attachments…";
    if (payload.id) loadAttachments(payload.id);

    if (elTimeline) elTimeline.textContent = "Loading timeline…";
    if (payload.id) loadTimeline(payload.id);

    // Permissions / button visibility
    const ctx = window.__CTX__ || {};
    const myRole = (ctx.myRole || "division").toString().toLowerCase();
    const mySectionId = Number(ctx.mySectionId || 0);

    const openToSectionId = Number.parseInt(payload.open_to_section_id, 10) || 0;
    const holderSectionId = Number.parseInt(payload.current_holder_section_id, 10) || 0;

    const isPrivileged = (myRole === "admin" || myRole === "records");

    const canAttach = (docStatus === "ACTIVE" && !inTransit && (
      isPrivileged || (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId)
    ));

    const canForward = (!inTransit && docStatus === "ACTIVE" && holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId);

    // cache forward permission for toggle handler
    currentCanForward = canForward;

    // Show/hide toggles (NOT the boxes)
    if (btnToggleUpload) btnToggleUpload.style.display = canAttach ? "" : "none";
    if (btnToggleAttachments) btnToggleAttachments.style.display = "";
    // Forward toggle is controlled by updateForwardUI()
    updateForwardUI();

    // Reset inputs
    if (attachFile) attachFile.value = "";
    if (attachNote) attachNote.value = "";
    if (attachType) attachType.value = "1"; // append only
    if (selForwardTo) selForwardTo.value = "";

    // Action buttons visibility logic (yours, preserved)
    if (btnAckReceived) btnAckReceived.style.display = "none";
    if (btnRelease) btnRelease.style.display = "none";
    if (btnArchive) btnArchive.style.display = "none";
    if (btnUnderAction) btnUnderAction.style.display = "none";

    if (btnRelease) {
      btnRelease.textContent = "Release";
      btnRelease.dataset.nextStatus = "RELEASED";
    }
    if (btnArchive) {
      btnArchive.textContent = "Archive";
      btnArchive.dataset.nextStatus = "ARCHIVED";
    }

    if (docStatus === "ARCHIVED") {
      if (isPrivileged && btnArchive) {
        btnArchive.textContent = "Undo Archive";
        btnArchive.dataset.nextStatus = "RELEASED";
        btnArchive.style.display = "";
      }
      syncToggleLabels();
      return;
    }

    if (docStatus === "RELEASED") {
      if (btnRelease) {
        btnRelease.textContent = "Undo Release";
        btnRelease.dataset.nextStatus = "ACTIVE";
      }
    }

    if (isPrivileged) {
      if (docStatus === "ACTIVE") {
        if (inTransit && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId) {
          if (btnAckReceived) btnAckReceived.style.display = "";
        }
      }
      if (btnRelease) btnRelease.style.display = "";
      if (btnArchive) btnArchive.style.display = "";
      syncToggleLabels();
      return;
    }

    if (docStatus === "RELEASED") {
      if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
        if (btnRelease) btnRelease.style.display = "";
      }
      syncToggleLabels();
      return;
    }

    if (inTransit) {
      if (openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId) {
        if (btnAckReceived) btnAckReceived.style.display = "";
      }
      syncToggleLabels();
      return;
    }

    if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
      if (btnRelease) btnRelease.style.display = "";
      // forward toggle already handled
    }

    syncToggleLabels();
  }

  function closeDrawer() {
    drawer?.classList.remove("open");
    backdrop?.classList.remove("open");
  }

  // =========================
  // Timeline loader
  // =========================
  async function loadTimeline(docId) {
    if (!elTimeline) return;

    try {
      const url = `${API}/get_history.php?document_id=${encodeURIComponent(docId)}`;
      const res = await fetch(url, { headers: { "Accept": "application/json" } });

      if (!res.ok) {
        elTimeline.textContent = `Failed to load timeline. (${res.status})`;
        return;
      }

      const data = await res.json().catch(() => null);
      if (!data?.ok) {
        elTimeline.textContent = data?.error || "No timeline.";
        return;
      }

      const items = data.history || [];
      if (items.length === 0) {
        elTimeline.textContent = "No history yet.";
        return;
      }

      function fmt(dt) {
        const d = new Date((dt || "").toString().replace(" ", "T"));
        if (isNaN(d.getTime())) return dt || "";
        return d.toLocaleString("en-GB", {
          day: "2-digit",
          month: "short",
          year: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        }).replace(",", "");
      }

      const actionIcon = (k) => {
        const key = (k || "updated").toString().trim().toLowerCase();
        const map = {
          created: "＋",
          sent: "↗",
          forwarded: "➜",
          received: "✓",
          attachment_added: "📎",
          released: "⤴",
          release_undone: "↩",
          archived: "⧉",
          archive_undone: "↩",
          cancelled: "×",
          status_changed: "⚑",
          updated: "•",
        };
        return map[key] || "•";
      };

      const getKey = (i) => (i.action ?? "updated").toString().trim().toLowerCase() || "updated";

      function renderEventsView(itemsNewestFirst) {
        return `
          <div class="timeline">
            ${itemsNewestFirst.map((i, idx) => {
              const actionKey = getKey(i);
              const isCurrent = idx === 0;

              const from = (i.from_section || "").toString().trim();
              const to = (i.to_section || "").toString().trim();

              const moveHtml = (from || to)
                ? `
                  <div class="tMove">
                    ${from ? `<span class="tChip">${esc(from)}</span>` : `<span class="tChip muted">—</span>`}
                    <span class="tArrow">→</span>
                    ${to ? `<span class="tChip">${esc(to)}</span>` : `<span class="tChip muted">—</span>`}
                  </div>
                `
                : "";

              return `
                <div class="tItem action-${esc(actionKey)} ${isCurrent ? "isCurrent" : ""}">
                  <div class="tIcon" aria-hidden="true">
                    <span class="tGlyph">${esc(actionIcon(actionKey))}</span>
                  </div>

                  <div class="tContent">
                    <div class="tRow">
                      <div class="tMeta tMetaLeft">
                        ${esc(fmt(i.acted_at))}<br>
                        ${esc(i.actor || "System")}
                      </div>

                      <div class="tRight">
                        ${isCurrent ? `<span class="tBadge">LATEST</span>` : ``}
                        <div class="tAction">${esc(prettyAction(actionKey).toUpperCase())}</div>
                      </div>
                    </div>

                    ${i.title ? `<div class="tRemark">${esc(i.title)}</div>` : ""}
                    ${moveHtml}
                    ${i.remarks ? `<div class="tNote">${esc(i.remarks)}</div>` : ``}
                  </div>
                </div>
              `;
            }).join("")}
          </div>
        `;
      }

      function renderGroupedView(itemsNewestFirst) {
        const ts = (dt) => {
          const t = new Date((dt || "").toString().replace(" ", "T")).getTime();
          return isNaN(t) ? 0 : t;
        };

        const actionRank = (key) => {
          const k = (key || "updated").toString().trim().toLowerCase();
          const rank = {
            created: 10,
            sent: 20,
            forwarded: 25,
            received: 30,
            attachment_added: 35,
            released: 50,
            release_undone: 55,
            archived: 60,
            archive_undone: 65,
            cancelled: 70,
            status_changed: 80,
            updated: 90,
          };
          return rank[k] ?? 999;
        };

        const keyOf = (i) => (i.action ?? "updated").toString().trim().toLowerCase() || "updated";

        const chrono = [...itemsNewestFirst].sort((a, b) => {
          const da = ts(a.acted_at);
          const db = ts(b.acted_at);
          if (da !== db) return da - db;

          const ra = actionRank(keyOf(a));
          const rb = actionRank(keyOf(b));
          if (ra !== rb) return ra - rb;

          const ida = Number(a.event_id || 0);
          const idb = Number(b.event_id || 0);
          if (ida !== idb) return ida - idb;

          return 0;
        });

        const stints = [];
        const openBySection = new Map();

        function openStint(sectionId, sectionName, ev) {
          const key = sectionId > 0 ? `S:${sectionId}` : "SYS";
          if (openBySection.has(key)) return;

          const s = { sectionId, sectionName, startAt: ev.acted_at || "", endAt: "", events: [] };
          stints.push(s);
          openBySection.set(key, stints.length - 1);
        }

        function closeStint(sectionId, endEv) {
          const key = sectionId > 0 ? `S:${sectionId}` : "SYS";
          const idx = openBySection.get(key);
          if (idx === undefined) return;
          stints[idx].endAt = endEv.acted_at || "";
          openBySection.delete(key);
        }

        for (const ev of chrono) {
          const sid = Number(ev.actor_section_id || 0);
          const secName = (ev.actor_section || "").toString().trim() || (sid > 0 ? `Section #${sid}` : "System");
          const k = keyOf(ev);

          if (k === "created" || k === "received") openStint(sid, secName, ev);

          const skey = sid > 0 ? `S:${sid}` : "SYS";
          const openIdx = openBySection.get(skey);
          if (openIdx !== undefined) stints[openIdx].events.push(ev);

          if (k === "sent" || k === "forwarded") closeStint(sid, ev);
        }

        const lastAt = chrono.length ? (chrono[chrono.length - 1].acted_at || "") : "";
        for (const [, idx] of openBySection.entries()) {
          stints[idx].endAt = lastAt;
        }
        openBySection.clear();

        stints.sort((a, b) => ts(b.startAt) - ts(a.startAt));

        const rendered = stints.map((s) => {
          const eventsNewestFirst = [...s.events].reverse();

          const headerMeta = (() => {
            const count = s.events.length;
            const start = s.startAt ? fmt(s.startAt) : "";
            const end = s.endAt ? fmt(s.endAt) : "";
            if (!start) return `${count} action${count === 1 ? "" : "s"}`;
            if (end && end !== start) return `${count} actions • ${start} → ${end}`;
            return `${count} actions • ${start}`;
          })();

          return `
            <div class="tGroup">
              <div class="tGroupHead">
                <div class="tGroupTitle">${esc(s.sectionName)}</div>
                <div class="tGroupSub">${esc(headerMeta)}</div>
              </div>

              <div class="tGroupBody">
                ${eventsNewestFirst.map((ev) => {
                  const k = keyOf(ev);
                  const from = (ev.from_section || "").toString().trim();
                  const to   = (ev.to_section || "").toString().trim();
                  const moveText = (from || to) ? `${from || "—"} → ${to || "—"}` : "";

                  return `
                    <div class="tLine action-${esc(k)}">
                      <div class="tLineLeft">
                        <span class="tLineTime">${esc(fmt(ev.acted_at))}</span>
                        <span class="tLineTag">${esc(prettyAction(k).toUpperCase())}</span>
                      </div>

                      <div class="tLineMain">
                        <div class="tLineTitle">${esc(ev.title || `${(ev.actor || "System")} updated the document`)}</div>
                        ${moveText ? `<div class="tLineMove">${esc(moveText)}</div>` : ``}
                        ${ev.remarks ? `<div class="tLineNote">${esc(ev.remarks)}</div>` : ``}
                      </div>
                    </div>
                  `;
                }).join("")}
              </div>
            </div>
          `;
        }).join("");

        return `<div class="tGrouped">${rendered || `<div class="mini" style="opacity:.7;">No timeline yet.</div>`}</div>`;
      }

      const LS_KEY = "dt_timeline_view";
      const saved = (localStorage.getItem(LS_KEY) || "events").toLowerCase();
      let view = (saved === "grouped") ? "grouped" : "events";

      elTimeline.innerHTML = `
        <div class="tToolbar">
          <button type="button" class="tToggle ${view === "events" ? "isOn" : ""}" data-view="events">Events</button>
          <button type="button" class="tToggle ${view === "grouped" ? "isOn" : ""}" data-view="grouped">By Section</button>
        </div>
        <div id="timelineBody"></div>
      `;

      const body = elTimeline.querySelector("#timelineBody");
      const buttons = elTimeline.querySelectorAll(".tToggle");

      function paint() {
        if (!body) return;
        body.innerHTML = (view === "grouped") ? renderGroupedView(items) : renderEventsView(items);
        buttons.forEach(b => b.classList.toggle("isOn", b.dataset.view === view));
        localStorage.setItem(LS_KEY, view);
      }

      buttons.forEach(b => {
        b.addEventListener("click", () => {
          view = (b.dataset.view === "grouped") ? "grouped" : "events";
          paint();
        });
      });

      paint();

    } catch {
      elTimeline.textContent = "Failed to load timeline.";
    }
  }

  async function updateStatus(newStatus) {
    const docId = elId?.value;
    if (!docId) return;

    const form = new FormData();
    form.append("document_id", docId);
    form.append("new_status", newStatus);
    form.append("remarks", elRemarks ? elRemarks.value : "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/update_status.php`, {
        method: "POST",
        body: form,
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        alert(data?.error || `Failed to update status. (${res.status})`);
        return;
      }
      location.reload();
    } catch {
      alert("Failed to update status (network error).");
    }
  }

  async function ackReceived() {
    const docId = elId?.value;
    if (!docId) return;

    const form = new FormData();
    form.append("document_id", docId);
    form.append("remarks", elRemarks ? elRemarks.value : "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/ack_received.php`, {
        method: "POST",
        body: form,
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        alert(data?.error || `Failed to acknowledge received. (${res.status})`);
        return;
      }
      location.reload();
    } catch {
      alert("Failed to acknowledge received (network error).");
    }
  }

  async function forwardDoc() {
    const docId = elId?.value;
    if (!docId) return;

    const toSectionId = Number.parseInt(selForwardTo?.value || "0", 10) || 0;
    if (toSectionId <= 0) {
      alert("Please select a destination section.");
      return;
    }

    const form = new FormData();
    form.append("document_id", docId);
    form.append("to_section_id", String(toSectionId));
    form.append("remarks", elRemarks ? elRemarks.value : "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/forward.php`, {
        method: "POST",
        body: form,
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        alert(data?.error || `Failed to forward. (${res.status})`);
        return;
      }
      location.reload();
    } catch {
      alert("Failed to forward (network error).");
    }
  }

  closeBtn?.addEventListener("click", closeDrawer);
  backdrop?.addEventListener("click", closeDrawer);

  document.querySelectorAll("[data-doc]").forEach((row) => {
    row.addEventListener("click", () => {
      const raw = row.getAttribute("data-doc") || "{}";
      let payload;
      try { payload = JSON.parse(raw); } catch { payload = {}; }
      openDrawer(payload);
    });
  });

  btnToggleAttachments?.addEventListener("click", () => {
    if (!elAttachments) return;
    elAttachments.classList.toggle("collapsed");
    syncToggleLabels();
  });

  btnToggleUpload?.addEventListener("click", () => {
    if (!attachForm) return;
    attachForm.classList.toggle("collapsed");
    syncToggleLabels();
  });

  // ✅ Toggle-only behavior
  btnToggleForward?.addEventListener("click", () => {
    if (!forwardBox) return;

    forwardBox.classList.toggle("collapsed");
    syncToggleLabels();
    updateForwardUI();

    if (!forwardBox.classList.contains("collapsed")) {
      selForwardTo?.focus();
    }
  });

  btnAckReceived?.addEventListener("click", ackReceived);
  btnUnderAction?.addEventListener("click", () => updateStatus("ACTIVE"));

  btnRelease?.addEventListener("click", () => {
    const next = (btnRelease.dataset.nextStatus || "RELEASED").toUpperCase();
    updateStatus(next);
  });

  btnArchive?.addEventListener("click", () => {
    const next = (btnArchive.dataset.nextStatus || "ARCHIVED").toUpperCase();
    updateStatus(next);
  });

  btnForward?.addEventListener("click", forwardDoc);
  btnAttachUpload?.addEventListener("click", uploadAttachment);

  // View document hook
  btnViewDocument?.addEventListener("click", () => {
    const docId = btnViewDocument.dataset.docId || elId?.value || "";
    if (!docId) return;

    if (window.DTMergeView && typeof window.DTMergeView.open === "function") {
      window.DTMergeView.open(docId);
      return;
    }

    document.dispatchEvent(new CustomEvent("dt:view_document", { detail: { documentId: docId } }));
  });

  // initial label sync (in case drawer starts open)
  syncToggleLabels();
  updateForwardUI();
})();
