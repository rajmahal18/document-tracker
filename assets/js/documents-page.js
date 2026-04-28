(function () {
  const backdrop = document.getElementById("drawerBackdrop");
  const drawer = document.getElementById("drawer");
  const closeBtn = document.getElementById("drawerClose");
  const drawerTabs = Array.from(document.querySelectorAll("[data-drawer-tab]"));
  const drawerPanels = Array.from(document.querySelectorAll("[data-drawer-panel]"));

  const elId = document.getElementById("d_id");
  const elTracking = document.getElementById("d_tracking");
  const elRequester = document.getElementById("d_requester");
  const elDate = document.getElementById("d_date");
  const elDeadline = document.getElementById("d_deadline");
  const elPersonalDeadline = document.getElementById("d_personal_deadline");
  const elDeadlineCountdown = document.getElementById("d_deadline_countdown");
  const elSubject = document.getElementById("d_subject");
  const elType = document.getElementById("d_type");
  const elProjects = document.getElementById("d_projects");
  const elProjectsManageRow = document.getElementById("d_projects_manage_row");
  const elProjectsActions = document.getElementById("d_projects_actions");
  const btnProjectManageToggle = document.getElementById("btnProjectManageToggle");
  const btnProjectManageClose = document.getElementById("btnProjectManageClose");
  const inputProjectCode = document.getElementById("d_project_code_input");
  const selProject = document.getElementById("d_project_select");
  const btnProjectAttach = document.getElementById("btnProjectAttach");
  const elDays = document.getElementById("d_days");
  const elActivityLabel = document.getElementById("d_activity_label");

  const elStatus = document.getElementById("d_status");
  const elHolder = document.getElementById("d_holder");
  const elDestination = document.getElementById("d_destination");
  const elDestinationText = document.getElementById("d_destination_text");
  const elLastHolder = document.getElementById("d_last_holder");

  const elPendingRemarksWrap = document.getElementById("drawerPendingRemarks");
  const elPendingRemarksTitle = document.getElementById("d_pending_route_title");
  const elPendingRemarksBadge = document.getElementById("d_pending_route_badge");
  const elPendingRemarksPreview = document.getElementById("d_pending_route_preview");
  const elPendingRemarksHint = document.getElementById("d_pending_route_hint");
  const elPendingRemarksComposer = document.getElementById("d_pending_route_composer");
  const elPendingRemarksInput = document.getElementById("d_pending_route_remarks");
  const btnEditPendingRemarks = document.getElementById("btnEditPendingRemarks");
  const btnCancelPendingRemarks = document.getElementById("btnCancelPendingRemarks");
  const btnSavePendingRemarks = document.getElementById("btnSavePendingRemarks");
  const elTimeline = document.getElementById("d_timeline");
  const elBranchWrap = document.getElementById("d_branch_wrap");
  const elBranchBar = document.getElementById("d_branch_bar");
  const elBranchSelect = document.getElementById("d_branch_select");
  const elBranchMeta = document.getElementById("d_branch_meta");
  const elBranchHint = document.getElementById("d_branch_hint");

  const elAttachments = document.getElementById("d_attachments");
  const btnViewDocument = document.getElementById("btnViewDocument");
  const rowEditDocumentDetails = document.getElementById("rowEditDocumentDetails");
  const btnEditDocumentDetails = document.getElementById("btnEditDocumentDetails");

  const rowPpdSlip = document.getElementById("rowPpdSlip");
  const btnPpdSlipGenerate = document.getElementById("btnPpdSlipGenerate");
  const btnPpdSlipAttach = document.getElementById("btnPpdSlipAttach");
  const btnPpdSlipPrint = document.getElementById("btnPpdSlipPrint");
  let currentPpdSlipAttId = 0;

  const btnToggleAttachments = document.getElementById("btnToggleAttachments");
  const btnToggleUpload = document.getElementById("btnToggleUpload");
  const btnRegenerateDivisionSlip = document.getElementById("btnRegenerateDivisionSlip");
  const btnToggleForward = document.getElementById("btnToggleForward");
  const btnToggleAttachmentForward = document.getElementById("btnToggleAttachmentForward");
  const btnAttachmentTaskDone = document.getElementById("btnAttachmentTaskDone");

  const forwardDeadlineGrid = document.getElementById("forwardDeadlineGrid");
  const forwardDocumentDeadlineWrap = document.getElementById("forwardDocumentDeadlineWrap");
  const inputForwardDocumentDeadline = document.getElementById("f_document_deadline");
  const forwardPersonalDeadlineWrap = document.getElementById("forwardPersonalDeadlineWrap");
  const inputForwardPersonalDeadline = document.getElementById("f_personal_deadline");
  const forwardModal = document.getElementById("forwardModal");
  const forwardModalBackdrop = document.getElementById("forwardModalBackdrop");
  const forwardModalClose = document.getElementById("forwardModalClose");
  const btnForwardCancel = document.getElementById("btnForwardCancel");
  const elForwardRemarks = document.getElementById("d_forward_remarks");
  const releaseModal = document.getElementById("releaseModal");
  const releaseModalBackdrop = document.getElementById("releaseModalBackdrop");
  const releaseModalClose = document.getElementById("releaseModalClose");
  const btnReleaseCancel = document.getElementById("btnReleaseCancel");
  const btnReleaseConfirm = document.getElementById("btnReleaseConfirm");
  const inputReleaseTo = document.getElementById("d_release_to");
  const elReleaseRemarks = document.getElementById("d_release_remarks");
  const releaseModalMsg = document.getElementById("releaseModalMsg");

  const attachForm = document.getElementById("attachForm");
  const attachFile = document.getElementById("attachFile");
  const attachType = document.getElementById("attachType");
  const attachNote = document.getElementById("attachNote");
  const btnAttachUpload = document.getElementById("btnAttachUpload");
  const attachMsg = document.getElementById("attachMsg");

  const btnUnderAction = document.getElementById("btnUnderAction");
  const btnAckReceived = document.getElementById("btnAckReceived");
  const btnEndHere = document.getElementById("btnEndHere");
  const btnUndoEndHere = document.getElementById("btnUndoEndHere");
  const btnRelease = document.getElementById("btnRelease");
  const btnArchive = document.getElementById("btnArchive");
  const endHereModal = document.getElementById("endHereModal");
  const endHereModalBackdrop = document.getElementById("endHereModalBackdrop");
  const endHereModalClose = document.getElementById("endHereModalClose");
  const endHereModalTitle = document.getElementById("endHereModalTitle");
  const endHereModalSub = document.getElementById("endHereModalSub");
  const endHereModalMsg = document.getElementById("endHereModalMsg");
  const elEndHereRemarks = document.getElementById("d_end_here_remarks");
  const btnEndHereCancel = document.getElementById("btnEndHereCancel");
  const btnEndHereConfirm = document.getElementById("btnEndHereConfirm");

  const selForwardTo = document.getElementById("f_to_section");
  const elUserList = document.getElementById("f_user_list");
  const btnUserSelectAll = document.getElementById("btnUserSelectAll");
  const btnUserClear = document.getElementById("btnUserClear");
  const elRecipientsPreview = document.getElementById("forwardRecipientsPreview");
  const elForwardModeWrap = document.getElementById("forwardModeWrap");
  const cbReceiveOnly = document.getElementById("f_receive_only");
  const elReceiveOnlyHint = document.getElementById("f_receive_only_hint");
  const btnForward = document.getElementById("btnForward");
  const drawerAttachmentForwardHint = document.getElementById("drawerAttachmentForwardHint");
  const drawerAttachmentForwardStatus = document.getElementById("drawerAttachmentForwardStatus");

  const attachmentForwardModal = document.getElementById("attachmentForwardModal");
  const attachmentForwardModalBackdrop = document.getElementById("attachmentForwardModalBackdrop");
  const attachmentForwardModalClose = document.getElementById("attachmentForwardModalClose");
  const btnAttachmentForwardCancel = document.getElementById("btnAttachmentForwardCancel");
  const btnAttachmentForwardSend = document.getElementById("btnAttachmentForwardSend");
  const btnAttachmentForwardAddRow = document.getElementById("btnAttachmentForwardAddRow");
  const attachmentForwardRows = document.getElementById("attachmentForwardRows");
  const elAttachmentForwardRemarks = document.getElementById("d_attachment_forward_remarks");

  const attachmentTaskDoneModal = document.getElementById("attachmentTaskDoneModal");
  const attachmentTaskDoneModalBackdrop = document.getElementById("attachmentTaskDoneModalBackdrop");
  const attachmentTaskDoneModalClose = document.getElementById("attachmentTaskDoneModalClose");
  const btnAttachmentTaskDoneCancel = document.getElementById("btnAttachmentTaskDoneCancel");
  const btnAttachmentTaskDoneConfirm = document.getElementById("btnAttachmentTaskDoneConfirm");
  const elAttachmentTaskDoneRemarks = document.getElementById("d_attachment_task_done_remarks");
  const attachmentTaskDoneModalMsg = document.getElementById("attachmentTaskDoneModalMsg");

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

  const APP = { ...(window.__APP__ || {}), ...(window.__CTX__ || {}) };
  const fallbackBase = ((window.location.pathname.match(/^(.*?)(?:\/public\/|\/api\/|\/public$|\/api$)/) || [])[1] || '');
  const API = APP.api || (fallbackBase + '/api');
  const PUBLIC = APP.public || (fallbackBase + '/public');

  let currentCanForward = false;
  let currentCanAttachmentForward = false;
  let currentAttachmentForwardRows = [];
  let attachmentForwardAttachmentOptions = [];
  let attachmentForwardRecipientCache = new Map();
  let currentPayload = null;
  let currentBranchMode = false;
  let currentBranches = [];
  let currentBranchId = 0;
  let currentPendingRemarksState = null;
  let currentEndHereMode = "end";
  let projectManageOpen = false;
  const pendingRemarkLocalEvents = new Map();
  let deadlineTicker = null;

  const DRAWER_RESTORE_KEY = "dt_restore_drawer";
  const MANILA_TZ = "Asia/Manila";

  function actingPrincipalId(payload = null) {
    const fromPayload = Number(payload?.acting_principal_user_id || currentPayload?.acting_principal_user_id || 0);
    const fromCtx = Number((window.__CTX__ || {}).actingPrincipalUserId || 0);
    return fromPayload > 0 ? fromPayload : fromCtx;
  }

  function appendActingPrincipal(target, payload = null) {
    const principalId = actingPrincipalId(payload);
    if (principalId <= 0) return target;
    if (target instanceof URLSearchParams || target instanceof FormData) {
      target.append("acting_principal_user_id", String(principalId));
    }
    return target;
  }

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

  function canManageProjects(payload = null) {
    const p = payload || currentPayload || {};
    const ctx = window.__CTX__ || {};
    const myRole = (ctx.myRole || "user").toString().toLowerCase();
    if (myRole === "admin") return true;
    if (Number(p.my_has_actionable_role || 0) === 1) return true;
    return false;
  }

  function setProjectManageOpen(open) {
    projectManageOpen = !!open;
    syncProjectActions(currentPayload);
    renderProjectCodes(currentPayload);
    if (!projectManageOpen) {
      if (inputProjectCode) inputProjectCode.value = "";
      if (selProject) selProject.value = "";
    }
  }

  function normalizeProjectList(payload = null) {
    const p = payload || currentPayload || {};
    const codes = Array.isArray(p.project_codes) ? p.project_codes : [];
    const ids = Array.isArray(p.project_ids) ? p.project_ids : [];
    const normalized = [];
    const len = Math.max(codes.length, ids.length);
    for (let i = 0; i < len; i += 1) {
      const code = clean(codes[i]);
      const id = Number(ids[i] || 0);
      if (!code && id <= 0) continue;
      normalized.push({ id, project_code: code });
    }
    return normalized;
  }

  function renderProjectCodes(payload = null) {
    if (!elProjects) return;
    const list = normalizeProjectList(payload);
    if (!list.length) {
      elProjects.innerHTML = "—";
      return;
    }
    elProjects.innerHTML = list.map((item) => {
      const removable = canManageProjects(payload) && projectManageOpen && Number(item.id || 0) > 0;
      return `<span class="chip incoming" style="display:inline-flex; align-items:center; gap:6px; margin:2px 6px 2px 0;">
        <span>${esc(item.project_code || "PROJECT")}</span>
        ${removable ? `<button type="button" class="btnSecondary" data-project-remove="${Number(item.id || 0)}" style="padding:0 6px; min-height:24px; line-height:1;">x</button>` : ""}
      </span>`;
    }).join("");
  }

  async function loadProjectLookupOptions(payload = null) {
    if (!selProject) return;
    const p = payload || currentPayload || {};
    const selectedIds = Array.isArray(p.project_ids) ? p.project_ids.filter((v) => Number(v || 0) > 0) : [];
    const qs = appendActingPrincipal(new URLSearchParams(), p);
    selectedIds.forEach((id) => qs.append("selected_ids[]", String(Number(id))));
    selProject.innerHTML = `<option value="">Loading project codes...</option>`;
    try {
      const res = await fetch(`${API}/projects_lookup.php?${qs.toString()}`, {
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      const rows = Array.isArray(data?.projects) ? data.projects : [];
    selProject.innerHTML = `<option value="">Or pick existing project code…</option>` + rows.map((row) => {
        const id = Number(row?.id || 0);
        const label = clean(row?.label) || clean(row?.project_code) || `Project #${id}`;
        return `<option value="${id}">${esc(label)}</option>`;
      }).join("");
    } catch {
      selProject.innerHTML = `<option value="">Failed to load project codes</option>`;
    }
  }

  function syncProjectActions(payload = null) {
    if (!elProjectsActions || !elProjectsManageRow) return;
    const p = payload || currentPayload || {};
    const show = canManageProjects(p) && Number(p.id || 0) > 0;
    if (!show) {
      elProjectsManageRow.style.display = "none";
      elProjectsActions.style.display = "none";
      projectManageOpen = false;
      return;
    }
    elProjectsManageRow.style.display = projectManageOpen ? "none" : "";
    elProjectsActions.style.display = projectManageOpen ? "flex" : "none";
    if (projectManageOpen) loadProjectLookupOptions(p);
  }

  async function attachProjectCode() {
    const docId = Number(currentPayload?.id || 0);
    const projectId = Number(selProject?.value || 0);
    const projectCode = clean(inputProjectCode?.value || "");
    if (docId <= 0 || (projectId <= 0 && !projectCode)) return;
    if (btnProjectAttach) btnProjectAttach.disabled = true;
    try {
      const form = appendActingPrincipal(new FormData(), currentPayload);
      form.append("document_id", String(docId));
      if (projectId > 0) form.append("project_id", String(projectId));
      if (projectCode) form.append("project_code", projectCode);
      form.append("csrf_token", window.__CSRF__ || "");
      const res = await fetch(`${API}/attach_project.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to attach project code. (${res.status})`) || console.warn(data?.error || `Failed to attach project code. (${res.status})`);
        return;
      }
      const projects = Array.isArray(data?.projects) ? data.projects : [];
      currentPayload.project_codes = projects.map((row) => clean(row?.project_code)).filter(Boolean);
      currentPayload.project_ids = projects.map((row) => Number(row?.id || 0)).filter((v) => v > 0);
      renderProjectCodes(currentPayload);
      await loadProjectLookupOptions(currentPayload);
      if (inputProjectCode) inputProjectCode.value = "";
      if (selProject) selProject.value = "";
      window.DTToast?.success("Project code attached.") || console.log("Project code attached.");
    } catch {
      window.DTToast?.error("Failed to attach project code (network error).") || console.warn("Failed to attach project code (network error).");
    } finally {
      if (btnProjectAttach) btnProjectAttach.disabled = false;
    }
  }

  async function detachProjectCode(projectId) {
    const docId = Number(currentPayload?.id || 0);
    const pid = Number(projectId || 0);
    if (docId <= 0 || pid <= 0) return;
    try {
      const form = appendActingPrincipal(new FormData(), currentPayload);
      form.append("document_id", String(docId));
      form.append("project_id", String(pid));
      form.append("csrf_token", window.__CSRF__ || "");
      const res = await fetch(`${API}/detach_project.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to remove project code. (${res.status})`) || console.warn(data?.error || `Failed to remove project code. (${res.status})`);
        return;
      }
      const projects = Array.isArray(data?.projects) ? data.projects : [];
      currentPayload.project_codes = projects.map((row) => clean(row?.project_code)).filter(Boolean);
      currentPayload.project_ids = projects.map((row) => Number(row?.id || 0)).filter((v) => v > 0);
      renderProjectCodes(currentPayload);
      await loadProjectLookupOptions(currentPayload);
      window.DTToast?.success("Project code removed.") || console.log("Project code removed.");
    } catch {
      window.DTToast?.error("Failed to remove project code (network error).") || console.warn("Failed to remove project code (network error).");
    }
  }

  function actorInitials(name) {
    const parts = clean(name).split(/\s+/).filter(Boolean);
    if (parts.length === 0) return "U";
    if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase();
    return (parts[0].slice(0, 1) + parts[1].slice(0, 1)).toUpperCase();
  }

  function renderTimelineActorAvatar(item, sizeClass = "") {
    const photoUrl = clean(item?.actor_photo_url);
    const initials = clean(item?.actor_initials) || actorInitials(item?.actor || "User");
    const fallbackStyle = photoUrl ? ' style="display:none;"' : "";

    return `
      <span class="appAvatar tActorAvatar ${esc(sizeClass)}" aria-hidden="true">
        ${photoUrl ? `<img src="${esc(photoUrl)}" alt="" loading="lazy" decoding="async" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-flex';">` : ""}
        <span${fallbackStyle}>${esc(initials)}</span>
      </span>
    `;
  }

  function normalizedPendingRemarksValue() {
    return (elPendingRemarksInput?.value ?? "").toString().trim();
  }

  function currentPendingRemarksBranchId() {
    if (currentBranchMode) {
      const branch = getSelectedBranch();
      return Number(branch?.id || 0);
    }
    return 0;
  }

  function currentPendingRemarksEventKey(docId) {
    return `${Number(docId || 0)}:${Number(currentPendingRemarksBranchId() || 0)}`;
  }

  function pushPendingRemarkLocalEvent(docId, entry) {
    const key = currentPendingRemarksEventKey(docId);
    const list = pendingRemarkLocalEvents.get(key) || [];
    list.unshift(entry);
    pendingRemarkLocalEvents.set(key, list.slice(0, 5));
  }

  function updateLatestRemarkCell(docId, remarks) {
    const row = Array.from(document.querySelectorAll("[data-doc]")).find((el) => {
      try {
        const raw = el.getAttribute("data-doc") || "{}";
        const payload = JSON.parse(raw);
        return Number(payload?.id || 0) === Number(docId || 0);
      } catch {
        return false;
      }
    });
    if (!row) return;

    const cell = row.querySelector("td[data-label='Latest remark'] .latestRemarkCell");
    if (!cell) return;

    const cleanRemark = (remarks || "").toString().trim();
    if (cleanRemark) {
      cell.classList.add("hasRemark");
      cell.innerHTML = `<div class="latestRemarkText" title="${esc(cleanRemark)}">${esc(cleanRemark)}</div>`;
    } else {
      cell.classList.remove("hasRemark");
      cell.innerHTML = `<div class="latestRemarkBlank"></div>`;
    }
  }

  function setPendingRemarksEditing(isEditing) {
    if (!elPendingRemarksComposer || !btnCancelPendingRemarks || !btnSavePendingRemarks || !btnEditPendingRemarks) return;
    elPendingRemarksComposer.style.display = isEditing ? "" : "none";
    btnCancelPendingRemarks.style.display = isEditing ? "" : "none";
    btnSavePendingRemarks.style.display = isEditing ? "" : "none";
    btnEditPendingRemarks.style.display = isEditing ? "none" : "";
    if (isEditing) {
      setTimeout(() => elPendingRemarksInput?.focus(), 0);
    }
  }

  function renderPendingRemarksState(state) {
    currentPendingRemarksState = state || null;

    if (!elPendingRemarksWrap || !elPendingRemarksPreview || !elPendingRemarksHint || !btnEditPendingRemarks) return;

    const editable = !!state?.editable;
    elPendingRemarksWrap.style.display = editable ? "" : "none";

    if (!editable) {
      setPendingRemarksEditing(false);
      return;
    }

    const remarks = (state?.remarks || "").toString().trim();
    const hasRemark = !!remarks;

    if (elPendingRemarksTitle) {
      elPendingRemarksTitle.textContent = hasRemark ? "Pending remarks" : "Add pending remarks";
    }
    if (elPendingRemarksBadge) {
      elPendingRemarksBadge.textContent = state?.just_saved ? "Updated" : "Editable";
    }

    elPendingRemarksPreview.textContent = hasRemark ? remarks : "No pending remarks yet.";
    elPendingRemarksPreview.classList.toggle("isEmpty", !hasRemark);
    elPendingRemarksPreview.classList.toggle("isChanged", !!state?.just_saved);
    elPendingRemarksHint.textContent = state?.helper_text || "This stays editable until the recipient receives the route.";

    btnEditPendingRemarks.textContent = state?.button_label || (hasRemark ? "Edit pending remarks" : "Add pending remarks");

    if (elPendingRemarksInput) {
      elPendingRemarksInput.value = remarks;
    }

    setPendingRemarksEditing(false);
  }

  async function loadPendingRouteRemarks(docId, forcedBranchId = 0) {
    if (!elPendingRemarksWrap || !docId) return;

    const qs = appendActingPrincipal(new URLSearchParams({ document_id: String(docId) }), currentPayload);
    const branchId = Number(forcedBranchId || currentPendingRemarksBranchId() || 0);
    if (branchId > 0) qs.set("branch_id", String(branchId));

    try {
      const res = await fetch(`${API}/get_pending_route_remarks.php?${qs.toString()}`, {
        cache: "no-store",
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        renderPendingRemarksState(null);
        return;
      }
      renderPendingRemarksState(data);
    } catch {
      renderPendingRemarksState(null);
    }
  }

  function saveDrawerRestoreState(docId, branchId = 0) {
    const id = Number(docId || 0);
    if (id <= 0) return;
    try {
      sessionStorage.setItem(DRAWER_RESTORE_KEY, JSON.stringify({
        docId: id,
        branchId: Number(branchId || 0),
        at: Date.now(),
      }));
    } catch (_) {}
  }

  function consumeDrawerRestoreState() {
    try {
      const raw = sessionStorage.getItem(DRAWER_RESTORE_KEY);
      if (!raw) return null;
      sessionStorage.removeItem(DRAWER_RESTORE_KEY);
      const parsed = JSON.parse(raw);
      const docId = Number(parsed?.docId || 0);
      const branchId = Number(parsed?.branchId || 0);
      if (docId <= 0) return null;
      return { docId, branchId };
    } catch (_) {
      return null;
    }
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

  function parseSqlDateParts(dt) {
    const raw = (dt || "").toString().trim();
    if (!raw) return null;

    const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!m) return null;

    return {
      year: Number(m[1]),
      month: Number(m[2]),
      day: Number(m[3]),
      hour: Number(m[4] || 0),
      minute: Number(m[5] || 0),
      second: Number(m[6] || 0),
    };
  }

  function manilaDateFromSql(dt) {
    const parts = parseSqlDateParts(dt);
    if (!parts) return null;

    return new Date(Date.UTC(
      parts.year,
      parts.month - 1,
      parts.day,
      parts.hour - 8,
      parts.minute,
      parts.second
    ));
  }

  function manilaDeadlineDateFromSql(dt) {
    const parts = parseSqlDateParts(dt);
    if (!parts) return null;

    return new Date(Date.UTC(
      parts.year,
      parts.month - 1,
      parts.day,
      23 - 8,
      59,
      59
    ));
  }

  function timestampMs(dt) {
    const manilaDate = manilaDateFromSql(dt);
    if (manilaDate && !Number.isNaN(manilaDate.getTime())) {
      return manilaDate.getTime();
    }

    const fallback = new Date((dt || "").toString());
    return Number.isNaN(fallback.getTime()) ? 0 : fallback.getTime();
  }

  function nowManilaSqlTimestamp() {
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone: MANILA_TZ,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hourCycle: "h23",
    }).formatToParts(new Date()).reduce((acc, part) => {
      if (part.type !== "literal") acc[part.type] = part.value;
      return acc;
    }, {});

    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
  }

  function fmt(dt) {
    const d = manilaDateFromSql(dt) || new Date((dt || "").toString());
    if (isNaN(d.getTime())) return dt || "";
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: MANILA_TZ,
      day: "2-digit",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(d).replace(",", "");
  }

  function fmtDate(dt) {
    const d = manilaDateFromSql(dt) || new Date((dt || "").toString());
    if (isNaN(d.getTime())) return dt || "";
    return new Intl.DateTimeFormat("en-GB", {
      timeZone: MANILA_TZ,
      day: "2-digit",
      month: "short",
      year: "numeric",
    }).format(d).replace(",", "");
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

  function renderDeadline(documentDeadlineAt, personalDeadlineAt = "", finalOutcomeText = "") {
    const docRaw = (documentDeadlineAt || "").toString().trim();
    const personalRaw = (personalDeadlineAt || "").toString().trim();
    const effectiveRaw = personalRaw || docRaw;
    const outcomeText = (finalOutcomeText || "").toString().trim();

    if (elDeadline) elDeadline.textContent = docRaw ? fmtDate(docRaw) : "—";
    if (elPersonalDeadline) elPersonalDeadline.textContent = personalRaw ? fmtDate(personalRaw) : "—";

    if (deadlineTicker) {
      clearInterval(deadlineTicker);
      deadlineTicker = null;
    }

    if (!elDeadlineCountdown) return;
    if (outcomeText) {
      elDeadlineCountdown.textContent = outcomeText;
      return;
    }

    if (!effectiveRaw) {
      elDeadlineCountdown.textContent = "No deadline set";
      return;
    }

    const deadlineDate = manilaDeadlineDateFromSql(effectiveRaw) || manilaDateFromSql(effectiveRaw) || new Date(effectiveRaw.replace(" ", "T"));
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
      attachment_forwarded: "Attachment Forwarded",
      attachment_forward_task_done: "Attachment Task Done",
      released: "Released",
      release_undone: "Undo Release",
      archived: "Archived",
      archive_undone: "Undo Archive",
      attachment_added: "Attachment",
      pending_remarks_added: "Pending Remarks Added",
      pending_remarks_updated: "Pending Remarks Updated",
      pending_remarks_cleared: "Pending Remarks Cleared",
      branch_ended_here: "Lifecycle Ended",
      branch_end_here_undone: "Lifecycle Reopened",
      document_ended_here: "Lifecycle Ended",
      document_end_here_undone: "Lifecycle Reopened",
      cancelled: "Cancelled",
      under_action: "Under Action",
      updated: "Updated",
      status_changed: "Status Changed",
    };
    return map[key] || (key ? key : "Updated");
  }

  function actionIcon(k) {
    const key = (k || "updated").toString().trim().toLowerCase();
    const cleanMap = {
      created: "+",
      sent: "S",
      forwarded: "F",
      attachment_forwarded: "A",
      attachment_forward_task_done: "D",
      received: "R",
      attachment_added: "A",
      pending_remarks_added: "N",
      pending_remarks_updated: "N",
      pending_remarks_cleared: "N",
      branch_ended_here: "E",
      branch_end_here_undone: "U",
      document_ended_here: "E",
      document_end_here_undone: "U",
      released: "L",
      release_undone: "U",
      archived: "X",
      archive_undone: "U",
      cancelled: "!",
      status_changed: "C",
      updated: "U",
    };
    return cleanMap[key] || "U";
    const map = {
      created: "＋",
      sent: "↗",
      forwarded: "➜",
      attachment_forwarded: "📎",
      attachment_forward_task_done: "☑",
      received: "✓",
      attachment_added: "📎",
      pending_remarks_added: "✎",
      pending_remarks_updated: "✎",
      pending_remarks_cleared: "⌫",
      branch_ended_here: "■",
      branch_end_here_undone: "↩",
      document_ended_here: "■",
      document_end_here_undone: "↩",
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
    if (btnToggleForward) {
      btnToggleForward.textContent = "Forward";
    }
  }

  function setDrawerTab(tabName = "overview") {
    const target = drawerPanels.some((panel) => panel.dataset.drawerPanel === tabName)
      ? tabName
      : "overview";

    drawerTabs.forEach((tab) => {
      const active = tab.dataset.drawerTab === target;
      tab.classList.toggle("isActive", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
    });

    drawerPanels.forEach((panel) => {
      const active = panel.dataset.drawerPanel === target;
      panel.classList.toggle("isActive", active);
      panel.hidden = !active;
    });

    if (target === "files") {
      setCollapsed(elAttachments, false);
      syncToggleLabels();
    }
  }

  function getSelectedBranch() {
    return currentBranches.find((b) => Number(b.id || 0) === Number(currentBranchId || 0)) || null;
  }

  function viewerIsAttachmentOnlyReceiver() {
    const role = ((window.__CTX__?.myRole || "user").toString().toLowerCase());
    if (role === "admin" || role === "records") return false;

    if (currentBranchMode) {
      const branch = getSelectedBranch();
      if (!branch) return false;

      return !!(
        Number(branch.attachment_forward_recipient_branch || 0) === 1
        && Number(branch.attachment_forward_source_branch || 0) === 0
        && Number(branch.is_reference || 0) === 1
        && Number(branch.can_forward || 0) !== 1
      );
    }

    return !!(
      Number(currentPayload?.attachment_forward_recipient_branch || 0) === 1
      && Number(currentPayload?.attachment_forward_source_branch || 0) === 0
      && Number(currentPayload?.my_has_actionable_role || 0) !== 1
    );
  }

  function syncViewDocumentButton() {
    if (!btnViewDocument) return;

    const docId = Number(currentPayload?.id || 0);
    const canViewFullDocument = docId > 0 && !viewerIsAttachmentOnlyReceiver();

    btnViewDocument.dataset.docId = canViewFullDocument ? String(docId) : "";
    btnViewDocument.style.display = canViewFullDocument ? "" : "none";
    const row = btnViewDocument.closest(".kv");
    if (row) row.style.display = canViewFullDocument ? "" : "none";
  }

  window.DTGetSelectedBranchId = function () {
    return currentBranchMode ? Number(getSelectedBranch()?.id || 0) : 0;
  };


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
        canAttach = !!(
          isPrivileged
          || (branch && Number(branch.can_forward || 0) === 1)
          || (branch && Number(branch.attachment_forward_can_attach || 0) === 1)
        );
      } else {
        canAttach = !!(
          isPrivileged
          || Number(currentPayload?.my_has_actionable_role || 0) === 1
          || Number(currentPayload?.attachment_forward_can_attach || 0) === 1
        );
      }
    }

    btnToggleUpload.style.display = canAttach ? "" : "none";
  }


  function formatBranchDestination(branch) {
    if (!branch) return "—";
    const assignee = clean(branch.current_assignee_name);
    const section = clean(branch.current_assignee_section_name);
    if (assignee && section) return `${assignee} (${section})`;
    if (assignee) return assignee;
    if (section) return section;
    return clean(branch.branch_label) || "—";
  }

  function isOriginOverviewMode() {
    return !!(currentBranchMode && Number(currentPayload?.is_origin || 0) === 1);
  }

  function branchOptionLabel(branch) {
    const label = branchLabel(branch);
    if (Number(branch?.my_pending_route_id || 0) > 0) return `${label} — Pending receive`;
    if (Number(branch?.can_forward || 0) === 1) return `${label} — Actionable`;
    if (Number(branch?.is_reference || 0) === 1) return `${label} — Reference only`;
    if (((branch?.branch_status || "").toString().toUpperCase()) !== "ACTIVE") return `${label} — Completed`;
    return label;
  }

  function bindBranchSelector() {
    if (!elBranchSelect) return;
    elBranchSelect.onchange = () => {
      const bid = Number(elBranchSelect.value || 0);
      const docId = Number(currentPayload?.id || 0);
      if (bid <= 0 || docId <= 0) return;
      applyBranchSelection(bid);
      loadTimeline(docId, bid, { preserveSelection: true });
    };
  }

  function renderHeaderBranchPills() {
    if (!elBranchSelect) return;

    if (!currentBranchMode || !Array.isArray(currentBranches) || currentBranches.length === 0) {
      elBranchSelect.innerHTML = "";
      return;
    }

    const optionsHtml = currentBranches
      .filter((branch) => Number(branch?.id || 0) > 0)
      .map((branch) => {
        const bid = Number(branch.id || 0);
        const selected = bid === Number(currentBranchId || 0) ? ' selected' : '';
        return `<option value="${bid}"${selected}>${esc(branchOptionLabel(branch))}</option>`;
      })
      .join("");

    elBranchSelect.innerHTML = optionsHtml;
    bindBranchSelector();
  }

  function refreshDrawerBranchContext() {
    if (!currentPayload) return;

    const branch = currentBranchMode ? getSelectedBranch() : null;
    const inTransit = currentPayload?.in_transit === 1 || currentPayload?.in_transit === "1" || currentPayload?.in_transit === true;

    const destinationText = (branch && !isOriginOverviewMode())
      ? formatBranchDestination(branch)
      : (currentPayload.movement_text || "—");

    if (elDestinationText) {
      elDestinationText.textContent = destinationText;
    } else if (elDestination) {
      elDestination.textContent = destinationText;
    }

    if (elHolder) {
      if (branch) {
        const statusText = ((branch.branch_status || "ACTIVE").toString().toUpperCase() === "ACTIVE")
          ? (Number(branch.can_forward || 0) === 1 ? "With you" : (Number(branch.my_pending_route_id || 0) > 0 ? "To you" : "Active lane"))
          : "Branch complete";
        elHolder.textContent = statusText;
        elHolder.className = `chip ${Number(branch.can_forward || 0) === 1 ? "action" : (Number(branch.my_pending_route_id || 0) > 0 ? "incoming" : "archived")}`;
      } else {
        elHolder.textContent = currentPayload.current_holder_text || "—";
        elHolder.className = "chip incoming";
      }
    }

    if (elLastHolder) {
      if (branch) {
        elLastHolder.textContent = clean(currentPayload.open_from_section_name) || clean(currentPayload.last_holder_text) || "—";
      } else {
        elLastHolder.textContent = currentPayload.last_holder_text || "—";
      }
    }

    if (elDestination) {
      const clickable = !currentBranchMode && !!inTransit && (Number(currentPayload.open_route_count || 0) > 1);
      elDestination.classList.toggle("destClickable", clickable);
      if (clickable) {
        elDestination.dataset.docId = String(currentPayload.id || "");
        elDestination.dataset.count = String(Number(currentPayload.open_route_count || 0));
      } else {
        delete elDestination.dataset.docId;
        delete elDestination.dataset.count;
      }
    }

    renderAttachmentForwardStatusPanel();
  }

  function applyBranchSelection(branchId) {
    currentBranchId = Number(branchId || 0);
    savePreferredBranchId(currentPayload?.id || 0, currentBranchId);
    const branch = getSelectedBranch();

    renderHeaderBranchPills();

    syncInlineBranchSelection();

    if (elBranchSelect) {
      elBranchSelect.value = branch ? String(Number(branch.id || 0)) : "";
    }

    if (elBranchMeta) {
      if (!branch) {
        elBranchMeta.textContent = currentBranchMode ? "No lane selected." : "";
      } else {
        const bits = [];
        if (Number(branch.my_pending_route_id || 0) > 0) bits.push("Waiting for your receive");
        else if (Number(branch.can_forward || 0) === 1) bits.push("You can act on this lane");
        else if (Number(branch.is_reference || 0) === 1) bits.push("Reference-only lane");
        else if (((branch.branch_status || "").toString().toUpperCase()) !== "ACTIVE") bits.push("Completed lane");
        else bits.push("View-only lane");

        if (clean(branch.current_assignee_name)) {
          const sec = clean(branch.current_assignee_section_name);
          bits.push(`Assigned to ${branch.current_assignee_name}${sec ? ` (${sec})` : ""}`);
        }

        elBranchMeta.textContent = bits.join(" • ");
      }
    }

    currentCanForward = !!(branch && Number(branch.can_forward || 0) === 1);
    currentCanAttachmentForward = !!(
      branch
      && ((branch.branch_status || "").toString().toUpperCase() === "ACTIVE")
      && Number(branch.is_reference || 0) === 0
      && Number(branch.current_assignee_user_id || 0) > 0
      && Number(branch.current_assignee_user_id || 0) === Number((window.__CTX__ || {}).myUserId || 0)
      && Number(branch.my_pending_route_id || 0) === 0
    );
    syncViewDocumentButton();
    refreshDrawerBranchContext();
    syncAttachmentButtonVisibility();
    updateForwardUI();
    syncEndHereButtons();
    if (currentPayload?.id) {
      loadAttachments(currentPayload.id);
      loadPendingRouteRemarks(currentPayload.id, currentBranchId);
    }

    if (btnAckReceived) {
      const canReceive = !!(
        branch &&
        Number(branch.my_pending_route_id || 0) > 0 &&
        (currentPayload?.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE"
      );
      btnAckReceived.style.display = canReceive ? "" : "none";
    }
    if (btnAttachmentTaskDone) {
      const canTaskDone = !!(
        branch
        && Number(branch.attachment_forward_can_mark_done || 0) === 1
        && (currentPayload?.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE"
      );
      btnAttachmentTaskDone.style.display = canTaskDone ? "" : "none";
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
    return "";
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

      const ts = timestampMs(item?.acted_at);
      if (!found || ts > foundTs) {
        found = item;
        foundTs = ts;
      }
    });

    return found;
  }

  function renderBranchTabs(branches, options = {}) {
    currentBranches = Array.isArray(branches) ? branches : [];

    const visibleBranches = currentBranches.filter((b) => Number(b.id || 0) > 0);
    const preserveSelection = !!options.preserveSelection;

    if (!currentBranchMode || visibleBranches.length === 0) {
      currentBranchMode = false;
      if (elBranchWrap) elBranchWrap.style.display = "none";
      currentBranchId = 0;
      savePreferredBranchId(currentPayload?.id || 0, 0);
      syncViewDocumentButton();
      return;
    }

    const savedBranchId = loadPreferredBranchId(currentPayload?.id || 0);
    const savedBranch = visibleBranches.find((b) => Number(b.id || 0) === savedBranchId) || null;
    const currentBranch = visibleBranches.find((b) => Number(b.id || 0) === Number(currentBranchId || 0)) || null;

    const myPending = visibleBranches.find((b) => Number(b.my_pending_route_id || 0) > 0) || null;
    const myActionable = visibleBranches.find((b) => Number(b.can_forward || 0) === 1) || null;

    const isActionableBranch = (branch) => !!(
      branch && (
        Number(branch.my_pending_route_id || 0) > 0
        || Number(branch.can_forward || 0) === 1
      )
    );

    const hasMyWorkingLane = !!(myPending || myActionable);

    if (preserveSelection && currentBranch && (!hasMyWorkingLane || isActionableBranch(currentBranch))) {
      // keep current selection stable while refreshing the drawer
    } else if (myPending) {
      currentBranchId = Number(myPending.id || 0);
    } else if (myActionable) {
      currentBranchId = Number(myActionable.id || 0);
    } else if (savedBranch) {
      currentBranchId = Number(savedBranch.id || 0);
    } else if (currentBranch) {
      currentBranchId = Number(currentBranch.id || 0);
    } else {
      currentBranchId = preferredBranchId(visibleBranches);
    }

    if (elBranchWrap) elBranchWrap.style.display = visibleBranches.length > 1 ? "" : "none";
    if (elBranchSelect) elBranchSelect.innerHTML = "";
    if (elBranchHint) {
      elBranchHint.textContent = visibleBranches.length > 1
        ? "Select the lane you want to inspect."
        : "";
    }

    applyBranchSelection(currentBranchId);
  }

  function currentAttachmentForwardOpenTaskCount() {
    if (currentBranchMode) {
      const branch = getSelectedBranch();
      return Number(branch?.attachment_forward_open_task_count || 0);
    }
    return Number(currentPayload?.attachment_forward_open_task_count || 0);
  }

  function attachmentTaskStatusLabel(status) {
    const key = (status || '').toString().trim().toUpperCase();
    if (key === 'PENDING_RECEIVE') return 'Pending receive';
    if (key === 'IN_PROGRESS') return 'In progress';
    if (key === 'DONE') return 'Done';
    return key || 'Open';
  }

  function renderAttachmentForwardStatusPanel() {
    if (!drawerAttachmentForwardStatus) return;
    const summary = Array.isArray(currentPayload?.attachment_forward_task_summary)
      ? currentPayload.attachment_forward_task_summary
      : [];
    const senderItems = summary.filter((item) => Number(item?.is_sender || 0) === 1);
    const recipientItems = summary.filter((item) => Number(item?.is_recipient || 0) === 1);

    if (!senderItems.length && !recipientItems.length) {
      drawerAttachmentForwardStatus.style.display = 'none';
      drawerAttachmentForwardStatus.innerHTML = '';
      return;
    }

    const grouped = senderItems.map((item) => {
      const recipientName = clean(item.recipient_name);
      const recipientSection = clean(item.recipient_section_name);
      const attachment = clean(item.attachment_name) || 'Attachment';
      const status = attachmentTaskStatusLabel(item.task_status);
      const who = recipientName && recipientSection ? `${recipientName} (${recipientSection})` : (recipientName || recipientSection || 'Recipient');
      const statusClass = status === 'Done' ? 'isDone' : (status === 'In progress' ? 'isProgress' : 'isPending');
      return `<div class="attachmentTaskRow"><div><strong>${esc(attachment)}</strong><div>To: ${esc(who)}</div></div><span class="attachmentTaskStatus ${statusClass}">${esc(status)}</span></div>`;
    }).join('');

    const openCount = senderItems.filter((item) => ['PENDING_RECEIVE','IN_PROGRESS'].includes((item.task_status || '').toString().toUpperCase())).length;
    const recipientHtml = recipientItems.length ? `<div class="attachmentTaskNote">You have ${recipientItems.length} attachment task${recipientItems.length === 1 ? '' : 's'} on this document.</div>` : '';

    drawerAttachmentForwardStatus.innerHTML = `
      <details class="attachmentTaskProgress" ${openCount > 0 ? '' : ''}>
        <summary>
          <span>
            <span class="attachmentTaskProgressEyebrow">Attachment task progress</span>
            <span class="attachmentTaskProgressTitle">Waiting on ${openCount} open task${openCount === 1 ? '' : 's'}.</span>
          </span>
          <span class="attachmentTaskProgressBadge">${senderItems.length || recipientItems.length}</span>
        </summary>
        <div class="attachmentTaskProgressBody">
          ${senderItems.length ? grouped : ''}
          ${recipientHtml}
        </div>
      </details>
    `;
    drawerAttachmentForwardStatus.style.display = '';
  }

  function updateAttachmentForwardMessaging() {
    const branch = currentBranchMode ? getSelectedBranch() : null;
    const openTaskCount = currentAttachmentForwardOpenTaskCount();
    const canAttachmentForward = !!currentCanAttachmentForward;
    const normalForwardLockedByTasks = currentBranchMode
      ? !!(
          branch
          && openTaskCount > 0
          && Number(branch.is_reference || 0) === 0
          && ((branch.branch_status || "").toString().toUpperCase() === "ACTIVE")
        )
      : !!(openTaskCount > 0 && Number(currentPayload?.my_has_actionable_role || 0) === 1);

    if (btnToggleAttachmentForward) {
      btnToggleAttachmentForward.textContent = openTaskCount > 0 ? "Forward another attachment" : "Forward by attachment";
    }

    if (drawerAttachmentForwardHint) {
      if (normalForwardLockedByTasks && canAttachmentForward) {
        drawerAttachmentForwardHint.style.display = "";
        drawerAttachmentForwardHint.textContent = "Normal forward is locked until all attachment-forward tasks are marked done. You may still forward another attachment from this lane.";
      } else if (canAttachmentForward) {
        drawerAttachmentForwardHint.style.display = "";
        drawerAttachmentForwardHint.textContent = "Need selective routing? Use Forward by attachment so the document stays with you while specific files are sent out as task lanes.";
      } else {
        drawerAttachmentForwardHint.style.display = "none";
        drawerAttachmentForwardHint.textContent = "";
      }
    }
  }

  function updateForwardUI() {
    const chiefCanSetDeadline = !!(window.__CTX__?.isChief);
    const isInitialRouting = Number(currentPayload?.is_initial_routing || 0) === 1;
    const showDocumentDeadline = currentCanForward && isInitialRouting;
    const showPersonalDeadline = currentCanForward && chiefCanSetDeadline;

    if (btnToggleForward) btnToggleForward.style.display = currentCanForward ? "" : "none";
    if (btnToggleAttachmentForward) btnToggleAttachmentForward.style.display = currentCanAttachmentForward ? "" : "none";
    if (btnForward) btnForward.style.display = currentCanForward ? "" : "none";
    if (elForwardModeWrap) elForwardModeWrap.style.display = currentCanForward ? "" : "none";
    if (forwardDeadlineGrid) forwardDeadlineGrid.style.display = (showDocumentDeadline || showPersonalDeadline) ? "" : "none";
    if (forwardDocumentDeadlineWrap) forwardDocumentDeadlineWrap.style.display = showDocumentDeadline ? "" : "none";
    if (forwardPersonalDeadlineWrap) forwardPersonalDeadlineWrap.style.display = showPersonalDeadline ? "" : "none";

    if (!showDocumentDeadline && inputForwardDocumentDeadline) inputForwardDocumentDeadline.value = "";
    if (!showPersonalDeadline && inputForwardPersonalDeadline) inputForwardPersonalDeadline.value = "";

    if (!currentCanForward) {
      if (inputForwardDocumentDeadline) inputForwardDocumentDeadline.value = "";
      if (inputForwardPersonalDeadline) inputForwardPersonalDeadline.value = "";
      if (elForwardRemarks) elForwardRemarks.value = "";
      closeForwardModal();
    }
    if (!currentCanAttachmentForward) {
      if (elAttachmentForwardRemarks) elAttachmentForwardRemarks.value = "";
      closeAttachmentForwardModal();
    }

    if (btnAttachmentTaskDone) {
      const canTaskDone = currentBranchMode
        ? !!(getSelectedBranch() && Number(getSelectedBranch().attachment_forward_can_mark_done || 0) === 1 && (currentPayload?.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE")
        : !!(Number(currentPayload?.attachment_forward_can_mark_done || 0) === 1 && (currentPayload?.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE");
      btnAttachmentTaskDone.style.display = canTaskDone ? "" : "none";
    }

    updateAttachmentForwardMessaging();
    updateForwardModeUI();
  }

  function getEndHereBranchState() {
    const branch = currentBranchMode ? getSelectedBranch() : null;
    return {
      branch,
      canEnd: !!(branch && Number(branch.can_forward || 0) === 1),
      canUndo: !!(branch && Number(branch.can_undo_end_here || 0) === 1),
    };
  }

  function isLifecycleEndedKind(kind) {
    return ["branch_ended_here", "document_ended_here"].includes((kind || "").toString());
  }

  function syncEndHereButtons(options = {}) {
    const docStatus = ((currentPayload?.current_status || "ACTIVE").toString().toUpperCase());
    const flatActionable = Number(currentPayload?.my_has_actionable_role || 0) === 1;
    const flatLifecycle = Number(currentPayload?.my_can_change_lifecycle || 0) === 1;
    const lastEndKind = (currentPayload?.last_end_here_kind || "").toString();
    const isPrivileged = ((window.__CTX__?.myRole || "user").toString().toLowerCase() === "admin");
    const branchState = getEndHereBranchState();

    let showEnd = false;
    let showUndo = false;

    if (currentBranchMode) {
      const branch = branchState.branch;
      const attachmentForwardRecipient = !!(branch && Number(branch.attachment_forward_recipient_branch || 0) === 1);
      showEnd = docStatus === "ACTIVE" && branchState.canEnd && !attachmentForwardRecipient;
      showUndo = branchState.canUndo;
    } else {
      showEnd = docStatus === "ACTIVE" && flatActionable;
      showUndo = docStatus === "RELEASED" && flatLifecycle && isLifecycleEndedKind(lastEndKind);
    }

    if (btnEndHere) btnEndHere.style.display = showEnd ? "" : "none";
    if (btnUndoEndHere) btnUndoEndHere.style.display = showUndo ? "" : "none";

    if (!options.keepModal && !showEnd && !showUndo) {
      closeEndHereModal();
    }

    if (isPrivileged && !currentBranchMode && docStatus === "ACTIVE") {
      // Admin keeps Release/Archive as administrative lifecycle actions; End Now is for the active holder.
      if (btnEndHere) btnEndHere.style.display = flatActionable ? "" : "none";
    }
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
      elReceiveOnlyHint.textContent = "Recipient gets a reference copy only. Your current lane stays actionable with you.";
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
      elReceiveOnlyHint.textContent = currentBranchMode
        ? "Multiple recipients from an active lane are sent as reference only. No new sub-branches will be created."
        : "Multiple recipients are sent as reference only in the current workflow.";
      return;
    }

    cbReceiveOnly.disabled = false;
    elReceiveOnlyHint.textContent = cbReceiveOnly.checked
      ? "Recipient gets a reference copy only. Your current lane stays actionable with you."
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
      if (attModal?.classList.contains("open")) return closeAttachmentModal();
      if (recModal?.classList.contains("open")) return closeRecipientsModal();
      if (releaseModal?.classList.contains("open")) return closeReleaseModal();
      if (forwardModal?.classList.contains("open")) return closeForwardModal();
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
          const superseded = note.startsWith("AUTO:DIVISION_TRACKING_SLIP:") && note.includes(":SUPERSEDED");
          const meta = [
            fmt(a.uploaded_at || a.created_at || ""),
            clean(a.uploaded_by || a.uploaded_by_name || a.actor || ""),
            fmtBytes(a.size_bytes || a.size || 0),
          ].filter(Boolean).join(" • ");
          const scopeLabel = Number(a.branch_id || 0) > 0
            ? (clean(a.branch_label) || `Branch ${Number(a.branch_id || 0)}`)
            : (currentBranchMode ? "Global" : "");

          const principalQs = actingPrincipalId() > 0 ? `&acting_principal_user_id=${actingPrincipalId()}` : "";
          const viewUrl = `${PUBLIC}/view_attachment.php?id=${Number(a.id || 0)}${principalQs}`;
          const dlUrl = `${PUBLIC}/download_attachment.php?id=${Number(a.id || 0)}${principalQs}`;

          return `
            <div class="attachCard" style="border:1px solid rgba(0,0,0,.08); border-radius:12px; padding:12px; background:#fff;">
              <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start;">
                <div style="min-width:0;">
                  <div style="font-weight:900; line-height:1.25; word-break:break-word;">${esc(name)}</div>
                  ${superseded ? `<div class="mini" style="margin-top:5px; color:#b45309; font-weight:900;">Superseded - visible here, excluded from full document view</div>` : ""}
                  ${meta ? `<div class="mini" style="opacity:.7; margin-top:4px;">${esc(meta)}</div>` : ""}
                  ${scopeLabel ? `<div class="mini" style="margin-top:6px;"><strong>Scope:</strong> ${esc(scopeLabel)}</div>` : ""}
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
      const qs = appendActingPrincipal(new URLSearchParams({ document_id: String(docId) }));
      const branch = currentBranchMode ? getSelectedBranch() : null;
      if (currentBranchMode && Number(branch?.id || 0) > 0) qs.set("branch_id", String(Number(branch.id || 0)));
      const url = `${API}/attachments_list.php?${qs.toString()}`;
      const res = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.ok) {
        elAttachments.textContent = data?.error || `Failed to load attachments. (${res.status})`;
        return;
      }

      const items = Array.isArray(data.attachments) ? data.attachments : [];
      currentPpdSlipAttId = 0;

      for (const a of items) {
        const note = (a.note || '').toString();
        if (note === 'AUTO:PPD_TRACKING_SLIP' || (note.startsWith('AUTO:DIVISION_TRACKING_SLIP:') && !note.includes(':SUPERSEDED'))) {
          currentPpdSlipAttId = Number(a.id || 0);
          break;
        }
      }

      if (btnPpdSlipPrint) {
        btnPpdSlipPrint.disabled = !(APP.hasOwnDivisionSlip && currentPpdSlipAttId > 0);
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

  function isReferenceTimelineEvent(i) {
    return Number(i?.is_reference_event || 0) === 1
      || (i?.route_kind || "").toString().toUpperCase() === "REFERENCE";
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
            const name = clean(row?.name) || clean(row?.branch_label) || `Branch ${Number(row?.branch_id || 0)}`;
            return `<span class="ackSummaryChip">${esc(name)}</span>`;
          }).join("")}
        </div>
      `;
    }

    return `
      <div class="ackSummaryList">
        ${rows.map((row) => {
          const name = clean(row?.name) || clean(row?.branch_label) || `Branch ${Number(row?.branch_id || 0)}`;
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

  function attachmentTaskNamesHtml(names, emptyLabel, compact) {
    const rows = Array.isArray(names) ? names.map(clean).filter(Boolean) : [];
    if (!rows.length) {
      return `<div class="ackSummaryEmpty">${esc(emptyLabel)}</div>`;
    }

    return `
      <div class="ackSummaryChipList">
        ${rows.map((name) => `<span class="ackSummaryChip">${esc(name)}</span>`).join("")}
      </div>
    `;
  }

  function renderAttachmentTaskSummary(i, opts = {}) {
    const summary = i?.attachment_task_summary;
    if (!summary || typeof summary !== "object") return "";

    const compact = !!opts.compact;
    const totalRecipients = Number(summary.total_recipient_count || 0);
    const doneRecipients = Number(summary.done_recipient_count || 0);
    const openRecipients = Number(summary.open_recipient_count || 0);
    const totalTasks = Number(summary.total_task_count || 0);
    const doneTasks = Number(summary.done_task_count || 0);
    const showNames = summary.show_names === true;

    if (totalRecipients <= 0 && totalTasks <= 0) return "";

    const doneUsers = Array.isArray(summary.done_users) ? summary.done_users : [];
    const inProgressUsers = Array.isArray(summary.in_progress_users) ? summary.in_progress_users : [];
    const pendingUsers = Array.isArray(summary.pending_users) ? summary.pending_users : [];

    return `
      <div class="ackSummary attachmentTaskSummary ${compact ? "ackSummary--compact" : ""}">
        <div class="ackSummaryHead">
          <div class="ackSummaryTitle">${compact ? "Tasks" : "Attachment task status"}</div>
          <div class="ackSummaryCounts">${doneRecipients}/${totalRecipients} done</div>
        </div>

        ${totalTasks > 0 ? `
          <div class="ackSummaryEmpty">Files completed: ${doneTasks}/${totalTasks}</div>
        ` : ``}

        <div class="ackSummaryPanels attachmentTaskSummaryPanels">
          <div class="ackSummaryPanel isInline">
            <div class="attachmentTaskSummaryLabel">Done (${doneRecipients})</div>
            ${showNames
              ? attachmentTaskNamesHtml(doneUsers, "No completed recipients yet.", compact)
              : `<div class="ackSummaryEmpty">Done recipients: ${doneRecipients}</div>`
            }
          </div>

          <div class="ackSummaryPanel isInline">
            <div class="attachmentTaskSummaryLabel">Still open (${openRecipients})</div>
            ${showNames
              ? `
                ${attachmentTaskNamesHtml(inProgressUsers, "No one is currently in progress.", compact)}
                ${pendingUsers.length ? `<div class="attachmentTaskSummarySubLabel">Not yet received</div>${attachmentTaskNamesHtml(pendingUsers, "", compact)}` : ``}
              `
              : `<div class="ackSummaryEmpty">Open recipients: ${openRecipients}</div>`
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
          const attachmentTaskSummaryHtml = renderAttachmentTaskSummary(i);
          const isReferenceEvent = isReferenceTimelineEvent(i);

          if (isReferenceEvent) {
            details.push(`<span class="tChip">FOR REFERENCE</span>`);
          }
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
                    <div class="tActorMetaHead">
                      ${renderTimelineActorAvatar(i)}
                      <span class="tActorTime">${esc(fmt(i.acted_at))}</span>
                    </div>
                    <span class="tActorName">${esc(i.actor || "System")}</span>
                  </div>

                  <div class="tRight">
                    ${isCurrent ? `<span class="tBadge">LATEST</span>` : ``}
                    ${isReferenceEvent ? `<span class="tBadge">FOR REFERENCE</span>` : ``}
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
                ${attachmentTaskSummaryHtml}

                ${i.personal_deadline_at ? `<div class="tNote"><strong>Personal deadline:</strong> ${esc(fmtDate(i.personal_deadline_at))}</div>` : ``}
                ${i.released_to ? `<div class="tNote"><strong>Released to:</strong> ${esc(i.released_to)}</div>` : ``}
                ${i.remarks ? `<div class="tNote"><strong>Remarks:</strong> ${esc(i.remarks)}</div>` : ``}

              </div>
            </div>
          `;
        }).join("")}
      </div>
    `;
  }

  function renderGroupedView(itemsNewestFirst) {
    const renderedSplitEventIds = new Set();
    const ctx = window.__CTX__ || {};
    const myDivisionName = clean(ctx.myDivisionName);
    const timelineItems = Array.isArray(itemsNewestFirst) ? itemsNewestFirst : [];

    const groups = [];
    timelineItems.forEach((item, idx) => {
      const key = groupTitleFor(item);
      const prev = groups[groups.length - 1] || null;
      if (!prev || prev.key !== key) {
        groups.push({ key, items: [item], startIdx: idx, endIdx: idx });
        return;
      }

      prev.items.push(item);
      prev.endIdx = idx;
    });

    const normalizedGroups = groups.map((group, groupIndex) => {
      const newestFirst = [...group.items].sort((a, b) => {
        const ta = timestampMs(a.acted_at);
        const tb = timestampMs(b.acted_at);
        return tb - ta;
      });
      const latest = newestFirst[0] || null;
      const oldest = newestFirst[newestFirst.length - 1] || null;
      const latestEventId = Number(latest?.event_id || 0);
      const oldestEventId = Number(oldest?.event_id || 0);
      const storageKey = [
        group.key,
        groupIndex + 1,
        latestEventId > 0 ? `latest${latestEventId}` : `latestIdx${group.startIdx}`,
        oldestEventId > 0 ? `oldest${oldestEventId}` : `oldestIdx${group.endIdx}`,
      ].join("_");

      return {
        ...group,
        items: newestFirst,
        latestTs: timestampMs(latest?.acted_at),
        divisionName: groupDivisionFor(newestFirst),
        latest,
        storageKey,
      };
    });

    return `
      <div class="tGrouped">
        ${normalizedGroups.map((group) => {
          const latest = group.latest;
          const groupBranchId = resolveGroupBranchId(group.items);
          const splitEvent = findSplitEventForBranch(timelineItems, groupBranchId);
          const isMineDivision = !!(myDivisionName && group.divisionName && sameText(myDivisionName, group.divisionName));
          const isCollapsed = isTimelineGroupCollapsed(group.storageKey, group.divisionName);

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
                  data-group-key="${esc(group.storageKey)}"
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
                  const attachmentTaskSummaryHtml = renderAttachmentTaskSummary(i, { compact: true });
                  const isReferenceEvent = isReferenceTimelineEvent(i);

                  if (isReferenceEvent) {
                    details.push("FOR REFERENCE");
                  }
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
                        <div class="tLineMetaHead">
                          ${renderTimelineActorAvatar(i, "tActorAvatarSm")}
                          <span class="tLineTime">${esc(fmt(i.acted_at))}</span>
                        </div>
                        <span class="tLineTag">${esc(prettyAction(actionKey).toUpperCase())}</span>
                        ${isReferenceEvent ? `<span class="tLineTag">FOR REFERENCE</span>` : ``}
                      </div>

                      <div class="tLineRight">
                        <div class="tLineTitle">${esc(i.title || prettyAction(actionKey))}</div>
                        ${movement ? `<div class="tLineMove">${movement}</div>` : ``}
                        ${details.length ? `<div class="tLineMove">${details.join(" • ")}</div>` : ``}
                        ${ackSummaryHtml}
                        ${attachmentTaskSummaryHtml}
                        ${i.personal_deadline_at ? `<div class="tLineNote">Personal deadline: ${esc(fmtDate(i.personal_deadline_at))}</div>` : ``}
                        ${i.released_to ? `<div class="tLineNote">Released to: ${esc(i.released_to)}</div>` : ``}
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

  async function loadTimeline(docId, forcedBranchId = 0, options = {}) {
    if (!elTimeline) return;

    try {
      const qs = appendActingPrincipal(new URLSearchParams({ document_id: String(docId) }));
      const branchId = Number(forcedBranchId || currentBranchId || 0);
      if (currentBranchMode && branchId > 0) qs.set("branch_id", String(branchId));

      const res = await fetch(`${API}/get_history.php?${qs.toString()}`, {
        cache: "no-store",
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
      renderBranchTabs(branchRows, { preserveSelection: !!options.preserveSelection || Number(forcedBranchId || 0) > 0 });

      if (currentBranchMode) {
        const activeId = Number(forcedBranchId || currentBranchId || preferredBranchId(branchRows));
        if (activeId > 0 && activeId !== Number(data.selected_branch_id || 0)) {
          currentBranchId = activeId;
          return loadTimeline(docId, activeId);
        }
      }

      const serverItems = Array.isArray(data.history) ? data.history : [];
      const localKey = `${Number(docId || 0)}:${Number(currentBranchMode ? (forcedBranchId || currentBranchId || 0) : 0)}`;
      const localItems = pendingRemarkLocalEvents.get(localKey) || [];
      const items = [...localItems, ...serverItems];
      if (items.length === 0) {
        elTimeline.innerHTML = `<div class="mini" style="opacity:.7;">No timeline yet for this ${currentBranchMode ? "branch" : "document"}.</div>`;
        return;
      }

      const LS_KEY = currentBranchMode ? "dt_timeline_view_branch_v2" : "dt_timeline_view_v2";
      const saved = (localStorage.getItem(LS_KEY) || "grouped").toLowerCase();
      let view = saved === "events" ? "events" : "grouped";

      elTimeline.innerHTML = `
        <div class="tToolbar">
          <button type="button" class="tToggle ${view === "grouped" ? "isOn" : ""}" data-view="grouped">By Section</button>
          <button type="button" class="tToggle ${view === "events" ? "isOn" : ""}" data-view="events">Events</button>
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
      window.DTToast?.error("Please choose a file.") || console.warn("Please choose a file.");
      return;
    }

    const f0 = attachFile.files[0];
    if (!isAllowedAttachmentFile(f0)) {
      const msg = "Unsupported file type. Allowed: PDF, JPG, PNG. Please export Office files to PDF first.";
      if (attachMsg) attachMsg.textContent = msg;
      else window.DTToast?.error(msg) || console.warn(msg);
      attachFile.value = "";
      return;
    }

    if (attachMsg) attachMsg.textContent = "Uploading…";
    if (btnAttachUpload) btnAttachUpload.disabled = true;

    const form = appendActingPrincipal(new FormData(), currentPayload);
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
    form.append("csrf_token", window.__CSRF__ || window.__APP__?.csrf || "");

    try {
      const res = await fetch(`${API}/attachments_upload.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        if (attachMsg) attachMsg.textContent = data?.error || `Upload failed. (${res.status})`;
        else window.DTToast?.error(data?.error || `Upload failed. (${res.status})`) || console.warn(data?.error || `Upload failed. (${res.status})`);
        return;
      }

      if (attachMsg) attachMsg.textContent = "Uploaded ✅";
      if (attachFile) attachFile.value = "";
      if (attachNote) attachNote.value = "";

      const selectedBranchBefore = currentBranchMode ? Number(getSelectedBranch()?.id || 0) : 0;
      await loadAttachments(docId);
      await loadTimeline(docId, selectedBranchBefore, { preserveSelection: true });
    } catch {
      if (attachMsg) attachMsg.textContent = "Upload failed (network error).";
      else window.DTToast?.error("Upload failed (network error).") || console.warn("Upload failed (network error).");
    } finally {
      if (btnAttachUpload) btnAttachUpload.disabled = false;
    }
  }

  function openDrawer(payload) {
    currentPayload = payload || null;
    setCollapsed(elAttachments, true);
    setCollapsed(attachForm, true);
    closeForwardModal();
    closeAttachmentForwardModal();
    closeAttachmentTaskDoneModal();
    syncToggleLabels();
    setDrawerTab("overview");

    syncViewDocumentButton();

    if (rowEditDocumentDetails && btnEditDocumentDetails) {
      const canEditDetails = Number(payload.can_edit_details || 0) === 1;
      const docId = payload.id || "";
      rowEditDocumentDetails.style.display = (canEditDetails && docId) ? "" : "none";
      btnEditDocumentDetails.dataset.docId = canEditDetails ? String(docId || "") : "";
    }

    if (rowPpdSlip) {
      const docId = payload.id || "";
      rowPpdSlip.style.display = "none";
      const rowPpdSlipLabel = document.getElementById("rowPpdSlipLabel");
      if (rowPpdSlipLabel && APP.ownDivisionSlipLabel) rowPpdSlipLabel.textContent = APP.ownDivisionSlipLabel;

      if (btnPpdSlipGenerate) btnPpdSlipGenerate.dataset.docId = String(docId || "");
      if (btnPpdSlipAttach) btnPpdSlipAttach.dataset.docId = String(docId || "");
      if (btnPpdSlipPrint) {
        btnPpdSlipPrint.dataset.docId = String(docId || "");
        btnPpdSlipPrint.disabled = true;
      }
      currentPpdSlipAttId = 0;
    }

    if (elId) elId.value = payload.id || "";
    if (elTracking) elTracking.textContent = payload.tracking_display || payload.tracking_no || "";
    if (elRequester) elRequester.textContent = payload.requester || "—";
    if (elDate) elDate.textContent = payload.document_date || "—";
    const deadlineOutcome = ((payload.current_status || "ACTIVE").toString().toUpperCase() === "ACTIVE")
      ? ""
      : (payload.deadline_badge_text || "");
    renderDeadline(payload.deadline_at || "", payload.my_personal_deadline_at || "", deadlineOutcome);
    if (elSubject) elSubject.textContent = payload.subject || "—";
    if (elType) elType.textContent = payload.content_type || "—";
    projectManageOpen = false;
    renderProjectCodes(payload);
    syncProjectActions(payload);
    if (elActivityLabel) elActivityLabel.textContent = payload.activity_label || "Days stuck";
    if (elDays) elDays.textContent = payload.activity_value || (payload.days_stuck ?? "0");

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
    refreshDrawerBranchContext();

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
    syncViewDocumentButton();

    if (elAttachments) elAttachments.textContent = "Loading attachments…";
    if (payload.id) loadAttachments(payload.id);

    if (elTimeline) elTimeline.textContent = "Loading timeline…";
    if (payload.id) {
      loadTimeline(payload.id, 0, { preserveSelection: false });
      loadPendingRouteRemarks(payload.id, 0);
    }

    const openToSectionId = Number.parseInt(payload.open_to_section_id, 10) || 0;
    const openToUserId = Number.parseInt(payload.open_to_user_id, 10) || 0;
    const isPrivileged = myRole === "admin" || myRole === "records";
    const isStatusAdmin = myRole === "admin";
    const flatActionableByMe = Number(payload.my_has_actionable_role || 0) === 1;
    const flatLifecycleByMe = Number(payload.my_can_change_lifecycle || 0) === 1;

    const myOpenRouteId = Number.parseInt(payload.my_open_route_id || "0", 10) || 0;

    const canAckReceived = (!currentBranchMode && (
      myOpenRouteId > 0 ||
      (inTransit && (
      (openToUserId > 0 && myUserId > 0 && openToUserId === myUserId) ||
      (openToUserId === 0 && isChief && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId)
      ))
    ));

    const canAckReceivedPrivileged = (!currentBranchMode && inTransit && openToSectionId > 0 && mySectionId > 0 && openToSectionId === mySectionId);

    let canAttach = false;
    let canForward = false;

    if (currentBranchMode) {
      canAttach = docStatus === "ACTIVE" && isPrivileged;
      canForward = false;
    } else {
      canAttach = docStatus === "ACTIVE" && (isPrivileged || flatActionableByMe || Number(payload.attachment_forward_can_attach || 0) === 1);
      canForward = docStatus === "ACTIVE" && flatActionableByMe;
    }

    const flatAttachmentTaskLock = !!(
      !currentBranchMode
      && Number(payload.attachment_forward_open_task_count || 0) > 0
      && Number(payload.my_has_actionable_role || 0) === 1
    );
    currentCanForward = canForward && !flatAttachmentTaskLock;
    currentCanAttachmentForward = !!(
      !currentBranchMode
      && docStatus === "ACTIVE"
      && flatActionableByMe
      && !inTransit
      && Number(payload.my_has_open_inbound || 0) === 0
    );

    if (btnToggleUpload) btnToggleUpload.style.display = canAttach ? "" : "none";
    if (btnRegenerateDivisionSlip) {
      const canRegenerateSlip = !!APP.hasOwnDivisionSlip && Number(payload.can_regenerate_division_slip || 0) === 1;
      btnRegenerateDivisionSlip.style.display = canRegenerateSlip ? "" : "none";
      btnRegenerateDivisionSlip.dataset.docId = canRegenerateSlip ? String(payload.id || "") : "";
    }
    if (btnToggleAttachments) btnToggleAttachments.style.display = "";
    updateForwardUI();

    if (attachFile) attachFile.value = "";
    if (attachNote) attachNote.value = "";
    if (attachType) attachType.value = "1";
    if (selForwardTo) selForwardTo.value = "";
    if (inputForwardDocumentDeadline) inputForwardDocumentDeadline.value = "";
    if (inputForwardPersonalDeadline) inputForwardPersonalDeadline.value = "";
    resetUsersUI();
    updateForwardModeUI();

    if (btnAckReceived) btnAckReceived.style.display = "none";
    if (btnEndHere) btnEndHere.style.display = "none";
    if (btnUndoEndHere) btnUndoEndHere.style.display = "none";
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
    if (!currentBranchMode && btnAttachmentTaskDone) {
      const canTaskDoneFlat = !!(Number(payload.attachment_forward_can_mark_done || 0) === 1 && docStatus === "ACTIVE");
      btnAttachmentTaskDone.style.display = canTaskDoneFlat ? "" : "none";
    }

    if (docStatus === "ARCHIVED") {
      if (isStatusAdmin && btnArchive) {
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
      const releasedByEndHere = isLifecycleEndedKind(payload.last_end_here_kind || "");
      if (!releasedByEndHere && (isStatusAdmin || (!currentBranchMode && flatLifecycleByMe))) {
        if (btnRelease) btnRelease.style.display = "";
      }
      syncEndHereButtons();
      syncToggleLabels();
      return;
    }

    if (isPrivileged) {
      if (!currentBranchMode && canAckReceivedPrivileged && btnAckReceived) {
        btnAckReceived.style.display = "";
      }
      if ((isStatusAdmin || (!currentBranchMode && flatActionableByMe)) && btnRelease) btnRelease.style.display = "";
      if ((isStatusAdmin || (!currentBranchMode && flatActionableByMe)) && btnArchive) btnArchive.style.display = "";
      syncEndHereButtons();
      syncToggleLabels();
      return;
    }

    if (!currentBranchMode) {
      if (inTransit) {
        if (canAckReceived && btnAckReceived) btnAckReceived.style.display = "";
        syncToggleLabels();
        return;
      }

      if (flatActionableByMe) {
        if (btnEndHere) btnEndHere.style.display = "";
        if (btnRelease) btnRelease.style.display = "";
      }
    }

    syncEndHereButtons();
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
    currentPendingRemarksState = null;
    renderPendingRemarksState(null);
    if (deadlineTicker) {
      clearInterval(deadlineTicker);
      deadlineTicker = null;
    }
    if (elDeadline) elDeadline.textContent = "—";
    if (elPersonalDeadline) elPersonalDeadline.textContent = "—";
    if (elDeadlineCountdown) elDeadlineCountdown.textContent = "—";
    if (elBranchWrap) elBranchWrap.style.display = "none";
    if (elBranchSelect) elBranchSelect.innerHTML = "";
    if (elBranchMeta) elBranchMeta.textContent = "";
    closeEndHereModal();
    closeReleaseModal();
    closeForwardModal();
  }

  async function updateStatus(newStatus, options = {}) {
    const docId = elId?.value;
    if (!docId) return;

    const remarks = (options.remarks || "").toString().trim();
    const releasedTo = (options.releasedTo || "").toString().trim();

    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", docId);
    const routeId = Number.parseInt(currentPayload?.open_route_id || "0", 10) || 0;
    if (routeId > 0) form.append("route_id", String(routeId));
    form.append("new_status", newStatus);
    form.append("remarks", remarks);
    if (releasedTo) form.append("released_to", releasedTo);
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/update_status.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        if (releaseModal?.classList.contains("open") && releaseModalMsg) {
          releaseModalMsg.textContent = data?.error || `Failed to update status. (${res.status})`;
          releaseModalMsg.className = "modalMsg error";
          releaseModalMsg.style.display = "";
        }
        window.DTToast?.error(data?.error || `Failed to update status. (${res.status})`) || console.warn(data?.error || `Failed to update status. (${res.status})`);
        return;
      }
      location.reload();
    } catch {
      if (releaseModal?.classList.contains("open") && releaseModalMsg) {
        releaseModalMsg.textContent = "Failed to update status (network error).";
        releaseModalMsg.className = "modalMsg error";
        releaseModalMsg.style.display = "";
      }
      window.DTToast?.error("Failed to update status (network error).") || console.warn("Failed to update status (network error).");
    }
  }

  function openEndHereModal(mode = "end") {
    if (!endHereModal) return;
    currentEndHereMode = mode === "undo" ? "undo" : "end";
    if (elEndHereRemarks) elEndHereRemarks.value = "";
    if (endHereModalMsg) {
      endHereModalMsg.textContent = "";
      endHereModalMsg.className = "modalMsg";
      endHereModalMsg.style.display = "none";
    }

    if (endHereModalTitle) {
      endHereModalTitle.textContent = currentEndHereMode === "undo" ? "Reopen document lifecycle?" : "End document lifecycle now?";
    }
    if (endHereModalSub) {
      endHereModalSub.textContent = currentEndHereMode === "undo"
        ? "This will reopen the selected lane and put the document back with you for action."
        : "This stops routing for the selected lane. Use this only if no further action or forwarding is needed.";
    }
    if (btnEndHereConfirm) {
      btnEndHereConfirm.textContent = currentEndHereMode === "undo" ? "Reopen lifecycle" : "End now";
    }

    endHereModal.classList.add("open");
    endHereModal.setAttribute("aria-hidden", "false");
    elEndHereRemarks?.focus();
  }

  function closeEndHereModal() {
    if (!endHereModal) return;
    endHereModal.classList.remove("open");
    endHereModal.setAttribute("aria-hidden", "true");
  }

  async function submitEndHere() {
    const docId = elId?.value;
    if (!docId) return;

    const branch = currentBranchMode ? getSelectedBranch() : null;
    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", docId);
    form.append("mode", currentEndHereMode === "undo" ? "undo" : "end");
    if (currentBranchMode && Number(branch?.id || 0) > 0) {
      form.append("branch_id", String(Number(branch.id || 0)));
    }
    form.append("remarks", (elEndHereRemarks?.value || "").toString().trim());
    form.append("csrf_token", window.__CSRF__ || "");

    if (btnEndHereConfirm) btnEndHereConfirm.disabled = true;

    try {
      const res = await fetch(`${API}/end_here.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        const message = data?.error || `Failed to update End Now. (${res.status})`;
        if (endHereModalMsg) {
          endHereModalMsg.textContent = message;
          endHereModalMsg.className = "modalMsg error";
          endHereModalMsg.style.display = "";
        }
        window.DTToast?.error(message) || console.warn(message);
        return;
      }

      const selectedBranchBefore = currentBranchMode ? Number(branch?.id || 0) : 0;
      saveDrawerRestoreState(docId, selectedBranchBefore);
      location.reload();
    } catch {
      const message = "Failed to update End Now (network error).";
      if (endHereModalMsg) {
        endHereModalMsg.textContent = message;
        endHereModalMsg.className = "modalMsg error";
        endHereModalMsg.style.display = "";
      }
      window.DTToast?.error(message) || console.warn(message);
    } finally {
      if (btnEndHereConfirm) btnEndHereConfirm.disabled = false;
    }
  }

  async function ackReceived() {
    const docId = elId?.value;
    if (!docId) return;

    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", docId);
    const branch = currentBranchMode ? getSelectedBranch() : null;
    const selectedBranchBefore = currentBranchMode ? Number(branch?.id || 0) : 0;
    const routeId = currentBranchMode
      ? (Number.parseInt(branch?.my_pending_route_id || "0", 10) || 0)
      : (Number.parseInt(currentPayload?.my_open_route_id || currentPayload?.open_route_id || "0", 10) || 0);
    if (routeId > 0) form.append("route_id", String(routeId));
    form.append("remarks", "");
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/ack_received.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to acknowledge received. (${res.status})`) || console.warn(data?.error || `Failed to acknowledge received. (${res.status})`);
        return;
      }

      savePreferredBranchId(docId, selectedBranchBefore);
      saveDrawerRestoreState(docId, selectedBranchBefore);
      location.reload();
    } catch {
      window.DTToast?.error("Failed to acknowledge received (network error).") || console.warn("Failed to acknowledge received (network error).");
    }
  }

  async function forwardDoc() {
    const docId = elId?.value;
    const branchBeforeForward = currentBranchMode ? getSelectedBranch() : null;
    if (!docId) return;

    const toSectionId = Number.parseInt(selForwardTo?.value || "0", 10) || 0;
    if (toSectionId <= 0) {
      window.DTToast?.warning("Please select a destination section.") || console.warn("Please select a destination section.");
      return;
    }

    const selected = getSelectedRecipientIds();
    const form = appendActingPrincipal(new FormData(), currentPayload);
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

    const isInitialRouting = Number(currentPayload?.is_initial_routing || 0) === 1;
    if (isInitialRouting && inputForwardDocumentDeadline && inputForwardDocumentDeadline.value) {
      form.append("document_deadline_at", inputForwardDocumentDeadline.value);
    }

    form.append("remarks", (elForwardRemarks?.value || "").toString().trim());
    form.append("csrf_token", window.__CSRF__ || "");

    try {
      const res = await fetch(`${API}/forward.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to forward. (${res.status})`) || console.warn(data?.error || `Failed to forward. (${res.status})`);
        return;
      }
      if (currentBranchMode && Number(branchBeforeForward?.id || 0) > 0) {
        savePreferredBranchId(docId, Number(branchBeforeForward.id || 0));
        saveDrawerRestoreState(docId, Number(branchBeforeForward.id || 0));
      } else {
        saveDrawerRestoreState(docId, 0);
      }
      location.reload();
    } catch {
      window.DTToast?.error("Failed to forward (network error).") || console.warn("Failed to forward (network error).");
    }
  }

  async function savePendingRouteRemarks() {
    const docId = Number(elId?.value || 0);
    if (!docId || !currentPendingRemarksState?.editable) return;

    const remarks = normalizedPendingRemarksValue();
    const routeId = Number(currentPendingRemarksState?.route_id || 0);
    const branchId = Number(currentPendingRemarksBranchId() || currentPendingRemarksState?.branch_id || 0);

    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", String(docId));
    if (routeId > 0) form.append("route_id", String(routeId));
    if (branchId > 0) form.append("branch_id", String(branchId));
    form.append("remarks", remarks);
    form.append("csrf_token", window.__CSRF__ || "");

    if (btnSavePendingRemarks) btnSavePendingRemarks.disabled = true;

    try {
      const res = await fetch(`${API}/update_pending_route_remarks.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to save pending remarks. (${res.status})`) || console.warn(data?.error || `Failed to save pending remarks. (${res.status})`);
        return;
      }

      const helperText = data?.change_type === "pending_remarks_cleared"
        ? "Pending remarks cleared. The timeline keeps the change trail."
        : "Pending remarks saved. The timeline keeps the change trail until the route is received.";

      const nextState = {
        ...(currentPendingRemarksState || {}),
        editable: true,
        route_id: Number(data?.route_id || routeId || 0),
        branch_id: Number(data?.branch_id || branchId || 0),
        remarks: (data?.remarks || "").toString(),
        has_remark: !!data?.has_remark,
        button_label: !!data?.has_remark ? "Edit pending remarks" : "Add pending remarks",
        helper_text: helperText,
        just_saved: true,
      };

      renderPendingRemarksState(nextState);
      updateLatestRemarkCell(docId, nextState.remarks || "");

      pushPendingRemarkLocalEvent(docId, {
        action: (data?.change_type || "pending_remarks_updated").toString(),
        acted_at: nowManilaSqlTimestamp(),
        actor: (window.__CTX__?.actingPrincipalName || window.__CTX__?.myFullName || "You").toString(),
        actor_section: (window.__CTX__?.mySectionName || "").toString(),
        title: (data?.title || "Updated pending route remarks").toString(),
        remarks: data?.change_type === "pending_remarks_cleared"
          ? (data?.old_remarks ? `Previous: ${data.old_remarks}` : "Pending remarks cleared.")
          : (data?.old_remarks ? `Previous: ${data.old_remarks}
Now: ${data.remarks || ""}` : `Now: ${data?.remarks || ""}`),
        branch_id: Number(data?.branch_id || branchId || 0),
      });

      if (docId > 0) {
        loadTimeline(docId, branchId, { preserveSelection: true });
      }

      window.DTToast?.success(data?.change_type === "pending_remarks_cleared" ? "Pending remarks cleared." : "Pending remarks saved.") || console.log("Pending remarks saved.");
    } catch {
      window.DTToast?.error("Failed to save pending remarks (network error).") || console.warn("Failed to save pending remarks (network error).");
    } finally {
      if (btnSavePendingRemarks) btnSavePendingRemarks.disabled = false;
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

  const restoreState = consumeDrawerRestoreState();
  if (restoreState?.docId) {
    const rows = Array.from(document.querySelectorAll("[data-doc]"));
    const match = rows.find((row) => {
      const raw = row.getAttribute("data-doc") || "{}";
      try {
        const payload = JSON.parse(raw);
        return Number(payload?.id || 0) === Number(restoreState.docId || 0);
      } catch {
        return false;
      }
    });

    if (match) {
      const raw = match.getAttribute("data-doc") || "{}";
      let payload;
      try { payload = JSON.parse(raw); } catch { payload = {}; }
      if (Number(restoreState.branchId || 0) > 0) {
        savePreferredBranchId(Number(payload?.id || 0), Number(restoreState.branchId || 0));
      }
      setTimeout(() => openDrawer(payload), 0);
    }
  }

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

  function openForwardModal() {
    if (!currentCanForward || !forwardModal) return;
    updateForwardUI();
    const isInitialRouting = Number(currentPayload?.is_initial_routing || 0) === 1;
    if (inputForwardDocumentDeadline) {
      inputForwardDocumentDeadline.value = isInitialRouting
        ? clean(currentPayload?.deadline_at).slice(0, 10)
        : "";
    }
    forwardModal.classList.add("open");
    forwardModal.setAttribute("aria-hidden", "false");
    selForwardTo?.focus();
  }

  function openReleaseModal() {
    if (!releaseModal) return;
    if (inputReleaseTo) inputReleaseTo.value = "";
    if (elReleaseRemarks) elReleaseRemarks.value = "";
    if (releaseModalMsg) {
      releaseModalMsg.textContent = "";
      releaseModalMsg.className = "modalMsg";
      releaseModalMsg.style.display = "none";
    }
    releaseModal.classList.add("open");
    releaseModal.setAttribute("aria-hidden", "false");
    inputReleaseTo?.focus();
  }

  function closeReleaseModal() {
    if (!releaseModal) return;
    releaseModal.classList.remove("open");
    releaseModal.setAttribute("aria-hidden", "true");
  }

  async function confirmRelease() {
    const releasedTo = (inputReleaseTo?.value || "").toString().trim();
    const remarks = (elReleaseRemarks?.value || "").toString().trim();

    if (!releasedTo) {
      if (releaseModalMsg) {
        releaseModalMsg.textContent = "Please enter who or where the document was released to.";
        releaseModalMsg.className = "modalMsg error";
        releaseModalMsg.style.display = "";
      } else {
        window.DTToast?.error("Please enter who or where the document was released to.") || console.warn("Please enter who or where the document was released to.");
      }
      inputReleaseTo?.focus();
      return;
    }

    if (btnReleaseConfirm) btnReleaseConfirm.disabled = true;
    try {
      await updateStatus("RELEASED", { releasedTo, remarks });
    } finally {
      if (btnReleaseConfirm) btnReleaseConfirm.disabled = false;
    }
  }

  function closeForwardModal() {
    if (!forwardModal) return;
    forwardModal.classList.remove("open");
    forwardModal.setAttribute("aria-hidden", "true");
  }


  function attachmentForwardRowTemplate(row = {}) {
    const attachmentOptions = attachmentForwardAttachmentOptions.length > 0
      ? attachmentForwardAttachmentOptions.map((att) => {
          const selected = Number(row.attachment_id || 0) === Number(att.id || 0) ? " selected" : "";
          return `<option value="${Number(att.id || 0)}"${selected}>${esc(att.label || att.original_name || ("Attachment #" + Number(att.id || 0)))}</option>`;
        }).join("")
      : `<option value="">No attachments available</option>`;

    return `
      <div class="attachmentForwardRow" data-row-id="${esc(row.row_id || "")}" style="border:1px solid rgba(0,0,0,.10); border-radius:12px; padding:12px; display:grid; gap:10px;">
        <div style="display:grid; gap:6px;">
          <label class="mini" style="font-weight:900;">Attachment</label>
          <select class="select attachmentForwardAttachment">
            <option value="">-- Select attachment --</option>
            ${attachmentOptions}
          </select>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr auto; gap:10px; align-items:end;">
          <div style="display:grid; gap:6px;">
            <label class="mini" style="font-weight:900;">Section</label>
            <select class="select attachmentForwardSection">
              <option value="">-- Select section --</option>
            </select>
          </div>

          <div style="display:grid; gap:6px;">
            <label class="mini" style="font-weight:900;">Recipient</label>
            <select class="select attachmentForwardUser">
              <option value="">-- Select recipient --</option>
            </select>
          </div>

          <button type="button" class="btnSecondary attachmentForwardRemove">Remove</button>
        </div>
      </div>
    `;
  }

  async function fetchAttachmentForwardRecipients(sectionId) {
    const sid = Number(sectionId || 0);
    if (sid <= 0) return [];
    if (attachmentForwardRecipientCache.has(sid)) return attachmentForwardRecipientCache.get(sid);

    const qs = appendActingPrincipal(new URLSearchParams({ section_id: String(sid) }), currentPayload);
    const res = await fetch(`${API}/users_by_section.php?${qs.toString()}`, {
      cache: "no-store",
      headers: { Accept: "application/json" }
    });
    const data = await res.json().catch(() => null);
    const users = Array.isArray(data) ? data : (Array.isArray(data?.users) ? data.users : []);
    attachmentForwardRecipientCache.set(sid, users);
    return users;
  }

  async function syncAttachmentForwardRowElement(rowEl, row) {
    const sectionSelect = rowEl.querySelector(".attachmentForwardSection");
    const userSelect = rowEl.querySelector(".attachmentForwardUser");
    const attachmentSelect = rowEl.querySelector(".attachmentForwardAttachment");

    if (attachmentSelect) attachmentSelect.value = row.attachment_id ? String(row.attachment_id) : "";

    if (sectionSelect && sectionSelect.options.length <= 1) {
      const sections = Array.from(selForwardTo?.options || []).filter((opt) => String(opt.value || "").trim() !== "");
      sectionSelect.innerHTML = `<option value="">-- Select section --</option>` + sections.map((opt) => {
        const value = String(opt.value || "");
        const selected = Number(row.to_section_id || 0) === Number(value || 0) ? " selected" : "";
        return `<option value="${esc(value)}"${selected}>${esc(opt.textContent || "")}</option>`;
      }).join("");
    } else if (sectionSelect) {
      sectionSelect.value = row.to_section_id ? String(row.to_section_id) : "";
    }

    if (userSelect) {
      userSelect.innerHTML = `<option value="">Loading recipients…</option>`;
      const users = row.to_section_id ? await fetchAttachmentForwardRecipients(row.to_section_id) : [];
      userSelect.innerHTML = `<option value="">-- Select recipient --</option>` + users.map((user) => {
        const selected = Number(row.to_user_id || 0) === Number(user.id || 0) ? " selected" : "";
        return `<option value="${Number(user.id || 0)}"${selected}>${esc(user.full_name || user.name || ("User #" + Number(user.id || 0)))}</option>`;
      }).join("");
      if (row.to_user_id) userSelect.value = String(row.to_user_id);
    }
  }

  async function renderAttachmentForwardRows() {
    if (!attachmentForwardRows) return;

    if (attachmentForwardAttachmentOptions.length === 0) {
      attachmentForwardRows.innerHTML = `<div class="mini" style="opacity:.75;">No visible attachments are available in this lane yet. Add or select attachments first, then try again.</div>`;
      return;
    }

    if (currentAttachmentForwardRows.length === 0) {
      currentAttachmentForwardRows = [{
        row_id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now()),
        attachment_id: 0,
        to_section_id: 0,
        to_user_id: 0,
      }];
    }

    attachmentForwardRows.innerHTML = currentAttachmentForwardRows.map((row) => attachmentForwardRowTemplate(row)).join("");

    const rowEls = Array.from(attachmentForwardRows.querySelectorAll(".attachmentForwardRow"));
    for (const rowEl of rowEls) {
      const rowId = (rowEl.getAttribute("data-row-id") || "").toString();
      const row = currentAttachmentForwardRows.find((item) => item.row_id === rowId);
      if (!row) continue;

      await syncAttachmentForwardRowElement(rowEl, row);

      rowEl.querySelector(".attachmentForwardAttachment")?.addEventListener("change", (e) => {
        row.attachment_id = Number(e.target.value || 0);
      });

      rowEl.querySelector(".attachmentForwardSection")?.addEventListener("change", async (e) => {
        row.to_section_id = Number(e.target.value || 0);
        row.to_user_id = 0;
        await syncAttachmentForwardRowElement(rowEl, row);
      });

      rowEl.querySelector(".attachmentForwardUser")?.addEventListener("change", (e) => {
        row.to_user_id = Number(e.target.value || 0);
      });

      rowEl.querySelector(".attachmentForwardRemove")?.addEventListener("click", () => {
        currentAttachmentForwardRows = currentAttachmentForwardRows.filter((item) => item.row_id !== rowId);
        renderAttachmentForwardRows();
      });
    }
  }

  function openAttachmentForwardModal() {
    if (!currentCanAttachmentForward || !attachmentForwardModal) return;
    currentAttachmentForwardRows = [];
    attachmentForwardAttachmentOptions = [];
    attachmentForwardRecipientCache = new Map();
    if (elAttachmentForwardRemarks) elAttachmentForwardRemarks.value = "";

    fetch(`${API}/attachments_list.php?${appendActingPrincipal(new URLSearchParams({
      document_id: String(Number(currentPayload?.id || 0)),
      branch_id: String(currentBranchMode ? Number(getSelectedBranch()?.id || 0) : 0),
    }), currentPayload).toString()}`, {
      cache: "no-store",
      headers: { Accept: "application/json" }
    }).then((res) => res.json().catch(() => null))
      .then(async (data) => {
        attachmentForwardAttachmentOptions = (Array.isArray(data?.attachments) ? data.attachments : []).map((att) => ({
          id: Number(att.id || 0),
          original_name: att.original_name || "",
          label: `${att.original_name || ("Attachment #" + Number(att.id || 0))}${Number(att.branch_id || 0) > 0 ? " • lane" : ""}`,
        }));
        await renderAttachmentForwardRows();
      })
      .catch(() => {
        attachmentForwardRows.innerHTML = `<div class="mini" style="color:#b91c1c;">Failed to load attachments.</div>`;
      });

    attachmentForwardModal.classList.add("open");
    attachmentForwardModal.setAttribute("aria-hidden", "false");
  }

  function closeAttachmentForwardModal() {
    if (!attachmentForwardModal) return;
    attachmentForwardModal.classList.remove("open");
    attachmentForwardModal.setAttribute("aria-hidden", "true");
  }

  function openAttachmentTaskDoneModal() {
    if (!attachmentTaskDoneModal) return;
    if (elAttachmentTaskDoneRemarks) elAttachmentTaskDoneRemarks.value = "";
    if (attachmentTaskDoneModalMsg) {
      attachmentTaskDoneModalMsg.textContent = "";
      attachmentTaskDoneModalMsg.className = "modalMsg";
      attachmentTaskDoneModalMsg.style.display = "none";
    }
    attachmentTaskDoneModal.classList.add("open");
    attachmentTaskDoneModal.setAttribute("aria-hidden", "false");
    elAttachmentTaskDoneRemarks?.focus();
  }

  function closeAttachmentTaskDoneModal() {
    if (!attachmentTaskDoneModal) return;
    attachmentTaskDoneModal.classList.remove("open");
    attachmentTaskDoneModal.setAttribute("aria-hidden", "true");
  }

  async function submitAttachmentForward() {
    const docId = Number(currentPayload?.id || elId?.value || 0);
    const branch = currentBranchMode ? getSelectedBranch() : null;
    if (!docId) return;
    if (currentBranchMode && !branch) return;

    const rows = currentAttachmentForwardRows
      .map((row) => ({
        attachment_id: Number(row.attachment_id || 0),
        to_section_id: Number(row.to_section_id || 0),
        to_user_id: Number(row.to_user_id || 0),
      }))
      .filter((row) => row.attachment_id > 0 && row.to_section_id > 0 && row.to_user_id > 0);

    if (rows.length === 0) {
      window.DTToast?.warning("Please complete at least one attachment routing row.") || console.warn("Please complete at least one attachment routing row.");
      return;
    }

    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", String(docId));
    form.append("branch_id", String(currentBranchMode ? Number(branch?.id || 0) : 0));
    rows.forEach((row, idx) => {
      form.append(`routing_rows[${idx}][attachment_id]`, String(row.attachment_id));
      form.append(`routing_rows[${idx}][to_section_id]`, String(row.to_section_id));
      form.append(`routing_rows[${idx}][to_user_id]`, String(row.to_user_id));
    });
    form.append("remarks", (elAttachmentForwardRemarks?.value || "").toString().trim());
    form.append("csrf_token", window.__CSRF__ || "");

    if (btnAttachmentForwardSend) btnAttachmentForwardSend.disabled = true;
    try {
      const res = await fetch(`${API}/attachment_forward.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Failed to forward attachments. (${res.status})`) || console.warn(data?.error || `Failed to forward attachments. (${res.status})`);
        return;
      }
      const branchId = currentBranchMode ? Number(branch?.id || 0) : 0;
      savePreferredBranchId(docId, branchId);
      saveDrawerRestoreState(docId, branchId);
      location.reload();
    } catch {
      window.DTToast?.error("Failed to forward attachments (network error).") || console.warn("Failed to forward attachments (network error).");
    } finally {
      if (btnAttachmentForwardSend) btnAttachmentForwardSend.disabled = false;
    }
  }

  async function submitAttachmentTaskDone() {
    const docId = Number(currentPayload?.id || elId?.value || 0);
    const branch = currentBranchMode ? getSelectedBranch() : null;
    if (!docId) return;
    if (currentBranchMode && !branch) return;

    const form = appendActingPrincipal(new FormData(), currentPayload);
    form.append("document_id", String(docId));
    form.append("branch_id", String(currentBranchMode ? Number(branch?.id || 0) : 0));
    form.append("remarks", (elAttachmentTaskDoneRemarks?.value || "").toString().trim());
    form.append("csrf_token", window.__CSRF__ || "");

    if (btnAttachmentTaskDoneConfirm) btnAttachmentTaskDoneConfirm.disabled = true;
    try {
      const res = await fetch(`${API}/attachment_task_done.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        if (attachmentTaskDoneModalMsg) {
          attachmentTaskDoneModalMsg.textContent = data?.error || `Failed to mark task done. (${res.status})`;
          attachmentTaskDoneModalMsg.className = "modalMsg error";
          attachmentTaskDoneModalMsg.style.display = "";
        } else {
          window.DTToast?.error(data?.error || `Failed to mark task done. (${res.status})`) || console.warn(data?.error || `Failed to mark task done. (${res.status})`);
        }
        return;
      }
      saveDrawerRestoreState(docId, currentBranchMode ? Number(branch?.id || 0) : 0);
      location.reload();
    } catch {
      window.DTToast?.error("Failed to mark task done (network error).") || console.warn("Failed to mark task done (network error).");
    } finally {
      if (btnAttachmentTaskDoneConfirm) btnAttachmentTaskDoneConfirm.disabled = false;
    }
  }

  btnToggleForward?.addEventListener("click", openForwardModal);
  drawerTabs.forEach((tab) => {
    tab.addEventListener("click", () => setDrawerTab(tab.dataset.drawerTab || "overview"));
  });
  btnToggleAttachmentForward?.addEventListener("click", openAttachmentForwardModal);
  forwardModalClose?.addEventListener("click", closeForwardModal);
  btnForwardCancel?.addEventListener("click", closeForwardModal);
  forwardModalBackdrop?.addEventListener("click", closeForwardModal);
  attachmentForwardModalClose?.addEventListener("click", closeAttachmentForwardModal);
  btnAttachmentForwardCancel?.addEventListener("click", closeAttachmentForwardModal);
  attachmentForwardModalBackdrop?.addEventListener("click", closeAttachmentForwardModal);
  btnAttachmentForwardAddRow?.addEventListener("click", async () => {
    currentAttachmentForwardRows.push({
      row_id: crypto.randomUUID ? crypto.randomUUID() : String(Date.now() + Math.random()),
      attachment_id: 0,
      to_section_id: 0,
      to_user_id: 0,
    });
    await renderAttachmentForwardRows();
  });
  btnAttachmentForwardSend?.addEventListener("click", submitAttachmentForward);
  releaseModalClose?.addEventListener("click", closeReleaseModal);
  btnReleaseCancel?.addEventListener("click", closeReleaseModal);
  releaseModalBackdrop?.addEventListener("click", closeReleaseModal);
  btnReleaseConfirm?.addEventListener("click", confirmRelease);
  btnEndHere?.addEventListener("click", () => openEndHereModal("end"));
  btnUndoEndHere?.addEventListener("click", () => openEndHereModal("undo"));
  endHereModalClose?.addEventListener("click", closeEndHereModal);
  btnEndHereCancel?.addEventListener("click", closeEndHereModal);
  endHereModalBackdrop?.addEventListener("click", closeEndHereModal);
  btnEndHereConfirm?.addEventListener("click", submitEndHere);

  btnAckReceived?.addEventListener("click", ackReceived);
  btnAttachmentTaskDone?.addEventListener("click", openAttachmentTaskDoneModal);
  attachmentTaskDoneModalClose?.addEventListener("click", closeAttachmentTaskDoneModal);
  btnAttachmentTaskDoneCancel?.addEventListener("click", closeAttachmentTaskDoneModal);
  attachmentTaskDoneModalBackdrop?.addEventListener("click", closeAttachmentTaskDoneModal);
  btnAttachmentTaskDoneConfirm?.addEventListener("click", submitAttachmentTaskDone);
  btnEditPendingRemarks?.addEventListener("click", () => setPendingRemarksEditing(true));
  btnCancelPendingRemarks?.addEventListener("click", () => {
    if (elPendingRemarksInput) {
      elPendingRemarksInput.value = (currentPendingRemarksState?.remarks || "").toString();
    }
    setPendingRemarksEditing(false);
  });
  btnSavePendingRemarks?.addEventListener("click", savePendingRouteRemarks);
  btnUnderAction?.addEventListener("click", () => updateStatus("ACTIVE"));
  btnRelease?.addEventListener("click", () => {
    const nextStatus = (btnRelease.dataset.nextStatus || "RELEASED").toUpperCase();
    if (nextStatus === "RELEASED") {
      openReleaseModal();
      return;
    }
    updateStatus(nextStatus);
  });
  btnArchive?.addEventListener("click", () => updateStatus((btnArchive.dataset.nextStatus || "ARCHIVED").toUpperCase()));
  btnForward?.addEventListener("click", forwardDoc);
  btnAttachUpload?.addEventListener("click", uploadAttachment);

  async function regenerateDivisionSlip(triggerButton) {
    const docId = triggerButton?.dataset?.docId || elId?.value || "";
    if (!docId) return;

    if (triggerButton) triggerButton.disabled = true;
    try {
      const form = appendActingPrincipal(new FormData(), currentPayload);
      form.append("document_id", docId);
      form.append("csrf_token", window.__CSRF__ || "");

      const res = await fetch(`${API}/division_tracking_slip_generate.php`, {
        method: "POST",
        body: form,
        headers: { Accept: "application/json" }
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        window.DTToast?.error(data?.error || `Generate latest slip failed. (${res.status})`) || console.warn(data?.error || `Generate latest slip failed. (${res.status})`);
        return;
      }

      const attId = Number(data.attachment_id || 0);
      await loadAttachments(docId);
      window.DTToast?.success("Latest division tracking slip generated.") || console.log("Latest division tracking slip generated.");
      if (attId > 0) {
        const principalQs = actingPrincipalId() > 0 ? `&acting_principal_user_id=${actingPrincipalId()}` : "";
        window.open(`${PUBLIC}/view_attachment.php?id=${attId}${principalQs}`, "_blank", "noopener");
      }
    } catch {
      window.DTToast?.error("Generate latest slip failed.") || console.warn("Generate latest slip failed.");
    } finally {
      if (triggerButton) triggerButton.disabled = false;
    }
  }

  btnPpdSlipGenerate?.addEventListener("click", async () => {
    await regenerateDivisionSlip(btnPpdSlipGenerate);
  });

  btnRegenerateDivisionSlip?.addEventListener("click", async () => {
    await regenerateDivisionSlip(btnRegenerateDivisionSlip);
  });

  btnPpdSlipPrint?.addEventListener("click", () => {
    if (!currentPpdSlipAttId) return;
    const principalQs = actingPrincipalId() > 0 ? `&acting_principal_user_id=${actingPrincipalId()}` : "";
    window.open(`${PUBLIC}/division_tracking_slip_print.php?id=${currentPpdSlipAttId}${principalQs}`, "_blank", "noopener");
  });

  btnPpdSlipAttach?.addEventListener("click", () => {
    setCollapsed(attachForm, false);
    if (btnToggleUpload) btnToggleUpload.textContent = "Hide upload";
    if (attachType) attachType.value = "1";
    if (attachNote) attachNote.value = `${APP.ownDivisionSlipLabel || 'Division Tracking Slip'} (scanned/signed)`;
    attachFile?.focus();
  });

  btnViewDocument?.addEventListener("click", () => {
    const docId = btnViewDocument.dataset.docId || elId?.value || "";
    if (!docId) return;

    const selectedBranchId = currentBranchMode ? Number(getSelectedBranch()?.id || 0) : 0;

    if (window.DTMergeView && typeof window.DTMergeView.open === "function") {
      window.DTMergeView.open(docId, selectedBranchId);
      return;
    }

    document.dispatchEvent(new CustomEvent("dt:view_document", { detail: { documentId: docId, branchId: selectedBranchId, actingPrincipalUserId: actingPrincipalId() } }));
  });

  btnEditDocumentDetails?.addEventListener("click", () => {
    const docId = btnEditDocumentDetails.dataset.docId || elId?.value || "";
    if (!docId) return;

    const qs = new URLSearchParams({ edit_id: String(docId) });
    const actingId = actingPrincipalId();
    if (actingId > 0) qs.set("acting_principal_user_id", String(actingId));
    window.location.href = `${APP.public || ""}/add_document.php?${qs.toString()}`;
  });

  btnProjectAttach?.addEventListener("click", attachProjectCode);
  btnProjectManageToggle?.addEventListener("click", () => setProjectManageOpen(true));
  btnProjectManageClose?.addEventListener("click", () => setProjectManageOpen(false));
  elProjects?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-project-remove]");
    if (!btn) return;
    if (!canManageProjects(currentPayload)) return;
    const projectId = Number(btn.getAttribute("data-project-remove") || 0);
    if (projectId > 0) detachProjectCode(projectId);
  });

  syncToggleLabels();
  updateForwardUI();
})();


document.addEventListener("click", function (e) {
  const btn = e.target.closest(".toggleMembers");
  if (!btn) return;

  const container = btn.closest(".sectionCard");
  const list = container.querySelector(".membersList");

  if (!list) return;

  list.classList.toggle("collapsed");
});

(function () {
  const tabs = Array.from(document.querySelectorAll('[data-scroll-tab]'));
  if (!tabs.length) return;

  const sections = tabs
    .map((tab) => {
      const id = tab.getAttribute('href') || '';
      if (!id.startsWith('#')) return null;
      const el = document.querySelector(id);
      return el ? { tab, el } : null;
    })
    .filter(Boolean);

  if (!sections.length) return;

  const setActive = (activeId) => {
    tabs.forEach((tab) => {
      const isActive = tab.getAttribute('href') === `#${activeId}`;
      tab.classList.toggle('isActive', isActive);
      if (isActive) tab.setAttribute('aria-current', 'true');
      else tab.removeAttribute('aria-current');
    });
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const id = (tab.getAttribute('href') || '').replace('#', '');
      if (id) setActive(id);
    });
  });

  const observer = new IntersectionObserver((entries) => {
    const visible = entries
      .filter((entry) => entry.isIntersecting)
      .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];

    if (visible?.target?.id) setActive(visible.target.id);
  }, { rootMargin: '-25% 0px -55% 0px', threshold: [0.15, 0.35, 0.6] });

  sections.forEach(({ el }) => observer.observe(el));
})();
