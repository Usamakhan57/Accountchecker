import { Link } from 'react-router-dom';
import { usePageMeta } from '@/hooks/usePageMeta';

const SECTIONS = [
  {
    title: 'Checker workspace',
    points: [
      'Paste records one per line, or upload a TXT or CSV file up to the configured size limit.',
      'Input is validated and de-duplicated before a job is created, so you are not charged for the same record twice.',
      'The review step shows exactly how many records will run and how many credits the job will reserve.',
    ],
  },
  {
    title: 'Background processing',
    points: [
      'Jobs of up to 5,000 records are queued and processed by a PHP worker in chunks.',
      'Progress is polled while a job runs: processed, successful, failed and remaining update live.',
      'A job can be cancelled while it is queued or processing; unspent reserved credits are refunded.',
    ],
  },
  {
    title: 'Results and exports',
    points: [
      'Search, filter by status, checker and date, and sort — all paged server-side.',
      'Each row carries the input, the status, the reason, the source and the response time.',
      'Export the current filter as CSV or TXT. Export files live in a controlled directory and expire.',
    ],
  },
  {
    title: 'Wallet and plans',
    points: [
      'Every check has a published credit cost, set per checker by an administrator.',
      'Credits are reserved when a job starts and settled when it finishes; anything unprocessed is refunded.',
      'All wallet movement is recorded as a transaction with a running balance.',
    ],
  },
  {
    title: 'Free tools',
    points: [
      'The duplicate finder works entirely on the list you paste, with no external API and no credit cost.',
      'The name generator produces suggestions from your keywords and style options.',
      'Generated names are suggestions only — AccountCheck does not claim availability without an authorized source.',
    ],
  },
];

export function FeaturesPage() {
  usePageMeta({
    title: 'Features',
    description:
      'Checker workspace, background batch processing, filterable results, exports, credit wallet and free duplicate and name tools.',
    canonicalPath: '/features',
  });

  return (
    <section style={{ padding: 'var(--ac-space-12) 0' }}>
      <div className="ac-container">
        <h1>Features</h1>
        <p style={{ marginTop: 'var(--ac-space-3)', maxWidth: '68ch', color: 'var(--ac-text-muted)' }}>
          Everything below is part of the product. Where a capability depends on an authorized provider being
          configured, the interface says so rather than pretending the check ran.
        </p>

        <div className="ac-stack ac-stack--lg" style={{ marginTop: 'var(--ac-space-8)' }}>
          {SECTIONS.map((section) => (
            <article className="ac-card" key={section.title}>
              <div className="ac-card__body">
                <h2 style={{ fontSize: 'var(--ac-text-md)' }}>{section.title}</h2>
                <ul style={{ marginTop: 'var(--ac-space-3)', paddingLeft: '1.1rem', color: 'var(--ac-text-muted)', fontSize: 'var(--ac-text-sm)' }}>
                  {section.points.map((point) => (
                    <li key={point} style={{ marginBottom: 'var(--ac-space-2)' }}>
                      {point}
                    </li>
                  ))}
                </ul>
              </div>
            </article>
          ))}
        </div>

        <div className="ac-row" style={{ marginTop: 'var(--ac-space-8)' }}>
          <Link to="/register" className="ac-btn ac-btn--primary">
            Create an account
          </Link>
          <Link to="/pricing" className="ac-btn ac-btn--secondary">
            See plans
          </Link>
        </div>
      </div>
    </section>
  );
}
