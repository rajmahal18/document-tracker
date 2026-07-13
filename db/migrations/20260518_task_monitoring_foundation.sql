CREATE TABLE IF NOT EXISTS tms_user_profiles (
  user_id INT NOT NULL,
  can_manage_all_tasks TINYINT(1) NOT NULL DEFAULT 0,
  can_edit_templates TINYINT(1) NOT NULL DEFAULT 0,
  can_edit_task_types TINYINT(1) NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_tms_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_participant_role_presets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_label VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tms_role_presets_label (role_label),
  KEY idx_tms_role_presets_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  owner_division_id INT NULL,
  owner_section_id INT NULL,
  default_priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  default_workflow_template_id BIGINT UNSIGNED NULL,
  is_ipcr_relevant TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_by_user_id INT NULL,
  updated_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tms_task_types_code (code),
  KEY idx_tms_task_types_active_sort (is_active, sort_order),
  KEY idx_tms_task_types_owner_division (owner_division_id),
  KEY idx_tms_task_types_owner_section (owner_section_id),
  KEY idx_tms_task_types_default_template (default_workflow_template_id),
  CONSTRAINT fk_tms_task_types_owner_division FOREIGN KEY (owner_division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_types_owner_section FOREIGN KEY (owner_section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_types_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_types_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_workflow_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_type_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  flow_mode VARCHAR(20) NOT NULL DEFAULT 'sequential',
  owner_division_id INT NULL,
  owner_section_id INT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by_user_id INT NULL,
  updated_by_user_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tms_workflow_templates_type_name (task_type_id, name),
  KEY idx_tms_workflow_templates_type (task_type_id),
  KEY idx_tms_workflow_templates_active (is_active, name),
  KEY idx_tms_workflow_templates_scope (owner_division_id, owner_section_id),
  CONSTRAINT fk_tms_workflow_templates_type FOREIGN KEY (task_type_id) REFERENCES tms_task_types(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_workflow_templates_owner_division FOREIGN KEY (owner_division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_workflow_templates_owner_section FOREIGN KEY (owner_section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_workflow_templates_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_workflow_templates_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_workflow_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workflow_template_id BIGINT UNSIGNED NOT NULL,
  step_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  title VARCHAR(150) NOT NULL,
  instructions TEXT NULL,
  default_responsible_division_id INT NULL,
  default_responsible_section_id INT NULL,
  default_role_label VARCHAR(80) NULL,
  estimated_working_minutes INT UNSIGNED NULL,
  can_run_parallel TINYINT(1) NOT NULL DEFAULT 0,
  requires_output TINYINT(1) NOT NULL DEFAULT 0,
  requires_validation TINYINT(1) NOT NULL DEFAULT 0,
  is_ipcr_creditable TINYINT(1) NOT NULL DEFAULT 1,
  is_completion_step TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tms_workflow_steps_template_order (workflow_template_id, step_order),
  KEY idx_tms_workflow_steps_template_order (workflow_template_id, step_order),
  KEY idx_tms_workflow_steps_default_division (default_responsible_division_id),
  KEY idx_tms_workflow_steps_default_section (default_responsible_section_id),
  CONSTRAINT fk_tms_workflow_steps_template FOREIGN KEY (workflow_template_id) REFERENCES tms_workflow_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_workflow_steps_division FOREIGN KEY (default_responsible_division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_workflow_steps_section FOREIGN KEY (default_responsible_section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_workflow_transitions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workflow_template_id BIGINT UNSIGNED NOT NULL,
  from_step_id BIGINT UNSIGNED NULL,
  to_step_id BIGINT UNSIGNED NOT NULL,
  transition_label VARCHAR(80) NOT NULL DEFAULT 'Next',
  transition_type VARCHAR(32) NOT NULL DEFAULT 'next',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_workflow_transitions_template (workflow_template_id),
  KEY idx_tms_workflow_transitions_from (from_step_id),
  KEY idx_tms_workflow_transitions_to (to_step_id),
  CONSTRAINT fk_tms_workflow_transitions_template FOREIGN KEY (workflow_template_id) REFERENCES tms_workflow_templates(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_workflow_transitions_from FOREIGN KEY (from_step_id) REFERENCES tms_workflow_steps(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_workflow_transitions_to FOREIGN KEY (to_step_id) REFERENCES tms_workflow_steps(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_type_id INT UNSIGNED NOT NULL,
  workflow_template_id BIGINT UNSIGNED NULL,
  current_step_id BIGINT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  document_id INT NULL,
  created_by_user_id INT NOT NULL,
  updated_by_user_id INT NULL,
  owner_division_id INT NULL,
  owner_section_id INT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  flow_mode VARCHAR(20) NOT NULL DEFAULT 'sequential',
  lifecycle_status VARCHAR(32) NOT NULL DEFAULT 'OPEN',
  target_start_at DATETIME NULL,
  target_due_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  estimated_working_minutes INT UNSIGNED NULL,
  actual_working_minutes INT UNSIGNED NULL,
  context_json LONGTEXT NULL,
  ipcr_metadata_json LONGTEXT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_tasks_type (task_type_id),
  KEY idx_tms_tasks_template (workflow_template_id),
  KEY idx_tms_tasks_current_step (current_step_id),
  KEY idx_tms_tasks_project (project_id),
  KEY idx_tms_tasks_document (document_id),
  KEY idx_tms_tasks_creator (created_by_user_id),
  KEY idx_tms_tasks_scope (owner_division_id, owner_section_id),
  KEY idx_tms_tasks_status_due (lifecycle_status, target_due_at),
  KEY idx_tms_tasks_updated (updated_at),
  CONSTRAINT fk_tms_tasks_type FOREIGN KEY (task_type_id) REFERENCES tms_task_types(id),
  CONSTRAINT fk_tms_tasks_template FOREIGN KEY (workflow_template_id) REFERENCES tms_workflow_templates(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_tasks_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_tasks_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_tms_tasks_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_tasks_owner_division FOREIGN KEY (owner_division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_tasks_owner_section FOREIGN KEY (owner_section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  workflow_step_id BIGINT UNSIGNED NULL,
  step_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  title VARCHAR(150) NOT NULL,
  instructions TEXT NULL,
  responsible_division_id INT NULL,
  responsible_section_id INT NULL,
  responsible_user_id INT NULL,
  role_label VARCHAR(80) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  planned_start_at DATETIME NULL,
  planned_due_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  estimated_working_minutes INT UNSIGNED NULL,
  actual_working_minutes INT UNSIGNED NULL,
  can_run_parallel TINYINT(1) NOT NULL DEFAULT 0,
  requires_output TINYINT(1) NOT NULL DEFAULT 0,
  requires_validation TINYINT(1) NOT NULL DEFAULT 0,
  is_ipcr_creditable TINYINT(1) NOT NULL DEFAULT 1,
  is_completion_step TINYINT(1) NOT NULL DEFAULT 0,
  delay_reason TEXT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_task_steps_task_order (task_id, step_order),
  KEY idx_tms_task_steps_status (status),
  KEY idx_tms_task_steps_responsible_user (responsible_user_id),
  KEY idx_tms_task_steps_responsible_scope (responsible_division_id, responsible_section_id),
  KEY idx_tms_task_steps_due (planned_due_at),
  CONSTRAINT fk_tms_task_steps_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_steps_workflow_step FOREIGN KEY (workflow_step_id) REFERENCES tms_workflow_steps(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_steps_division FOREIGN KEY (responsible_division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_steps_section FOREIGN KEY (responsible_section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_steps_user FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_participants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  task_step_id BIGINT UNSIGNED NULL,
  user_id INT NOT NULL,
  division_id INT NULL,
  section_id INT NULL,
  participant_role_label VARCHAR(80) NOT NULL DEFAULT 'Contributor',
  participation_status VARCHAR(32) NOT NULL DEFAULT 'INVITED',
  is_lead TINYINT(1) NOT NULL DEFAULT 0,
  invited_by_user_id INT NULL,
  responded_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tms_task_participants_user_step (task_id, task_step_id, user_id),
  KEY idx_tms_task_participants_user (user_id),
  KEY idx_tms_task_participants_status (participation_status),
  KEY idx_tms_task_participants_scope (division_id, section_id),
  CONSTRAINT fk_tms_task_participants_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_participants_step FOREIGN KEY (task_step_id) REFERENCES tms_task_steps(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_participants_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_participants_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_participants_invited_by FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_outputs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  task_step_id BIGINT UNSIGNED NULL,
  uploaded_by_user_id INT NULL,
  title VARCHAR(180) NOT NULL,
  output_type VARCHAR(60) NOT NULL DEFAULT 'MOV',
  reference_url VARCHAR(500) NULL,
  file_path VARCHAR(500) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_task_outputs_task (task_id),
  KEY idx_tms_task_outputs_step (task_step_id),
  KEY idx_tms_task_outputs_user (uploaded_by_user_id),
  CONSTRAINT fk_tms_task_outputs_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_outputs_step FOREIGN KEY (task_step_id) REFERENCES tms_task_steps(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_outputs_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  task_step_id BIGINT UNSIGNED NULL,
  actor_user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  message VARCHAR(255) NULL,
  old_values_json LONGTEXT NULL,
  new_values_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_task_activity_task (task_id),
  KEY idx_tms_task_activity_step (task_step_id),
  KEY idx_tms_task_activity_actor (actor_user_id),
  KEY idx_tms_task_activity_created (created_at),
  CONSTRAINT fk_tms_task_activity_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_activity_step FOREIGN KEY (task_step_id) REFERENCES tms_task_steps(id) ON DELETE SET NULL,
  CONSTRAINT fk_tms_task_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_task_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id BIGINT UNSIGNED NOT NULL,
  task_type_id INT UNSIGNED NOT NULL,
  workflow_template_id BIGINT UNSIGNED NULL,
  metric_key VARCHAR(80) NOT NULL,
  metric_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  metric_unit VARCHAR(40) NOT NULL DEFAULT 'count',
  computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_task_metrics_task (task_id),
  KEY idx_tms_task_metrics_type_key (task_type_id, metric_key),
  CONSTRAINT fk_tms_task_metrics_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_metrics_type FOREIGN KEY (task_type_id) REFERENCES tms_task_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_task_metrics_template FOREIGN KEY (workflow_template_id) REFERENCES tms_workflow_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_label VARCHAR(120) NOT NULL,
  source_filename VARCHAR(255) NULL,
  imported_by_user_id INT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_import_batches_user (imported_by_user_id),
  CONSTRAINT fk_tms_import_batches_user FOREIGN KEY (imported_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tms_import_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NULL,
  task_type_code VARCHAR(64) NULL,
  legacy_key VARCHAR(120) NULL,
  raw_payload_json LONGTEXT NULL,
  import_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  import_message VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tms_import_rows_batch (batch_id),
  KEY idx_tms_import_rows_task (task_id),
  CONSTRAINT fk_tms_import_rows_batch FOREIGN KEY (batch_id) REFERENCES tms_import_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_tms_import_rows_task FOREIGN KEY (task_id) REFERENCES tms_tasks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tms_participant_role_presets (role_label, description, sort_order, is_active) VALUES
  ('Lead', 'Primary person responsible for the current work.', 10, 1),
  ('Contributor', 'Participant helping complete the work.', 20, 1),
  ('Reviewer', 'Person checking the output before completion.', 30, 1),
  ('Validator', 'Person validating completion or evidence.', 40, 1),
  ('Observer', 'Person included for monitoring only.', 50, 1),
  ('Requesting Office', 'Office or staff requesting the output.', 60, 1),
  ('Receiving Office', 'Office or staff receiving the output.', 70, 1)
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  sort_order = VALUES(sort_order),
  is_active = VALUES(is_active);

INSERT INTO tms_task_types (code, name, description, default_priority, is_ipcr_relevant, is_active, sort_order) VALUES
  ('general_task', 'General Task', 'Generic task for office work that does not need a specialized type yet.', 'normal', 1, 1, 10),
  ('document_review', 'Document Review', 'Review, comment, validate, or endorse a document or package.', 'normal', 1, 1, 20),
  ('field_work', 'Field Work', 'Inspection, survey, validation, or other work done outside the office.', 'normal', 1, 1, 30),
  ('report_output', 'Report or Output Preparation', 'Preparation of a report, memo, plan, dataset, or other deliverable.', 'normal', 1, 1, 40),
  ('coordination_task', 'Coordination Task', 'Cross-office coordination, follow-up, consolidation, or handoff work.', 'normal', 1, 1, 50)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  description = VALUES(description),
  default_priority = VALUES(default_priority),
  is_ipcr_relevant = VALUES(is_ipcr_relevant),
  is_active = VALUES(is_active),
  sort_order = VALUES(sort_order);

INSERT INTO tms_workflow_templates (task_type_id, name, description, flow_mode, is_default, is_active)
SELECT id, 'Basic Sequential Workflow', 'Start with one responsible step, then review or completion.', 'sequential', 1, 1
FROM tms_task_types
WHERE code = 'general_task'
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  flow_mode = VALUES(flow_mode),
  is_default = VALUES(is_default),
  is_active = VALUES(is_active);

INSERT INTO tms_workflow_templates (task_type_id, name, description, flow_mode, is_default, is_active)
SELECT id, 'Basic Parallel Workflow', 'Use when multiple participants can work at the same time.', 'parallel', 0, 1
FROM tms_task_types
WHERE code = 'coordination_task'
ON DUPLICATE KEY UPDATE
  description = VALUES(description),
  flow_mode = VALUES(flow_mode),
  is_default = VALUES(is_default),
  is_active = VALUES(is_active);

UPDATE tms_task_types tt
JOIN tms_workflow_templates wt ON wt.task_type_id = tt.id AND wt.is_default = 1
SET tt.default_workflow_template_id = wt.id
WHERE tt.code = 'general_task';

INSERT INTO tms_workflow_steps (
  workflow_template_id,
  step_order,
  title,
  instructions,
  default_role_label,
  estimated_working_minutes,
  can_run_parallel,
  requires_output,
  requires_validation,
  is_ipcr_creditable,
  is_completion_step
)
SELECT wt.id, 1, 'Do work', 'Complete the assigned work and add remarks or output when needed.', 'Lead', 480, 0, 0, 0, 1, 0
FROM tms_workflow_templates wt
WHERE wt.name = 'Basic Sequential Workflow'
  AND NOT EXISTS (
    SELECT 1 FROM tms_workflow_steps ws WHERE ws.workflow_template_id = wt.id AND ws.step_order = 1
  );

INSERT INTO tms_workflow_steps (
  workflow_template_id,
  step_order,
  title,
  instructions,
  default_role_label,
  estimated_working_minutes,
  can_run_parallel,
  requires_output,
  requires_validation,
  is_ipcr_creditable,
  is_completion_step
)
SELECT wt.id, 2, 'Review or close', 'Review the output if needed, then mark the work complete.', 'Reviewer', 240, 0, 0, 1, 1, 1
FROM tms_workflow_templates wt
WHERE wt.name = 'Basic Sequential Workflow'
  AND NOT EXISTS (
    SELECT 1 FROM tms_workflow_steps ws WHERE ws.workflow_template_id = wt.id AND ws.step_order = 2
  );

INSERT INTO tms_workflow_steps (
  workflow_template_id,
  step_order,
  title,
  instructions,
  default_role_label,
  estimated_working_minutes,
  can_run_parallel,
  requires_output,
  requires_validation,
  is_ipcr_creditable,
  is_completion_step
)
SELECT wt.id, 1, 'Shared work', 'Participants may work at the same time and coordinate updates in one task.', 'Contributor', 480, 1, 0, 0, 1, 0
FROM tms_workflow_templates wt
WHERE wt.name = 'Basic Parallel Workflow'
  AND NOT EXISTS (
    SELECT 1 FROM tms_workflow_steps ws WHERE ws.workflow_template_id = wt.id AND ws.step_order = 1
  );
