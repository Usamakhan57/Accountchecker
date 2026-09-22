import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '@/services/apiClient';
import { getJobProgress } from '@/services/jobs';
import type { JobProgress } from '@/types/api';

interface JobProgressResult {
  progress: JobProgress | null;
  loading: boolean;
  error: string | null;
  /** True while the job is still running and the poll is scheduled. */
  polling: boolean;
  refresh: () => void;
}

/**
 * Polls a job's progress until it finishes.
 *
 * Polling rather than a socket: a job's state is a handful of counters that
 * change a few times a second at most, so a small periodic GET is cheaper to
 * run and far simpler to operate than a persistent connection — and it keeps
 * working through a reload, a proxy or a flaky network.
 *
 * Three things keep the polling well-behaved:
 *
 *   - It stops the moment the server says `is_finished`, so a finished job
 *     costs nothing.
 *   - It pauses while the tab is hidden and catches up on the way back, so a
 *     forgotten tab is not still requesting an hour later.
 *   - Each request is aborted if it is superseded or the component unmounts, so
 *     a slow response can never overwrite a newer one.
 */
export function useJobProgress(
  reference: string | number | null,
  options: { intervalMs?: number; enabled?: boolean; onFinished?: (progress: JobProgress) => void } = {},
): JobProgressResult {
  const { intervalMs = 2000, enabled = true } = options;

  const [progress, setProgress] = useState<JobProgress | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [polling, setPolling] = useState(false);

  const onFinishedRef = useRef(options.onFinished);
  onFinishedRef.current = options.onFinished;

  const [refreshToken, setRefreshToken] = useState(0);
  const refresh = useCallback(() => setRefreshToken((token) => token + 1), []);

  useEffect(() => {
    if (!enabled || reference === null || reference === '') {
      setProgress(null);
      setPolling(false);
      return;
    }

    let active = true;
    let timer: number | undefined;
    let controller: AbortController | null = null;

    const stop = (): void => {
      active = false;
      setPolling(false);
      if (timer !== undefined) {
        window.clearTimeout(timer);
      }
      controller?.abort();
    };

    const schedule = (): void => {
      if (!active) {
        return;
      }

      // A hidden tab is checked much less often: nobody is looking, and the
      // next visible poll refreshes it immediately anyway.
      const delay = document.visibilityState === 'hidden' ? Math.max(intervalMs, 30_000) : intervalMs;
      timer = window.setTimeout(() => void poll(), delay);
    };

    const poll = async (): Promise<void> => {
      if (!active) {
        return;
      }

      controller = new AbortController();

      try {
        const next = await getJobProgress(reference, controller.signal);

        if (!active) {
          return;
        }

        setProgress(next);
        setError(null);
        setLoading(false);

        if (next.is_finished) {
          setPolling(false);
          onFinishedRef.current?.(next);
          active = false;
          return;
        }

        schedule();
      } catch (requestError: unknown) {
        if (!active || (requestError instanceof DOMException && requestError.name === 'AbortError')) {
          return;
        }

        setLoading(false);

        // A job that has gone (or was never the caller's) will not appear
        // later, so there is nothing to keep polling for.
        if (requestError instanceof ApiError && (requestError.status === 404 || requestError.isUnauthenticated)) {
          setError(requestError.status === 404 ? requestError.message : null);
          setPolling(false);
          active = false;
          return;
        }

        setError(requestError instanceof Error ? requestError.message : 'Progress could not be loaded.');

        // A transient failure is not a reason to give up on a running job;
        // back off and try again.
        if (active) {
          timer = window.setTimeout(() => void poll(), Math.max(intervalMs * 2, 5000));
        }
      }
    };

    const onVisibilityChange = (): void => {
      if (document.visibilityState === 'visible' && active) {
        if (timer !== undefined) {
          window.clearTimeout(timer);
        }
        void poll();
      }
    };

    setLoading(true);
    setPolling(true);
    document.addEventListener('visibilitychange', onVisibilityChange);
    void poll();

    return () => {
      document.removeEventListener('visibilitychange', onVisibilityChange);
      stop();
    };
  }, [reference, enabled, intervalMs, refreshToken]);

  return { progress, loading, error, polling, refresh };
}
