<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_login();

$pageTitle = 'Which Button Should I Click? - Document Tracker';

require __DIR__ . '/../includes/layout.php';
?>

<style>
  .buttonGuidePage { max-width: 980px; margin: 0 auto; display: grid; gap: 14px; }
  .buttonGuideHead { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .buttonGuideHeadActions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .buttonGuideTitle { margin: 0; font-size: 24px; line-height: 1.15; font-weight: 900; color: #0f172a; }
  .buttonGuideSub { margin: 4px 0 0; color: #5b6b81; font-size: 13px; font-weight: 700; }
  .guidePlainAction {
    appearance: none;
    border: 0;
    background: transparent;
    color: #0b3a66;
    font-size: 13px;
    font-weight: 900;
    line-height: 1.2;
    padding: 4px 0;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    position: relative;
  }
  .guidePlainAction::before {
    content: "";
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    border-radius: 999px;
    background: currentColor;
    opacity: 0.92;
    -webkit-mask: var(--guide-icon-mask) center / 13px 13px no-repeat;
    mask: var(--guide-icon-mask) center / 13px 13px no-repeat;
  }
  .guidePlainAction::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -2px;
    height: 2px;
    border-radius: 999px;
    background: currentColor;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.16s ease;
    opacity: 0.8;
  }
  .guidePlainAction:hover::after,
  .guidePlainAction:focus-visible::after { transform: scaleX(1); }
  .guidePlainAction:focus-visible { outline: none; }
  #btnToggleTagalog.guidePlainAction {
    --guide-icon-mask: url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='black' d='M12.9 15.6 8.5 11h2.6V9H7V5H5v4H1v2h5.5A15 15 0 0 1 1 18.3L2.2 20A17.8 17.8 0 0 0 8 13.8l3.6 3.8zM17 4h-2l-4.5 12h2l1.2-3h4.7l1.1 3h2zm-2.6 7 1.7-4.4 1.7 4.4z'/%3E%3C/svg%3E");
  }
  .buttonGuideBack.guidePlainAction {
    --guide-icon-mask: url("data:image/svg+xml,%3Csvg viewBox='0 0 24 24' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath fill='black' d='M10 6 8.6 7.4 13.2 12l-4.6 4.6L10 18l6-6z'/%3E%3C/svg%3E");
  }
  .buttonGuideGrid { display: grid; gap: 10px; }
  .buttonGuideRow { display: grid; grid-template-columns: minmax(170px, 250px) minmax(0, 1fr); gap: 12px; align-items: center; padding: 12px; border: 1px solid rgba(15,23,42,.10); border-radius: 14px; background: #fff; }
  .buttonGuidePreview { display: flex; align-items: center; }
  .buttonGuidePreview > button { width: 100%; justify-content: center; pointer-events: none; }
  .buttonGuideDesc { color: #334155; font-size: 13px; font-weight: 700; line-height: 1.4; }
  .buttonGuideTip { margin-top: 4px; color: #1e3a8a; font-size: 12px; font-weight: 800; }
  @media (max-width: 760px) {
    .buttonGuideTitle { font-size: 21px; }
    .buttonGuideRow { grid-template-columns: 1fr; gap: 8px; }
  }
  body[data-theme="dark"] .buttonGuideTitle { color: #e6f0ff; }
  body[data-theme="dark"] .buttonGuideSub { color: #b7c8dc; }
  body[data-theme="dark"] .buttonGuideRow { background: rgba(12,27,42,.72); border-color: rgba(160,181,205,.24); }
  body[data-theme="dark"] .buttonGuideDesc { color: #d8e7f8; }
  body[data-theme="dark"] .buttonGuideTip { color: #93c5fd; }
  body[data-theme="dark"] .guidePlainAction { color: #cae6ff; }
</style>

<section class="buttonGuidePage">
  <div class="buttonGuideHead">
    <div>
      <h2 class="buttonGuideTitle">Which Button Should I Click?</h2>
      <p class="buttonGuideSub" id="buttonGuideSubText">Quick guide for the action buttons in the document drawer.</p>
    </div>
    <div class="buttonGuideHeadActions">
      <button type="button" class="guidePlainAction" id="btnToggleTagalog">Translate to Tagalog</button>
      <a class="buttonGuideBack guidePlainAction" href="<?= htmlspecialchars(PUBLIC_PATH . '/documents.php', ENT_QUOTES, 'UTF-8') ?>">Back to Documents</a>
    </div>
  </div>

  <div class="buttonGuideGrid">
    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnToggleForward" data-no-loading>Forward</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this when you are done with your part and you want the recipient to receive the full document as the next actionable holder." data-tl="Ito yung pindutin mo kapag tapos ka na sa part mo at ready mo nang ipasa yung buong document sa next na gagalaw.">Use this when you are done with your part and you want the recipient to receive the full document as the next actionable holder.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnToggleAttachmentForward" data-no-loading>Forward attach</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this for selective routing. The main document stays with you, while specific attachment files are sent as task lanes to recipients." data-tl="Gamitin mo ito kung specific files lang ang ipapagawa mo. Nasa'yo pa rin yung main document, pero may mapapasa kang attachments sa iba bilang task.">Use this for selective routing. The main document stays with you, while specific attachment files are sent as task lanes to recipients.</div>
        <div class="buttonGuideTip" data-en="Best for parallel tasks on files without releasing your current lane." data-tl="Maganda ito kung sabay-sabay may gumagalaw sa files pero hawak mo pa rin ang lane ng document.">Best for parallel tasks on files without releasing your current lane.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnEditPendingRemarks" data-no-loading>Add remarks</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this to post a progress note while the document is still with you, or to update pending-route remarks before the recipient receives it." data-tl="Gamitin mo ito kung gusto mong mag-iwan ng quick update habang nasa'yo pa yung document, o para ma-edit mo yung note bago pa ma-receive ng next person.">Use this to post a progress note while the document is still with you, or to update pending-route remarks before the recipient receives it.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnGreen" id="btnAckReceived" data-no-loading>Received</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Click this when the document or assigned attachment task has reached you and you are formally acknowledging receipt." data-tl="Pindutin mo ito kapag natanggap mo na yung document or attachment task mo. Parang official na nasa akin na.">Click this when the document or assigned attachment task has reached you and you are formally acknowledging receipt.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnComp" id="btnAttachmentTaskDone" data-no-loading>Task done</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this after finishing your attachment-forward task so the sender sees your lane as completed." data-tl="Ito yung pindutin mo kapag tapos mo na yung attachment task mo para makita ng sender na done ka na.">Use this after finishing your attachment-forward task so the sender sees your lane as completed.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnGreen" id="btnRelease" data-no-loading>Release</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this when the document is physically released outside the active routing flow, especially if the destination division or entity is not in the Document Tracker system, so you can record where it was released. The same slot can become Undo Release when reopening from released state." data-tl="Ito ang gamitin mo kapag ipapasa na sa labas ng active routing flow, lalo na kung yung division or entity na padadalhan ay wala sa Document Tracker system, para ma-log mo kung saan napunta yung document. Yung same slot na ito puwedeng maging Undo Release kapag ire-reopen mula released state.">Use this when the document is physically released outside the active routing flow, especially if the destination division or entity is not in the Document Tracker system, so you can record where it was released. The same slot can become Undo Release when reopening from released state.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnComp" id="btnEndHere" data-no-loading>End Now</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this only when the selected lane workflow should stop completely and no further forwarding is needed." data-tl="Pindutin lang ito kapag final na talaga at wala nang susunod na forward sa lane na yan.">Use this only when the selected lane workflow should stop completely and no further forwarding is needed.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnComp" id="btnArchive" data-no-loading>Archive</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this after the document lifecycle is complete and you only need to keep it for record/reference. The same slot can become Undo Archive when moving it back to active record state." data-tl="Gamitin mo ito kapag tapos na buong lifecycle ng document at for record/reference na lang siya. Yung same slot na ito puwedeng maging Undo Archive kapag ibabalik mo sa active record state.">Use this after the document lifecycle is complete and you only need to keep it for record/reference. The same slot can become Undo Archive when moving it back to active record state.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnUndoEndHere" data-no-loading>Reopen Lifecycle</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="This is the undo action for End Now. Use this when a lane was ended by mistake and should continue routing." data-tl="Ito yung undo action ng End Now. Gamitin mo ito kapag na-end ang lane by mistake at kailangan ituloy ulit ang routing.">This is the undo action for End Now. Use this when a lane was ended by mistake and should continue routing.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnComp" id="btnViewDocument" data-no-loading>View document</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this to open and review the full assembled document file before deciding your next action." data-tl="Gamitin mo ito para buksan at i-review yung buong document bago ka mag-decide ng next action.">Use this to open and review the full assembled document file before deciding your next action.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnProjectManageToggle" data-no-loading>Add Project Code</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this when you need to tag the document under a project code for grouping, search, and reporting." data-tl="Pindutin mo ito kapag lalagyan mo ng project code yung document para mas madaling i-group, hanapin, at i-report.">Use this when you need to tag the document under a project code for grouping, search, and reporting.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnRegenerateDivisionSlip" data-no-loading>Generate latest slip</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this to generate the latest division tracking slip version when you need an updated print or attachment copy." data-tl="Gamitin mo ito para gumawa ng latest version ng division tracking slip kapag kailangan ng updated print or attachment copy.">Use this to generate the latest division tracking slip version when you need an updated print or attachment copy.</div>
      </div>
    </div>

    <div class="buttonGuideRow">
      <div class="buttonGuidePreview"><button type="button" class="btnSecondary" id="btnToggleUpload" data-no-loading>Add attachment</button></div>
      <div>
        <div class="buttonGuideDesc" data-en="Use this when you need to upload supporting files (PDF/image) related to the current document." data-tl="Ito yung pindutin mo kapag mag-a-upload ka ng supporting files (PDF/image) para sa current document.">Use this when you need to upload supporting files (PDF/image) related to the current document.</div>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  const btn = document.getElementById('btnToggleTagalog');
  const sub = document.getElementById('buttonGuideSubText');
  if (!btn) return;

  let mode = 'en';
  const nodes = Array.from(document.querySelectorAll('[data-en][data-tl]'));

  function apply(nextMode) {
    mode = nextMode;
    const isTl = mode === 'tl';
    nodes.forEach((el) => {
      el.textContent = isTl ? (el.getAttribute('data-tl') || '') : (el.getAttribute('data-en') || '');
    });
    if (sub) {
      sub.textContent = isTl
        ? 'Quick guide ito para sa action buttons sa document drawer.'
        : 'Quick guide for the action buttons in the document drawer.';
    }
    btn.textContent = isTl ? 'Translate to English' : 'Translate to Tagalog';
  }

  btn.addEventListener('click', () => {
    apply(mode === 'en' ? 'tl' : 'en');
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
