(function () {
  const cfg = window.addDocumentConfig || null;
  if (!cfg) return;

  const apiPath = String(cfg.apiPath || "");
  const editMode = !!cfg.editMode;
  const hasOwnDivisionSlip = !!cfg.hasOwnDivisionSlip;
  const sectionLabels = cfg.sectionLabels && typeof cfg.sectionLabels === "object" ? cfg.sectionLabels : {};
  const sectionMeta = cfg.sectionMeta && typeof cfg.sectionMeta === "object" ? cfg.sectionMeta : {};
  const divisionChiefTargets = Array.isArray(cfg.divisionChiefTargets) ? cfg.divisionChiefTargets : [];
  const divisionDirectory = Array.isArray(cfg.divisionDirectory) ? cfg.divisionDirectory : [];
  const seedRecipientMap = cfg.seedRecipientMap && typeof cfg.seedRecipientMap === "object" ? cfg.seedRecipientMap : {};
  const seedDestinationMode = cfg.seedDestinationMode && typeof cfg.seedDestinationMode === "object" ? cfg.seedDestinationMode : {};
  const seedPersonalDeadlineMap = cfg.seedPersonalDeadlineMap && typeof cfg.seedPersonalDeadlineMap === "object" ? cfg.seedPersonalDeadlineMap : {};
  const canSetPersonalDeadline = !!cfg.canSetPersonalDeadline;
  const divisionTrackingLookupUrl = String(cfg.divisionTrackingLookupUrl || "");
  const excludeDocumentId = Number(cfg.excludeDocumentId || 0);

  const contentTypeSelect = document.getElementById("contentTypeSelect");
  const contentTypeOtherWrap = document.getElementById("contentTypeOtherWrap");
  const contentTypeOtherInput = document.getElementById("contentTypeOtherInput");

  const form = document.querySelector("form.docFormGrid");
  const removeSavedAttachmentUrl = String(form?.getAttribute("data-remove-saved-attachment-url") || "");
  const removeSavedAttachmentInput = document.getElementById("removeSavedAttachmentInput");
  const destinationBuilderContractInput = document.getElementById("destinationBuilderContractInput");
  const creationModeInput = document.getElementById("creationModeInput");
  const destinationBuilderSection = document.getElementById("destinationBuilderSection");
  const btnSubmitRouteNow = document.getElementById("btnSubmitRouteNow");
  const btnSubmitReview = document.getElementById("btnSubmitReview");
  const btnRemoveSavedAttachment = document.getElementById("btnRemoveSavedAttachment");
  const savedAttachmentCard = document.getElementById("savedAttachmentCard");
  const createDivisionTrackingNoInput = document.getElementById("createDivisionTrackingNo");
  const createDivisionTrackingDuplicateHint = document.getElementById("createDivisionTrackingDuplicateHint");
  const editDivisionTrackingNoInput = document.getElementById("editDivisionTrackingNo");
  const editDivisionTrackingDuplicateHint = document.getElementById("editDivisionTrackingDuplicateHint");

  const destinationBuilder = document.querySelector(".destBuilderV2");
  const btnAddAllDivisionChiefs = document.getElementById("btnAddAllDivisionChiefs");
  const btnClearDestinations = document.getElementById("btnClearDestinations");
  const destinationAccordion = document.getElementById("destinationAccordion");
  const destinationSummaryBox = document.getElementById("destinationSummaryBox");
  const destinationsBox = document.getElementById("destinationsBox");
  const destinationNotice = document.getElementById("destinationNotice");

  const destinations = new Map(); // sectionId => {mode, users: Map, personalDeadline}
  const openDivisionIds = new Set();
  const destinationNoticeState = { timer: null };

  let divisionChiefQuickMode = false;

  if (destinationBuilderContractInput) destinationBuilderContractInput.value = editMode ? "0" : "1";

  function selectedCreationMode() {
    const checked = form?.querySelector('input[name="creation_mode_choice"]:checked');
    return String(creationModeInput?.value || checked?.value || "route_now");
  }

  function syncCreationMode(mode) {
    const cleanMode = mode === "review" ? "review" : "route_now";
    if (creationModeInput) creationModeInput.value = cleanMode;
    const radio = form?.querySelector(`input[name="creation_mode_choice"][value="${cleanMode}"]`);
    if (radio) radio.checked = true;
    const isReview = cleanMode === "review";
    if (destinationBuilderSection) destinationBuilderSection.hidden = isReview;
    if (btnSubmitRouteNow) btnSubmitRouteNow.hidden = isReview;
    if (btnSubmitReview) btnSubmitReview.hidden = !isReview;
  }

  form?.querySelectorAll('input[name="creation_mode_choice"]').forEach((radio) => {
    radio.addEventListener("change", () => syncCreationMode(radio.value));
  });

  form?.querySelectorAll("[data-creation-mode-submit]").forEach((button) => {
    button.addEventListener("click", () => {
      syncCreationMode(button.getAttribute("data-creation-mode-submit") || "route_now");
    });
  });
  syncCreationMode(selectedCreationMode());
  createDivisionTrackingDuplicateWatcher(createDivisionTrackingNoInput, createDivisionTrackingDuplicateHint);
  createDivisionTrackingDuplicateWatcher(editDivisionTrackingNoInput, editDivisionTrackingDuplicateHint);

  function esc(value) {
    return String(value ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[c]));
  }

  function setDivisionTrackingDuplicateHint(el, message) {
    if (!el) return;
    el.textContent = String(message || "");
    el.style.display = message ? "block" : "none";
  }

  function createDivisionTrackingDuplicateWatcher(input, hintEl) {
    if (!input || !hintEl || !divisionTrackingLookupUrl) return;
    let timer = null;
    let seq = 0;

    async function checkNow() {
      const trackingNo = String(input.value || "").trim().toUpperCase();
      if (!trackingNo) {
        setDivisionTrackingDuplicateHint(hintEl, "");
        return;
      }

      const currentSeq = ++seq;
      try {
        const qs = new URLSearchParams({
          tracking_no: trackingNo,
          exclude_document_id: String(excludeDocumentId)
        });
        const res = await fetch(`${divisionTrackingLookupUrl}?${qs.toString()}`, {
          headers: { Accept: "application/json" },
          cache: "no-store"
        });
        const data = await res.json().catch(() => null);
        if (currentSeq !== seq) return;
        if (!res.ok || !data?.ok || !data?.exists) {
          setDivisionTrackingDuplicateHint(hintEl, "");
          return;
        }

        const docTracking = String(data.document_tracking_no || "").trim() || `Document #${Number(data.document_id || 0)}`;
        const subjectShort = String(data.subject_short || "").trim();
        setDivisionTrackingDuplicateHint(
          hintEl,
          `This division tracking number already exists. See: ${docTracking}${subjectShort ? ` (SUBJECT: ${subjectShort})` : ""}`
        );
      } catch {
        if (currentSeq !== seq) return;
        setDivisionTrackingDuplicateHint(hintEl, "");
      }
    }

    input.addEventListener("input", () => {
      if (timer) clearTimeout(timer);
      timer = window.setTimeout(checkNow, 260);
    });
    input.addEventListener("blur", checkNow);
    checkNow();
  }

  function show(el, on) {
    if (!el) return;
    el.style.display = on ? "flex" : "none";
  }


  function syncDivisionChiefQuickMode() {
    if (!destinationBuilder) return;
    destinationBuilder.classList.toggle("is-division-chief-quick-mode", divisionChiefQuickMode);
  }

  function setDivisionChiefQuickMode(on) {
    divisionChiefQuickMode = !!on;
    syncDivisionChiefQuickMode();
  }

  function syncContentTypeOther() {
    if (!contentTypeSelect || !contentTypeOtherWrap || !contentTypeOtherInput) return;
    const isOther = String(contentTypeSelect.value || "") === "Others";
    contentTypeOtherWrap.style.display = isOther ? "block" : "none";
    contentTypeOtherInput.required = isOther;
  }

  if (contentTypeSelect) {
    contentTypeSelect.addEventListener("change", syncContentTypeOther);
    syncContentTypeOther();
  }

  {
    const transOpts = document.getElementById("transmittalOpts");
    const slipOpts = document.getElementById("divisionSlipOpts");
    const slipReceivedWrap = document.getElementById("divisionSlipReceivedWrap");
    const radios = document.querySelectorAll('input[name="gen_choice"]');

    function syncGen() {
      let choice = "none";
      radios.forEach((r) => { if (r.checked) choice = r.value; });
      show(transOpts, choice === "transmittal");
      show(slipOpts, hasOwnDivisionSlip && choice === "division_slip");
      if (slipReceivedWrap) {
        slipReceivedWrap.style.display = hasOwnDivisionSlip && choice === "division_slip" ? "grid" : "none";
      }

      if (choice === "transmittal" && transOpts) {
        const any = transOpts.querySelector('input[type="radio"]:checked');
        if (!any) {
          const def = transOpts.querySelector('input[type="radio"][value="attach"]');
          if (def) def.checked = true;
        }
      }

      if (choice === "division_slip" && slipOpts) {
        const any = slipOpts.querySelector('input[type="radio"]:checked');
        if (!any) {
          const def = slipOpts.querySelector('input[type="radio"][value="attach"]');
          if (def) def.checked = true;
        }
      }
    }

    if (radios.length) {
      radios.forEach((r) => r.addEventListener("change", syncGen));
      syncGen();
    }
  }

  function clearBuilderNotice() {
    if (!destinationNotice) return;
    if (destinationNoticeState.timer) {
      clearTimeout(destinationNoticeState.timer);
      destinationNoticeState.timer = null;
    }
    destinationNotice.textContent = "";
    destinationNotice.className = "destStatus";
    destinationNotice.style.display = "none";
  }

  function setBuilderNotice(message, type = "info", opts = {}) {
    if (!destinationNotice) return;
    if (destinationNoticeState.timer) {
      clearTimeout(destinationNoticeState.timer);
      destinationNoticeState.timer = null;
    }
    destinationNotice.textContent = String(message || "");
    destinationNotice.className = `destStatus is-${type}`;
    destinationNotice.style.display = message ? "flex" : "none";
    if (message && !opts.persist) {
      destinationNoticeState.timer = window.setTimeout(clearBuilderNotice, 2800);
    }
  }

  function getSectionMeta(sectionId) {
    return sectionMeta[String(sectionId)] || {
      division_name: "",
      section_name: sectionLabels[String(sectionId)] || `Section #${sectionId}`,
      label: sectionLabels[String(sectionId)] || `Section #${sectionId}`
    };
  }

  function getSectionLabel(sectionId) {
    const meta = getSectionMeta(sectionId);
    return String(meta.label || sectionLabels[String(sectionId)] || `Section #${sectionId}`);
  }

  function getSectionRecord(sectionId) {
    const sid = String(sectionId);
    for (const division of divisionDirectory) {
      const sections = Array.isArray(division?.sections) ? division.sections : [];
      for (const section of sections) {
        if (String(section?.id || "") === sid) return section;
      }
    }
    return null;
  }

  function getUsersForSection(sectionId) {
    const section = getSectionRecord(sectionId);
    const users = Array.isArray(section?.users) ? section.users : [];
    return users.map((user) => ({
      id: Number(user.id || 0),
      name: String(user.name || `#${user.id || ""}`),
      is_chief: !!user.is_chief
    })).filter((user) => user.id > 0);
  }

  function getChiefUsers(sectionId) {
    return getUsersForSection(sectionId).filter((user) => !!user.is_chief);
  }

  function createEmptyDestination() {
    return { mode: "users", users: new Map(), personalDeadline: "" };
  }

  function getOrCreateDestination(sectionId) {
    const sid = String(sectionId);
    if (!destinations.has(sid)) {
      destinations.set(sid, createEmptyDestination());
    }
    return destinations.get(sid);
  }

  function normalizeDestination(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return;
    if (dest.users.size === 0) {
      destinations.delete(sid);
      return;
    }
    const recipients = Array.from(dest.users.values());
    dest.mode = recipients.every((user) => !!user.isChief) ? "chief" : "users";
  }

  function setSectionRecipients(sectionId, users, personalDeadline = null) {
    const sid = String(sectionId);
    const dest = getOrCreateDestination(sid);
    dest.users.clear();
    (Array.isArray(users) ? users : []).forEach((user) => {
      const id = Number(user.id || 0);
      if (id <= 0) return;
      const name = String(user.name || `#${id}`);
      dest.users.set(String(id), {
        id,
        name,
        rawName: name,
        isChief: !!(user.isChief ?? user.is_chief)
      });
    });
    if (typeof personalDeadline === "string") {
      dest.personalDeadline = personalDeadline;
    }
    normalizeDestination(sid);
  }

  function toggleUser(sectionId, user, checked) {
    const sid = String(sectionId);
    const dest = getOrCreateDestination(sid);
    const uid = String(user.id);
    if (checked) {
      dest.users.set(uid, {
        id: Number(user.id),
        name: String(user.name || `#${user.id}`),
        rawName: String(user.name || `#${user.id}`),
        isChief: !!user.is_chief
      });
    } else {
      dest.users.delete(uid);
    }
    normalizeDestination(sid);
  }

  function clearSection(sectionId) {
    destinations.delete(String(sectionId));
  }

  function getSelectedSectionIds() {
    return Array.from(destinations.keys());
  }

  function getSelectedRecipientsCount() {
    let count = 0;
    destinations.forEach((dest) => { count += dest.users.size; });
    return count;
  }

  function syncHiddenInputs() {
    if (!form) return;
    form.querySelectorAll('[data-destination-hidden="1"]').forEach((el) => el.remove());

    destinations.forEach((dest, sid) => {
      if (!dest || dest.users.size === 0) return;

      const modeInput = document.createElement("input");
      modeInput.type = "hidden";
      modeInput.name = `destination_mode[${sid}]`;
      modeInput.value = dest.mode;
      modeInput.setAttribute("data-destination-hidden", "1");
      form.appendChild(modeInput);

      dest.users.forEach((user) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `recipient_map[${sid}][]`;
        input.value = String(user.id);
        input.setAttribute("data-destination-hidden", "1");
        form.appendChild(input);
      });

      if (canSetPersonalDeadline && dest.personalDeadline) {
        const deadlineInput = document.createElement("input");
        deadlineInput.type = "hidden";
        deadlineInput.name = `personal_deadline_map[${sid}]`;
        deadlineInput.value = String(dest.personalDeadline || "");
        deadlineInput.setAttribute("data-destination-hidden", "1");
        form.appendChild(deadlineInput);
      }
    });
  }

  function seedFromPost() {
    const allSectionIds = new Set([
      ...Object.keys(seedDestinationMode),
      ...Object.keys(seedRecipientMap)
    ]);

    allSectionIds.forEach((sectionId) => {
      const sid = String(sectionId);
      if (!sectionLabels[sid]) return;
      const deadline = String(seedPersonalDeadlineMap[sid] || "");
      const rawUsers = Array.isArray(seedRecipientMap[sid]) ? seedRecipientMap[sid] : [];
      const selectedUsers = rawUsers.map((uid) => {
        const match = getUsersForSection(sid).find((user) => Number(user.id) === Number(uid));
        return match ? { ...match, isChief: !!match.is_chief } : null;
      }).filter(Boolean);

      if (selectedUsers.length > 0) {
        setSectionRecipients(sid, selectedUsers, deadline);
        return;
      }

      if (String(seedDestinationMode[sid] || "") === "chief") {
        const chiefs = getChiefUsers(sid).map((user) => ({ ...user, isChief: true }));
        if (chiefs.length > 0) {
          setSectionRecipients(sid, [chiefs[0]], deadline);
        }
      }
    });
  }

  function divisionSelectedCount(division) {
    let count = 0;
    const sections = Array.isArray(division?.sections) ? division.sections : [];
    sections.forEach((section) => {
      const dest = destinations.get(String(section.id || ""));
      if (dest) count += dest.users.size;
    });
    return count;
  }

  function sectionSelectedCount(sectionId) {
    return destinations.get(String(sectionId))?.users.size || 0;
  }

  function renderAccordion() {
    if (!destinationAccordion) return;
    const html = divisionDirectory.map((division, index) => {
      const divisionId = String(division.division_id || `d${index}`);
      const isOpen = openDivisionIds.has(divisionId) || divisionSelectedCount(division) > 0 || index === 0;
      const sections = Array.isArray(division.sections) ? division.sections : [];
      const sectionsHtml = sections.map((section) => renderSectionBlock(section)).join("");
      return `
        <div class="destDivisionCard" data-division-card="${esc(divisionId)}">
          <button type="button" class="destDivisionToggle${isOpen ? ' is-open' : ''}" data-division-toggle="${esc(divisionId)}" aria-expanded="${isOpen ? 'true' : 'false'}">
            <div class="destDivisionTitle">
              <div class="destDivisionName">${esc(division.division_name || 'Division')}</div>
              <div class="destDivisionMeta">${sections.length} section${sections.length === 1 ? '' : 's'}</div>
            </div>
            <div class="destDivisionRight">
              <span class="destDivisionCount">${divisionSelectedCount(division)}</span>
              <span class="destDivisionChevron">▾</span>
            </div>
          </button>
          <div class="destDivisionPanel${isOpen ? ' is-open' : ''}" data-division-panel="${esc(divisionId)}">
            ${sectionsHtml || '<div class="destSectionEmpty">No active sections found.</div>'}
          </div>
        </div>
      `;
    }).join("");

    destinationAccordion.innerHTML = html || '<div class="destSummaryEmpty">No destination sections available.</div>';
    attachAccordionHandlers();
  }

  function renderSectionBlock(section) {
    const sid = String(section.id || "");
    const users = getUsersForSection(sid);
    const chiefUsers = users.filter((user) => !!user.is_chief);
    const otherUsers = users.filter((user) => !user.is_chief);
    const selectedCount = sectionSelectedCount(sid);

    return `
      <div class="destSectionBlock" data-section-block="${esc(sid)}">
        <div class="destSectionHead">
          <div>
            <div class="destSectionTitle">${esc(section.name || getSectionLabel(sid))}</div>
            <div class="destSectionMetaLine">${selectedCount > 0 ? `${selectedCount} selected` : 'No one selected yet'}</div>
          </div>
          <div class="destSectionActions">
            <button type="button" class="destSectionActionBtn" data-select-chief="${esc(sid)}">Chief</button>
            <button type="button" class="destSectionActionBtn" data-select-all-users="${esc(sid)}">All</button>
            <button type="button" class="destSectionActionBtn" data-clear-section="${esc(sid)}">Clear</button>
          </div>
        </div>
        <div class="destPickerGroups">
          <div class="destPickerGroup">
            <div class="destPickerLabel">Chief</div>
            <div class="destCheckboxList">
              ${renderUserRows(sid, chiefUsers, true)}
            </div>
          </div>
          <div class="destPickerGroup">
            <div class="destPickerLabel">Users</div>
            <div class="destCheckboxList">
              ${renderUserRows(sid, otherUsers, false)}
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function renderUserRows(sectionId, users, isChiefGroup) {
    if (!users.length) {
      return `<div class="destSectionEmpty">No ${isChiefGroup ? 'chief' : 'other users'} configured.</div>`;
    }
    const dest = destinations.get(String(sectionId));
    return users.map((user) => {
      const checked = !!dest?.users?.has(String(user.id));
      return `
        <label class="destCheckboxRow${isChiefGroup ? ' is-chief' : ''}">
          <input
            type="checkbox"
            class="dest-user-checkbox"
            data-section-id="${esc(String(sectionId))}"
            data-user-id="${esc(String(user.id))}"
            ${checked ? 'checked' : ''}
          >
          <span class="destCheckboxName">${esc(user.name)}</span>
          ${isChiefGroup ? '<span class="destCheckboxBadge">Chief</span>' : ''}
        </label>
      `;
    }).join("");
  }

  function renderDestinationSummary() {
    if (!destinationSummaryBox) return;
    const sectionCount = getSelectedSectionIds().length;
    const recipientCount = getSelectedRecipientsCount();

    if (sectionCount === 0) {
      destinationSummaryBox.innerHTML = `
        <div class="destSummaryTopV2">
          <div>
            <div class="destHeadline">No recipients selected</div>
            <div class="destSubline">Open a division and check the people who should receive this document.</div>
          </div>
          <div class="destSummaryStat"><strong>0</strong><span>Recipients</span></div>
        </div>
      `;
      return;
    }

    const labels = getSelectedSectionIds().map((sid) => getSectionLabel(sid));
    destinationSummaryBox.innerHTML = `
      <div class="destSummaryTopV2">
        <div>
          <div class="destHeadline">${recipientCount} recipient${recipientCount === 1 ? '' : 's'} selected</div>
          <div class="destSubline">Across ${sectionCount} section${sectionCount === 1 ? '' : 's'}.</div>
        </div>
        <div class="destSummaryStat"><strong>${recipientCount}</strong><span>Recipients</span></div>
      </div>
      <div class="destSummaryChips">
        ${labels.map((label) => `<span class="destSummaryChip">${esc(label)}</span>`).join("")}
      </div>
    `;
  }

  function renderSelectedDestinations() {
    if (!destinationsBox) return;
    if (destinations.size === 0) {
      destinationsBox.innerHTML = '<div class="destSummaryEmpty">No recipients selected yet.</div>';
      return;
    }

    const html = Array.from(destinations.entries()).map(([sid, dest]) => {
      const meta = getSectionMeta(sid);
      const users = Array.from(dest.users.values());
      const userChips = users.map((user) => `<span class="destNameChip${user.isChief ? ' is-chief' : ''}">${esc(user.name)}</span>`).join("");
      return `
        <div class="destSelectionCard" data-destination-card="${esc(sid)}">
          <div class="destSelectionHead">
            <div>
              <div class="destSelectionTitle">${esc(meta.label || getSectionLabel(sid))}</div>
              <div class="destSelectionMeta">${dest.mode === 'chief' ? 'Chief only' : `${users.length} selected`}</div>
            </div>
            <button type="button" class="destInlineBtn" data-remove-destination="${esc(sid)}">Remove</button>
          </div>
          <div class="destSelectionNames">${userChips}</div>
          <div class="destSelectionFooter">
            ${canSetPersonalDeadline ? `
              <label class="destDeadlineInline">
                <span>Personal deadline</span>
                <input type="date" value="${esc((dest.personalDeadline || '').slice(0, 10))}" data-personal-deadline="${esc(sid)}">
              </label>
            ` : '<span class="mini">&nbsp;</span>'}
          </div>
        </div>
      `;
    }).join("");

    destinationsBox.innerHTML = html;
    attachSelectedDestinationHandlers();
  }

  function renderAll() {
    renderAccordion();
    renderDestinationSummary();
    renderSelectedDestinations();
    syncHiddenInputs();
  }

  function attachAccordionHandlers() {
    destinationAccordion?.querySelectorAll("[data-division-toggle]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const divisionId = String(btn.getAttribute("data-division-toggle") || "");
        if (!divisionId) return;
        if (openDivisionIds.has(divisionId)) openDivisionIds.delete(divisionId);
        else openDivisionIds.add(divisionId);
        renderAccordion();
      });
    });

    destinationAccordion?.querySelectorAll(".dest-user-checkbox").forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        const sectionId = String(checkbox.getAttribute("data-section-id") || "");
        const userId = Number(checkbox.getAttribute("data-user-id") || 0);
        const user = getUsersForSection(sectionId).find((row) => Number(row.id) === userId);
        if (!sectionId || !user) return;
        setDivisionChiefQuickMode(false);
        toggleUser(sectionId, user, checkbox.checked);
        renderAll();
      });
    });

    destinationAccordion?.querySelectorAll("[data-select-chief]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const sectionId = String(btn.getAttribute("data-select-chief") || "");
        const chiefs = getChiefUsers(sectionId).map((user) => ({ ...user, isChief: true }));
        if (!chiefs.length) {
          setBuilderNotice("No section chief is configured for that section.", "warning");
          return;
        }
        setDivisionChiefQuickMode(false);
        setSectionRecipients(sectionId, [chiefs[0]]);
        renderAll();
      });
    });

    destinationAccordion?.querySelectorAll("[data-select-all-users]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const sectionId = String(btn.getAttribute("data-select-all-users") || "");
        const users = getUsersForSection(sectionId).map((user) => ({ ...user, isChief: !!user.is_chief }));
        if (!users.length) {
          setBuilderNotice("No active users found in that section.", "warning");
          return;
        }
        setDivisionChiefQuickMode(false);
        setSectionRecipients(sectionId, users);
        renderAll();
      });
    });

    destinationAccordion?.querySelectorAll("[data-clear-section]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const sectionId = String(btn.getAttribute("data-clear-section") || "");
        setDivisionChiefQuickMode(false);
        clearSection(sectionId);
        renderAll();
      });
    });
  }

  function attachSelectedDestinationHandlers() {
    destinationsBox?.querySelectorAll("[data-remove-destination]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const sid = String(btn.getAttribute("data-remove-destination") || "");
        setDivisionChiefQuickMode(false);
        clearSection(sid);
        renderAll();
      });
    });

    destinationsBox?.querySelectorAll("[data-personal-deadline]").forEach((input) => {
      input.addEventListener("change", () => {
        const sid = String(input.getAttribute("data-personal-deadline") || "");
        const dest = destinations.get(sid);
        if (!dest) return;
        dest.personalDeadline = String(input.value || "");
        syncHiddenInputs();
      });
    });
  }

  function getValidDivisionChiefTargets() {
    const seen = new Set();
    return divisionChiefTargets.filter((target) => {
      const sid = String(target && target.section_id ? target.section_id : "").trim();
      if (!sid || seen.has(sid) || !sectionLabels[sid]) return false;
      seen.add(sid);
      return true;
    });
  }

  btnAddAllDivisionChiefs?.addEventListener("click", () => {
    clearBuilderNotice();
    const targets = getValidDivisionChiefTargets();
    if (!targets.length) {
      setBuilderNotice("No other division chiefs available.", "warning");
      return;
    }

    let added = 0;
    let missing = 0;
    targets.forEach((target) => {
      const chiefs = getChiefUsers(target.section_id).map((user) => ({ ...user, isChief: true }));
      if (!chiefs.length) {
        missing += 1;
        return;
      }
      setSectionRecipients(target.section_id, [chiefs[0]]);
      added += 1;
    });

    if (added > 0) {
      setDivisionChiefQuickMode(true);
    } else {
      setDivisionChiefQuickMode(false);
    }
    renderAll();
    if (added > 0) {
      const parts = [];
      if (missing > 0) parts.push(`${missing} missing chief`);
      setBuilderNotice(`Selected all division chiefs${parts.length ? ` (${parts.join(', ')})` : ''}.`, "success");
      return;
    }

    setBuilderNotice("Unable to select division chiefs right now.", "danger", { persist: true });
  });

  btnClearDestinations?.addEventListener("click", () => {
    clearBuilderNotice();
    setDivisionChiefQuickMode(false);
    destinations.clear();
    renderAll();
    setBuilderNotice("Recipient selections cleared.", "info");
  });

  form?.addEventListener("submit", (event) => {
    if (editMode) return;
    const submitterMode = event.submitter?.getAttribute?.("data-creation-mode-submit");
    if (submitterMode) syncCreationMode(submitterMode);
    const mode = selectedCreationMode();
    syncHiddenInputs();
    if (mode === "review") return;
    if (destinations.size > 0) return;
    event.preventDefault();
    setBuilderNotice("Select at least one recipient before saving and routing. Use Save for Principal Review if you do not want to route yet.", "danger", { persist: true });
  });

  btnRemoveSavedAttachment?.addEventListener("click", async () => {
    if (!removeSavedAttachmentUrl || !savedAttachmentCard || !btnRemoveSavedAttachment) {
      if (!removeSavedAttachmentInput || !form) return;
      removeSavedAttachmentInput.value = "1";
      form.submit();
      return;
    }

    const originalLabel = btnRemoveSavedAttachment.textContent || "Remove saved file";
    btnRemoveSavedAttachment.disabled = true;
    btnRemoveSavedAttachment.textContent = "Removing...";

    try {
      const res = await fetch(removeSavedAttachmentUrl, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest"
        },
        credentials: "same-origin"
      });

      let payload = null;
      try { payload = await res.json(); } catch (_err) { payload = null; }
      if (!res.ok || !payload || payload.ok !== true) {
        throw new Error(payload && payload.error ? String(payload.error) : "Failed to remove saved attachment.");
      }

      savedAttachmentCard.remove();
      if (removeSavedAttachmentInput) removeSavedAttachmentInput.value = "0";
      setBuilderNotice("Saved attachment removed.", "success");
      if (window.DTToast && typeof window.DTToast.success === "function") {
        window.DTToast.success("Saved attachment removed.");
      }
    } catch (error) {
      const message = error && error.message ? String(error.message) : "Failed to remove saved attachment.";
      setBuilderNotice(message, "danger", { persist: true });
      if (window.DTToast && typeof window.DTToast.error === "function") {
        window.DTToast.error(message);
      }
      btnRemoveSavedAttachment.disabled = false;
      btnRemoveSavedAttachment.textContent = originalLabel;
    }
  });

  seedFromPost();
  if (divisionDirectory[0]?.division_id !== undefined && divisionDirectory[0]?.division_id !== null) {
    openDivisionIds.add(String(divisionDirectory[0].division_id));
  }
  renderAll();
  syncDivisionChiefQuickMode();
})();
