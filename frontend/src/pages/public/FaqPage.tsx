import { usePageMeta } from '@/hooks/usePageMeta';

const FAQS = [
  {
    question: 'What does each result status mean?',
    answer:
      'VALID means an authorized source confirmed the record. INVALID means the source reported it as not valid. UNKNOWN means the source answered without a definite result. ERROR means the check failed after retries. UNAVAILABLE means no authorized verification source exists or is configured for that checker, so no check was attempted.',
  },
  {
    question: 'How large can a batch be?',
    answer:
      'Up to 5,000 records per job by default, and an administrator can lower the limit per checker. Larger lists are split into several jobs.',
  },
  {
    question: 'Why did my job report UNAVAILABLE for every record?',
    answer:
      'That checker has no authorized verification source configured on the server. Nothing was attempted, and reserved credits are refunded. An administrator can configure a provider under the admin checker settings.',
  },
  {
    question: 'Does AccountCheck test passwords or log in to accounts?',
    answer:
      'No. AccountCheck never tests credentials, attempts logins, bypasses CAPTCHAs or rate limits, reuses session cookies, or enumerates accounts against endpoints that do not permit it. Verification runs only through official and authorized APIs and publicly permitted data sources.',
  },
  {
    question: 'How are credits charged?',
    answer:
      'Each checker has a credit cost per record. Starting a job reserves the full amount; when the job finishes, only the records actually processed are charged and the rest is refunded to your wallet. Cancelling a job refunds everything not yet processed.',
  },
  {
    question: 'Are the generated names available to register?',
    answer:
      'The name generator produces suggestions from your inputs. It does not check availability on any platform, and AccountCheck never claims a name is available unless an authorized availability API confirms it.',
  },
  {
    question: 'Can I export my results?',
    answer:
      'Yes — CSV and TXT, for the filter you currently have applied. Export files are written to a controlled directory, are only downloadable by the account that created them, and are removed automatically after the retention period.',
  },
];

export function FaqPage() {
  usePageMeta({
    title: 'FAQ',
    description:
      'Answers about result statuses, batch sizes, credit charging, exports and what AccountCheck deliberately does not do.',
    canonicalPath: '/faq',
  });

  return (
    <section style={{ padding: 'var(--ac-space-12) 0' }}>
      <div className="ac-container" style={{ maxWidth: 820 }}>
        <h1>Frequently asked questions</h1>

        <div className="ac-stack" style={{ marginTop: 'var(--ac-space-8)' }}>
          {FAQS.map((faq) => (
            <details
              key={faq.question}
              className="ac-card"
              style={{ padding: 'var(--ac-space-5) var(--ac-space-6)' }}
            >
              <summary style={{ cursor: 'pointer', fontWeight: 650, listStyle: 'revert' }}>{faq.question}</summary>
              <p style={{ marginTop: 'var(--ac-space-3)', color: 'var(--ac-text-muted)', fontSize: 'var(--ac-text-sm)' }}>
                {faq.answer}
              </p>
            </details>
          ))}
        </div>
      </div>
    </section>
  );
}
