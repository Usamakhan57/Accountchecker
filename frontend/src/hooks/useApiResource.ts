import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError } from '@/services/apiClient';

interface ResourceState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
}

interface ResourceResult<T> extends ResourceState<T> {
  reload: () => void;
  setData: (updater: (current: T | null) => T | null) => void;
}

/**
 * Loads a resource and re-loads it when `deps` change.
 *
 * In-flight requests are aborted on unmount and superseded when deps change, so
 * a slow first response can never overwrite a newer one.
 */
export function useApiResource<T>(
  loader: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  options: { enabled?: boolean } = {},
): ResourceResult<T> {
  const enabled = options.enabled ?? true;

  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    loading: enabled,
    error: null,
  });

  const [reloadToken, setReloadToken] = useState(0);
  const loaderRef = useRef(loader);
  loaderRef.current = loader;

  useEffect(() => {
    if (!enabled) {
      setState({ data: null, loading: false, error: null });
      return;
    }

    const controller = new AbortController();
    let active = true;

    setState((current) => ({ ...current, loading: true, error: null }));

    loaderRef
      .current(controller.signal)
      .then((data) => {
        if (active) {
          setState({ data, loading: false, error: null });
        }
      })
      .catch((error: unknown) => {
        if (!active || controller.signal.aborted) {
          return;
        }

        // A 401 is handled globally by AuthProvider, which redirects to the
        // sign-in screen; showing an inline error too would be noise.
        if (error instanceof ApiError && error.isUnauthenticated) {
          setState({ data: null, loading: false, error: null });
          return;
        }

        setState({
          data: null,
          loading: false,
          error: error instanceof Error ? error.message : 'The request could not be completed.',
        });
      });

    return () => {
      active = false;
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, reloadToken, ...deps]);

  const reload = useCallback(() => setReloadToken((token) => token + 1), []);

  const setData = useCallback((updater: (current: T | null) => T | null) => {
    setState((current) => ({ ...current, data: updater(current.data) }));
  }, []);

  return { ...state, reload, setData };
}
