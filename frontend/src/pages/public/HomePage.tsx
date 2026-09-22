import { Link } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import type { IconName } from '@/components/Icon';
import { usePageMeta } from '@/hooks/usePageMeta';

const CAPABILITIES: { icon: IconName; title: string; body: string }[] = [
  {
    icon: 'mail',
    title: 'Email verification',
    body: 'Validate address syntax, strip duplicates and, where an authorized verification source is configured, resolve deliverability — with format problems reported separately from unavailable verification.',
  },
  {
    icon: 'platform',
    title: 'Platform handles',
    body: 'Instagram, Facebook, X, TikTok and Threads run through a common adapter layer. A platform with no authorized API reports UNAVAILABLE instead of guessing.',
  },
  {
    icon: 'jobs',
    title: 'Batches up to 5,000',
    body: 'Large lists become queued jobs processed by a background worker in chunks, so a batch never blocks a browser request or times out mid-run.',
  },
  {
    icon: 'results',
    title: 'Results you can work with',
    body: 'Search, filter by status and checker, sort and page through results server-side, then export the slice you need as CSV or TXT.',
  },
  {
    icon: 'duplicate',
    title: 'Duplicate finder',
    body: 'Paste a list and get unique entries, duplicates and counts. Runs entirely on your own data with no external API and no credit cost.',
  },
  {
    icon: 'wallet',
    title: 'Credit wallet',
    body: 'Every check has a published credit cost. Credits are reserved when a job starts and refunded for anything the worker could not complete.',
  },
];

const FLOW = [
  { step: '01', title: 'Choose a checker', body: 'Pick the checker and review its credit cost and batch limit before anything runs.' },
  { step: '02', title: 'Add your records', body: 'Paste one record per line or upload a TXT or CSV file. Input is validated and de-duplicated first.' },
  { step: '03', title: 'Start the job', body: 'Credits are reserved, the job is queued and the worker processes it in chunks.' },
  { step: '04', title: 'Watch it run', body: 'Live counts for processed, successful, failed and remaining while the job is in flight.' },
  { step: '05', title: 'Export the result', body: 'Filter to the statuses you want, then export as CSV or TXT.' },
];

export function HomePage() {
  usePageMeta({
    title: 'AccountCheck',
    description:
      'AccountCheck runs email and platform handle verification in batches of up to 5,000 records through authorized APIs, with live progress, filterable results and exports.',
    canonicalPath: '/',
  });

  return (
    <>
      <section
        style={{
          padding: 'var(--ac-space-16) 0 var(--ac-space-12)',
          background:
            'radial-gradient(1000px 520px at 15% -10%, rgb(79 91 240 / 14%), transparent 60%), radial-gradient(760px 420px at 92% 0%, rgb(34 184 207 / 12%), transparent 55%)',
        }}
      >
        <div className="ac-container">
          <span className="ac-badge ac-badge--info">Authorized verification only</span>
          <h1
            style={{
              marginTop: 'var(--ac-space-4)',
              maxWidth: '18ch',
              fontSize: 'clamp(2rem, 5vw, 3.25rem)',
              letterSpacing: '-0.03em',
            }}
          >
            Verify long lists without babysitting a browser tab.
          </h1>
          <p style={{ marginTop: 'var(--ac-space-4)', maxWidth: '62ch', fontSize: 'var(--ac-text-lg)', color: 'var(--ac-text-muted)' }}>
            Paste up to 5,000 records, start a job and let a background worker do the run. AccountCheck
            reports each record as VALID, INVALID, UNKNOWN, ERROR or UNAVAILABLE, so you always know the
            difference between a bad record and a check that could not be performed.
          </p>

          <div className="ac-row" style={{ marginTop: 'var(--ac-space-8)', gap: 'var(--ac-space-3)' }}>
            <Link to="/register" className="ac-btn ac-btn--primary ac-btn--lg">
              Create an account
            </Link>
            <Link to="/pricing" className="ac-btn ac-btn--secondary ac-btn--lg">
              See plans
            </Link>
          </div>
        </div>
      </section>

      <section style={{ padding: 'var(--ac-space-12) 0' }}>
        <div className="ac-container">
          <h2>What it does</h2>
          <div className="ac-grid" style={{ marginTop: 'var(--ac-space-6)', gridTemplateColumns: 'repeat(auto-fit, minmax(290px, 1fr))' }}>
            {CAPABILITIES.map((capability) => (
              <article className="ac-card" key={capability.title}>
                <div className="ac-card__body">
                  <span
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      width: 40,
                      height: 40,
                      borderRadius: 'var(--ac-radius-md)',
                      background: 'var(--ac-accent-soft)',
                      color: 'var(--ac-accent)',
                    }}
                  >
                    <Icon name={capability.icon} size={20} />
                  </span>
                  <h3 style={{ marginTop: 'var(--ac-space-4)', fontSize: 'var(--ac-text-md)' }}>{capability.title}</h3>
                  <p style={{ marginTop: 'var(--ac-space-2)', fontSize: 'var(--ac-text-sm)', color: 'var(--ac-text-muted)' }}>
                    {capability.body}
                  </p>
                </div>
              </article>
            ))}
          </div>
        </div>
      </section>

      <section style={{ padding: 'var(--ac-space-12) 0', background: 'var(--ac-surface)', borderBlock: '1px solid var(--ac-border)' }}>
        <div className="ac-container">
          <h2>How a run works</h2>
          <ol role="list" className="ac-grid" style={{ marginTop: 'var(--ac-space-6)', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
            {FLOW.map((entry) => (
              <li key={entry.step}>
                <p className="ac-mono" style={{ color: 'var(--ac-accent)', fontWeight: 600 }}>{entry.step}</p>
                <p style={{ marginTop: 'var(--ac-space-2)', fontWeight: 650 }}>{entry.title}</p>
                <p style={{ marginTop: 'var(--ac-space-1)', fontSize: 'var(--ac-text-sm)', color: 'var(--ac-text-muted)' }}>
                  {entry.body}
                </p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section style={{ padding: 'var(--ac-space-12) 0' }}>
        <div className="ac-container">
          <div className="ac-card">
            <div className="ac-card__body">
              <h2 style={{ fontSize: 'var(--ac-text-lg)' }}>What AccountCheck will not do</h2>
              <p style={{ marginTop: 'var(--ac-space-3)', maxWidth: '72ch', color: 'var(--ac-text-muted)', fontSize: 'var(--ac-text-sm)' }}>
                Checks run through official and authorized APIs and publicly permitted data sources. AccountCheck
                does not test passwords, attempt logins, bypass CAPTCHAs or rate limits, reuse session cookies, or
                enumerate accounts against endpoints that do not permit it. Where a platform offers no authorized
                verification method, the result is reported as UNAVAILABLE rather than worked around.
              </p>
            </div>
          </div>
        </div>
      </section>
    </>
  );
}
