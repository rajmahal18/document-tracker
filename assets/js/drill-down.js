(function () {
  const atlas = document.getElementById('orgAtlas2026');
  if (!atlas) return;

  const toggles = Array.from(document.querySelectorAll('.orgMemberToggle[data-target]'));
  const searchInput = document.getElementById('orgSearchInput');
  const expandAllBtn = document.getElementById('orgExpandAllBtn');
  const collapseAllBtn = document.getElementById('orgCollapseAllBtn');
  const resetFilterBtn = document.getElementById('orgResetFilterBtn');
  const divisionCards = Array.from(document.querySelectorAll('.orgDivisionCard.orgSearchable'));
  const emptyState = document.getElementById('orgEmptyState');
  const drillBar = document.getElementById('orgDrillBar');
  const drillBackBtn = document.getElementById('orgDrillBackBtn');
  const drillBackLabel = document.getElementById('orgDrillBackDivName');
  const drillHints = Array.from(document.querySelectorAll('.orgDrillHint[data-drill]'));
  const rootCard = document.getElementById('org-root-card');
  const stage = document.querySelector('.orgStage');

  let activeDrillId = null;
  const drawerStateBeforeDrill = new Map();

  function animateOpen(drawer) {
    if (!drawer || typeof drawer.animate !== 'function') return;
    drawer.animate(
      [
        { opacity: 0, transform: 'translateY(-10px) scale(.985)' },
        { opacity: 1, transform: 'translateY(0) scale(1)' }
      ],
      { duration: 220, easing: 'cubic-bezier(.2,.8,.2,1)' }
    );
  }

  function setDrawerState(button, open) {
    const id = button.getAttribute('data-target');
    if (!id) return;
    const drawer = document.getElementById(id);
    if (!drawer) return;

    if (open) {
      if (drawer.hasAttribute('hidden')) {
        drawer.removeAttribute('hidden');
        animateOpen(drawer);
      }
    } else {
      drawer.setAttribute('hidden', 'hidden');
    }

    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.textContent = open ? 'Hide members' : 'See all members';
  }

  toggles.forEach((button) => {
    button.addEventListener('click', () => {
      const opening = button.getAttribute('aria-expanded') !== 'true';
      setDrawerState(button, opening);
    });
  });

  function normalize(value) {
    return (value || '').toString().trim().toLowerCase();
  }

  function collectSearchText(container) {
    if (!container) return '';
    return [
      normalize(container.dataset.search || ''),
      ...Array.from(container.querySelectorAll('[data-search]')).map((el) => normalize(el.dataset.search || ''))
    ].join(' ').trim();
  }

  function filterChart() {
    if (activeDrillId !== null) return;

    const query = normalize(searchInput ? searchInput.value : '');
    let visibleDivisions = 0;

    if (rootCard) {
      const rootSearch = collectSearchText(rootCard);
      const showRoot = !query || rootSearch.includes(query);
      rootCard.hidden = !showRoot;
      if (stage) stage.classList.toggle('isRootHidden', !showRoot);
    }

    divisionCards.forEach((card) => {
      const cardSearch = normalize(card.dataset.search || '');
      let cardVisible = !query || cardSearch.includes(query);

      const sections = Array.from(card.querySelectorAll('.orgSectionCard.orgSearchable, .orgChiefSection.orgSearchable'));
      if (query && sections.length) {
        let sectionVisibleCount = 0;
        sections.forEach((section) => {
          const sectionSearch = collectSearchText(section);
          const showSection = sectionSearch.includes(query);
          section.hidden = !showSection;
          if (showSection) sectionVisibleCount += 1;
        });
        cardVisible = cardVisible || sectionVisibleCount > 0;
      } else {
        sections.forEach((section) => {
          section.hidden = false;
        });
      }

      card.hidden = !cardVisible;
      if (cardVisible) visibleDivisions += 1;
    });

    const rootVisible = !!rootCard && !rootCard.hidden;
    if (emptyState) {
      emptyState.classList.toggle('isVisible', !!query && visibleDivisions === 0 && !rootVisible);
    }
  }

  function updateDrillUi() {
    const isDrillMode = activeDrillId !== null;
    atlas.classList.toggle('isDrillActive', isDrillMode);
    if (stage) stage.classList.toggle('isDrillMode', isDrillMode);
    if (drillBar) drillBar.classList.toggle('isVisible', isDrillMode);

    divisionCards.forEach((card) => {
      const isActive = isDrillMode && card.dataset.divisionId === String(activeDrillId);
      card.classList.toggle('isDrillFocus', isActive);
      card.classList.toggle('isDrillHidden', isDrillMode && !isActive);
      card.hidden = isDrillMode ? !isActive : false;
    });

    if (rootCard) {
      rootCard.hidden = isDrillMode;
    }
    if (stage) {
      stage.classList.toggle('isRootHidden', isDrillMode);
    }

    if (emptyState) {
      emptyState.classList.remove('isVisible');
    }

    if (drillBackLabel) {
      if (isDrillMode) {
        const activeCard = divisionCards.find((card) => card.dataset.divisionId === String(activeDrillId));
        drillBackLabel.textContent = activeCard ? (activeCard.dataset.divisionName || 'All divisions') : 'All divisions';
      } else {
        drillBackLabel.textContent = 'All divisions';
      }
    }
  }

  function enterDrill(divisionId) {
    if (!divisionId) return;
    activeDrillId = String(divisionId);
    drawerStateBeforeDrill.clear();

    divisionCards.forEach((card) => {
      const cardId = card.dataset.divisionId || '';
      const isActive = cardId === activeDrillId;
      const cardToggles = Array.from(card.querySelectorAll('.orgMemberToggle[data-target]'));

      cardToggles.forEach((button) => {
        const key = button.getAttribute('data-target') || '';
        drawerStateBeforeDrill.set(key, button.getAttribute('aria-expanded') === 'true');
        if (isActive) {
          setDrawerState(button, true);
        }
      });
    });

    updateDrillUi();

    const activeCard = divisionCards.find((card) => card.dataset.divisionId === activeDrillId);
    if (activeCard) {
      window.setTimeout(() => {
        activeCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 80);
    }

    try {
      history.replaceState(null, '', '#division-' + activeDrillId);
    } catch (error) {}
  }

  function clearDrill() {
    if (activeDrillId === null) return;

    divisionCards.forEach((card) => {
      Array.from(card.querySelectorAll('.orgMemberToggle[data-target]')).forEach((button) => {
        const key = button.getAttribute('data-target') || '';
        const wasOpen = drawerStateBeforeDrill.get(key) === true;
        setDrawerState(button, wasOpen);
      });

      Array.from(card.querySelectorAll('.orgSectionCard, .orgChiefSection')).forEach((section) => {
        section.hidden = false;
      });
    });

    activeDrillId = null;
    drawerStateBeforeDrill.clear();
    updateDrillUi();

    try {
      history.replaceState(null, '', location.pathname + location.search);
    } catch (error) {}

    filterChart();
  }

  searchInput?.addEventListener('input', filterChart);
  expandAllBtn?.addEventListener('click', () => toggles.forEach((button) => setDrawerState(button, true)));
  collapseAllBtn?.addEventListener('click', () => toggles.forEach((button) => setDrawerState(button, false)));
  resetFilterBtn?.addEventListener('click', () => {
    if (searchInput) searchInput.value = '';
    clearDrill();
    toggles.forEach((button) => setDrawerState(button, false));
    filterChart();
  });

  drillHints.forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const divisionId = button.getAttribute('data-drill');
      if (divisionId) enterDrill(divisionId);
    });
  });

  divisionCards.forEach((card) => {
    const head = card.querySelector('.orgDivisionHead');
    if (!head) return;
    head.style.cursor = 'pointer';
    head.setAttribute('title', 'Click to focus this division');
    head.addEventListener('click', (event) => {
      const clickedToggle = event.target instanceof Element && event.target.closest('.orgDrillHint, .orgMemberToggle, a, button, input');
      if (clickedToggle) return;
      const divisionId = card.dataset.divisionId;
      if (divisionId) enterDrill(divisionId);
    });
  });

  drillBackBtn?.addEventListener('click', clearDrill);

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      clearDrill();
    }
  });

  const hashMatch = window.location.hash.match(/^#division-(\d+)$/);
  if (hashMatch) {
    enterDrill(hashMatch[1]);
  } else {
    updateDrillUi();
    filterChart();
  }
})();
