/**
 * Response envelope shared by every endpoint.
 *
 * The backend always answers with { success, message, ... }; these types are
 * the client-side mirror of backend/src/Core/Response.php.
 */
export interface ApiSuccess<T> {
  success: true;
  message: string;
  data: T;
}

export interface ApiFailure {
  success: false;
  message: string;
  error_code: string;
  errors?: Record<string, string[]>;
}

export type ApiEnvelope<T> = ApiSuccess<T> | ApiFailure;

export interface Pagination {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
  has_more: boolean;
}

export interface Paginated<T> {
  items: T[];
  pagination: Pagination;
}

export type Role = 'USER' | 'ADMIN' | 'SUPPORT' | 'MANAGER' | 'SUPER_ADMIN';

export type UserStatus = 'ACTIVE' | 'SUSPENDED' | 'PENDING';

export interface User {
  id: number;
  uuid: string;
  name: string;
  email: string;
  role: Role;
  status: UserStatus;
  email_verified: boolean;
  created_at: string;
  last_login_at: string | null;
}

export type CheckerCategory = 'email' | 'platform' | 'utility';

export interface CheckerType {
  id: number;
  slug: string;
  label: string;
  category: CheckerCategory;
  input_kind: 'email' | 'username';
  description: string;
  credit_cost: number;
  max_batch_size: number;
  enabled: boolean;
  /** False when no authorized verification source is configured for it. */
  configured: boolean;
  /** 'mock' when the server is running the deterministic local engine. */
  mode: 'mock' | 'production';
}

export type JobStatus =
  | 'PENDING'
  | 'QUEUED'
  | 'PROCESSING'
  | 'COMPLETED'
  | 'FAILED'
  | 'CANCELLED';

export interface Job {
  id: number;
  uuid: string;
  checker_slug: string;
  checker_label: string;
  status: JobStatus;
  total_items: number;
  processed_items: number;
  successful_items: number;
  failed_items: number;
  remaining_items: number;
  progress_percent: number;
  credits_reserved: number;
  credits_spent: number;
  error_message: string | null;
  source: 'PASTE' | 'UPLOAD' | 'API';
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
}

/** Counts per result status for one job. */
export type ResultBreakdown = Record<ResultStatus, number>;

export interface JobDetail extends Job {
  breakdown: ResultBreakdown;
  options: Record<string, unknown>;
}

/**
 * The polling payload.
 *
 * Deliberately smaller than Job: the workspace asks for this every couple of
 * seconds while a job runs, and stops as soon as `is_finished` is true.
 */
export interface JobProgress {
  id: number;
  uuid: string;
  status: JobStatus;
  total_items: number;
  processed_items: number;
  successful_items: number;
  failed_items: number;
  progress_percent: number;
  credits_reserved: number;
  credits_spent: number;
  error_message: string | null;
  started_at: string | null;
  completed_at: string | null;
  is_finished: boolean;
}

/** What the review step shows before any credits are committed. */
export interface InputReview {
  total_lines: number;
  valid_count: number;
  invalid_count: number;
  duplicate_count: number;
  accepted_count: number;
  over_limit: boolean;
  max_batch_size: number;
  credit_cost_each: number;
  credits_required: number;
  sample: string[];
  invalid_samples: { input: string; reason: string | null }[];
  configured: boolean;
  mode: 'mock' | 'production';
}

export interface JobSubmission {
  job: Job;
  skipped: { invalid: number; duplicate: number };
  mode: 'mock' | 'production';
  configured: boolean;
}

export type ResultStatus = 'VALID' | 'INVALID' | 'UNKNOWN' | 'ERROR' | 'UNAVAILABLE';

export interface CheckResult {
  id: number;
  job_id: number;
  input: string;
  normalized_input: string;
  status: ResultStatus;
  reason: string | null;
  source: string;
  checker_slug: string;
  checker_label: string;
  response_time_ms: number | null;
  checked_at: string;
}

