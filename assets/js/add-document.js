(function () {
  const cfg = window.addDocumentConfig || null;
  if (!cfg) return;

  const hasOwnDivisionSlip = !!cfg.hasOwnDivisionSlip;
  const apiPath = String(cfg.apiPath || "");
  const sectionLabels = cfg.sectionLabels && typeof cfg.sectionLabels === "object" ? cfg.sectionLabels : {};
  const sectionMeta = cfg.sectionMeta && typeof cfg.sectionMeta === "object" ? cfg.sectionMeta : {};
  const divisionChiefTargets = Array.isArray(cfg.divisionChiefTargets) ? cfg.divisionChiefTargets : [];
  const seedRecipientMap = cfg.seedRecipientMap && typeof cfg.seedRecipientMap === "object" ? cfg.seedRecipientMap : {};
  const seedDestinationMode = cfg.seedDestinationMode && typeof cfg.seedDestinationMode === "object" ? cfg.seedDestinationMode : {};
  const seedPersonalDeadlineMap = cfg.seedPersonalDeadlineMap && typeof cfg.seedPersonalDeadlineMap === "object" ? cfg.seedPersonalDeadlineMap : {};
  const canSetPersonalDeadline = !!cfg.canSetPersonalDeadline;

  const contentTypeSelect = document.getElementById("contentTypeSelect");
  const contentTypeOtherWrap = document.getElementById("contentTypeOtherWrap");
  const contentTypeOtherInput = document.getElementById("contentTypeOtherInput");

  function syncContentTypeOther() {
    if (!contentTypeSelect || !contentTypeOtherWrap || !contentTypeOtherInput) return;
    const isOther = String(contentTypeSelect.value || "") === "Others";
    contentTypeOtherWrap.style.display = isOther ? "block" : "none";
    contentTypeOtherInput.required = isOther;
    if (!isOther) {
      contentTypeOtherInput.value = contentTypeOtherInput.value;
    }
  }

  if (contentTypeSelect) {
    contentTypeSelect.addEventListener("change", syncContentTypeOther);
    syncContentTypeOther();
  }

  function show(el, on) {
    if (!el) return;
    el.style.display = on ? "flex" : "none";
  }

  {
    const transOpts = document.getElementById("transmittalOpts");
    const slipOpts = document.getElementById("divisionSlipOpts");
    const radios = document.querySelectorAll('input[name="gen_choice"]');

    function syncGen() {
      let choice = "none";
      radios.forEach((r) => {
        if (r.checked) choice = r.value;
      });

      show(transOpts, choice === "transmittal");
      show(slipOpts, hasOwnDivisionSlip && choice === "division_slip");

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

  const selSection = document.getElementById("addToSection");
  const btnAddDestination = document.getElementById("btnAddDestination");
  const btnAddAllDivisionChiefs = document.getElementById("btnAddAllDivisionChiefs");
  const btnCancelAllDivisionChiefs = document.getElementById("btnCancelAllDivisionChiefs");
  const sectionPreviewBox = document.getElementById("sectionPreviewBox");
  const destinationsBox = document.getElementById("destinationsBox");
  const destinationSummaryBox = document.getElementById("destinationSummaryBox");
  const destinationModeHint = document.getElementById("destinationModeHint");
  const destinationNotice = document.getElementById("destinationNotice");
  const form = document.querySelector("form.docFormGrid");
  const removeSavedAttachmentUrl = String(form?.getAttribute("data-remove-saved-attachment-url") || "");
  const removeSavedAttachmentInput = document.getElementById("removeSavedAttachmentInput");
  const destinationBuilderContractInput = document.getElementById("destinationBuilderContractInput");
  const btnRemoveSavedAttachment = document.getElementById("btnRemoveSavedAttachment");
  const savedAttachmentCard = document.getElementById("savedAttachmentCard");
  const destBuilder = document.querySelector(".destBuilder");
  const destViewButtons = Array.from(document.querySelectorAll(".destViewBtn"));

  let viewMode = "simple";
  let destinationNoticeTimer = null;
  let allDivisionChiefsLocked = false;

  const destinations = new Map();

  if (destinationBuilderContractInput) {
    destinationBuilderContractInput.value = "1";
  }

  function esc(s) {
    return String(s ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    }[c]));
  }

  function truncateText(text, limit = 72) {
    text = String(text || "");
    if (text.length <= limit) return text;
    return text.slice(0, Math.max(0, limit - 1)).trimEnd() + "…";
  }



  function isAllDivisionChiefSelectionExact() {
    const targets = getValidDivisionChiefTargets();
    if (!targets.length || destinations.size !== targets.length) return false;
    return targets.every((target) => destinations.has(String(target.section_id)));
  }

  function updateBulkLockStateFromCurrentDestinations() {
    allDivisionChiefsLocked = allDivisionChiefsLocked && isAllDivisionChiefSelectionExact();
  }

  function renderToolbarState() {
    const hasSelection = !!String(selSection?.value || '').trim();
    if (btnAddDestination) {
      btnAddDestination.style.display = hasSelection && !allDivisionChiefsLocked ? 'inline-flex' : 'none';
      btnAddDestination.disabled = !hasSelection || allDivisionChiefsLocked;
    }
    if (selSection) {
      selSection.disabled = allDivisionChiefsLocked;
    }
    if (btnAddAllDivisionChiefs) {
      btnAddAllDivisionChiefs.disabled = allDivisionChiefsLocked;
      btnAddAllDivisionChiefs.classList.toggle('is-disabled', allDivisionChiefsLocked);
    }
    if (btnCancelAllDivisionChiefs) {
      btnCancelAllDivisionChiefs.style.display = allDivisionChiefsLocked ? 'inline-flex' : 'none';
    }
  }

  async function renderSectionPreview() {
    if (!sectionPreviewBox) return;

    const sid = String(selSection?.value || '').trim();
    renderToolbarState();

    if (!sid) {
      sectionPreviewBox.innerHTML = '<div class="destSummaryEmpty">Pick a section to preview users.</div>';
      return;
    }

    sectionPreviewBox.innerHTML = '<div class="destPreviewLoading">Loading users…</div>';

    try {
      const rows = await fetchUsersBySection(sid);
      const names = rows.map((u) => ({
        name: String(u.name || `#${u.id}`),
        isChief: !!u.is_chief
      }));

      if (!names.length) {
        sectionPreviewBox.innerHTML = `
          <div class="destPreviewTitle">${esc(getSectionLabel(sid))}</div>
          <div class="destSummaryEmpty">No active users found.</div>
        `;
        return;
      }

      const { chiefUsers, otherUsers } = getChiefAndOtherUsers(names);
      sectionPreviewBox.innerHTML = `
        <div class="destPreviewHead">
          <div>
            <div class="destPreviewTitle">${esc(getSectionLabel(sid))}</div>
            <div class="destPreviewMeta">${names.length} user${names.length === 1 ? '' : 's'}</div>
          </div>
        </div>
        <div class="destPreviewGroup">
          <div class="destRecipientLabel">Chief</div>
          <div class="destPreviewNames">
            ${chiefUsers.length ? chiefUsers.map((user) => `<span class="destNameChip is-chief">${esc(user.name)}</span>`).join('') : '<span class="destNameChip is-muted">No chief found</span>'}
          </div>
        </div>
        <div class="destPreviewGroup">
          <div class="destRecipientLabel">Users</div>
          <div class="destPreviewNames">
            ${otherUsers.length ? otherUsers.map((user) => `<span class="destNameChip">${esc(user.name)}</span>`).join('') : '<span class="destNameChip is-muted">No users found</span>'}
          </div>
        </div>
      `;
    } catch (_err) {
      sectionPreviewBox.innerHTML = '<div class="destSummaryEmpty">Failed to load users.</div>';
    }
  }  function getDestinationCount() {
    return destinations.size;
  }

  function isMultiSectionMode() {
    return getDestinationCount() > 1;
  }

  function getSectionLabel(sectionId) {
    return sectionLabels[String(sectionId)] || `Section #${sectionId}`;
  }

  function getSectionMeta(sectionId) {
    const meta = sectionMeta[String(sectionId)] || {};
    return {
      divisionName: String(meta.division_name || ""),
      sectionName: String(meta.section_name || ""),
      label: String(meta.label || getSectionLabel(sectionId))
    };
  }

  async function fetchUsersBySection(sectionId) {
    const res = await fetch(`${apiPath}/users_by_section.php?section_id=${encodeURIComponent(sectionId)}`, {
      headers: { Accept: "application/json" }
    });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const rows = await res.json();
    return Array.isArray(rows) ? rows : [];
  }

  async function ensureUsersLoaded(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return [];

    if (Array.isArray(dest.loadedUsers) && dest.loadedUsers.length > 0) {
      return dest.loadedUsers;
    }

    const rows = await fetchUsersBySection(sid);
    dest.loadedUsers = rows;

    dest.users.forEach((u, uid) => {
      const found = rows.find((r) => String(r.id) === String(uid));
      if (found) {
        const rawName = String(found.name || `#${found.id}`);
        dest.users.set(String(found.id), {
          id: Number(found.id),
          name: rawName,
          rawName,
          isChief: !!found.is_chief
        });
      }
    });

    return rows;
  }

  async function setDestinationToChiefOnly(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return;

    const rows = await ensureUsersLoaded(sid);
    const chief = rows.find((u) => !!u.is_chief);
    if (!chief) throw new Error(`No Section Chief configured for ${getSectionLabel(sid)}`);

    dest.mode = "chief";
    dest.users.clear();
    dest.users.set(String(chief.id), {
      id: Number(chief.id),
      name: String(chief.name || `#${chief.id}`),
      rawName: String(chief.name || `#${chief.id}`),
      isChief: true
    });
  }

  async function enforceMultiSectionChiefOnly() {
    if (!isMultiSectionMode()) return;
    const ids = Array.from(destinations.keys());
    for (const sid of ids) {
      await setDestinationToChiefOnly(sid);
    }
  }

  function createEmptyDestination() {
    return {
      mode: "chief",
      users: new Map(),
      loadedUsers: [],
      personalDeadline: ""
    };
  }

  function seedFromPost() {
    const allSectionIds = new Set([
      ...Object.keys(seedDestinationMode),
      ...Object.keys(seedRecipientMap)
    ]);

    allSectionIds.forEach((sectionId) => {
      const sid = String(sectionId);
      if (!sectionLabels[sid]) return;

      const dest = createEmptyDestination();
      dest.mode = String(seedDestinationMode[sid] || "chief") === "users" ? "users" : "chief";
      dest.personalDeadline = String(seedPersonalDeadlineMap[sid] || "");

      const seedUsers = Array.isArray(seedRecipientMap[sid]) ? seedRecipientMap[sid] : [];
      seedUsers.forEach((uid) => {
        const id = Number(uid);
        if (id > 0) {
          dest.users.set(String(id), {
            id,
            name: `#${id}`,
            rawName: `#${id}`,
            isChief: false
          });
        }
      });

      syncDestinationModeFromSelection(dest);
      destinations.set(sid, dest);
    });
  }

  function syncHiddenInputs() {
    if (!form) return;
    form.querySelectorAll('[data-destination-hidden="1"]').forEach((el) => el.remove());

    destinations.forEach((dest, sid) => {
      const modeInput = document.createElement("input");
      modeInput.type = "hidden";
      modeInput.name = `destination_mode[${sid}]`;
      modeInput.value = dest.mode;
      modeInput.setAttribute("data-destination-hidden", "1");
      form.appendChild(modeInput);

      dest.users.forEach((u) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = `recipient_map[${sid}][]`;
        input.value = String(u.id);
        input.setAttribute("data-destination-hidden", "1");
        form.appendChild(input);
      });

      if (canSetPersonalDeadline && dest.personalDeadline) {
        const deadlineInput = document.createElement("input");
        deadlineInput.type = "hidden";
        deadlineInput.name = `personal_deadline_map[${sid}]`;
        deadlineInput.value = dest.personalDeadline;
        deadlineInput.setAttribute("data-destination-hidden", "1");
        form.appendChild(deadlineInput);
      }
    });
  }

  function clearBuilderNotice() {
    if (!destinationNotice) return;
    if (destinationNoticeTimer) {
      clearTimeout(destinationNoticeTimer);
      destinationNoticeTimer = null;
    }
    destinationNotice.style.display = "none";
    destinationNotice.className = "destStatus";
    destinationNotice.textContent = "";
  }

  function setBuilderNotice(message, type = "info", options = {}) {
    if (!destinationNotice) return;
    const persist = !!options.persist;

    if (destinationNoticeTimer) {
      clearTimeout(destinationNoticeTimer);
      destinationNoticeTimer = null;
    }

    destinationNotice.className = `destStatus is-${type}`;
    destinationNotice.textContent = String(message || "");
    destinationNotice.style.display = message ? "flex" : "none";

    if (!persist && message) {
      const timeoutMs = type === "danger" ? 6000 : 3600;
      destinationNoticeTimer = window.setTimeout(clearBuilderNotice, timeoutMs);
    }
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

  function setViewMode(nextMode) {
    viewMode = nextMode === "detailed" ? "detailed" : "simple";
    if (destBuilder) {
      destBuilder.classList.toggle("is-detailed", viewMode === "detailed");
    }
    destViewButtons.forEach((btn) => {
      const active = btn.getAttribute("data-view-mode") === viewMode;
      btn.classList.toggle("is-active", active);
      btn.setAttribute("aria-selected", active ? "true" : "false");
    });
  }

  function getEffectiveRecipients(sectionId, dest) {
    const meta = getSectionMeta(sectionId);
    const recipients = Array.from(dest.users.values());

    if (recipients.length === 0) {
      return [{
        id: null,
        name: "Section Chief",
        rawName: "Section Chief",
        sectionName: meta.sectionName,
        divisionName: meta.divisionName,
        isChief: true,
        isFallback: true
      }];
    }

    return recipients.map((user) => ({
      id: user.id ?? null,
      name: String(user.name || `#${user.id}`),
      rawName: String(user.rawName || user.name || `#${user.id}`),
      sectionName: meta.sectionName,
      divisionName: meta.divisionName,
      isChief: !!user.isChief,
      isFallback: false
    }));
  }


  function syncDestinationModeFromSelection(dest) {
    if (!dest) return;
    const recipients = Array.from(dest.users.values());
    const hasNonChief = recipients.some((user) => !user.isChief);
    dest.mode = hasNonChief ? "users" : "chief";
  }

  function getChiefAndOtherUsers(rows) {
    const chiefUsers = [];
    const otherUsers = [];
    (Array.isArray(rows) ? rows : []).forEach((row) => {
      if (row && row.isChief) chiefUsers.push(row);
      else otherUsers.push(row);
    });
    return { chiefUsers, otherUsers };
  }

  function renderRecipientCheckboxRows(rows, sectionId, dest, groupLabel) {
    if (!Array.isArray(rows) || rows.length === 0) {
      return `<div class="destPickerEmpty">No ${esc(groupLabel.toLowerCase())} found.</div>`;
    }

    return rows.map((u) => {
      const id = Number(u.id);
      const rawName = String(u.name || `#${id}`);
      const checked = dest.users.has(String(id));
      return `
        <label class="destUserRow compact${u.isChief ? ' is-chief-row' : ''}">
          <input class="dest-user-cb" type="checkbox" value="${id}" data-dest-user-section="${esc(String(sectionId))}" data-name="${esc(rawName)}" data-raw-name="${esc(rawName)}" data-is-chief="${u.isChief ? '1' : '0'}" ${checked ? 'checked' : ''}>
          <span class="destUserName">${esc(rawName)}</span>
        </label>
      `;
    }).join("");
  }

  function getRecipientCount(dest, sectionId = null) {
    if (!dest) return 0;
    if (sectionId !== null) {
      return getEffectiveRecipients(sectionId, dest).length;
    }
    return dest && dest.users ? dest.users.size : 0;
  }

  function getRecipientSummary(sectionId, dest, multi) {
    const names = getEffectiveRecipients(sectionId, dest).map((u) => u.name || `#${u.id}`);
    if (names.length === 0) {
      return multi || dest.mode === "chief" ? "Section Chief" : "Section Chief by default";
    }
    if (names.length === 1) return names[0];
    if (names.length === 2) return `${names[0]}, ${names[1]}`;
    return `${names[0]}, ${names[1]} +${names.length - 2} more`;
  }

  function getSimpleRecipients(sectionId, dest) {
    return getEffectiveRecipients(sectionId, dest).map((user) => ({
      name: String(user.rawName || user.name || `#${user.id ?? ""}`),
      isChief: !!user.isChief,
      isFallback: !!user.isFallback
    }));
  }

  function renderSimpleCard(sectionId, dest) {
    const rows = getSimpleRecipients(sectionId, dest);
    const multi = isMultiSectionMode();
    const canEditSingle = !multi && !allDivisionChiefsLocked;
    const chiefRows = rows.filter((row) => row.isChief);
    const otherRows = rows.filter((row) => !row.isChief);

    const chiefHtml = chiefRows.length
      ? chiefRows.map((row) => `<span class="destNameChip is-chief">${esc(row.name)}</span>`).join("")
      : '<span class="destNameChip is-muted">No chief selected</span>';

    const userHtml = otherRows.length
      ? otherRows.map((row) => `<span class="destNameChip">${esc(row.name)}</span>`).join("")
      : '<span class="destNameChip is-muted">No users selected</span>';

    return `
      <div class="destCard" data-destination-card="${esc(sectionId)}">
        <div class="destCardTop compact">
          <div class="destCardTitleWrap">
            <div class="destCardTitle">${esc(getSectionLabel(sectionId))}</div>
            <div class="destCompactSubline">${multi ? 'Chief only' : (dest.mode === 'users' ? 'Recipients selected' : 'Chief only')}</div>
          </div>
          ${allDivisionChiefsLocked ? '' : `<button type="button" class="destInlineBtn" data-remove-destination="${esc(sectionId)}">Remove</button>`}
        </div>

        <div class="destRecipientGroups">
          <div class="destRecipientGroup">
            <div class="destRecipientLabel">Chief</div>
            <div class="destCompactNames">${chiefHtml}</div>
          </div>
          ${!multi ? `
            <div class="destRecipientGroup">
              <div class="destRecipientLabel">Users</div>
              <div class="destCompactNames">${userHtml}</div>
            </div>
          ` : ''}
        </div>

        ${canEditSingle ? `
          <div class="destUsersPanel is-open">
            <div class="destUsersHeader compact">
              <div class="destSectionLabel">Select recipients</div>
              <div class="destUsersActions">
                <button type="button" class="destInlineBtn" data-select-chief-only="${esc(sectionId)}">Chief only</button>
                <button type="button" class="destInlineBtn" data-clear-non-chief="${esc(sectionId)}">Clear users</button>
              </div>
            </div>
            <div data-users-box="${esc(sectionId)}" class="destUsersBox"><div class="mini" style="opacity:.8;">Loading users…</div></div>
          </div>
        ` : ''}

        ${canSetPersonalDeadline ? `
          <div class="destDeadlineWrap compact">
            <label class="destDeadlineLabel">Deadline</label>
            <input type="datetime-local" value="${esc(dest.personalDeadline || '')}" data-personal-deadline="${esc(sectionId)}" class="search" style="width:100%;">
          </div>
        ` : ''}
      </div>
    `;
  }

  function renderDestinationSummary() {
    if (!destinationSummaryBox) return;

    if (destinations.size === 0) {
      destinationSummaryBox.innerHTML = `<div class="destHeadline">No destinations yet</div>`;
      return;
    }

    const labels = Array.from(destinations.keys()).map((sid) => getSectionLabel(sid));
    const visibleLabels = labels.slice(0, 5);
    const hiddenCount = Math.max(0, labels.length - visibleLabels.length);
    const chips = visibleLabels.map((label) => `<span class="destSummaryChip">${esc(truncateText(label, 42))}</span>`).join("")
      + (hiddenCount > 0 ? `<span class="destSummaryChip">+${hiddenCount}</span>` : "");

    destinationSummaryBox.innerHTML = `
      <div class="destSummaryTop compact">
        <div>
          <div class="destHeadline">${allDivisionChiefsLocked ? 'All division chiefs selected' : `${destinations.size} destination${destinations.size === 1 ? '' : 's'} selected`}</div>
        </div>
        <div class="destCounter"><strong>${destinations.size}</strong><span>${destinations.size === 1 ? 'target' : 'targets'}</span></div>
      </div>
      <div class="destSummaryChips">${chips}</div>
    `;
  }

  function renderModeHint() {
    if (!destinationModeHint) return;
    destinationModeHint.style.display = "none";
    destinationModeHint.textContent = "";
  }

  function renderDestinations() {
    if (!destinationsBox) return;

    updateBulkLockStateFromCurrentDestinations();
    renderToolbarState();

    if (destinations.size === 0) {
      destinationsBox.innerHTML = '<div class="destSummaryEmpty">No destinations added yet.</div>';
      renderDestinationSummary();
      renderModeHint();
      syncHiddenInputs();
      return;
    }

    const html = Array.from(destinations.entries()).map(([sid, dest]) => renderSimpleCard(sid, dest)).join('');
    destinationsBox.innerHTML = html;

    renderDestinationSummary();
    renderModeHint();
    syncHiddenInputs();
    attachDestinationHandlers();

    destinations.forEach((_dest, sid) => {
      if (!isMultiSectionMode() && !allDivisionChiefsLocked) {
        renderUsersPanel(sid);
      }
    });
  }

  async function renderUsersPanel(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    const panel = destinationsBox.querySelector(`[data-users-box="${CSS.escape(sid)}"]`);
    if (!dest || !panel) return;

    try {
      const rows = await ensureUsersLoaded(sid);
      if (!Array.isArray(rows) || rows.length === 0) {
        panel.innerHTML = '<div class="mini" style="opacity:.8;">No active users found in that section.</div>';
        return;
      }

      const normalizedRows = rows.map((u) => ({
        id: Number(u.id),
        name: String(u.name || `#${u.id}`),
        isChief: !!u.is_chief
      }));
      const { chiefUsers, otherUsers } = getChiefAndOtherUsers(normalizedRows);

      panel.innerHTML = `
        <div class="destPickerGroups">
          <div class="destPickerGroup">
            <div class="destRecipientLabel">Chief</div>
            <div class="destPickerList">
              ${renderRecipientCheckboxRows(chiefUsers, sid, dest, "Chief")}
            </div>
          </div>
          <div class="destPickerGroup">
            <div class="destRecipientLabel">Users</div>
            <div class="destPickerList">
              ${renderRecipientCheckboxRows(otherUsers, sid, dest, "Users")}
            </div>
          </div>
        </div>
      `;

      attachUserCheckboxHandlers(sid);
    } catch (_err) {
      panel.innerHTML = '<div class="mini" style="opacity:.8;">Failed to load users. Try again.</div>';
    }
  }

  function attachUserCheckboxHandlers(sectionId) {
    const sid = String(sectionId);
    const dest = destinations.get(sid);
    if (!dest) return;

    destinationsBox.querySelectorAll(`input.dest-user-cb[data-dest-user-section="${CSS.escape(sid)}"]`).forEach((cb) => {
      cb.addEventListener("change", () => {
        const uid = String(cb.value);
        const id = Number(cb.value);
        const name = cb.dataset.name || `#${id}`;
        const rawName = cb.dataset.rawName || name;
        const isChief = cb.dataset.isChief === "1";

        if (cb.checked) {
          dest.users.set(uid, { id, name, rawName, isChief });
        } else {
          dest.users.delete(uid);
        }

        syncDestinationModeFromSelection(dest);
        renderDestinations();
      });
    });
  }

  function attachDestinationHandlers() {
    destinationsBox.querySelectorAll("[data-remove-destination]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-remove-destination") || "");
        if (!sid || allDivisionChiefsLocked) return;

        destinations.delete(sid);

        if (destinations.size === 1) {
          const onlySid = Array.from(destinations.keys())[0];
          const onlyDest = destinations.get(onlySid);
          if (onlyDest && onlyDest.mode === "chief") {
            await setDestinationToChiefOnly(onlySid);
          }
        }

        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-select-chief-only]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-select-chief-only") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || allDivisionChiefsLocked) return;
        await setDestinationToChiefOnly(sid);
        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-clear-non-chief]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-clear-non-chief") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || allDivisionChiefsLocked) return;
        const rows = await ensureUsersLoaded(sid);
        const chief = rows.find((u) => !!u.is_chief);
        dest.users.clear();
        if (chief) {
          dest.users.set(String(chief.id), {
            id: Number(chief.id),
            name: String(chief.name || `#${chief.id}`),
            rawName: String(chief.name || `#${chief.id}`),
            isChief: true
          });
        }
        syncDestinationModeFromSelection(dest);
        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-personal-deadline]").forEach((input) => {
      input.addEventListener("change", () => {
        const sid = String(input.getAttribute("data-personal-deadline") || "");
        const dest = destinations.get(sid);
        if (!dest) return;
        dest.personalDeadline = String(input.value || "");
        syncHiddenInputs();
      });
    });
  }

  async function addDestinationById(sid) {
    const sectionId = String(sid || "").trim();
    if (!sectionId) return { ok: false, reason: "invalid" };
    if (!sectionLabels[sectionId]) return { ok: false, reason: "unknown" };
    if (destinations.has(sectionId)) return { ok: false, reason: "duplicate" };

    const dest = createEmptyDestination();
    destinations.set(sectionId, dest);

    try {
      if (isMultiSectionMode()) {
        await enforceMultiSectionChiefOnly();
      } else {
        await setDestinationToChiefOnly(sectionId);
      }
      renderDestinations();
      return { ok: true, reason: "added", sectionId };
    } catch (error) {
      destinations.delete(sectionId);
      renderDestinations();
      const message = error && error.message ? String(error.message) : "Unable to add that destination.";
      if (/No Section Chief configured/i.test(message)) {
        return { ok: false, reason: "missing-chief", sectionId, message };
      }
      return { ok: false, reason: "error", sectionId, message };
    }
  }

  selSection?.addEventListener("change", () => {
    clearBuilderNotice();
    renderSectionPreview();
  });

  btnAddDestination?.addEventListener("click", async () => {
    clearBuilderNotice();
    const sid = String(selSection?.value || "").trim();
    if (!sid) {
      setBuilderNotice("Select a section first.", "warning");
      selSection?.focus();
      return;
    }
    if (destinations.has(sid)) {
      setBuilderNotice("That destination section is already in the list.", "info");
      return;
    }

    const result = await addDestinationById(sid);
    if (result.ok) {
      setBuilderNotice(`${getSectionLabel(sid)} added.`, "success");
      if (selSection) selSection.value = "";
      await renderSectionPreview();
      return;
    }

    if (result.reason === "missing-chief") {
      setBuilderNotice(result.message || "No section chief is configured for that section.", "danger", { persist: true });
      return;
    }
    setBuilderNotice(result.message || "Unable to add that destination right now.", "danger", { persist: true });
  });

  form?.addEventListener("submit", (event) => {
    syncHiddenInputs();
    if (destinations.size > 0) return;

    event.preventDefault();
    setBuilderNotice("Add at least one destination to the list before saving.", "danger", { persist: true });
    selSection?.focus();
  });

  btnAddAllDivisionChiefs?.addEventListener("click", async () => {
    clearBuilderNotice();
    const targets = getValidDivisionChiefTargets();
    if (targets.length === 0) {
      setBuilderNotice("No other division chiefs available.", "warning");
      return;
    }

    destinations.clear();

    let added = 0;
    let missingChief = 0;
    let invalid = 0;

    for (const target of targets) {
      const result = await addDestinationById(target.section_id);
      if (result.ok) added += 1;
      else if (result.reason === "missing-chief") missingChief += 1;
      else invalid += 1;
    }

    if (added > 0) {
      allDivisionChiefsLocked = true;
      if (selSection) selSection.value = "";
      await renderSectionPreview();
      renderDestinations();
      const extras = [];
      if (missingChief > 0) extras.push(`${missingChief} missing chief`);
      if (invalid > 0) extras.push(`${invalid} skipped`);
      setBuilderNotice(`Locked to all division chiefs${extras.length ? ` (${extras.join(', ')})` : ''}.`, "success");
      return;
    }

    renderDestinations();
    const parts = [];
    if (missingChief > 0) parts.push(`${missingChief} missing chief`);
    if (invalid > 0) parts.push(`${invalid} skipped`);
    setBuilderNotice(`Unable to load division chiefs${parts.length ? ` (${parts.join(', ')})` : ''}.`, "danger", { persist: true });
  });

  btnCancelAllDivisionChiefs?.addEventListener("click", async () => {
    allDivisionChiefsLocked = false;
    destinations.clear();
    renderDestinations();
    await renderSectionPreview();
    setBuilderNotice("Division chief batch cancelled.", "info");
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
      try {
        payload = await res.json();
      } catch (_err) {
        payload = null;
      }

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

  destViewButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      setViewMode(String(btn.getAttribute("data-view-mode") || "simple"));
      renderDestinations();
    });
  });

  setViewMode("detailed");
  seedFromPost();
  allDivisionChiefsLocked = false;

  (async function init() {
    const preloadTasks = [];
    if (destinations.size > 1) {
      await enforceMultiSectionChiefOnly();
    } else if (destinations.size === 1) {
      const sid = Array.from(destinations.keys())[0];
      const dest = destinations.get(sid);
      if (dest && dest.mode === "chief") {
        await setDestinationToChiefOnly(sid);
      }
    }

    destinations.forEach((dest, sid) => {
      if (dest.mode === "users") preloadTasks.push(ensureUsersLoaded(sid));
    });
    if (preloadTasks.length) {
      try { await Promise.all(preloadTasks); } catch (_err) {}
    }

    renderDestinations();
    await renderSectionPreview();
  })();
})();
