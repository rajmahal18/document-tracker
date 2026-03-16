(function () {
  const backdrop = document.getElementById("drawerBackdrop");
  const drawer = document.getElementById("drawer");
  const closeBtn = document.getElementById("drawerClose");

  const elId = document.getElementById("d_id");
  const elTracking = document.getElementById("d_tracking");
  const elRequester = document.getElementById("d_requester");
  const elDate = document.getElementById("d_date");
  const elDeadline = document.getElementById("d_deadline");
  const elPersonalDeadline = document.getElementById("d_personal_deadline");
  const elDeadlineCountdown = document.getElementById("d_deadline_countdown");
  const elSubject = document.getElementById("d_subject");
  const elType = document.getElementById("d_type");
  const elDays = document.getElementById("d_days");

  const elStatus = document.getElementById("d_status");
  const elHolder = document.getElementById("d_holder");
  const elDestination = document.getElementById("d_destination");
  const elDestinationText = document.getElementById("d_destination_text");
  const elLastHolder = document.getElementById("d_last_holder");

  const elActionRemarks = document.getElementById("d_action_remarks");
  const elTimeline = document.getElementById("d_timeline");
  const elBranchWrap = document.getElementById("d_branch_wrap");
  const elBranchBar = document.getElementById("d_branch_bar");
  const elBranchMeta = document.getElementById("d_branch_meta");
  const elBranchHint = document.getElementById("d_branch_hint");

  const elAttachments = document.getElementById("d_attachments");
  const btnViewDocument = document.getElementById("btnViewDocument");

  const rowPpdSlip = document.getElementById("rowPpdSlip");
  const btnPpdSlipGenerate = document.getElementById("btnPpdSlipGenerate");
  const btnPpdSlipAttach = document.getElementById("btnPpdSlipAttach");
  const btnPpdSlipPrint = document.getElementById("btnPpdSlipPrint");
  let currentPpdSlipAttId = 0;

  const btnToggleAttachments = document.getElementById("btnToggleAttachments");
  const btnToggleUpload = document.getElementById("btnToggleUpload");
  const btnToggleForward = document.getElementById("btnToggleForward");

  const forwardPersonalDeadlineWrap = document.getElementById("forwardPersonalDeadlineWrap");
  const inputForwardPersonalDeadline = document.getElementById("f_personal_deadline");

  const attachForm = document.getElementById("attachForm");
  const attachFile = document.getElementById("attachFile");
  const attachType = document.getElementById("attachType");
  const attachNote = document.getElementById("attachNote");
  const btnAttachUpload = document.getElementById("btnAttachUpload");
  const attachMsg = document.getElementById("attachMsg");

  const btnUnderAction = document.getElementById("btnUnderAction");
  const btnAckReceived = document.getElementById("btnAckReceived");
  const btnRelease = document.getElementById("btnRelease");
  const btnArchive = document.getElementById("btnArchive");

  const forwardBox = document.getElementById("forwardBox");
  const selForwardTo = document.getElementById("f_to_section");
  const elUserList = document.getElementById("f_user_list");
  const btnUserSelectAll = document.getElementById("btnUserSelectAll");
  const btnUserClear = document.getElementById("btnUserClear");
  const elRecipientsPreview = document.getElementById("forwardRecipientsPreview");
  const elForwardModeWrap = document.getElementById("forwardModeWrap");
  const cbReceiveOnly = document.getElementById("f_receive_only");
  const elReceiveOnlyHint = document.getElementById("f_receive_only_hint");
  const btnForward = document.getElementById("btnForward");

  const recModal = document.getElementById("recModal");
  const recModalBackdrop = document.getElementById("recModalBackdrop");
  const recClose = document.getElementById("recClose");
  const recBody = document.getElementById("recBody");
  const recTitle = document.getElementById("recTitle");
  const recSub = document.getElementById("recSub");

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

  let currentCanForward = false;
  let currentPayload = null;
  let currentBranchMode = false;
  let currentBranches = [];
  let currentBranchId = 0;
  let deadlineTicker = null;

  function esc(s) {
    return (s ?? "").toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function clean(v) {
    const s = (v ?? "").toString().trim();
    if (!s) return "";
    if (["-", "—", "n/a", "na", "null", "undefined"].includes(s.toLowerCase())) return "";
    return s;
  }

  function normalizedRemarksValue() {
    return (elActionRemarks?.value ?? "").toString().trim();
  }

  function fmtBytes(n) {
    const b = Number(n || 0);
    if (!isFinite(b) || b <= 0) return "—";
    const units = ["B", "KB", "MB", "GB"];
    let i = 0;
    let v = b;
    while (v >= 1024 && i < units.length - 1) {
      v /= 1024;
      i++;
    }
    return `${v.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
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

  function formatCountdown(ms) {
    const totalMinutes = Math.max(0, Math.floor(ms / 60000));
    const days = Math.floor(totalMinutes / (60 * 24));
    const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
  }

  function renderDeadline(documentDeadlineAt, personalDeadlineAt = "") {
    const docRaw = (documentDeadlineAt || "").toString().trim();
    const personalRaw = (personalDeadlineAt || "").toString().trim();
    const effectiveRaw = personalRaw || docRaw;

    if (elDeadline) elDeadline.textContent = docRaw ? fmt(docRaw) : "—";
    if (elPersonalDeadline) elPersonalDeadline.textContent = personalRaw ? fmt(personalRaw) : "—";

    if (deadlineTicker) {
      clearInterval(deadlineTicker);
      deadlineTicker = null;
    }

    if (!elDeadlineCountdown) return;
    if (!effectiveRaw) {
      elDeadlineCountdown.textContent = "No deadline set";
      return;
    }

    const deadlineDate = new Date(effectiveRaw.replace(" ", "T"));
    if (Number.isNaN(deadlineDate.getTime())) {
      elDeadlineCountdown.textContent = "—";
      return;
    }

    const tick = () => {
      const diff = deadlineDate.getTime() - Date.now();
      if (diff < 0) {
        const lateDays = Math.max(1, Math.ceil(Math.abs(diff) / 86400000));
        elDeadlineCountdown.textContent = lateDays === 1 ? "OVERDUE BY 1 DAY" : `OVERDUE BY ${lateDays} DAYS`;
        return;
      }
      if (diff <= 86400000) {
        elDeadlineCountdown.textContent = "DUE TODAY";
        return;
      }
      const daysLeft = Math.floor(diff / 86400000);
      elDeadlineCountdown.textContent = daysLeft <= 1 ? "1 DAY LEFT" : `${daysLeft} DAYS LEFT`;
    };

    tick();
    deadlineTicker = window.setInterval(tick, 60000);
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

  function actionIcon(k) {
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
  }

  function getKey(i) {
    return (i?.action ?? "updated").toString().trim().toLowerCase() || "updated";
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

  function getSelectedBranch() {
    return currentBranches.find((b) => Number(b.id || 0) === Number(currentBranchId || 0)) || null;
  }


  function getBranchPrefStorageKey(docId) {
    const id = Number(docId || currentPayload?.id || 0);
    const ctx = window.__CTX__ || {};
    const uid = Number(ctx.myUserId || 0);
    return id > 0 ? `dt_selected_branch_u${uid}_d${id}` : '';
  }
  function savePreferredBranchId(docId, branchId) {
    const key = getBranchPrefStorageKey(docId);
    const bid = Number(branchId || 0);
    if (!key) return;
    try {
      if (bid > 0) sessionStorage.setItem(key, String(bid));
      else sessionStorage.removeItem(key);
    } catch (_) {}
  }

  function loadPreferredBranchId(docId) {
    const key = getBranchPrefStorageKey(docId);
    if (!key) return 0;
    try {
      return Number(sessionStorage.getItem(key) || 0) || 0;
    } catch (_) {
      return 0;
    }
  }

  function preferredBranchId(branches) {
    const list = Array.isArray(branches) ? branches : [];
    const pending = list.find((b) => Number(b.my_pending_route_id || 0) > 0);
    if (pending) return Number(pending.id || 0);
    const mine = list.find((b) => Number(b.can_forward || 0) === 1);
    if (mine) return Number(mine.id || 0);
    return Number(list[0]?.id || 0);
  }

  function branchLabel(branch) {
    const raw = clean(branch?.branch_label);
    if (raw) return raw;
    const id = Number(branch?.id || 0);
    return id > 0 ? `Branch ${id}` : "Branch";
  }

  function getBranchById(branchId) {
    return currentBranches.find((b) => Number(b.id || 0) === Number(branchId || 0)) || null;
  }

  function getBranchLineageIds(branchId) {
    const lineage = new Set();
    let guard = 0;
    let cursor = getBranchById(branchId);

    while (cursor && guard < 100) {
      const id = Number(cursor.id || 0);
      if (id <= 0 || lineage.has(id)) break;
      lineage.add(id);

      const parentId = Number(cursor.parent_branch_id || 0);
      if (parentId <= 0) break;
      cursor = getBranchById(parentId);
      guard += 1;
    }

    return lineage;
  }

  function getSwitcherActiveBranchId(branchIds) {
    const ids = (Array.isArray(branchIds) ? branchIds : []).map((id) => Number(id || 0)).filter((id) => id > 0);
    if (!ids.length) return 0;
    if (ids.includes(Number(currentBranchId || 0))) return Number(currentBranchId || 0);

    const lineage = Array.from(getBranchLineageIds(currentBranchId));
    for (const lineageId of lineage) {
      if (ids.includes(Number(lineageId || 0))) return Number(lineageId || 0);
    }
    return 0;
  }

  function syncInlineBranchSelection() {
    const activeLineage = getBranchLineageIds(currentBranchId);
    document.querySelectorAll(".inlineBranchBar").forEach((bar) => {
      const branchIds = Array.from(bar.querySelectorAll(".inlineBranchPill")).map((btn) => Number(btn.dataset.branchId || 0));
      const switcherActiveId = getSwitcherActiveBranchId(branchIds);
      bar.querySelectorAll(".inlineBranchPill").forEach((btn) => {
        const bid = Number(btn.dataset.branchId || 0);
        btn.classList.toggle("isActive", bid > 0 && bid === switcherActiveId);
        btn.classList.toggle("isLineActive", bid > 0 && activeLineage.has(bid));
      });
    });
  }

  function syncAttachmentButtonVisibility() {
    if (!btnToggleUpload) return;

    const ctx = window.__CTX__ || {};
    const myRole = (ctx.myRole || "user").toString().toLowerCase();
    const isPrivileged = myRole === "admin" || myRole === "records";
    const docStatus = (currentPayload?.current_status || "ACTIVE").toString().toUpperCase();

    let canAttach = false;
    if (docStatus === "ACTIVE") {
      if (currentBranchMode) {
        const branch = getSelectedBranch();
        canAttach = !!(isPrivileged || (branch && Number(branch.can_forward || 0) === 1));
      } else {
        const mySectionId = Number(ctx.mySectionId || 0);
        const holderSectionId = Number.parseInt(currentPayload?.current_holder_section_id, 10) || 0;
        const openFromSectionId = Number.parseInt(currentPayload?.open_from_section_id, 10) || 0;
        const inTransit = currentPayload?.in_transit === 1 || currentPayload?.in_transit === "1" || currentPayload?.in_transit === true;
        const holderStillSending = inTransit && holderSectionId > 0 && openFromSectionId > 0 && openFromSectionId === holderSectionId;

        canAttach = !!(isPrivileged || (
          holderSectionId > 0 &&
          mySectionId > 0 &&
          holderSectionId === mySectionId &&
          !holderStillSending
        ));
      }
    }

    btnToggleUpload.style.display = canAttach ? "" : "none";
  }

  function applyBranchSelection(branchId) {
    currentBranchId = Number(branchId || 0);
    savePreferredBranchId(currentPayload?.id || 0, currentBranchId);
    const branch = getSelectedBranch();

    if (elBranchBar) {
      if (!branch) {
        elBranchBar.innerHTML = "";
      } else {
        elBranchBar.innerHTML = `
          <div class="branchBar activeLaneBar">
            <button type="button" class="${branchPillClassList(branch, currentBranchId).concat(["activeLanePill"]).join(" ")}" data-branch-id="${Number(branch.id || 0)}">${esc(branchLabel(branch))}${esc(branchPillSuffix(branch))}</button>
          </div>
        `;
      }
    }

    syncInlineBranchSelection();

    if (elBranchMeta) {
      if (!branch) {
        elBranchMeta.textContent = currentBranchMode ? "No branch selected." : "";
      } else {
        const bits = [];
        bits.push(`${branchLabel(branch)} • ${(branch.branch_status || "ACTIVE").toString().toUpperCase()}`);
        if (Number(branch.is_reference || 0) === 1) bits.push("Reference only");
        if (Number(branch.my_pending_route_id || 0) > 0) bits.push("Pending receive by you");
        else if (Number(branch.can_forward || 0) === 1) bits.push("Actionable by you");
        if (clean(branch.current_assignee_name)) {
          const sec = clean(branch.current_assignee_section_name);
          bits.push(`Assignee: ${branch.current_assignee_name}${sec ? ` (${sec})` : ""}`);
        }
        elBranchMeta.textContent = bits.join(" • ");
      }
    }

    currentCanForward = !!(branch && Number(branch.can_forward || 0) === 1);
    syncAttachmentButtonVisibility();
    updateForwardUI();

    if (btnAckReceived) {
      const canReceive = !!(
        branch &&
        Number(branch.my_pending_route_id || 0) > 0 &&
        (currentPayload?.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE"
      );
      btnAckReceived.style.display = canReceive ? "" : "none";
    }
  }

  function branchPillClassList(branch, activeBranchId) {
    const cls = ["branchPill"];
    if (Number(branch?.can_forward || 0) === 1) cls.push("isMine");
    if (Number(branch?.my_pending_route_id || 0) > 0) cls.push("isPending");
    if (((branch?.branch_status || "").toString().toUpperCase()) !== "ACTIVE") cls.push("isCompleted");
    if (Number(branch?.id || 0) === Number(activeBranchId || 0)) cls.push("isActive");
    return cls;
  }

  function branchPillSuffix(branch) {
    if (Number(branch?.my_pending_route_id || 0) > 0) return " • Receive";
    if (Number(branch?.can_forward || 0) === 1) return " • Your turn";
    return "";
  }

  function renderStandaloneBranchSwitcher(rawBranchIds, opts = {}) {
    if (!currentBranchMode) return "";
    if (opts.hidden) return "";

    const branchIds = Array.from(new Set((Array.isArray(rawBranchIds) ? rawBranchIds : [])
      .map((id) => Number(id || 0))
      .filter((id) => id > 0)));

    if (branchIds.length <= 1) return "";

    const branches = branchIds
      .map((id) => currentBranches.find((b) => Number(b.id || 0) === id))
      .filter(Boolean);

    if (branches.length <= 1) return "";

    const label = clean(opts.label) || "Branches";
    const switcherActiveId = getSwitcherActiveBranchId(branchIds);
    const activeLineage = getBranchLineageIds(currentBranchId);

    return `
      <div class="timelineSplitBar" data-inline-branch-root="1">
        <div class="timelineSplitLabel">${esc(label)}</div>
        <div class="branchBar inlineBranchBar splitBranchBar">
          ${branches.map((branch) => `
            <button
              type="button"
              class="${branchPillClassList(branch, switcherActiveId).concat(activeLineage.has(Number(branch.id || 0)) ? ["isLineActive"] : [], ["inlineBranchPill"]).join(" ")}"
              data-branch-id="${Number(branch.id || 0)}"
            >${esc(branchLabel(branch))}${esc(branchPillSuffix(branch))}</button>
          `).join("")}
        </div>
      </div>
    `;
  }

  function resolveGroupBranchId(items) {
    const lineage = getBranchLineageIds(currentBranchId);
    for (const item of items) {
      const bid = Number(item?.branch_id || 0);
      if (bid > 0 && lineage.has(bid)) return bid;
    }
    for (const item of items) {
      const bid = Number(item?.branch_id || item?.source_branch_id || 0);
      if (bid > 0) return bid;
    }
    return 0;
  }

  function findSplitEventForBranch(items, branchId) {
    const bid = Number(branchId || 0);
    if (bid <= 0) return null;

    let found = null;
    let foundTs = 0;

    items.forEach((item) => {
      if (item?.viewer_redacted) return;

      const newIds = Array.isArray(item?.new_branch_ids)
        ? item.new_branch_ids.map((id) => Number(id || 0))
        : [];

      if (!newIds.includes(bid)) return;

      const ts = new Date((item?.acted_at || "").toString().replace(" ", "T")).getTime() || 0;
      if (!found || ts > foundTs) {
        found = item;
        foundTs = ts;
      }
    });

    return found;
  }

  function renderBranchTabs(branches) {
    currentBranches = Array.isArray(branches) ? branches : [];

    const visibleBranches = currentBranches.filter((b) => Number(b.id || 0) > 0);

    if (!currentBranchMode || visibleBranches.length === 0) {
      currentBranchMode = false;
      if (elBranchWrap) elBranchWrap.style.display = "none";
      currentBranchId = 0;
      savePreferredBranchId(currentPayload?.id || 0, 0);
      return;
    }

    const savedBranchId = loadPreferredBranchId(currentPayload?.id || 0);
    const savedBranchStillVisible =
      savedBranchId > 0 &&
      visibleBranches.some((b) => Number(b.id || 0) === savedBranchId);

    const myPending = visibleBranches.find((b) => Number(b.my_pending_route_id || 0) > 0);
    const myActionable = visibleBranches.find((b) => Number(b.can_forward || 0) === 1);

    if (myPending) {
      currentBranchId = Number(myPending.id || 0);
    } else if (myActionable) {
      currentBranchId = Number(myActionable.id || 0);
    } else if (savedBranchStillVisible) {
      currentBranchId = savedBranchId;
    } else {
      currentBranchId = preferredBranchId(visibleBranches);
    }

    if (elBranchWrap) elBranchWrap.style.display = visibleBranches.length > 1 ? "" : "none";
    if (elBranchBar) elBranchBar.innerHTML = "";
    if (elBranchHint) {
      elBranchHint.textContent = visibleBranches.length > 1
        ? "Switch lanes from the split bars placed between timeline groups."
        : "";
    }

    applyBranchSelection(currentBranchId);
  }

  function updateForwardUI() {
    if (!forwardBox) return;

    const isOpen = !forwardBox.classList.contains("collapsed");
    const chiefCanSetDeadline = !!(window.__CTX__?.isChief);

    if (btnToggleForward) btnToggleForward.style.display = currentCanForward ? "" : "none";
    if (btnForward) btnForward.style.display = (currentCanForward && isOpen) ? "" : "none";
    if (elForwardModeWrap) elForwardModeWrap.style.display = (currentCanForward && isOpen) ? "" : "none";
    if (forwardPersonalDeadlineWrap) forwardPersonalDeadlineWrap.style.display = (currentCanForward && isOpen && chiefCanSetDeadline) ? "" : "none";

    if (!currentCanForward && inputForwardPersonalDeadline) {
      inputForwardPersonalDeadline.value = "";
    }

    updateForwardModeUI();
  }

  function loadSectionsOptions() {
    if (!selForwardTo) return;

    const list = window.__SECTIONS__ || [];
    const grouped = {};

    list.forEach((s) => {
      const div = (s.division_name || "Other").toString();
      if (!grouped[div]) grouped[div] = [];
      grouped[div].push(s);
    });

    let html = `<option value="">-- Select section --</option>`;
    Object.keys(grouped).forEach((div) => {
      html += `<optgroup label="${esc(div)}">`;
      grouped[div].forEach((s) => {
        html += `<option value="${Number(s.id)}">${esc(s.name)}</option>`;
      });
      html += `</optgroup>`;
    });

    selForwardTo.innerHTML = html;
  }
  loadSectionsOptions();

  function resetUsersUI(msg = "Select a section to load users…") {
    if (elUserList) elUserList.innerHTML = `<div style="opacity:.7;">${esc(msg)}</div>`;
    if (elRecipientsPreview) elRecipientsPreview.textContent = "Recipients: —";
    if (cbReceiveOnly) {
      cbReceiveOnly.checked = false;
      cbReceiveOnly.disabled = false;
    }
    if (elReceiveOnlyHint) {
      elReceiveOnlyHint.textContent = "Recipient can acknowledge receive, but cannot forward or act further.";
    }
  }

  function getAllRecipientBoxes() {
    if (!elUserList) return [];
    return Array.from(elUserList.querySelectorAll("input.f_user_cb"));
  }

  function getSelectedRecipientIds() {
    return getAllRecipientBoxes()
      .filter((b) => b.checked)
      .map((b) => Number.parseInt(b.value || "0", 10))
      .filter((n) => Number.isFinite(n) && n > 0);
  }

  function updateRecipientsPreview() {
    if (!elRecipientsPreview) return;

    const allBoxes = getAllRecipientBoxes();
    const selectedBoxes = allBoxes.filter((b) => b.checked);

    if (allBoxes.length === 0 || selectedBoxes.length === 0) {
      elRecipientsPreview.textContent = "Recipients: —";
      return;
    }

    if (selectedBoxes.length === allBoxes.length) {
      elRecipientsPreview.textContent = `Recipients: All selected (${allBoxes.length})`;
      return;
    }

    const labels = selectedBoxes.slice(0, 3).map((b) => {
      const text = b.closest("label")?.innerText?.trim() || `#${b.value}`;
      return text.replace(/\s+/g, " ");
    });

    const more = selectedBoxes.length - labels.length;
    elRecipientsPreview.textContent = `Recipients: ${labels.join(", ")}${more > 0 ? ` (+${more} more)` : ""}`;
  }

  function updateForwardModeUI() {
    if (!elForwardModeWrap || !cbReceiveOnly || !elReceiveOnlyHint) return;

    const selectedCount = getSelectedRecipientIds().length;
    const isMulti = selectedCount > 1;

    elForwardModeWrap.style.display = currentCanForward ? "" : "none";

    if (isMulti) {
      cbReceiveOnly.checked = true;
      cbReceiveOnly.disabled = true;
      elReceiveOnlyHint.textContent = "Multiple recipients are always receive-only in the current workflow.";
      return;
    }

    cbReceiveOnly.disabled = false;
    elReceiveOnlyHint.textContent = cbReceiveOnly.checked
      ? "Recipient can acknowledge receive, but cannot forward or act further."
      : "Recipient will receive the normal actionable lane if allowed by workflow rules.";
  }

  async function loadUsersForSection(sectionId) {
    if (!elUserList) return;

    resetUsersUI("Loading users…");

    try {
      const res = await fetch(`${API}/users_by_section.php?section_id=${encodeURIComponent(sectionId)}`, {
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !Array.isArray(data) || data.length === 0) {
        resetUsersUI("— No users found —");
        return;
      }

      elUserList.innerHTML = data.map((u) => {
        const id = Number(u.id || 0);
        const name = (u.name || "").toString();
        return `
          <label class="userChk" style="display:flex; gap:8px; align-items:flex-start; padding:6px 4px; cursor:pointer;">
            <input type="checkbox" class="f_user_cb" value="${id}" style="margin-top:2px;">
            <span>${esc(name)} <span style="opacity:.6;">(#${id})</span></span>
          </label>
        `;
      }).join("");

      updateRecipientsPreview();
    } catch {
      resetUsersUI("Failed to load users");
    }
  }

  resetUsersUI();

  cbReceiveOnly?.addEventListener("change", () => {
    updateForwardModeUI();
  });

  selForwardTo?.addEventListener("change", () => {
    const sectionId = Number.parseInt(selForwardTo.value || "0", 10) || 0;
    resetUsersUI();
    if (sectionId > 0) loadUsersForSection(sectionId);
  });

  elUserList?.addEventListener("change", (e) => {
    if (e.target && e.target.classList.contains("f_user_cb")) {
      updateRecipientsPreview();
      updateForwardModeUI();
    }
  });

  btnUserSelectAll?.addEventListener("click", () => {
    getAllRecipientBoxes().forEach((b) => { b.checked = true; });
    updateRecipientsPreview();
    updateForwardModeUI();
  });

  btnUserClear?.addEventListener("click", () => {
    getAllRecipientBoxes().forEach((b) => { b.checked = false; });
    updateRecipientsPreview();
    updateForwardModeUI();
  });

  function openRecipientsModal({ docId, countHint }) {
    if (!recModal || !recBody) return;

    if (recTitle) recTitle.textContent = "Recipients";
    if (recSub) recSub.textContent = (typeof countHint === "number" && countHint >= 0)
      ? `${countHint} recipient${countHint === 1 ? "" : "s"}`
      : "";

    recBody.innerHTML = `<div class="mini" style="opacity:.8;">Loading…</div>`;
    recModal.classList.add("open");
    recModal.setAttribute("aria-hidden", "false");

    fetch(`${API}/get_recipients.php?document_id=${encodeURIComponent(docId)}`, {
      headers: { Accept: "application/json" }
    })
      .then(async (res) => {
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.ok) {
          recBody.innerHTML = `<div class="mini" style="opacity:.8;">${esc(data?.error || `Failed to load recipients. (${res.status})`)}</div>`;
          return;
        }

        const items = Array.isArray(data.recipients) ? data.recipients : [];
        if (recSub) recSub.textContent = `${items.length} recipient${items.length === 1 ? "" : "s"}`;

        if (items.length === 0) {
          recBody.innerHTML = `<div class="mini" style="opacity:.8;">No pending recipients.</div>`;
          return;
        }

        const groups = new Map();
        items.forEach((r) => {
          const sec = (r.to_section_name || "—").toString();
          const user = (r.to_user_name || "").toString().trim() || "(No specific user)";
          if (!groups.has(sec)) groups.set(sec, []);
          groups.get(sec).push(user);
        });

        recBody.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:12px;">
            ${Array.from(groups.entries()).map(([sec, users]) => `
              <div style="border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:10px 12px;">
                <div style="font-weight:900; margin-bottom:6px;">${esc(sec)}</div>
                <div style="display:flex; flex-direction:column; gap:6px;">
                  ${users.map((u) => `<div class="mini" style="opacity:.9;">• ${esc(u)}</div>`).join("")}
                </div>
              </div>
            `).join("")}
          </div>
        `;
      })
      .catch(() => {
        recBody.innerHTML = `<div class="mini" style="opacity:.8;">Failed to load recipients (network error).</div>`;
      });
  }

  function closeRecipientsModal() {
    if (!recModal) return;
    recModal.classList.remove("open");
    recModal.setAttribute("aria-hidden", "true");
    if (recBody) recBody.innerHTML = "";
  }

  recClose?.addEventListener("click", closeRecipientsModal);
  recModalBackdrop?.addEventListener("click", closeRecipientsModal);

  elDestination?.addEventListener("click", () => {
    if (!elDestination.classList.contains("destClickable")) return;

    const docId = Number.parseInt(elDestination.dataset.docId || "0", 10);
    const countHint = Number.parseInt(elDestination.dataset.count || "0", 10);
    if (!docId) return;
    openRecipientsModal({ docId, countHint });
  });

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
      if (recModal?.classList.contains("open")) closeRecipientsModal();
      if (drawer?.classList.contains("open")) closeDrawer();
    }
  });

  function renderAttachments(items) {
    if (!elAttachments) return;

    if (!Array.isArray(items) || items.length === 0) {
      elAttachments.innerHTML = `<div class="mini" style="opacity:.7;">No files yet.</div>`;
      return;
    }

    elAttachments.innerHTML = `
      <div class="attachList" style="display:flex; flex-direction:column; gap:10px;">
        ${items.map((a) => {
          const name = a.original_name || a.filename || `Attachment #${a.id || ""}`;
          const note = clean(a.note);
          const meta = [
            fmt(a.uploaded_at || a.created_at || ""),
            clean(a.uploaded_by_name || a.actor || ""),
            fmtBytes(a.size_bytes || a.size || 0),
          ].filter(Boolean).join(" • ");

          const viewUrl = `${PUBLIC}/view_attachment.php?id=${Number(a.id || 0)}`;
          const dlUrl = `${PUBLIC}/download_attachment.php?id=${Number(a.id || 0)}`;

          return `
            <div class="attachCard" style="border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:12px; background:#fff;">
              <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                <div style="min-width:0;">
                  <div style="font-weight:900; line-height:1.25; word-break:break-word;">${esc(name)}</div>
                  ${meta ? `<div class="mini" style="opacity:.7; margin-top:4px;">${esc(meta)}</div>` : ""}
                  ${note ? `<div class="mini" style="margin-top:8px;"><strong>Note:</strong> ${esc(note)}</div>` : ""}
                </div>
                <div style="display:flex; gap:8px; flex-shrink:0;">
                  <a href="#" class="attachLink btn btnSm" data-view-url="${esc(viewUrl)}" data-dl-url="${esc(dlUrl)}" data-mime="${esc(a.mime || "")}" data-name="${esc(name)}">View</a>
                  <a href="${esc(dlUrl)}" class="btn btnSm btnGhost" target="_blank" rel="noopener">Download</a>
                </div>
              </div>
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  async function loadAttachments(docId) {
    if (!elAttachments) return;

    try {
      const url = `${API}/attachments_list.php?document_id=${encodeURIComponent(docId)}`;
      const res = await fetch(url, { headers: { Accept: "application/json" } });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.ok) {
        elAttachments.textContent = data?.error || `Failed to load attachments. (${res.status})`;
        return;
      }

      const items = Array.isArray(data.attachments) ? data.attachments : [];
      currentPpdSlipAttId = 0;

      for (const a of items) {
        if ((a.note || "").toString() === "AUTO:PPD_TRACKING_SLIP") {
          currentPpdSlipAttId = Number(a.id || 0);
          break;
        }
      }

      if (btnPpdSlipPrint) {
        btnPpdSlipPrint.disabled = !(APP.isPPD && currentPpdSlipAttId > 0);
      }

      renderAttachments(items);
    } catch {
      elAttachments.textContent = "Failed to load attachments.";
    }
  }

  function movementParts(i) {
    const from = clean(i.from_section);
    const to = clean(i.to_section);
    if (from && to && from === to) return { from, to: "" };
    return { from, to };
  }

  function detailChips(i) {
    const chips = [];
    const rec = clean(i.recipient_summary);
    const branch = clean(i.branch_label);
    const actorSection = clean(i.actor_section);
    if (branch && currentBranchMode) chips.push(`<span class="tChip">${esc(branch)}</span>`);
    if (rec) chips.push(`<span class="tChip">Recipients: ${esc(rec)}</span>`);
    if (actorSection) chips.push(`<span class="tChip">${esc(actorSection)}</span>`);
    return chips.join("");
  }

  function movementHtml(i) {
    const move = movementParts(i);
    const actionKey = getKey(i);
    if (move.from && move.to) {
      return `<div class="tMove"><span class="tChip">${esc(move.from)}</span><span class="tArrow">→</span><span class="tChip">${esc(move.to)}</span></div>`;
    }
    if (move.to) return `<div class="tMove"><span class="tChip">${esc(move.to)}</span></div>`;
    if (move.from && ["sent", "forwarded", "received"].includes(actionKey)) {
      return `<div class="tMove"><span class="tChip">${esc(move.from)}</span></div>`;
    }
    return "";
  }

  function ackSummaryListHtml(items, emptyLabel, opts = {}) {
    const rows = Array.isArray(items) ? items : [];
    const compact = !!opts.compact;

    if (!rows.length) {
      return `<div class="ackSummaryEmpty">${esc(emptyLabel)}</div>`;
    }

    if (compact) {
      return `
        <div class="ackSummaryChipList">
          ${rows.map((row) => {
            const name = clean(row?.branch_label) || clean(row?.name) || `Branch ${Number(row?.branch_id || 0)}`;
            return `<span class="ackSummaryChip">${esc(name)}</span>`;
          }).join("")}
        </div>
      `;
    }

    return `
      <div class="ackSummaryList">
        ${rows.map((row) => {
          const name = clean(row?.branch_label) || clean(row?.name) || `Branch ${Number(row?.branch_id || 0)}`;
          const section = clean(row?.section_name);
          return `
            <div class="ackSummaryPerson">
              <span class="ackSummaryPersonName">${esc(name)}</span>
              ${section ? `<span class="ackSummaryPersonSection">${esc(section)}</span>` : ``}
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  function renderAckSummary(i, opts = {}) {
    const summary = i?.ack_summary;
    if (!summary || summary.enabled !== true) return "";

    const compact = !!opts.compact;
    const receivedCount = Number(summary.received_count || 0);
    const pendingCount = Number(summary.pending_count || 0);
    const totalCount = Number(summary.total || 0);
    const showNames = summary.show_names === true;

    if (totalCount <= 0) return "";

    const receivedUsers = Array.isArray(summary.received_users) ? summary.received_users : [];
    const pendingUsers = Array.isArray(summary.pending_users) ? summary.pending_users : [];

    return `
      <div class="ackSummary ${compact ? "ackSummary--compact" : ""}" data-ack-summary-root="1">
        <div class="ackSummaryHead">
          <div class="ackSummaryTitle">${compact ? "Ack" : "Acknowledgements"}</div>
          <div class="ackSummaryCounts">${receivedCount}/${totalCount} received</div>
        </div>

        <div class="ackSummaryTabs">
          <button
            type="button"
            class="ackSummaryTab"
            data-ack-tab="received"
            aria-expanded="false"
          >
            Received (${receivedCount})
          </button>
          <button
            type="button"
            class="ackSummaryTab"
            data-ack-tab="pending"
            aria-expanded="false"
          >
            Not yet received (${pendingCount})
          </button>
        </div>

        <div class="ackSummaryPanels">
          <div class="ackSummaryPanel" data-ack-panel="received">
            ${showNames
              ? ackSummaryListHtml(receivedUsers, "No one has received this yet.", { compact })
              : `<div class="ackSummaryEmpty">Received count: ${receivedCount}</div>`
            }
          </div>

          <div class="ackSummaryPanel" data-ack-panel="pending">
            ${showNames
              ? ackSummaryListHtml(pendingUsers, "Everyone has already received this.", { compact })
              : `<div class="ackSummaryEmpty">Not yet received count: ${pendingCount}</div>`
            }
          </div>
        </div>
      </div>
    `;
  }

  function bindAckSummaryToggles(root) {
    if (!root) return;

    root.querySelectorAll("[data-ack-summary-root]").forEach((wrap) => {
      const tabs = wrap.querySelectorAll(".ackSummaryTab");
      const panels = wrap.querySelectorAll(".ackSummaryPanel");

      tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
          const target = (tab.dataset.ackTab || "received").toString();
          const wasActive = tab.classList.contains("isActive");

          tabs.forEach((btn) => {
            btn.classList.remove("isActive");
            btn.setAttribute("aria-expanded", "false");
          });

          panels.forEach((panel) => {
            panel.classList.remove("isActive");
          });

          if (wasActive) return;

          tab.classList.add("isActive");
          tab.setAttribute("aria-expanded", "true");

          panels.forEach((panel) => {
            panel.classList.toggle("isActive", panel.dataset.ackPanel === target);
          });
        });
      });
    });
  }

  function sameText(a, b) {
    return clean(a).toLowerCase() === clean(b).toLowerCase();
  }

  function groupDivisionFor(items) {
    const rows = Array.isArray(items) ? items : [];
    for (const row of rows) {
      const div = clean(row?.actor_division);
      if (div) return div;
    }
    return "";
  }

  function getTimelineGroupStorageKey(groupKey) {
    const ctx = window.__CTX__ || {};
    const uid = Number(ctx.myUserId || 0);
    const docId = Number(currentPayload?.id || elId?.value || 0);
    const safeGroupKey = (groupKey || "")
      .toString()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_+|_+$/g, "") || "group";
    return `dt_group_collapsed_u${uid}_d${docId}_${safeGroupKey}`;
  }

  function isTimelineGroupCollapsed(groupKey, groupDivision) {
    const key = getTimelineGroupStorageKey(groupKey);
    try {
      const saved = sessionStorage.getItem(key);
      if (saved === "1") return true;
      if (saved === "0") return false;
    } catch (_) {}

    const ctx = window.__CTX__ || {};
    const myDivisionName = clean(ctx.myDivisionName);

    if (!myDivisionName || !clean(groupDivision)) {
      return false;
    }

    return !sameText(myDivisionName, groupDivision);
  }

  function saveTimelineGroupCollapsed(groupKey, collapsed) {
    const key = getTimelineGroupStorageKey(groupKey);
    try {
      sessionStorage.setItem(key, collapsed ? "1" : "0");
    } catch (_) {}
  }

  function bindTimelineGroupToggles(root) {
    if (!root) return;

    root.querySelectorAll("[data-group-toggle]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const groupKey = (btn.dataset.groupKey || "").toString();
        const groupEl = btn.closest(".tGroup");
        if (!groupEl || !groupKey) return;

        const nextCollapsed = !groupEl.classList.contains("isCollapsed");
        groupEl.classList.toggle("isCollapsed", nextCollapsed);
        btn.setAttribute("aria-expanded", nextCollapsed ? "false" : "true");
        btn.textContent = nextCollapsed ? "Show" : "Hide";

        saveTimelineGroupCollapsed(groupKey, nextCollapsed);
      });
    });
  }

  function groupTitleFor(i) {
    const move = movementParts(i);
    if (clean(i.actor_section)) return clean(i.actor_section);
    if (move.to) return move.to;
    if (move.from) return move.from;
    return "General";
  }

  function renderEventsView(itemsNewestFirst) {
    return `
      <div class="timeline">
        ${itemsNewestFirst.map((i, idx) => {
          const actionKey = getKey(i);
          const isCurrent = idx === 0;

          const details = [];
          const personMove = clean(i.person_movement);
          const recipientSummary = clean(i.recipient_summary);
          const branchLabelText = clean(i.branch_label);
          const ackSummaryHtml = renderAckSummary(i);

          if (
            currentBranchMode &&
            branchLabelText &&
            !["sent", "forwarded", "received"].includes(actionKey)
          ) {
            details.push(`<span class="tChip">${esc(branchLabelText)}</span>`);
          }
          if (personMove && ["sent", "forwarded", "received"].includes(actionKey)) {
            details.push(`<span class="tChip">${esc(personMove)}</span>`);
          } else if (
            ["sent", "forwarded"].includes(actionKey) &&
            recipientSummary &&
            !personMove
          ) {
            details.push(`<span class="tChip">Recipients: ${esc(recipientSummary)}</span>`);
          }

          return `
            <div class="tItem action-${esc(actionKey)} ${isCurrent ? "isCurrent" : ""}">
              <div class="tIcon" aria-hidden="true">
                <span class="tGlyph">${esc(actionIcon(actionKey))}</span>
              </div>

              <div class="tContent">
                <div class="tRow">
                  <div class="tMeta tMetaLeft">
                    ${esc(fmt(i.acted_at))}
                    <br>
                    ${esc(i.actor || "System")}
                  </div>

                  <div class="tRight">
                    ${isCurrent ? `<span class="tBadge">LATEST</span>` : ``}
                    <div class="tAction">${esc(prettyAction(actionKey).toUpperCase())}</div>
                  </div>
                </div>

                ${i.title ? `<div class="tRemark">${esc(i.title)}</div>` : ""}

                ${movementHtml(i)}

                ${details.length ? `
                  <div class="tMove" style="margin-top:8px; gap:6px; flex-wrap:wrap;">
                    ${details.join("")}
                  </div>
                ` : ""}

                ${ackSummaryHtml}

                ${i.personal_deadline_at ? `<div class="tNote"><strong>Personal deadline:</strong> ${esc(fmt(i.personal_deadline_at))}</div>` : ``}
                ${i.remarks ? `<div class="tNote"><strong>Remarks:</strong> ${esc(i.remarks)}</div>` : ``}

              </div>
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  function renderGroupedView(itemsNewestFirst) {
    const byKey = new Map();
    const renderedSplitEventIds = new Set();
    const ctx = window.__CTX__ || {};
    const myDivisionName = clean(ctx.myDivisionName);

    itemsNewestFirst.forEach((item) => {
      const key = groupTitleFor(item);
      if (!byKey.has(key)) {
        byKey.set(key, { key, items: [] });
      }
      byKey.get(key).items.push(item);
    });

    const groups = Array.from(byKey.values())
      .map((group) => {
        const newestFirst = [...group.items].sort((a, b) => {
          const ta = new Date((a.acted_at || "").toString().replace(" ", "T")).getTime() || 0;
          const tb = new Date((b.acted_at || "").toString().replace(" ", "T")).getTime() || 0;
          return tb - ta;
        });

        return {
          ...group,
          items: newestFirst,
          latestTs: new Date((newestFirst[0]?.acted_at || "").toString().replace(" ", "T")).getTime() || 0,
          divisionName: groupDivisionFor(newestFirst),
        };
      })
      .sort((a, b) => b.latestTs - a.latestTs);

    return `
      <div class="tGrouped">
        ${groups.map((group) => {
          const latest = group.items[0];
          const groupBranchId = resolveGroupBranchId(group.items);
          const splitEvent = findSplitEventForBranch(itemsNewestFirst, groupBranchId);
          const isMineDivision = !!(myDivisionName && group.divisionName && sameText(myDivisionName, group.divisionName));
          const isCollapsed = isTimelineGroupCollapsed(group.key, group.divisionName);

          let splitMarker = "";
          const splitEventId = Number(splitEvent?.event_id || 0);

          if (splitEvent && splitEventId > 0 && !renderedSplitEventIds.has(splitEventId)) {
            renderedSplitEventIds.add(splitEventId);
            splitMarker = renderStandaloneBranchSwitcher(splitEvent.new_branch_ids, {
              label: clean(splitEvent.actor_section) || "Branches"
            });
          }

          return `
            ${splitMarker}
            <section class="tGroup ${isCollapsed ? "isCollapsed" : ""}">
              <div class="tGroupHead">
                <div class="tGroupHeadMain">
                  <div class="tGroupTitleRow">
                    <div class="tGroupTitle">${esc(group.key)}</div>
                    ${group.divisionName ? `
                      <span class="tGroupDivisionBadge ${isMineDivision ? "isMine" : "isOther"}">
                        ${isMineDivision ? "Your division" : esc(group.divisionName)}
                      </span>
                    ` : ``}
                  </div>
                  <div class="tGroupSub">
                    ${group.items.length} action${group.items.length === 1 ? "" : "s"} • ${esc(fmt(latest?.acted_at || ""))}
                  </div>
                </div>

                <button
                  type="button"
                  class="tGroupToggle"
                  data-group-toggle="1"
                  data-group-key="${esc(group.key)}"
                  aria-expanded="${isCollapsed ? "false" : "true"}"
                >
                  ${isCollapsed ? "Show" : "Hide"}
                </button>
              </div>

              <div class="tGroupBody">
                ${group.items.map((i) => {
                  const actionKey = getKey(i);
                  const move = movementParts(i);

                  const details = [];
                  const personMove = clean(i.person_movement);
                  const recipientSummary = clean(i.recipient_summary);
                  const branchLabelText = clean(i.branch_label);
                  const ackSummaryHtml = renderAckSummary(i, { compact: true });

                  if (
                    currentBranchMode &&
                    branchLabelText &&
                    !["sent", "forwarded", "received"].includes(actionKey)
                  ) {
                    details.push(esc(branchLabelText));
                  }

                  if (personMove && ["sent", "forwarded", "received"].includes(actionKey)) {
                    details.push(esc(personMove));
                  } else if (
                    ["sent", "forwarded"].includes(actionKey) &&
                    recipientSummary &&
                    !personMove
                  ) {
                    details.push(`Recipients: ${esc(recipientSummary)}`);
                  }

                  let movement = "";
                  if (move.from && move.to) movement = `${esc(move.from)} → ${esc(move.to)}`;
                  else if (move.to) movement = esc(move.to);
                  else if (move.from && ["sent", "forwarded", "received"].includes(actionKey)) movement = esc(move.from);

                  return `
                    <div class="tLine action-${esc(actionKey)}">
                      <div class="tLineLeft">
                        <span class="tLineTime">${esc(fmt(i.acted_at))}</span>
                        <span class="tLineTag">${esc(prettyAction(actionKey).toUpperCase())}</span>
                      </div>

                      <div class="tLineRight">
                        <div class="tLineTitle">${esc(i.title || prettyAction(actionKey))}</div>
                        ${movement ? `<div class="tLineMove">${movement}</div>` : ``}
                        ${details.length ? `<div class="tLineMove">${details.join(" • ")}</div>` : ``}
                        ${ackSummaryHtml}
                        ${i.personal_deadline_at ? `<div class="tLineNote">Personal deadline: ${esc(fmt(i.personal_deadline_at))}</div>` : ``}
                        ${i.remarks ? `<div class="tLineNote">Remarks: ${esc(i.remarks)}</div>` : ``}
                      </div>
                    </div>
                  `;
                }).join("")}
              </div>
            </section>
          `;
        }).join("")}
      </div>
    `;
  }

  async function loadTimeline(docId, forcedBranchId = 0) {
    if (!elTimeline) return;

    try {
      const qs = new URLSearchParams({ document_id: String(docId) });
      const branchId = Number(forcedBranchId || currentBranchId || 0);
      if (currentBranchMode && branchId > 0) qs.set("branch_id", String(branchId));

      const res = await fetch(`${API}/get_history.php?${qs.toString()}`, {
        headers: { Accept: "application/json" }
      });

      if (!res.ok) {
        elTimeline.textContent = `Failed to load timeline. (${res.status})`;
        return;
      }

      const data = await res.json().catch(() => null);
      if (!data?.ok) {
        elTimeline.textContent = data?.error || "No timeline.";
        return;
      }

      const branchRows = Array.isArray(data.branches) ? data.branches : [];
      const hasRealBranches = branchRows.some((b) => Number(b.id || 0) > 0);

      currentBranchMode = !!data.branch_mode && hasRealBranches;
      renderBranchTabs(branchRows);

      if (currentBranchMode) {
        const activeId = Number(forcedBranchId || currentBranchId || preferredBranchId(branchRows));
        if (activeId > 0 && activeId !== Number(data.selected_branch_id || 0)) {
          currentBranchId = activeId;
          return loadTimeline(docId, activeId);
        }
      }

      const items = Array.isArray(data.history) ? data.history : [];
      if (items.length === 0) {
        elTimeline.innerHTML = `<div class="mini" style="opacity:.7;">No timeline yet for this ${currentBranchMode ? "branch" : "document"}.</div>`;
        return;
      }

      const LS_KEY = currentBranchMode ? "dt_timeline_view_branch" : "dt_timeline_view";
      const saved = (localStorage.getItem(LS_KEY) || "events").toLowerCase();
      let view = saved === "grouped" ? "grouped" : "events";

      elTimeline.innerHTML = `
        <div class="tToolbar">
          <button type="button" class="tToggle ${view === "events" ? "isOn" : ""}" data-view="events">Events</button>
          <button type="button" class="tToggle ${view === "grouped" ? "isOn" : ""}" data-view="grouped">By Section</button>
        </div>
        <div id="timelineBody"></div>`;

      const body = elTimeline.querySelector("#timelineBody");
      const buttons = elTimeline.querySelectorAll(".tToggle");

      function paint() {
        if (!body) return;
        body.innerHTML = view === "grouped" ? renderGroupedView(items) : renderEventsView(items);

        body.querySelectorAll(".inlineBranchPill").forEach((btn) => {
          btn.addEventListener("click", () => {
            const bid = Number(btn.dataset.branchId || 0);
            if (bid <= 0) return;
            applyBranchSelection(bid);
            const docId = Number(elId?.value || 0);
            if (docId > 0) loadTimeline(docId, bid);
          });
        });

        bindAckSummaryToggles(body);
        bindTimelineGroupToggles(body);

        buttons.forEach((b) => b.classList.toggle("isOn", b.dataset.view === view));
        localStorage.setItem(LS_KEY, view);
      }

      buttons.forEach((b) => {
        b.addEventListener("click", () => {
          view = b.dataset.view === "grouped" ? "grouped" : "events";
          paint();
        });
      });

      paint();
    } catch {
      elTimeline.textContent = "Failed to load timeline.";
    }
  }

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
    const branch = currentBranchMode ? getSelectedBranch() : null;
    const routeId = currentBranchMode
      ? (Number.parseInt(branch?.my_pending_route_id || "0", 10) || 0)
      : (Number.parseInt(currentPayload?.open_route_id || "0", 10) || 0);
    if (routeId > 0) form.append("route_id", String(routeId));
    if (currentBranchMode && branch && Number(branch.id || 0) > 0) {
      form.append("branch_id", String(Number(branch.id || 0)));
    }
    form.append("file", f0);
    form.append("is_append", (attachType?.value || "1") === "1" ? "1" : "0");
    form.append("note", attachNote ? attachNote.value : "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/attachments_upload.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
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

  function openDrawer(payload) {
    currentPayload = payload || null;
    setCollapsed(elAttachments, true);
    setCollapsed(attachForm, true);
    setCollapsed(forwardBox, true);
    syncToggleLabels();

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

    if (rowPpdSlip) {
      const isPPD = !!APP.isPPD;
      const docId = payload.id || "";
      rowPpdSlip.style.display = (isPPD && docId) ? "" : "none";

      if (btnPpdSlipGenerate) btnPpdSlipGenerate.dataset.docId = String(docId || "");
      if (btnPpdSlipAttach) btnPpdSlipAttach.dataset.docId = String(docId || "");
      if (btnPpdSlipPrint) {
        btnPpdSlipPrint.dataset.docId = String(docId || "");
        btnPpdSlipPrint.disabled = true;
      }
      currentPpdSlipAttId = 0;
    }

    if (elId) elId.value = payload.id || "";
    if (elTracking) elTracking.textContent = payload.tracking_no || "";
    if (elRequester) elRequester.textContent = payload.requester || "—";
    if (elDate) elDate.textContent = payload.document_date || "—";
    renderDeadline(payload.deadline_at || "", payload.my_personal_deadline_at || "");
    if (elSubject) elSubject.textContent = payload.subject || "—";
    if (elType) elType.textContent = payload.content_type || "—";
    if (elDays) elDays.textContent = payload.days_stuck ?? "0";

    const inTransit = payload.in_transit === 1 || payload.in_transit === "1" || payload.in_transit === true;
    const docStatus = (payload.current_status || "ACTIVE").toString().toUpperCase();

    if (elStatus) {
      elStatus.textContent = payload.status_label || (inTransit ? "IN TRANSIT" : (docStatus || "—"));
      elStatus.className = payload.status_chip_class || (inTransit ? "chip action" : "chip incoming");
    }

    if (elHolder) {
      elHolder.textContent = payload.current_holder_text || "—";
      elHolder.className = "chip incoming";
    }

    const openCount = Number.parseInt(payload.open_route_count, 10) || 0;
    const destText = payload.movement_text || "—";
    if (elDestinationText) elDestinationText.textContent = destText;
    else if (elDestination) elDestination.textContent = destText;

    if (elDestination) {
      const clickable = !!inTransit && openCount > 1;
      elDestination.classList.toggle("destClickable", clickable);
      if (clickable) {
        elDestination.dataset.docId = String(payload.id || "");
        elDestination.dataset.count = String(openCount || 0);
      } else {
        delete elDestination.dataset.docId;
        delete elDestination.dataset.count;
      }
    }

    if (elLastHolder) elLastHolder.textContent = payload.last_holder_text || "—";
    if (elActionRemarks) elActionRemarks.value = "";

    backdrop?.classList.add("open");
    drawer?.classList.add("open");

    if (attachMsg) attachMsg.textContent = "";

    const ctx = window.__CTX__ || {};
    const myRole = (ctx.myRole || "user").toString().toLowerCase();
    const mySectionId = Number(ctx.mySectionId || 0);
    const myUserId = Number(ctx.myUserId || 0);
    const isChief = !!ctx.isChief;

    // Important:
    // branch mode here must mean actual branch context for this document,
    // not just globally-enabled branch feature.
    currentBranchMode = false;
    currentBranches = [];
    currentBranchId = 0;

    if (elAttachments) elAttachments.textContent = "Loading attachments…";
    if (payload.id) loadAttachments(payload.id);

    if (elTimeline) elTimeline.textContent = "Loading timeline…";
    if (payload.id) loadTimeline(payload.id);

    const openToSectionId = Number.parseInt(payload.open_to_section_id, 10) || 0;
    const holderSectionId = Number.parseInt(payload.current_holder_section_id, 10) || 0;
    const openFromSectionId = Number.parseInt(payload.open_from_section_id, 10) || 0;
    const openToUserId = Number.parseInt(payload.open_to_user_id, 10) || 0;
    const isPrivileged = myRole === "admin" || myRole === "records";

    const canAckReceived = (!currentBranchMode && inTransit && (
      (openToUserId > 0 && myUserId > 0 && openToUserId === myUserId) ||
      (openToUserId === 0 && isChief && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId)
    ));

    const canAckReceivedPrivileged = (!currentBranchMode && inTransit && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId);

    let canAttach = false;
    let canForward = false;

    if (currentBranchMode) {
      canAttach = docStatus === "ACTIVE" && isPrivileged;
      canForward = false;
    } else {
      const holderStillSending = inTransit && holderSectionId > 0 && openFromSectionId > 0 && openFromSectionId === holderSectionId;

      canAttach = docStatus === "ACTIVE" && (
        isPrivileged || (
          holderSectionId > 0 &&
          mySectionId > 0 &&
          holderSectionId === mySectionId &&
          !holderStillSending
        )
      );

      canForward = docStatus === "ACTIVE" &&
        holderSectionId > 0 &&
        mySectionId > 0 &&
        holderSectionId === mySectionId &&
        !holderStillSending;
    }

    currentCanForward = canForward;

    if (btnToggleUpload) btnToggleUpload.style.display = canAttach ? "" : "none";
    if (btnToggleAttachments) btnToggleAttachments.style.display = "";
    updateForwardUI();

    if (attachFile) attachFile.value = "";
    if (attachNote) attachNote.value = "";
    if (attachType) attachType.value = "1";
    if (selForwardTo) selForwardTo.value = "";
    if (inputForwardPersonalDeadline) inputForwardPersonalDeadline.value = "";
    resetUsersUI();
    updateForwardModeUI();

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

    if (!currentBranchMode && btnAckReceived) {
      btnAckReceived.style.display = canAckReceived ? "" : "none";
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
      if (isPrivileged || (!currentBranchMode && holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId)) {
        if (btnRelease) btnRelease.style.display = "";
      }
      syncToggleLabels();
      return;
    }

    if (isPrivileged) {
      if (!currentBranchMode && canAckReceivedPrivileged && btnAckReceived) {
        btnAckReceived.style.display = "";
      }
      if (btnRelease) btnRelease.style.display = "";
      if (btnArchive) btnArchive.style.display = "";
      syncToggleLabels();
      return;
    }

    if (!currentBranchMode) {
      if (inTransit) {
        if (canAckReceived && btnAckReceived) btnAckReceived.style.display = "";
        syncToggleLabels();
        return;
      }

      if (holderSectionId > 0 && mySectionId > 0 && holderSectionId === mySectionId) {
        if (btnRelease) btnRelease.style.display = "";
      }
    }

    syncToggleLabels();
  }

  function closeDrawer() {
    drawer?.classList.remove("open");
    backdrop?.classList.remove("open");
    currentPayload = null;
    currentBranchMode = false;
    currentBranches = [];
    currentBranchId = 0;
    currentCanForward = false;
    if (deadlineTicker) {
      clearInterval(deadlineTicker);
      deadlineTicker = null;
    }
    if (elDeadline) elDeadline.textContent = "—";
    if (elPersonalDeadline) elPersonalDeadline.textContent = "—";
    if (elDeadlineCountdown) elDeadlineCountdown.textContent = "—";
    if (elBranchWrap) elBranchWrap.style.display = "none";
    if (elBranchBar) elBranchBar.innerHTML = "";
    if (elBranchMeta) elBranchMeta.textContent = "";
  }

  async function updateStatus(newStatus) {
    const docId = elId?.value;
    if (!docId) return;

    const form = new FormData();
    form.append("document_id", docId);
    const routeId = Number.parseInt(currentPayload?.open_route_id || "0", 10) || 0;
    if (routeId > 0) form.append("route_id", String(routeId));
    form.append("new_status", newStatus);
    form.append("remarks", normalizedRemarksValue());
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/update_status.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
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
    const branch = currentBranchMode ? getSelectedBranch() : null;
    const routeId = currentBranchMode
      ? (Number.parseInt(branch?.my_pending_route_id || "0", 10) || 0)
      : (Number.parseInt(currentPayload?.open_route_id || "0", 10) || 0);
    if (routeId > 0) form.append("route_id", String(routeId));
    form.append("remarks", normalizedRemarksValue());
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/ack_received.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
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
    const branchBeforeForward = currentBranchMode ? getSelectedBranch() : null;
    if (!docId) return;

    const toSectionId = Number.parseInt(selForwardTo?.value || "0", 10) || 0;
    if (toSectionId <= 0) {
      alert("Please select a destination section.");
      return;
    }

    const selected = getSelectedRecipientIds();
    const form = new FormData();
    form.append("document_id", docId);
    const branch = currentBranchMode ? getSelectedBranch() : null;
    const routeId = currentBranchMode
      ? (Number.parseInt(branch?.my_pending_route_id || "0", 10) || 0)
      : (Number.parseInt(currentPayload?.open_route_id || "0", 10) || 0);
    if (routeId > 0) form.append("route_id", String(routeId));
    if (currentBranchMode && Number(branch?.id || 0) > 0) form.append("branch_id", String(Number(branch.id || 0)));
    form.append("to_section_id", String(toSectionId));

    if (selected.length === 1) {
      form.append("to_user_id", String(selected[0]));
    } else if (selected.length > 1) {
      selected.forEach((id) => form.append("to_user_ids[]", String(id)));
    }

    const receiveOnly = selected.length > 1 || !!cbReceiveOnly?.checked;
    form.append("receive_only", receiveOnly ? "1" : "0");

    if (inputForwardPersonalDeadline && inputForwardPersonalDeadline.value) {
      form.append("personal_deadline_at", inputForwardPersonalDeadline.value);
    }

    form.append("remarks", normalizedRemarksValue());
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/forward.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        alert(data?.error || `Failed to forward. (${res.status})`);
        return;
      }
      if (currentBranchMode && Number(branchBeforeForward?.id || 0) > 0) {
        savePreferredBranchId(docId, Number(branchBeforeForward.id || 0));
      }
      location.reload();
    } catch {
      alert("Failed to forward (network error).");
    }
  }

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

  btnToggleForward?.addEventListener("click", () => {
    if (!forwardBox) return;
    forwardBox.classList.toggle("collapsed");
    syncToggleLabels();
    updateForwardUI();
    if (!forwardBox.classList.contains("collapsed")) selForwardTo?.focus();
  });

  btnAckReceived?.addEventListener("click", ackReceived);
  btnUnderAction?.addEventListener("click", () => updateStatus("ACTIVE"));
  btnRelease?.addEventListener("click", () => updateStatus((btnRelease.dataset.nextStatus || "RELEASED").toUpperCase()));
  btnArchive?.addEventListener("click", () => updateStatus((btnArchive.dataset.nextStatus || "ARCHIVED").toUpperCase()));
  btnForward?.addEventListener("click", forwardDoc);
  btnAttachUpload?.addEventListener("click", uploadAttachment);

  btnPpdSlipGenerate?.addEventListener("click", async () => {
    const docId = btnPpdSlipGenerate.dataset.docId || elId?.value || "";
    if (!docId) return;

    btnPpdSlipGenerate.disabled = true;
    try {
      const form = new FormData();
      form.append("document_id", docId);
      form.append("csrf_token", window.__CSRF__ || "");

      const res = await fetch(`${API}/ppd_tracking_slip_generate.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        alert(data?.error || `Generate failed. (${res.status})`);
        return;
      }

      const attId = Number(data.attachment_id || 0);
      await loadAttachments(docId);
      if (attId > 0) {
        window.open(`${PUBLIC}/view_attachment.php?id=${attId}`, "_blank", "noopener");
      }
    } catch {
      alert("Generate failed.");
    } finally {
      btnPpdSlipGenerate.disabled = false;
    }
  });

  btnPpdSlipPrint?.addEventListener("click", () => {
    if (!currentPpdSlipAttId) return;
    window.open(`${PUBLIC}/ppd_tracking_slip_print.php?id=${currentPpdSlipAttId}`, "_blank", "noopener");
  });

  btnPpdSlipAttach?.addEventListener("click", () => {
    setCollapsed(attachForm, false);
    if (btnToggleUpload) btnToggleUpload.textContent = "Hide upload";
    if (attachType) attachType.value = "1";
    if (attachNote) attachNote.value = "PPD Tracking Slip (scanned/signed)";
    attachFile?.focus();
  });

  btnViewDocument?.addEventListener("click", () => {
    const docId = btnViewDocument.dataset.docId || elId?.value || "";
    if (!docId) return;

    if (window.DTMergeView && typeof window.DTMergeView.open === "function") {
      window.DTMergeView.open(docId);
      return;
    }

    document.dispatchEvent(new CustomEvent("dt:view_document", { detail: { documentId: docId } }));
  });

  syncToggleLabels();
  updateForwardUI();
})();
