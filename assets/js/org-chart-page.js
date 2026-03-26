(function () {
  const editModeBtn = document.getElementById('orgEditModeBtn');
  const modal = document.getElementById('orgEditModal');
  const form = document.getElementById('orgEditForm');
  if (!editModeBtn || !modal || !form) return;

  const msg = document.getElementById('orgEditMsg');
  const nameInput = document.getElementById('org_full_name');
  const usernamePreview = document.getElementById('org_username_preview');
  const emailInput = document.getElementById('org_email');
  const titleInput = document.getElementById('org_official_title');
  const roleInput = document.getElementById('org_authority_role');
  const permanentInput = document.getElementById('org_permanent');
  const targetIdInput = document.getElementById('org_target_user_id');

  function generateUsername(fullName) {
    const parts = String(fullName || '').trim().split(/\s+/).filter(Boolean).map((p) => p.replace(/[^a-z0-9]/gi, ''));
    if (!parts.length) return '';
    const count = parts.length;
    const last = (parts[count - 1] || '').toLowerCase();
    let middle = '';
    let given = [parts[0] || ''];
    if (count >= 3) {
      middle = ((parts[count - 2] || '').charAt(0) || '').toLowerCase();
      given = parts.slice(0, count - 2);
    }
    const givenInitials = given.map((v) => (v.charAt(0) || '').toLowerCase()).join('');
    return (givenInitials + middle + last).replace(/[^a-z0-9]/g, '') || 'user';
  }

  function setEditMode(on) {
    document.body.classList.toggle('orgEditModeActive', on);
    editModeBtn.dataset.editMode = on ? 'on' : 'off';
    editModeBtn.textContent = on ? 'Disable edit mode' : 'Enable edit mode';
  }

  function openModal(button) {
    targetIdInput.value = button.dataset.userId || '';
    nameInput.value = button.dataset.fullName || '';
    emailInput.value = button.dataset.email || '';
    titleInput.value = button.dataset.title || '';
    roleInput.value = button.dataset.authorityRole || 'staff';
    if (permanentInput) permanentInput.checked = button.dataset.permanent === '1';
    usernamePreview.value = generateUsername(nameInput.value);
    msg.style.display = 'none';
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('isOpen');
    nameInput.focus();
  }

  function closeModal() {
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('isOpen');
  }

  editModeBtn.addEventListener('click', () => {
    const next = editModeBtn.dataset.editMode !== 'on';
    setEditMode(next);
  });

  document.addEventListener('click', (event) => {
    const editBtn = event.target.closest('[data-org-edit="1"]');
    if (editBtn) {
      if (editModeBtn.dataset.editMode !== 'on') {
        window.DTToast?.info('Enable edit mode first.');
        return;
      }
      openModal(editBtn);
      return;
    }

    const closeBtn = event.target.closest('[data-org-close="1"]');
    if (closeBtn) {
      closeModal();
    }
  });

  nameInput.addEventListener('input', () => {
    usernamePreview.value = generateUsername(nameInput.value);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    msg.style.display = 'none';
    const fd = new FormData(form);

    try {
      const res = await fetch(window.__APP__.api + '/org_chart_update_user.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok) {
        msg.style.display = 'block';
        msg.style.background = '#f8d7da';
        msg.style.border = '1px solid #f5c2c7';
        msg.textContent = data.error || 'Failed to update org user.';
        return;
      }
      window.DTToast?.success(data.message || 'Org user updated.');
      closeModal();
      window.setTimeout(() => window.location.reload(), 320);
    } catch (err) {
      msg.style.display = 'block';
      msg.style.background = '#f8d7da';
      msg.style.border = '1px solid #f5c2c7';
      msg.textContent = 'Network error. Please try again.';
    }
  });

  window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('isOpen')) {
      closeModal();
    }
  });
})();
