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
  /** Signed: positive added, negative spent. */
  amount: number;
  type: WalletTransactionType;
  balance_after: number;
  description: string;
  reference: string | null;
  created_at: string;
}

/** A running job and the credits it is currently holding. */
export interface WalletHold {
  uuid: string;
  checker_label: string;
  status: JobStatus;
  credits_reserved: number;
  credits_spent: number;
  total_items: number;
  processed_items: number;
}

export interface WalletSummary {
  wallet: Wallet;
  totals: { added: number; spent: number; refunded: number; adjusted: number; entries: number };
  spend_by_day: { date: string; credits: number }[];
  holds: WalletHold[];
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
  is_free: boolean;
  /** Cost of one credit in minor units, or null for a free plan. */
  cents_per_credit: number | null;
}

/**
 * Whether a plan can actually be bought.
 *
 * Travels with the catalogue rather than being fetched separately, because a
 * price shown without this is misleading.
 */
export interface PaymentStatus {
  configured: boolean;
  driver: string | null;
  currency: string;
  reason: string;
  contact_email: string | null;
}

export interface PlanCatalogue {
  items: Plan[];
  payments: PaymentStatus;
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
  last_reply_at: string | null;
  /** False once a ticket is closed; the reply box hides rather than failing. */
  can_reply: boolean;
}

export interface SupportMessage {
  id: number;
  ticket_id: number;
  /**
   * A staff reply reads as the team rather than the agent who wrote it, so no
   * individual support account is exposed to a customer.
   */
  author_name: string;
  is_staff: boolean;
  body: string;
  created_at: string;
}

export interface SupportThread {
  ticket: SupportTicket;
  messages: SupportMessage[];
}

/** A queue row, which carries the customer. */
export interface AdminTicket extends SupportTicket {
  user: { uuid: string; name: string; email: string };
}

export interface AdminTicketThread {
  ticket: AdminTicket;
  messages: SupportMessage[];
}

export interface NotificationList extends Paginated<Notification> {
  unread_count: number;
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

/* Administration ---------------------------------------------------------- */

export interface AdminUser {
  id: number;
  uuid: string;
  name: string;
  email: string;
  role: string;
  status: 'ACTIVE' | 'SUSPENDED' | 'PENDING';
  email_verified: boolean;
  wallet_balance: number;
  wallet_reserved: number;
  created_at: string;
  last_login_at: string | null;
}

/** A role as the admin panel lists it, read from the roles table. */
export interface RoleOption {
  slug: string;
  name: string;
  description: string;
  is_staff: boolean;
}

export interface AdminUserList extends Paginated<AdminUser> {
  counts: Record<string, number>;
  roles: RoleOption[];
}

export interface AdminUserDetail {
  user: AdminUser;
  wallet: Wallet;
  wallet_totals: { added: number; spent: number; refunded: number; adjusted: number; entries: number };
  holds: WalletHold[];
  recent_jobs: Job[];
  recent_activity: { id: number; action: string; description: string; created_at: string }[];
  roles: RoleOption[];
}

/** A job row in the admin list, which carries its owner. */
export interface AdminJob extends Job {
  user: { uuid: string; name: string; email: string };
}

export interface AdminJobList extends Paginated<AdminJob> {
  queue_depth: number;
}

export interface AdminTransaction extends WalletTransaction {
  user: { uuid: string; email: string };
}

export interface AdminTransactionList extends Paginated<AdminTransaction> {
  credits: { outstanding: number; reserved: number };
}

export interface AdminChecker {
  id: number;
  slug: string;
  label: string;
  description: string;
  credit_cost: number;
  max_batch_size: number;
  is_enabled: boolean;
  usage_30d: number;
  configured: boolean;
  mode: string;
}

export interface SystemSetting {
  setting_key: string;
  value_type: string;
  description: string;
  is_public: boolean;
  updated_at: string;
  value: unknown;
}

export interface AuditEntry {
  id: number;
  event: string;
  severity: 'INFO' | 'NOTICE' | 'WARNING' | 'CRITICAL';
  actor_id: number | null;
  actor_role: string;
  actor_email: string | null;
  target_type: string | null;
  target_id: number | null;
  context: Record<string, unknown> | null;
  ip_address: string | null;
  created_at: string;
}

export interface AuditList extends Paginated<AuditEntry> {
  events: string[];
}

export interface AdminOverview {
  totals: {
    users: { total: number; active: number; suspended: number; new_this_week: number };
    jobs: { total: number; queued: number; processing: number; failed: number; checks: number };
    credits: { outstanding: number; reserved: number };
    support: { open: number; pending: number };
  };
  health: {
    worker: {
      status: 'ok' | 'stale';
      worker_id: string | null;
      hostname: string | null;
      seconds_since_heartbeat: number | null;
      jobs_processed: number;
      items_processed: number;
    };
    queue: { pending_items: number; stalled_jobs: number };
    errors_last_hour: number;
  };
  checker_usage: { slug: string; label: string; checks: number; valid: number; unavailable: number; errors: number }[];
  recent_jobs: AdminJob[];
  recent_events: AuditEntry[];
}
