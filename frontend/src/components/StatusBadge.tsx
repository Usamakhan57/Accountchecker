import type { JobStatus, ResultStatus, TicketStatus } from '@/types/api';

type BadgeTone = 'valid' | 'invalid' | 'unknown' | 'unavailable' | 'info' | 'neutral' | 'error';

const RESULT_TONES: Record<ResultStatus, BadgeTone> = {
  VALID: 'valid',
  INVALID: 'invalid',
  UNKNOWN: 'unknown',
  ERROR: 'error',
  UNAVAILABLE: 'unavailable',
};

const JOB_TONES: Record<JobStatus, BadgeTone> = {
  PENDING: 'neutral',
  QUEUED: 'neutral',
  PROCESSING: 'info',
  COMPLETED: 'valid',
  FAILED: 'invalid',
  CANCELLED: 'unknown',
};

const TICKET_TONES: Record<TicketStatus, BadgeTone> = {
  OPEN: 'info',
  PENDING: 'unavailable',
  RESOLVED: 'valid',
  CLOSED: 'neutral',
};

/** Human wording for the statuses whose meaning is not obvious from the word. */
const RESULT_TITLES: Record<ResultStatus, string> = {
  VALID: 'Confirmed by an authorized verification source',
  INVALID: 'The source reported this entry as not valid',
  UNKNOWN: 'The source answered without a definite result',
  ERROR: 'The check could not be completed after retries',
  UNAVAILABLE: 'No authorized verification source is available for this checker',
};

export function ResultStatusBadge({ status }: { status: ResultStatus }) {
  return (
    <span className={`ac-badge ac-badge--${RESULT_TONES[status]}`} title={RESULT_TITLES[status]}>
      {status}
    </span>
  );
}

export function JobStatusBadge({ status }: { status: JobStatus }) {
  return <span className={`ac-badge ac-badge--${JOB_TONES[status]}`}>{status}</span>;
}

export function TicketStatusBadge({ status }: { status: TicketStatus }) {
  return <span className={`ac-badge ac-badge--${TICKET_TONES[status]}`}>{status}</span>;
}
