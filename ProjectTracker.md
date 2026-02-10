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

Day 4 — Feb 10, 2026 (CURRENT STATE)

Major refactor completed
Database (finalized model)
    documents
        lifecycle status (ACTIVE | RELEASED | ARCHIVED)
        current_holder_section_id
routes
    models movement
    only one open route per document
document_participants
    permanent visibility list
document_events
    normalized audit trail (created, forwarded, received, released, archived)