import { useState } from 'react';
import { Icon } from '@/components/Icon';
import { useToast } from '@/components/ToastProvider';
import { ApiError } from '@/services/apiClient';
import { downloadExport } from '@/services/exports';
import type { ExportFormat, ExportRecord } from '@/types/api';
import { formatNumber } from '@/utils/format';

interface ExportButtonProps {
  /** Creates the export server-side and resolves with its record. */
  onCreate: (format: ExportFormat) => Promise<ExportRecord>;
  disabled?: boolean;
  label?: string;
}

/**
 * Creates an export and hands the file to the browser.
 *
 * Two steps rather than a direct link: the server builds the file first, so a
 * large export does not hold a download open while rows are still being read,
 * and a failure arrives as a readable message instead of navigating the user
 * to a page of JSON.
 */
export function ExportButton({ onCreate, disabled, label = 'Export' }: ExportButtonProps) {
  const toast = useToast();
  const [busy, setBusy] = useState<ExportFormat | null>(null);

  async function run(format: ExportFormat) {
    setBusy(format);

    try {
      const record = await onCreate(format);
      await downloadExport(record);
      toast.success(`${formatNumber(record.row_count)} rows exported.`);
    } catch (error) {
      toast.error(error instanceof ApiError ? error.message : 'The export could not be created.');
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="ac-row" style={{ gap: '0.375rem' }}>
      <button
        type="button"
        className="ac-btn ac-btn--secondary ac-btn--sm"
        onClick={() => void run('csv')}
        disabled={disabled || busy !== null}
      >
        <Icon name="download" size={14} />
        {busy === 'csv' ? 'Preparing…' : `${label} CSV`}
      </button>
      <button
        type="button"
        className="ac-btn ac-btn--ghost ac-btn--sm"
        onClick={() => void run('txt')}
        disabled={disabled || busy !== null}
        title="The checked values only, one per line"
      >
        {busy === 'txt' ? 'Preparing…' : 'TXT'}
      </button>
    </div>
  );
}