/** Filters shared by the results table and the export it produces. */
export interface ResultFilters {
  page?: number;
  per_page?: number;
  status?: ResultStatus | '';
  checker?: string;
  search?: string;
  /** YYYY-MM-DD, inclusive. */
  from?: string;
  /** YYYY-MM-DD, inclusive. */
  to?: string;
  sort?: 'checked_at' | 'input' | 'status' | 'response_time';
  direction?: 'asc' | 'desc';
}

export interface ResultsPage extends Paginated<CheckResult> {
  /** Counts across the whole filtered set, not just the current page. */
  breakdown: ResultBreakdown;
}

export interface JobResultsPage extends ResultsPage {
  job: Job;
}

export interface HistoryEntry {
  id: number;
  job_id: number | null;
  checker_slug: string;
  checker_label: string;
  status: JobStatus;
  total_items: number;
  successful_items: number;
  failed_items: number;
  credits_spent: number;
  created_at: string;
}

export interface HistoryPage extends Paginated<HistoryEntry> {
  totals: { jobs: number; records: number; credits: number };
}

export type ExportFormat = 'csv' | 'txt';

export interface ExportRecord {
  uuid: string;
  job_id: number | null;
  format: ExportFormat;
  filename: string;
  row_count: number;
  size_bytes: number;
  status: 'READY' | 'EXPIRED' | 'DELETED';
  expires_at: string;
  created_at: string;
  /** Null once the export has expired; the file is gone by then. */
  download_path: string | null;
}

/* Free tools ------------------------------------------------------------- */

export type DuplicateMode = 'exact' | 'relaxed' | 'checker';

export interface DuplicateGroup {
  value: string;
  count: number;
  /** A few of the original lines that collapsed into this value. */
  lines: string[];
  positions: number[];
}

export interface DuplicateReport {
  mode: DuplicateMode;
  checker: string | null;
  total_lines: number;
  unique_count: number;
  /** Lines that would be removed, not the number of repeated values. */
  duplicate_count: number;
  repeated_values: number;
  unreadable_count: number;
  truncated: boolean;
  max_lines: number;
  unique: string[];
  groups: DuplicateGroup[];
}

export type NameStyle = 'plain' | 'numbers' | 'years' | 'separators' | 'prefixes' | 'suffixes';

export interface NameSuggestions {
  names: string[];
  count: number;
  rejected_count: number;
  shortened_words: { word: string; shortened: string }[];
  /** The same seed reproduces the same list. */
  seed: number;
  platform: string | null;
  styles: NameStyle[];
  rules: { min_length: number; max_length: number; allowed: string } | null;
  notice: string;
}

export interface Wallet {
  balance: number;
  reserved: number;
  available: number;
  currency: 'CREDITS';
  updated_at: string;
}

export type WalletTransactionType = 'CREDIT' | 'DEBIT' | 'REFUND' | 'ADJUSTMENT';

export interface WalletTransaction {
  id: number;
  amount: number;
  type: WalletTransactionType;
  balance_after: number;
  description: string;
  reference: string | null;
  created_at: string;
}

export interface Plan {
  id: number;
  slug: string;
  name: string;
  description: string;
  price_cents: number;
  currency: string;
  credits: number;
  features: string[];
  is_active: boolean;
  sort_order: number;
}

export interface Notification {
  id: number;
  type: string;
  title: string;
  body: string;
  link: string | null;
  read_at: string | null;
  created_at: string;
}

export type TicketStatus = 'OPEN' | 'PENDING' | 'RESOLVED' | 'CLOSED';

export interface SupportTicket {
  id: number;
  uuid: string;
  subject: string;
  status: TicketStatus;
  priority: 'LOW' | 'NORMAL' | 'HIGH';
  message_count: number;
  created_at: string;
  updated_at: string;
}

export interface SupportMessage {
  id: number;
  ticket_id: number;
  author_name: string;
  author_role: Role;
  body: string;
  created_at: string;
}

export interface DashboardStats {
  wallet: Wallet;
  totals: {
    jobs: number;
    checks: number;
    valid: number;
    invalid: number;
    unknown: number;
    unavailable: number;
    errors: number;
    credits_spent: number;
  };
  recent_jobs: Job[];
  usage_by_day: { date: string; checks: number }[];
}
