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

  const forwardBox = document.getElementById("forwardBox");
  const selForwardTo = document.getElementById("f_to_section");
  const btnForward = document.getElementById("btnForward");

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

  function loadSectionsOptions() {
    if (!selForwardTo) return;
    const list = window.__SECTIONS__ || [];
    selForwardTo.innerHTML =
      `<option value="">-- Select section --</option>` +
      list.map(s => `<option value="${Number(s.id)}">${esc(s.name)}</option>`).join("");
  }
  loadSectionsOptions();

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
      cancelled: "Cancelled",
      under_action: "Under Action",
      updated: "Updated",
      status_changed: "Status Changed", // kept for old rows, but new rows should stop using this
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

    if (forwardBox) forwardBox.style.display = "none";
    if (btnForward) btnForward.style.display = "none";
    if (selForwardTo) selForwardTo.value = "";

    const inTransit = (
      payload.in_transit === 1 ||
      payload.in_transit === "1" ||
      payload.in_transit === true
    );

    // IMPORTANT: make sure documents.php includes this in data-doc
    const docStatus = (payload.current_status || "ACTIVE").toString().toUpperCase();

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

    // Reset defaults
    if (btnRelease) {
      btnRelease.textContent = "Release";
      btnRelease.dataset.nextStatus = "RELEASED";
    }
    if (btnArchive) {
      btnArchive.textContent = "Archive";
      btnArchive.dataset.nextStatus = "ARCHIVED";
    }

    // ---- Status-based hard rules FIRST ----
    // ARCHIVED: only Undo Archive (admin/records), nothing else should appear
    if (docStatus === "ARCHIVED") {
      if (myRole === "admin" || myRole === "records") {
        if (btnArchive) {
          btnArchive.textContent = "Undo Archive";
          btnArchive.dataset.nextStatus = "RELEASED";
          btnArchive.style.display = "";
        }
      }
      return;
    }

    // RELEASED: hide Receive always. Release becomes Undo Release.
    if (docStatus === "RELEASED") {
      // release button becomes undo (holder/admin/records only)
      if (btnRelease) {
        btnRelease.textContent = "Undo Release";
        btnRelease.dataset.nextStatus = "ACTIVE";
      }
      // NOTE: keep going to apply role gating + archive availability
      // but NEVER show Received in RELEASED state.
    }

    // ---- Role gating ----
    // Admin/Records: can see Release/UndoRelease (depending on status) + Archive (or UndoArchive earlier handled)
    if (myRole === "admin" || myRole === "records") {
      if (docStatus === "ACTIVE") {
        // In ACTIVE, show Received only when appropriate, not always
        if (inTransit && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId) {
          if (btnAckReceived) btnAckReceived.style.display = "";
        }
      }
      // Release or Undo Release visible for admin/records (you chose this policy)
      if (btnRelease) btnRelease.style.display = "";

      // Archive visible for admin/records.
      if (btnArchive) btnArchive.style.display = "";
      return;
    }

    // ---- Non-records (division) ----
    // RELEASED: no receive, no forward. Only Undo Release for holder.
    if (docStatus === "RELEASED") {
      if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
        if (btnRelease) btnRelease.style.display = ""; // Undo Release -> ACTIVE
      }
      return;
    }

    // ACTIVE flow for non-records
    if (inTransit) {
      // Only pending recipient can "Receive"
      if (openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId) {
        if (btnAckReceived) btnAckReceived.style.display = "";
      }
      // No release while in transit for non-records
      return;
    }

    // Not in transit: only current holder can Release + Forward
    if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
      if (btnRelease) btnRelease.style.display = ""; // Release -> RELEASED
      if (forwardBox) forwardBox.style.display = "";
      if (btnForward) btnForward.style.display = "";
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

      // ---------- helpers ----------
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

                    ${i.remarks ? `<div class="tNote">${esc(i.remarks)}</div>` : ""}
                  </div>
                </div>
              `;
            }).join("")}
          </div>
        `;
      }

      function renderGroupedView(itemsNewestFirst) {
        // ---------- helpers ----------
        const actionRank = (key) => {
          const k = (key || "updated").toString().trim().toLowerCase();
          // Lower = earlier (older)
          const rank = {
            created: 10,
            sent: 20,
            received: 30,
            forwarded: 40,
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

        // Higher = later (newer) for tie-breaker when sorting section boxes by "latest activity"
        const actionRankNewerWins = (key) => {
          const k = (key || "updated").toString().trim().toLowerCase();
          const rank = {
            updated: 10,
            status_changed: 20,
            cancelled: 30,
            archive_undone: 40,
            archived: 50,
            release_undone: 60,
            released: 70,
            forwarded: 80,
            received: 90,
            sent: 100,
            created: 110,
          };
          return rank[k] ?? 0;
        };

        const ts = (dt) => {
          const t = new Date((dt || "").toString().replace(" ", "T")).getTime();
          return isNaN(t) ? 0 : t;
        };

        // Expect getKey(), fmt(), esc(), prettyAction() to exist in your outer scope (as in your file)
        // getKey(i) = (i.action ?? "updated").toString().trim().toLowerCase() || "updated";

        // 1) Convert newest-first -> true chronological (oldest -> newest)
        //    Tie-break: SENT before RECEIVED when same timestamp, etc.
        const itemsChrono = [...itemsNewestFirst].sort((a, b) => {
          const da = ts(a.acted_at);
          const db = ts(b.acted_at);
          if (da !== db) return da - db;

          const ra = actionRank(getKey(a));
          const rb = actionRank(getKey(b));
          if (ra !== rb) return ra - rb;

          // stable-ish tiebreak: event_id if present
          const ida = Number(a.event_id || 0);
          const idb = Number(b.event_id || 0);
          if (ida !== idb) return ida - idb;

          return 0;
        });

        // 2) Group by actor_section_id (fallback: SYS)
        const groups = new Map(); // key -> { sectionName, items: [] } where items are chronological
        const keys = [];          // list of group keys (unique)

        for (const ev of itemsChrono) {
          const sid = Number(ev.actor_section_id || 0);
          const key = sid > 0 ? `S:${sid}` : "SYS";
          const secName =
            (ev.actor_section || "").toString().trim() ||
            (sid > 0 ? `Section #${sid}` : "System");

          if (!groups.has(key)) {
            groups.set(key, { sectionName: secName, items: [] });
            keys.push(key);
          }
          groups.get(key).items.push(ev);
        }

        // 3) Sort section boxes by latest activity (newest first)
        //    Tie-breaker: if same timestamp, RECEIVER box should win (received > sent)
        keys.sort((ka, kb) => {
          const a = groups.get(ka)?.items || [];
          const b = groups.get(kb)?.items || [];

          const aLast = a.length ? ts(a[a.length - 1].acted_at) : 0; // items chronological
          const bLast = b.length ? ts(b[b.length - 1].acted_at) : 0;

          if (aLast !== bLast) return bLast - aLast;

          const aKey = a.length ? getKey(a[a.length - 1]) : "updated";
          const bKey = b.length ? getKey(b[b.length - 1]) : "updated";

          const aR = actionRankNewerWins(aKey);
          const bR = actionRankNewerWins(bKey);

          if (aR !== bR) return bR - aR; // higher rank wins (receiver > sender)

          // final stable tie-breaker: keep consistent order
          return ka.localeCompare(kb);
        });

        // 4) Render groups (each group newest on top, but logic stays chronological internally)
        const rendered = keys.map((key) => {
          const g = groups.get(key);
          if (!g) return "";

          const list = g.items; // chronological (oldest -> newest)

          // Apply your rule: for non-origin sections, start at first RECEIVED
          // (Creation is only exception: origin section may include CREATED before RECEIVED)
          const firstReceivedIdx = list.findIndex((x) => getKey(x) === "received");
          const firstCreatedIdx  = list.findIndex((x) => getKey(x) === "created");

          let displayList = list;

          if (key !== "SYS") {
            if (firstReceivedIdx >= 0) {
              if (firstCreatedIdx >= 0 && firstCreatedIdx < firstReceivedIdx) {
                displayList = list.slice(firstCreatedIdx);
              } else {
                displayList = list.slice(firstReceivedIdx);
              }
            }
          }

          const headerMeta = (() => {
            const first = displayList[0]?.acted_at ? fmt(displayList[0].acted_at) : "";
            const last  = displayList[displayList.length - 1]?.acted_at
              ? fmt(displayList[displayList.length - 1].acted_at)
              : "";
            const count = displayList.length;

            if (!first) return `${count} action${count === 1 ? "" : "s"}`;
            return `${count} action${count === 1 ? "" : "s"} • ${first}${last && last !== first ? ` → ${last}` : ""}`;
          })();

          // UI wants newest on top inside the box
          const newestFirst = [...displayList].reverse();

          return `
            <div class="tGroup">
              <div class="tGroupHead">
                <div class="tGroupTitle">${esc(g.sectionName)}</div>
                <div class="tGroupSub">${esc(headerMeta)}</div>
              </div>

              <div class="tGroupBody">
                ${newestFirst.map((i) => {
                  const actionKey = getKey(i);

                  const from = (i.from_section || "").toString().trim();
                  const to   = (i.to_section || "").toString().trim();

                  const moveText = (from || to) ? `${from || "—"} → ${to || "—"}` : "";

                  return `
                    <div class="tLine action-${esc(actionKey)}">
                      <div class="tLineLeft">
                        <span class="tLineTime">${esc(fmt(i.acted_at))}</span>
                        <span class="tLineTag">${esc(prettyAction(actionKey).toUpperCase())}</span>
                      </div>

                      <div class="tLineMain">
                        <div class="tLineTitle">${esc(i.title || `${(i.actor || "System")} updated the document`)}</div>
                        ${moveText ? `<div class="tLineMove">${esc(moveText)}</div>` : ``}
                        ${i.remarks ? `<div class="tLineNote">${esc(i.remarks)}</div>` : ``}
                      </div>
                    </div>
                  `;
                }).join("")}
              </div>
            </div>
          `;
        }).join("");

        return `<div class="tGrouped">${rendered}</div>`;
      }


      // ---------- UI toggle ----------
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
        body.innerHTML = (view === "grouped")
          ? renderGroupedView(items)
          : renderEventsView(items);

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
        console.log("Forward response:", data);
        alert(data?.error || `Failed to forward. (${res.status})`);
        return;
      }

      location.reload();
    } catch {
      alert("Failed to forward (network error).");
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

  // IMPORTANT: use dataset.nextStatus (Release can become Undo Release, Archive can become Undo Archive)
  btnRelease?.addEventListener("click", () => {
    const next = (btnRelease.dataset.nextStatus || "RELEASED").toUpperCase();
    updateStatus(next);
  });

  btnArchive?.addEventListener("click", () => {
    const next = (btnArchive.dataset.nextStatus || "ARCHIVED").toUpperCase();
    updateStatus(next);
  });

  btnForward?.addEventListener("click", forwardDoc);

})();
