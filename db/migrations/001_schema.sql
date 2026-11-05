-- LMS core schema (PostgreSQL 16). Academic records use RESTRICT, never cascade.
CREATE TABLE roles (
  id SMALLINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE
);
INSERT INTO roles(name) VALUES ('administrator'),('instructor'),('learner'),('support');

CREATE TABLE users (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(254) NOT NULL,
  password_hash TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','deactivated')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  CONSTRAINT users_email_lower CHECK (email = lower(email))
);
CREATE UNIQUE INDEX users_email_key ON users(email);

CREATE TABLE user_roles (
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  role_id SMALLINT NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
  PRIMARY KEY (user_id, role_id)
);

CREATE TABLE academic_terms (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  starts_at DATE NOT NULL,
  ends_at DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'planned' CHECK (status IN ('planned','active','closed')),
  CHECK (ends_at > starts_at)
);

CREATE TABLE courses (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','archived')),
  term_id BIGINT REFERENCES academic_terms(id) ON DELETE RESTRICT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE course_sections (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
  section_code VARCHAR(20) NOT NULL,
  instructor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE (course_id, section_code)
);
CREATE INDEX sections_instructor_idx ON course_sections(instructor_id);

CREATE TABLE enrollments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  section_id BIGINT NOT NULL REFERENCES course_sections(id) ON DELETE RESTRICT,
  learner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active','dropped','completed')),
  enrolled_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (section_id, learner_id)
);
CREATE INDEX enrollments_learner_idx ON enrollments(learner_id, status);

CREATE TABLE modules (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
  title VARCHAR(200) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  published_at TIMESTAMPTZ
);
CREATE INDEX modules_order_idx ON modules(course_id, position);

CREATE TABLE lessons (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  module_id BIGINT NOT NULL REFERENCES modules(id) ON DELETE RESTRICT,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  position INT NOT NULL DEFAULT 0,
  published_at TIMESTAMPTZ
);
CREATE INDEX lessons_order_idx ON lessons(module_id, position);

CREATE TABLE lesson_progress (
  learner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  lesson_id BIGINT NOT NULL REFERENCES lessons(id) ON DELETE RESTRICT,
  completed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (learner_id, lesson_id)
);

CREATE TABLE resources (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  lesson_id BIGINT NOT NULL REFERENCES lessons(id) ON DELETE RESTRICT,
  storage_key VARCHAR(120) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  size_bytes BIGINT NOT NULL CHECK (size_bytes >= 0),
  uploaded_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT
);

CREATE TABLE announcements (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
  author_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  published_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX announcements_course_idx ON announcements(course_id, published_at DESC);

CREATE TABLE assignments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
  title VARCHAR(200) NOT NULL,
  instructions TEXT NOT NULL DEFAULT '',
  due_at TIMESTAMPTZ NOT NULL,
  max_points NUMERIC(6,2) NOT NULL CHECK (max_points > 0),
  allow_late BOOLEAN NOT NULL DEFAULT TRUE,
  published_at TIMESTAMPTZ,
  created_by BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX assignments_course_idx ON assignments(course_id, due_at);

CREATE TABLE submissions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  assignment_id BIGINT NOT NULL REFERENCES assignments(id) ON DELETE RESTRICT,
  learner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  submitted_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  status VARCHAR(20) NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted','graded')),
  body TEXT,
  storage_key VARCHAR(120),
  filename VARCHAR(255),
  is_late BOOLEAN NOT NULL,
  UNIQUE (assignment_id, learner_id),
  CHECK (body IS NOT NULL OR storage_key IS NOT NULL)
);
CREATE INDEX submissions_learner_idx ON submissions(learner_id);

CREATE TABLE grades (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  submission_id BIGINT NOT NULL UNIQUE REFERENCES submissions(id) ON DELETE RESTRICT,
  grader_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  points NUMERIC(6,2) NOT NULL CHECK (points >= 0),
  feedback TEXT,
  graded_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Quiz tables are in place for phase 3 (endpoints not built yet).
CREATE TABLE quizzes (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  course_id BIGINT NOT NULL REFERENCES courses(id) ON DELETE RESTRICT,
  title VARCHAR(200) NOT NULL,
  opens_at TIMESTAMPTZ,
  closes_at TIMESTAMPTZ,
  duration_minutes INT CHECK (duration_minutes > 0),
  max_attempts INT NOT NULL DEFAULT 1 CHECK (max_attempts > 0),
  settings JSONB NOT NULL DEFAULT '{}',
  published_at TIMESTAMPTZ
);
CREATE TABLE questions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  quiz_id BIGINT NOT NULL REFERENCES quizzes(id) ON DELETE RESTRICT,
  type VARCHAR(20) NOT NULL CHECK (type IN ('single','multiple','short')),
  prompt TEXT NOT NULL,
  points NUMERIC(6,2) NOT NULL DEFAULT 1,
  position INT NOT NULL DEFAULT 0,
  options JSONB,
  answer JSONB
);
CREATE TABLE quiz_attempts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  quiz_id BIGINT NOT NULL REFERENCES quizzes(id) ON DELETE RESTRICT,
  learner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  started_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  submitted_at TIMESTAMPTZ,
  score NUMERIC(6,2),
  answers JSONB NOT NULL DEFAULT '{}'
);

CREATE TABLE notifications (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
  type VARCHAR(40) NOT NULL,
  payload JSONB NOT NULL DEFAULT '{}',
  read_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX notifications_user_idx ON notifications(user_id, created_at DESC);

CREATE TABLE audit_logs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_id BIGINT REFERENCES users(id) ON DELETE RESTRICT,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT,
  metadata JSONB NOT NULL DEFAULT '{}',
  ip VARCHAR(45),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX audit_created_idx ON audit_logs(created_at DESC);
CREATE INDEX audit_actor_idx ON audit_logs(actor_id);

-- Audit rows can be added but never changed or removed.
CREATE FUNCTION audit_immutable() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END $$ LANGUAGE plpgsql;
CREATE TRIGGER audit_no_change BEFORE UPDATE OR DELETE ON audit_logs
  FOR EACH ROW EXECUTE FUNCTION audit_immutable();

CREATE TABLE login_attempts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email VARCHAR(254) NOT NULL,
  ip VARCHAR(45),
  succeeded BOOLEAN NOT NULL,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX login_attempts_idx ON login_attempts(email, attempted_at DESC);
