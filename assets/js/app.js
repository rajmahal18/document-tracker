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

  // Drawer fields
  const elStatus = document.getElementById("d_status"); // chip: Current Holder / IN TRANSIT
  const elDestination = document.getElementById("d_destination");
  const elLastHolder = document.getElementById("d_last_holder");

  const elRemarks = document.getElementById("d_remarks");
  const elTimeline = document.getElementById("d_timeline");

  const btnUnderAction = document.getElementById("btnUnderAction");
  const btnAckReceived = document.getElementById("btnAckReceived");
  const btnRelease = document.getElementById("btnRelease");
  const btnArchive = document.getElementById("btnArchive");

  // Base paths from PHP
  const APP = window.__APP__ || {};
  const API = APP.api || "/document-tracker/api";

  function esc(s) {
    return (s ?? "").toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function prettyAction(a) {
    const key = (a ?? "").toString().trim().toLowerCase();
    const map = {
      created: "Created",
      sent: "Sent",
      received: "Received",
      forwarded: "Forwarded",
      released: "Released",
      archived: "Archived",
      cancelled: "Cancelled",
      under_action: "Under Action",
      updated: "Updated",
      status_changed: "Status Changed",
    };
    return map[key] || (key ? key : "Updated");
  }

  function openDrawer(payload) {
    // Basic fields
    if (elId) elId.value = payload.id || "";
    if (elTracking) elTracking.textContent = payload.tracking_no || "";

    if (elRequester) elRequester.textContent = payload.requester || "—";
    if (elDate) elDate.textContent = payload.document_date || "—";
    if (elSubject) elSubject.textContent = payload.subject || "—";
    if (elType) elType.textContent = payload.content_type || "—";
    if (elDays) elDays.textContent = payload.days_stuck ?? "0";

    const inTransit = (
      payload.in_transit === 1 ||
      payload.in_transit === "1" ||
      payload.in_transit === true
    );
    


    // Current holder chip
    if (elStatus) {
      if (inTransit) {
        elStatus.textContent = "IN TRANSIT";
        elStatus.className = "chip action";
      } else {
        elStatus.textContent = payload.current_holder_text || "—";
        elStatus.className = "chip incoming";
      }
    }

    // Destination + Last holder
    if (elDestination) elDestination.textContent = payload.movement_text || "—";
    if (elLastHolder) elLastHolder.textContent = payload.last_holder_text || "—";

    if (elRemarks) elRemarks.value = "";

    if (elTimeline) elTimeline.textContent = "Loading timeline…";
    if (payload.id) loadTimeline(payload.id);

    // ✅ IMPORTANT: open drawer FIRST so returns won’t prevent UI opening
    backdrop?.classList.add("open");
    drawer?.classList.add("open");

    // =========================
    // ✅ Button visibility logic
    // =========================
    const ctx = window.__CTX__ || {};
    const myRole = (ctx.myRole || "division").toString().toLowerCase();
    const mySectionId = Number(ctx.mySectionId || 0);

    const openToSectionId = Number.parseInt(payload.open_to_section_id, 10) || 0;
    const holderSectionId = Number.parseInt(payload.current_holder_section_id, 10) || 0;

    // Hide all by default
    if (btnAckReceived) btnAckReceived.style.display = "none";
    if (btnRelease) btnRelease.style.display = "none";
    if (btnArchive) btnArchive.style.display = "none";
    if (btnUnderAction) btnUnderAction.style.display = "none";

    console.log("[BTN-CTX]", {
      myRole,
      mySectionId,
      inTransit,
      openToSectionId,
      holderSectionId,
      payload_in_transit_raw: payload.in_transit,
      payload_open_to_raw: payload.open_to_section_id,
      payload_holder_raw: payload.current_holder_section_id,
    });

    // Records/Admin: show all main controls (your current policy)
    if (myRole === "admin" || myRole === "records") {
      if (btnAckReceived) btnAckReceived.style.display = "";
      if (btnRelease) btnRelease.style.display = "";
      if (btnArchive) btnArchive.style.display = "";
      // if (btnUnderAction) btnUnderAction.style.display = "";
      return;
    }

    // Non-records rules
    if (inTransit) {
      // Only pending recipient can "Receive"
      if (openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId) {
        if (btnAckReceived) btnAckReceived.style.display = "";
      }
      // No release/archive while in transit for non-records
      return;
    }

    // Not in transit: only current holder can Release (archive stays records/admin only)
    if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
      if (btnRelease) btnRelease.style.display = "";
    }
  }

  function closeDrawer() {
    drawer?.classList.remove("open");
    backdrop?.classList.remove("open");
  }

  async function loadTimeline(docId) {
    if (!elTimeline) return;

    try {
      const url = `${API}/get_history.php?document_id=${encodeURIComponent(docId)}`;
      const res = await fetch(url, { headers: { "Accept": "application/json" } });

      if (!res.ok) {
        elTimeline.textContent = `Failed to load timeline. (${res.status})`;
        return;
      }

      let data;
      try {
        data = await res.json();
      } catch {
        elTimeline.textContent = "Failed to load timeline (invalid JSON response).";
        return;
      }

      if (!data.ok) {
        elTimeline.textContent = data.error || "No timeline.";
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

      elTimeline.innerHTML = `
        <div class="timeline">
          ${items.map((i, idx) => {
            // get_history.php returns: action, remarks, acted_at, actor
            const actionKey = (i.action ?? "updated").toString().trim().toLowerCase() || "updated";
            return `
              <div class="tItem action-${esc(actionKey)} ${idx === 0 ? "isCurrent" : ""}">
                <div class="tIcon"></div>

                <div class="tContent">
                  <div class="tRow">
                    <div class="tMeta tMetaLeft">
                      ${esc(fmt(i.acted_at))}<br>
                      ${esc(i.actor || "System")}
                    </div>

                    <div class="tAction">
                      ${esc(prettyAction(actionKey).toUpperCase())}
                    </div>
                  </div>

                  ${i.remarks ? `<div class="tRemark">${esc(i.remarks)}</div>` : ""}
                </div>
              </div>
            `;
          }).join("")}
        </div>
      `;
    } catch (e) {
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
        console.log("Update status response:", data);
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
        console.log("Ack received response:", data);
        alert(data?.error || `Failed to acknowledge received. (${res.status})`);
        return;
      }

      location.reload();
    } catch {
      alert("Failed to acknowledge received (network error).");
    }
  }

  // Events
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

  btnAckReceived?.addEventListener("click", ackReceived);

  // Optional: repurpose UnderAction button to ACTIVE (if you keep it in UI)
  btnUnderAction?.addEventListener("click", () => updateStatus("ACTIVE"));

  btnRelease?.addEventListener("click", () => updateStatus("RELEASED"));
  btnArchive?.addEventListener("click", () => updateStatus("ARCHIVED"));
})();
