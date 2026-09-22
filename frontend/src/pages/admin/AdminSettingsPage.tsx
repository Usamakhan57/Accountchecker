import { useState } from 'react';
import { Card } from '@/components/Card';
import { useToast } from '@/components/ToastProvider';
import { Alert, ErrorState, LoadingState } from '@/components/states';
import { useApiResource } from '@/hooks/useApiResource';
import { usePageMeta } from '@/hooks/usePageMeta';
import { listSettings, updateSetting } from '@/services/admin';
import { ApiError } from '@/services/apiClient';
import type { SystemSetting } from '@/types/api';

/**
 * System settings.
 *
 * Writing is restricted to a super administrator on the server. When the
 * signed-in administrator is not one, the controls are disabled and the page
 * says why, rather than letting a click fail.
 */
export function AdminSettingsPage() {
  usePageMeta({ title: 'System settings', noIndex: true, canonicalPath: '/admin/settings' });

  const toast = useToast();
  const { data, loading, error, reload } = useApiResource<{ items: SystemSetting[]; can_edit: boolean }>(
    (signal) => listSettings(signal),
    [],
  );

  const [busy, setBusy] = useState<string | null>(null);
  const canEdit = data?.can_edit ?? false;

  async function save(key: string, value: string, message: string) {
    setBusy(key);

    try {
      await updateSetting(key, value);
      toast.success(message);
      reload();
    } catch (saveError) {
      toast.error(saveError instanceof ApiError ? saveError.message : 'That setting could not be changed.');
    } finally {
      setBusy(null);
    }
  }

  if (error) {
    return <ErrorState message={error} onRetry={reload} />;
  }

  if (loading && !data) {
    return <LoadingState label="Loading settings…" />;
  }

  return (
    <>
      <header className="ac-page-header">
        <div>
          <h1 className="ac-page-header__title">System settings</h1>
          <p className="ac-page-header__subtitle">These apply to the whole installation.</p>
        </div>
      </header>

      {!canEdit && (
        <Alert tone="info">
          These settings are read-only for your role. A super administrator can change them, because some of
          them switch the product off for everyone.
        </Alert>
      )}

      <Card flush title="Settings">
        {(data?.items ?? []).map((setting) => {
          const isBoolean = setting.value_type === 'boolean';
          const current = setting.value;

          return (
            <div className="ac-setting" key={setting.setting_key}>
              <div className="ac-setting__main">
                <span className="ac-setting__key">{setting.setting_key}</span>
                <p className="ac-setting__description">{setting.description}</p>
              </div>

              <div className="ac-setting__control">
                {isBoolean ? (
                  <button
                    type="button"
                    className={`ac-btn ac-btn--sm ${current === true ? 'ac-btn--secondary' : 'ac-btn--primary'}`}
                    disabled={!canEdit || busy === setting.setting_key}
                    onClick={() =>
                      void save(
                        setting.setting_key,
                        current === true ? 'false' : 'true',
                        current === true ? 'Switched off.' : 'Switched on.',
                      )
                    }
                  >
                    {current === true ? 'On' : 'Off'}
                  </button>
                ) : (
                  <input
                    type={setting.value_type === 'integer' ? 'number' : 'text'}
                    className="ac-input ac-input--inline"
                    defaultValue={String(current ?? '')}
                    disabled={!canEdit || busy === setting.setting_key}
                    aria-label={setting.setting_key}
                    onBlur={(event) => {
                      const next = event.target.value.trim();

                      if (next !== String(current ?? '')) {
                        void save(setting.setting_key, next, 'Setting updated.');
                      }
                    }}
                  />
                )}
              </div>
            </div>
          );
        })}
      </Card>
    </>
  );
}
