(function () {
  const ctx = window.__TMS__ || {};
  const modal = document.getElementById("tmsTaskModal");
  const form = document.getElementById("tmsTaskForm");
  const messageEl = document.getElementById("tmsTaskFormMessage");
  const titleEl = document.getElementById("tmsModalTitle");
  const subEl = document.getElementById("tmsModalSub");
  const typeSelect = document.getElementById("tmsTaskType");
  const projectSelect = document.getElementById("tmsProjectId");
  const projectCodeInput = document.getElementById("tmsProjectCode");
  const projectTitleInput = document.getElementById("tmsProjectTitle");
  const assigneeLabel = document.getElementById("tmsAssigneeLabel");
  const referenceLabel = document.getElementById("tmsReferenceLabel");
  const assigneesSelect = document.getElementById("tmsAssignees");
  const openCreateBtn = document.getElementById("tmsOpenCreateBtn");

  if (!modal || !form) return;

  const fieldNodes = {
    date_surveyed: document.querySelector('[data-tms-field="date_surveyed"]'),
    date_received: document.querySelector('[data-tms-field="date_received"]'),
    date_started: document.querySelector('[data-tms-field="date_started"]'),
    target_completion: document.querySelector('[data-tms-field="target_completion"]'),
    progress_percent: document.querySelector('[data-tms-field="progress_percent"]'),
    reference_code: document.querySelector('[data-tms-field="reference_code"]'),
  };

  function setMessage(type, text) {
    if (!messageEl) return;
    messageEl.className = "tmsFormMessage" + (type ? ` ${type}` : "");
    messageEl.textContent = text || "";
    messageEl.style.display = text ? "block" : "none";
  }

  function closeModal() {
    modal.hidden = true;
    setMessage("", "");
  }

  function openModal() {
    modal.hidden = false;
  }

  function selectedTaskType() {
    const id = String(typeSelect?.value || "");
    return (ctx.taskTypes || []).find((row) => String(row.id) === id) || null;
  }

  function applyTaskTypeUI() {
    const type = selectedTaskType();
    const roleLabel = type?.assignment_role_label || "Assigned users";
    const refLabel = type?.reference_label || "Reference code";
    if (assigneeLabel) assigneeLabel.textContent = roleLabel;
    if (referenceLabel) referenceLabel.textContent = refLabel;

    const visible = {
      date_surveyed: Number(type?.show_date_surveyed || 0) === 1,
      date_received: Number(type?.show_date_received || 0) === 1,
      date_started: Number(type?.show_date_started || 0) === 1,
      target_completion: Number(type?.show_target_completion || 0) === 1,
      progress_percent: Number(type?.show_progress || 0) === 1,
      reference_code: Number(type?.show_reference_code || 0) === 1,
    };

    Object.entries(fieldNodes).forEach(([key, node]) => {
      if (!node) return;
      node.hidden = !visible[key];
      const input = node.querySelector("input, select, textarea");
      if (input) {
        input.disabled = !visible[key];
      }
    });
  }

  function resetForm() {
    form.reset();
    document.getElementById("tmsTaskId").value = "0";
    Array.from(assigneesSelect?.options || []).forEach((option) => {
      option.selected = false;
    });
    titleEl.textContent = "New Task";
    subEl.textContent = "Create a monitoring record without changing DTS routing behavior.";
    setMessage("", "");
    applyTaskTypeUI();
  }

  function setAssignees(userIds) {
    const selected = new Set((userIds || []).map((value) => String(value)));
    Array.from(assigneesSelect?.options || []).forEach((option) => {
      option.selected = selected.has(String(option.value));
    });
  }

  function hydrateForm(taskId) {
    const task = (ctx.tasksById || {})[String(taskId)] || (ctx.tasksById || {})[taskId];
    if (!task) return false;

    document.getElementById("tmsTaskId").value = String(task.id || 0);
    if (typeSelect) typeSelect.value = String(task.task_type_id || "");
    if (projectSelect) projectSelect.value = String(task.project_id || "");
    if (projectCodeInput) projectCodeInput.value = task.project_code || "";
    if (projectTitleInput) projectTitleInput.value = task.project_title || "";
    document.getElementById("tmsDescription").value = task.description || "";
    document.getElementById("tmsDeo").value = task.deo || "";
    document.getElementById("tmsLgu").value = task.lgu || "";
    document.getElementById("tmsDateSurveyed").value = (task.date_surveyed || "").slice(0, 10);
    document.getElementById("tmsDateReceived").value = (task.date_received || "").slice(0, 10);
    document.getElementById("tmsDateStarted").value = (task.date_started || "").slice(0, 10);
    document.getElementById("tmsTargetCompletion").value = (task.target_completion || "").slice(0, 10);
    document.getElementById("tmsProgressPercent").value = task.progress_percent ?? "";
    document.getElementById("tmsReferenceCode").value = task.reference_code || "";
    document.getElementById("tmsRemarks").value = task.remarks || "";

    const assigneeIds = Array.isArray(task.assignees)
      ? task.assignees.map((row) => String(row.user_id || "")).filter(Boolean)
      : [];
    setAssignees(assigneeIds);

    titleEl.textContent = "Edit Task";
    subEl.textContent = `Update ${task.project_code || "task"} without changing DTS workflow data.`;
    setMessage("", "");
    applyTaskTypeUI();
    return true;
  }

  openCreateBtn?.addEventListener("click", () => {
    resetForm();
    openModal();
  });

  modal.querySelectorAll("[data-tms-close]").forEach((button) => {
    button.addEventListener("click", closeModal);
  });

  document.querySelectorAll("[data-tms-edit]").forEach((button) => {
    button.addEventListener("click", () => {
      resetForm();
      if (hydrateForm(button.getAttribute("data-tms-edit"))) {
        openModal();
      }
    });
  });

  document.querySelectorAll("[data-tms-delete]").forEach((button) => {
    button.addEventListener("click", async () => {
      const taskId = Number(button.getAttribute("data-tms-delete") || 0);
      const label = button.getAttribute("data-tms-label") || "this task";
      if (!taskId) return;
      if (!window.confirm(`Delete ${label}? This only removes the TMS record.`)) return;

      const formData = new FormData();
      formData.append("csrf_token", ctx.csrf || "");
      formData.append("id", String(taskId));

      try {
        const response = await fetch(`${window.__APP__.api}/tms_task_delete.php`, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        const payload = await response.json().catch(() => ({ ok: false, error: "Invalid server response." }));
        if (!response.ok || !payload.ok) {
          throw new Error(payload.error || "Delete failed.");
        }
        window.location.reload();
      } catch (error) {
        window.alert(error.message || "Delete failed.");
      }
    });
  });

  typeSelect?.addEventListener("change", applyTaskTypeUI);

  projectSelect?.addEventListener("change", () => {
    const selected = (ctx.projects || []).find((row) => String(row.id) === String(projectSelect.value || ""));
    if (!selected) return;
    if (projectCodeInput && !projectCodeInput.value.trim()) projectCodeInput.value = selected.project_code || "";
    if (projectTitleInput && !projectTitleInput.value.trim()) projectTitleInput.value = selected.title || "";
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    setMessage("", "");

    const submitter = form.querySelector('button[type="submit"]');
    if (submitter) submitter.disabled = true;

    try {
      const response = await fetch(`${window.__APP__.api}/tms_task_save.php`, {
        method: "POST",
        body: new FormData(form),
        credentials: "same-origin",
        headers: { Accept: "application/json" }
      });
      const payload = await response.json().catch(() => ({ ok: false, error: "Invalid server response." }));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || "Save failed.");
      }
      setMessage("isOk", payload.message || "Task saved.");
      window.setTimeout(() => window.location.reload(), 360);
    } catch (error) {
      setMessage("isError", error.message || "Save failed.");
    } finally {
      if (submitter) submitter.disabled = false;
    }
  });

  resetForm();
})();
