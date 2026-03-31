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
  const expandedUserPanels = new Set();

  let viewMode = "simple";
  let destinationNoticeTimer = null;

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

  function getDestinationCount() {
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
        const label = found.is_chief ? `${rawName} (CHIEF)` : rawName;
        dest.users.set(String(found.id), {
          id: Number(found.id),
          name: label,
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
      name: `${chief.name} (CHIEF)`,
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

  function getUserToggleLabel(sectionId) {
    return expandedUserPanels.has(String(sectionId)) ? "Hide user list" : "Choose users";
  }

  function getSimpleRecipients(sectionId, dest) {
    return getEffectiveRecipients(sectionId, dest).map((user) => ({
      name: String(user.rawName || user.name || `#${user.id ?? ''}`),
      sectionName: user.sectionName,
      divisionName: user.divisionName,
      isChief: !!user.isChief,
      isFallback: !!user.isFallback
    }));
  }

  function renderSimpleCard(sectionId, dest) {
    const rows = getSimpleRecipients(sectionId, dest);
    const deadlineChip = dest.personalDeadline ? `<span class="destInlineChip">Has deadline</span>` : "";
    const fallbackChip = (!dest.users.size && dest.mode === "users") ? `<span class="destInlineChip">Chief fallback</span>` : "";

    return `
      <div class="destCard" data-destination-card="${esc(sectionId)}">
        <div class="destCardTop">
          <div class="destCardTitleWrap">
            <div class="destCardTitle">${esc(getSectionLabel(sectionId))}</div>
          </div>
          <button type="button" class="destInlineBtn" data-remove-destination="${esc(sectionId)}">Remove</button>
        </div>
        <div class="destSimpleList">
          ${rows.map((row) => `
            <div class="destSimpleRow">
              <div class="destSimpleInfo">
                <div class="destSimpleName">${esc(row.name)}</div>
                <div class="destSimpleMeta">
                  <span>${esc(row.sectionName || "Section")}</span>
                  <span>${esc(row.divisionName || "Division")}</span>
                </div>
              </div>
              <div class="destSimpleSide">
                ${row.isChief ? '<span class="destInlineChip">Chief</span>' : ''}
                ${row.isFallback ? fallbackChip : ''}
                ${deadlineChip}
              </div>
            </div>
          `).join("")}
        </div>
      </div>
    `;
  }

  function renderDestinationSummary() {
    if (!destinationSummaryBox) return;

    if (destinations.size === 0) {
      destinationSummaryBox.innerHTML = `
        <div class="destHeadline">No destinations queued yet</div>
        <div class="destSubline">Add one destination manually or use the all division chiefs shortcut.</div>
      `;
      return;
    }

    const multi = isMultiSectionMode();
    const labels = Array.from(destinations.keys()).map((sid) => getSectionLabel(sid));
    const visibleLabels = labels.slice(0, 4);
    const hiddenCount = Math.max(0, labels.length - visibleLabels.length);
    const chips = visibleLabels.map((label) => `<span class="destSummaryChip">${esc(truncateText(label, 64))}</span>`).join("")
      + (hiddenCount > 0 ? `<span class="destSummaryChip">+${hiddenCount} more</span>` : "");
    const headline = multi
      ? `Initial send goes to ${destinations.size} section/division chiefs.`
      : `Initial send goes to ${esc(labels[0] || "the selected destination")}.`;
    const detail = multi
      ? `Chief-only routing is locked right now, but per-destination deadlines are still available below.`
      : `Single destination mode lets you stay with the chief or switch to specific users.`;

    destinationSummaryBox.innerHTML = `
      <div class="destSummaryTop">
        <div>
          <div class="destHeadline">${headline}</div>
          <div class="destSubline">${detail}</div>
        </div>
        <div class="destCounter"><strong>${destinations.size}</strong><span>${destinations.size === 1 ? "target" : "targets"}</span></div>
      </div>
      <div class="destSummaryChips">${chips}</div>
    `;
  }

  function renderModeHint() {
    if (!destinationModeHint) return;
    if (destinations.size === 0 || viewMode !== "detailed") {
      destinationModeHint.style.display = "none";
      destinationModeHint.textContent = "";
      return;
    }

    destinationModeHint.style.display = "inline-flex";
    destinationModeHint.textContent = isMultiSectionMode()
      ? "Locked to chief-only because multiple destinations are selected."
      : "Single destination mode: you can switch between chief only and specific users.";
  }

  function renderDestinations() {
    if (!destinationsBox) return;

    if (destinations.size === 0) {
      destinationsBox.innerHTML = '<div class="destSummaryEmpty">No destinations added yet.</div>';
      renderDestinationSummary();
      renderModeHint();
      syncHiddenInputs();
      return;
    }

    const multi = isMultiSectionMode();
    const detailed = viewMode === "detailed";

    const html = Array.from(destinations.entries()).map(([sid, dest]) => {
      if (!detailed) {
        return renderSimpleCard(sid, dest);
      }

      const sectionLabel = getSectionLabel(sid);
      const modeLabel = multi ? "Chief only" : (dest.mode === "users" ? "Specific users" : "Chief only");
      const recipientCount = getRecipientCount(dest, sid);
      const recipientSummary = getRecipientSummary(sid, dest, multi);
      const compactSummary = multi
        ? `Initial routing is <strong>chief only</strong>. Current recipient: <strong>${esc(truncateText(recipientSummary, 88))}</strong>.`
        : `Mode: <strong>${esc(modeLabel)}</strong> · Initial recipient${recipientCount === 1 ? '' : 's'}: <strong>${esc(truncateText(recipientSummary, 88))}</strong>.`;
      const showUsersPanel = !multi && dest.mode === "users" && expandedUserPanels.has(String(sid));
      const modeControls = multi ? `
        <div class="destModeLock">Chief-only is locked for this multi-send batch</div>
      ` : `
        <div class="destModePills">
          <label class="destModeOption">
            <input type="radio" name="destModeUI_${esc(sid)}" value="chief" ${dest.mode === "chief" ? "checked" : ""} data-dest-mode="${esc(sid)}">
            Chief only
          </label>
          <label class="destModeOption">
            <input type="radio" name="destModeUI_${esc(sid)}" value="users" ${dest.mode === "users" ? "checked" : ""} data-dest-mode="${esc(sid)}">
            Specific users
          </label>
        </div>
      `;
      const userToggle = !multi && dest.mode === "users" ? `
        <button type="button" class="destInlineBtn" data-toggle-users="${esc(sid)}">${esc(getUserToggleLabel(sid))}</button>
      ` : "";

      return `
        <div class="destCard" data-destination-card="${esc(sid)}">
          <div class="destCardTop">
            <div class="destCardTitleWrap">
              <div class="destCardTitle">${esc(sectionLabel)}</div>
              <div class="destFacts">
                <span class="destFactChip">${esc(modeLabel)}</span>
                <span class="destFactChip">${recipientCount > 0 ? `${recipientCount} selected` : '1 default recipient'}</span>
                ${dest.personalDeadline ? '<span class="destFactChip">Has personal deadline</span>' : ''}
              </div>
            </div>
            <button type="button" class="destInlineBtn" data-remove-destination="${esc(sid)}">Remove</button>
          </div>

          <div class="destCardBody">
            <div class="destCompactSummary">${compactSummary}</div>
            ${modeControls}
            <div class="destCardMeta">
              <div class="destMetaBlock">
                <div class="destMetaLabel">Current mode</div>
                <div class="destMetaValue">${esc(!multi && dest.mode === 'users' && dest.users.size === 0 ? 'Specific users (empty → chief fallback)' : modeLabel)}</div>
              </div>
              <div class="destMetaBlock">
                <div class="destMetaLabel">Initial recipient(s)</div>
                <div class="destMetaValue">${esc(recipientSummary)}</div>
              </div>
            </div>

            ${canSetPersonalDeadline ? `
              <div class="destDeadlineWrap">
                <label class="destDeadlineLabel">Personal deadline</label>
                <div class="destDeadlineGrid">
                  <input type="datetime-local" value="${esc(dest.personalDeadline || '')}" data-personal-deadline="${esc(sid)}" class="search" style="width:100%;">
                  ${userToggle}
                </div>
                <div class="destDeadlineNote">Applies only to this destination.</div>
              </div>
            ` : userToggle}

            <div data-users-panel="${esc(sid)}" class="destUsersPanel" style="${showUsersPanel ? '' : 'display:none;'}">
              <div class="destUsersHeader">
                <div>
                  <div class="destSectionLabel">Specific users</div>
                  <div class="mini" style="opacity:.8;">Select users from this section. If none are selected, initial routing still goes to the section chief.</div>
                </div>
                <div class="destUsersActions">
                  <button type="button" class="destInlineBtn" data-select-all-users="${esc(sid)}">Select all</button>
                  <button type="button" class="destInlineBtn" data-clear-users="${esc(sid)}">Clear</button>
                </div>
              </div>
              <div data-users-box="${esc(sid)}" class="destUsersBox"><div class="mini" style="opacity:.8;">Loading users…</div></div>
            </div>
          </div>
        </div>
      `;
    }).join("");

    destinationsBox.innerHTML = html;
    attachDestinationHandlers();
    renderDestinationSummary();
    renderModeHint();
    syncHiddenInputs();

    destinations.forEach((dest, sid) => {
      if (detailed && !multi && dest.mode === "users" && expandedUserPanels.has(String(sid))) {
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

      panel.innerHTML = rows.map((u) => {
        const id = Number(u.id);
        const rawName = String(u.name || `#${id}`);
        const isChief = !!u.is_chief;
        const label = isChief ? `${rawName} (CHIEF)` : rawName;
        const checked = dest.users.has(String(id));
        return `
          <label class="destUserRow">
            <input class="dest-user-cb" type="checkbox" value="${id}" data-dest-user-section="${esc(sid)}" data-name="${esc(label)}" data-raw-name="${esc(rawName)}" data-is-chief="${isChief ? '1' : '0'}" ${checked ? 'checked' : ''}>
            <span style="font-weight:900;">${esc(label)}</span>
            ${isChief ? '<span class="mini" style="padding:2px 8px; border-radius:999px; border:1px solid rgba(15, 23, 42, .12); background:#fff;">Chief</span>' : ''}
            <span class="destUserBadge">#${id}</span>
          </label>
        `;
      }).join("");

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

        syncHiddenInputs();
        renderDestinations();
      });
    });
  }

  function attachDestinationHandlers() {
    destinationsBox.querySelectorAll("[data-remove-destination]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-remove-destination") || "");
        if (!sid) return;

        destinations.delete(sid);
        expandedUserPanels.delete(sid);

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

    destinationsBox.querySelectorAll("[data-dest-mode]").forEach((radio) => {
      radio.addEventListener("change", async () => {
        const sid = String(radio.getAttribute("data-dest-mode") || "");
        const value = String(radio.value || "chief");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode()) return;

        dest.mode = value === "users" ? "users" : "chief";

        if (dest.mode === "chief") {
          expandedUserPanels.delete(sid);
          await setDestinationToChiefOnly(sid);
        } else {
          expandedUserPanels.add(sid);
          await ensureUsersLoaded(sid);
        }

        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-toggle-users]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-toggle-users") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || dest.mode !== "users") return;

        if (expandedUserPanels.has(sid)) {
          expandedUserPanels.delete(sid);
          renderDestinations();
          return;
        }

        expandedUserPanels.add(sid);
        renderDestinations();
        await renderUsersPanel(sid);
      });
    });

    destinationsBox.querySelectorAll("[data-select-all-users]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const sid = String(btn.getAttribute("data-select-all-users") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || dest.mode !== "users") return;

        const rows = await ensureUsersLoaded(sid);
        dest.users.clear();
        rows.forEach((u) => {
          const rawName = String(u.name || `#${u.id}`);
          const label = u.is_chief ? `${rawName} (CHIEF)` : rawName;
          dest.users.set(String(u.id), { id: Number(u.id), name: label, rawName, isChief: !!u.is_chief });
        });
        renderDestinations();
      });
    });

    destinationsBox.querySelectorAll("[data-clear-users]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const sid = String(btn.getAttribute("data-clear-users") || "");
        const dest = destinations.get(sid);
        if (!dest || isMultiSectionMode() || dest.mode !== "users") return;
        dest.users.clear();
        setBuilderNotice("No specific users selected. Initial routing will fall back to the section chief.", "info");
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
      expandedUserPanels.delete(sectionId);
      renderDestinations();
      const message = error && error.message ? String(error.message) : "Unable to add that destination.";
      if (/No Section Chief configured/i.test(message)) {
        return { ok: false, reason: "missing-chief", sectionId, message };
      }
      return { ok: false, reason: "error", sectionId, message };
    }
  }

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
      setBuilderNotice(`${getSectionLabel(sid)} added to the destination list.`, "success");
      if (selSection) selSection.value = "";
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
      setBuilderNotice("No other division chief targets are available.", "warning");
      return;
    }

    let added = 0;
    let duplicates = 0;
    let missingChief = 0;
    let invalid = 0;

    for (const target of targets) {
      const result = await addDestinationById(target.section_id);
      if (result.ok) {
        added += 1;
      } else if (result.reason === "duplicate") {
        duplicates += 1;
      } else if (result.reason === "missing-chief") {
        missingChief += 1;
      } else {
        invalid += 1;
      }
    }

    if (added > 0) {
      const extras = [];
      if (duplicates > 0) extras.push(`${duplicates} already listed`);
      if (missingChief > 0) extras.push(`${missingChief} without section chief`);
      if (invalid > 0) extras.push(`${invalid} skipped`);
      setBuilderNotice(`${added} division chief target${added === 1 ? "" : "s"} added${extras.length ? ` (${extras.join(', ')})` : ''}.`, "success");
      return;
    }

    if (duplicates > 0 && missingChief === 0 && invalid === 0) {
      setBuilderNotice("All division chief targets are already in the destination list.", "info");
      return;
    }

    if (missingChief > 0 && duplicates === 0 && invalid === 0) {
      setBuilderNotice("Some division chief targets do not have a configured section chief yet.", "danger", { persist: true });
      return;
    }

    const parts = [];
    if (duplicates > 0) parts.push(`${duplicates} already listed`);
    if (missingChief > 0) parts.push(`${missingChief} without section chief`);
    if (invalid > 0) parts.push(`${invalid} skipped`);
    setBuilderNotice(`No new division chief targets were added${parts.length ? ` (${parts.join(', ')})` : ''}.`, "warning", { persist: true });
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

  setViewMode("simple");
  seedFromPost();

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
  })();
})();
