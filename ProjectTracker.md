MPW Document Tracker – Progress

Day 1 — Feb 3, 2026

Login UI (MPW style)
Full-width layout
Logo integrated

Day 2 — Feb 4, 2026

XAMPP + MySQL setup working
Login / logout with sessions
Protected documents page
Modern dashboard (cards, filters, search)
Drawer for document details
Status actions (Under Action / Release / Archive) with remarks
Add Document page (receiving)
Auto “received” history log
Timeline view of document history in drawer

Day 3 — Feb 5, 2026

Implemented Received button and ack_received.php
Introduced created action in timeline (proper first event)
Discovered ENUM issue causing empty action values in doc_history
Identified core visibility flaw: documents visibility relied only on doc_history
Observed inconsistency where current_section_id was updated but history rows did not reflect section movement

Agreed on new design principle:
    documents.current_section_id = source of truth for current holder
    doc_history = audit trail only
    Defined target behavior for visibility (to implement next):
    Any section that was ever involved in a document should always see it, even after forwarding to another division

Planned refactor for tomorrow:
    Fix visibility rule in documents.php
    Normalize all history inserts to include from_section_id and to_section_id
    Clean up timeline semantics: Created ≠ Received
    Align system behavior with actual paper flow in MPW offices

Day 4 — Feb 10, 2026

Major refactor of document movement and visibility
Finalized separation of lifecycle status vs movement (routes)

Implemented forwarding flow
    Added Forward UI for current holder
    Created forward.php
    Enforced one open route per document
    Auto-added destination section to document_participants
Fixed receive-after-forward issue
    Corrected open route handling
    Ensured destination section can receive forwarded documents
    Stabilized ack_received.php logic
Visibility behavior finalized
    Any section ever involved retains visibility via document_participants
    Visibility no longer depends on document_events
Database cleanup
    Fixed routes unique/index constraints to prevent duplicate open routes
    Normalized movement data consistency
Timeline refactor started
    Switched timeline source fully to document_events
    Added human-readable titles and movement meta (from → to)
    Began UI redesign after schema change (visual polish pending)