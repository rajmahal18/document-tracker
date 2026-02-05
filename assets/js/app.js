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
  const elRemarks = document.getElementById("d_remarks");
  const elTimeline = document.getElementById("d_timeline");

  const btnUnderAction = document.getElementById("btnUnderAction");
  const btnRelease = document.getElementById("btnRelease");
  const btnArchive = document.getElementById("btnArchive");

  function esc(s) {
    return (s ?? "").toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function prettyAction(a) {
    return ({
      received: "Received",
      forwarded: "Forwarded",
      released: "Released",
      archived: "Archived",
      updated: "Updated"
    }[a] || a);
  }

  function openDrawer(payload) {
    if (elId) elId.value = payload.id || "";
    if (elTracking) elTracking.textContent = payload.tracking_no || "";
    if (elRequester) elRequester.textContent = payload.requester || "";
    if (elDate) elDate.textContent = payload.document_date || "";
    if (elSubject) elSubject.textContent = payload.subject || "";
    if (elType) elType.textContent = payload.content_type || "";
    if (elDays) elDays.textContent = payload.days_stuck ?? "0";

    if (elStatus) {
      elStatus.textContent = payload.status_label || "Unknown";
      elStatus.className = "chip " + (payload.status_class || "archived");
    }

    if (elRemarks) elRemarks.value = "";

    if (elTimeline) elTimeline.textContent = "Loading timeline…";
    if (payload.id) loadTimeline(payload.id);

    backdrop?.classList.add("open");
    drawer?.classList.add("open");
  }

  function closeDrawer() {
    drawer?.classList.remove("open");
    backdrop?.classList.remove("open");
  }

  async function loadTimeline(docId) {
    if (!elTimeline) return;

    try {
      const res = await fetch(`/document-tracker/get_history.php?document_id=${encodeURIComponent(docId)}`);
      if (!res.ok) {
        elTimeline.textContent = "Failed to load timeline.";
        return;
      }

      const data = await res.json();
      if (!data.ok) {
        elTimeline.textContent = data.error || "No timeline.";
        return;
      }

      const items = data.history || [];
      if (items.length === 0) {
        elTimeline.textContent = "No history yet.";
        return;
      }

      elTimeline.innerHTML = `
        <div class="timeline">
            ${items.map(i => `
            <div class="tItem action-${esc(i.action)}">
                <div class="tIcon"></div>

                <div class="tContent">
                <div class="tRow">
                    <div class="tAction">${esc(prettyAction(i.action))}</div>
                    <div class="tMeta">${esc(i.acted_at)} • ${esc(i.actor)}</div>
                </div>

                ${i.remarks ? `<div class="tRemark">${esc(i.remarks)}</div>` : ""}
                </div>
            </div>
            `).join("")}
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

    const res = await fetch("/document-tracker/update_status.php", {
      method: "POST",
      body: form
    });

    if (!res.ok) {
      alert("Failed to update status. Check update_status.php.");
      return;
    }

    // simplest reliable refresh:
    location.reload();
  }

  closeBtn?.addEventListener("click", closeDrawer);
  backdrop?.addEventListener("click", closeDrawer);

  document.querySelectorAll("[data-doc]").forEach((row) => {
    row.addEventListener("click", () => {
      const payload = JSON.parse(row.getAttribute("data-doc"));
      openDrawer(payload);
    });
  });

  btnUnderAction?.addEventListener("click", () => updateStatus("under_action"));
  btnRelease?.addEventListener("click", () => updateStatus("released"));
  btnArchive?.addEventListener("click", () => updateStatus("archived"));
})();
